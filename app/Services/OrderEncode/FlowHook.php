<?php

namespace App\Services\OrderEncode;

use App\Jobs\EncodeOrder;
use App\Models\MacroOutput;
use App\Models\OrderEncode;
use App\Services\Chatbot\FlowRun;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ang tulay ng sheet step ng flow papunta sa order encode. Hindi kailanman nagpapabagsak ng flow: anumang error ay itinatala sa log
 * at ang flow ay nagpapatuloy (shadow: wala lang encode; live: ang row ay isusulat sa sheet gaya ng dati).
 * Mga order lang ang ine-encode: ang Sheet step na sumusulat sa tab na "All Orders". Ang ibang Sheet step ng flow ay hindi ginagalaw.
 */
class FlowHook
{
    public const NOTE = 'encoded in Likha';
    public const TAB = 'All Orders';

    public function __construct(private Settings $settings, private LocationList $list)
    {
    }

    /** off / shadow / live ng page ng takbo. Ang Test tab at ang ibang tab ay laging off: walang order na nagagawa roon. */
    public function mode(FlowRun $run, string $tab): string
    {
        if ($run->test || mb_strtolower(trim($tab)) !== mb_strtolower(self::TAB)) return 'off';
        try {
            return $this->settings->mode($run->pageName);
        } catch (Throwable $e) {
            report($e);

            return 'off';
        }
    }

    /** Shadow: pagkatapos masulat ang row. Ang row number ay galing sa range ng append, hal. 'All Orders'!A23823:I23823. */
    public function shadow(FlowRun $run, string $sheetId, string $tab, array $row, ?string $range): void
    {
        $number = is_string($range) && preg_match('/!\$?[A-Z]+\$?(\d+)/', $range, $m) ? (int) $m[1] : null;
        if ($number === null) {
            Log::warning('order-encode: no row number in the append result, not encoded', ['page' => $run->pageName]);

            return;
        }
        $this->queue($run, 'shadow', $sheetId, $tab, $row, $number);
    }

    /**
     * Live: bago ang sheet. Hindi ito sumasalo ng order kung hindi handa ang page (compare_sheet, ang address file, ang key): saka isinusulat ang row sa sheet gaya ng dati.
     * @return bool true kapag naka-queue na ang encode (hindi na isusulat sa sheet)
     */
    public function live(FlowRun $run, string $sheetId, string $tab, array $row): bool
    {
        if (!$this->ready($run->pageName)) {
            Log::warning('order-encode: live page is not ready, the row goes to the sheet', ['page' => $run->pageName]);

            return false;
        }

        return $this->queue($run, 'live', $sheetId, $tab, $row, null);
    }

    /** Ang order_encodes.spreadsheet_id ng order ng Encode order step na walang sheet na mapagsusulatan kapag pumalya. */
    public const NODE_SHEET = 'flow-node';

    /** Naka-queue na ba ang step na ito sa takbong ito? (ulit na takbo ng parehong run = parehong order) */
    public function hasNode(FlowRun $run, string $nodeId): bool
    {
        try {
            return $run->runId !== null && OrderEncode::where('mode', 'live')->where('psid', (int) $run->fields->psid)->where('run_id', $run->runId)->where('flow_id', $run->flowId)
                ->where('input->node', $nodeId)->exists(); // ang psid: ang run id ay 8 hex lang, hindi dapat magkabanggaan ang dalawang customer
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Naisulat na ba sa sheet ang order na tatamaan ng takbong ito? Oo kapag ang customer ay may naisulat nang order na naka-queue pa
     * (double tap), o naka-link sa bukas na row niya, o sa final na row niya ngayong araw (hindi tatanggapin ang order). Ang bagong row
     * (kinabukasan, pagkatapos ng CANNOT PROCEED) ay may sariling sheet row at fallback.
     */
    public function sheetDone(FlowRun $run): bool
    {
        try {
            $today = now('Asia/Manila')->toDateString();
            $written = OrderEncode::where('mode', 'live')->where('page', $run->pageName)->where('psid', (int) $run->fields->psid)->where('created_at', '>=', now()->subDays(2))
                ->orderByDesc('id')->limit(20)->get(['id', 'status', 'macro_output_id', 'input', 'created_at'])->filter(fn (OrderEncode $o) => !empty($o->input['sheet_written']));
            foreach ($written as $o) {
                if (!$o->macro_output_id) {
                    if ($o->status === 'queued') return true;
                    continue;
                }
                $row = MacroOutput::find($o->macro_output_id);
                if (!$row) continue;
                $status = strtoupper(trim((string) $row->STATUS));
                // Ang bukas na row ay tatamaan lang sa loob ng 24 oras (gaya ng LiveOrder::find); lampas doon, bagong row at sariling sheet row.
                if ($status === '' && trim((string) $row->waybill) === '' && $o->created_at?->gte(now()->subDay())) return true;
                if ($status !== 'CANNOT PROCEED' && substr((string) $row->ts_date, 0, 10) === $today) return true;
            }

            return false;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Encode order step: naka-queue ba ang order? Hindi kailangan ang compare_sheet at ang mode ng page; ang kailangan ay ang
     * key at ang address file. $sheetWritten: naisulat na ang row sa sheet (kasabay na sulat), kaya hindi na ito isusulat ng fallback.
     */
    public function node(FlowRun $run, string $nodeId, string $sheetId, string $tab, array $row, bool $sheetWritten): bool
    {
        try {
            if ($this->settings->key() === '' || !$this->list->has()) {
                Log::warning('order-encode: the Encode order step is not ready (key or address file)', ['page' => $run->pageName]);

                return false;
            }
            $order = OrderEncode::create([
                'page' => $run->pageName, 'psid' => (int) $run->fields->psid, 'chat_id' => $run->chatId, 'flow_id' => $run->flowId, 'run_id' => $run->runId,
                'spreadsheet_id' => $sheetId, 'tab' => $tab, 'row_number' => null, 'mode' => 'live', 'status' => 'queued',
                'input' => ['row' => array_values($row), 'node' => $nodeId, 'sheet_written' => $sheetWritten, 'page_id' => $run->pageId],
            ]);
            try {
                EncodeOrder::dispatch($order->id, true);
            } catch (Throwable $e) {
                $order->update(['status' => 'failed', 'error' => 'The encode could not be queued.']); // walang naiwang queued na hindi tatakbo kailanman
                throw $e;
            }

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    private function ready(string $page): bool
    {
        try {
            $after = $this->settings->sheetId($page, 'compare_sheet');

            return $after !== null && $this->settings->key() !== '' && $this->list->has();
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    private function queue(FlowRun $run, string $mode, string $sheetId, string $tab, array $row, ?int $number): bool
    {
        try {
            $attrs = ['spreadsheet_id' => $sheetId, 'tab' => $tab, 'row_number' => $number];
            $values = [
                'page' => $run->pageName, 'psid' => (int) $run->fields->psid, 'chat_id' => $run->chatId, 'flow_id' => $run->flowId, 'run_id' => $run->runId,
                'mode' => $mode, 'status' => 'queued', 'input' => ['row' => array_values($row)] + ($mode === 'live' ? ['page_id' => $run->pageId] : []),
            ];
            // Shadow: ang parehong row ng sheet ay isang beses lang. Live: bawat takbo ng step ay sariling order.
            $order = $number === null ? OrderEncode::create($attrs + $values) : OrderEncode::firstOrCreate($attrs, $values);
            if ($order->wasRecentlyCreated) EncodeOrder::dispatch($order->id, $mode === 'live');

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
