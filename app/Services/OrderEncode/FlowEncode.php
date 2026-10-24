<?php

namespace App\Services\OrderEncode;

use App\Jobs\EncodeOrder;
use App\Jobs\RunEncodeAstra;
use App\Models\MacroOutput;
use App\Models\OrderEncode;
use App\Services\AiCheckerRowRunner;
use App\Services\BotcakeCustomerMatcher;
use App\Services\Chatbot\FlowRun;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Throwable;

/**
 * "Encode order" step: ine-queue ang encode ng row ng order (ang isinulat ng Upload to macro sa parehong takbo, o ang bukas na
 * row ng customer) sa express lane, at tuloy agad ang flow sa next, sa anumang takbo (main flow o sequence step). Sa background:
 * ang encode ng row (Runner), saka si Astra kapag hindi PROCEED. Ang resulta ay binabasa ng flow sa mga order_* variable
 * (OrderVars), hal. sa sequence step pagkatapos ng delay.
 */
class FlowEncode
{
    /** Ang ticket ng lumang paghihintay sa chatbot_ai_requests: ang naiwan bago ang pagbabago ay tinatapos pa ng sweep. */
    public const MODEL = 'order-encode';
    public const STATUS = 'encoding';
    public const NO_ROW = 'No order row to encode';
    /** Ang mga hold ng panuntunan na tungkol sa address (R5 = doble). */
    private const ADDRESS_RULES = ['R2', 'R4', 'R6'];

    public function __construct(private Settings $settings, private LocationList $list, private LiveOrder $live)
    {
    }

    /**
     * Ang step sa takbo (live): ang resulta para sa FlowEngine (port at note). Hindi naghihintay.
     * @return array{port: string, note: string}
     */
    public function start(FlowRun $run, string $nodeId, array $c): array
    {
        $up = Upload::ofRun($run);
        $row = $up ? MacroOutput::find($up->macro_output_id) : $this->live->openRow($run->pageName, (string) $run->fields->psid);
        if (!$row) return $this->failNow($run, $nodeId, $up ? 'The uploaded row is gone' : self::NO_ROW);

        // Final na ang row (PROCEED, courier status o waybill): walang ginagalaw.
        if (self::final($row) || ($up && $up->status !== 'uploaded')) {
            $run->event('encode_macro', $nodeId, ['result' => self::final($row) ? 'final' : 'already', 'row' => (string) $row->id]);

            return ['port' => 'next', 'note' => self::final($row) ? 'Row ' . $row->id . ' is final: not encoded' : 'Already encoded in this run: row ' . $row->id];
        }
        // Isang encode lang kada row habang naka-queue o tumatakbo pa (kasama si Astra).
        if (self::busy((int) $row->id)) {
            $run->event('encode_macro', $nodeId, ['result' => 'already', 'row' => (string) $row->id]);

            return ['port' => 'next', 'note' => 'Row ' . $row->id . ' is already being encoded'];
        }
        if ($this->settings->key() === '' || !$this->list->has()) return $this->failNow($run, $nodeId, 'The encode is not ready (no OpenAI key or address file)');

        $o = $up ?? $this->orderFor($run, $nodeId, $row);
        $before = $o->exists ? $o->status : null;
        $o->fill(['status' => 'queued', 'input' => array_merge((array) $o->input, ['encode' => ['node' => $nodeId, 'astra' => !empty($c['astra'])]])])->save();
        try {
            EncodeOrder::dispatch($o->id, true);
        } catch (Throwable $e) {
            report($e);
            $before ? $o->update(['status' => $before]) : $o->delete();

            return $this->failNow($run, $nodeId, 'The encode could not be queued');
        }
        $run->event('encode_macro', $nodeId, ['result' => 'queued', 'row' => (string) $row->id]);

        return ['port' => 'next', 'note' => 'Encode of row ' . $row->id . ' queued'];
    }

    /**
     * Ang order_encodes row na ie-encode para sa bukas na row (walang Upload sa takbong ito): ang upload nito na hindi pa
     * na-encode, o isang bago na may usapan ng huling encode o upload ng row (kung wala, ang laman ng row mismo).
     */
    private function orderFor(FlowRun $run, string $nodeId, MacroOutput $row): OrderEncode
    {
        $last = OrderEncode::where('macro_output_id', $row->id)->where('mode', 'live')->whereNotNull('input->fields')->orderByDesc('id')->first();
        if ($last && $last->status === 'uploaded' && !empty($last->input['upload'])) return $last;

        $fields = $last ? (array) $last->input['fields'] : [
            'date' => (string) $row->TIMESTAMP, 'page' => (string) $row->PAGE, 'name' => (string) $row->fb_name, 'phone' => (string) $row->{'PHONE NUMBER'},
            'input' => (string) $row->all_user_input, 'shop' => (string) $row->shop_details, 'cxd' => (string) $row->extracted_details, 'price' => (string) $row->COD,
        ];

        return new OrderEncode([
            'page' => $run->pageName, 'psid' => (int) $run->fields->psid, 'chat_id' => $run->chatId, 'flow_id' => $run->flowId, 'run_id' => $run->runId,
            'spreadsheet_id' => FlowHook::NODE_SHEET, 'tab' => FlowHook::TAB, 'row_number' => null, 'mode' => 'live', 'macro_output_id' => $row->id,
            'input' => ['row' => [], 'node' => $nodeId, 'page_id' => $run->pageId, 'upload' => 'encode', 'linked' => true, 'fields' => $fields],
        ]);
    }

    /** May encode ng row na naka-queue o tumatakbo pa (pati si Astra pagkatapos nito). */
    public static function busy(int $rowId): bool
    {
        return OrderEncode::where('macro_output_id', $rowId)->where('mode', 'live')->whereIn('status', ['queued', 'done'])->orderByDesc('id')->limit(5)->get()
            ->contains(fn (OrderEncode $o) => in_array(OrderVars::state($o), ['queued', 'running'], true));
    }

    /** Ang job ng encode ay nagsimula na (order_encode_state = running). */
    public static function started(OrderEncode $o): void
    {
        $o->update(['input' => array_merge((array) $o->input, ['encode' => array_merge((array) ($o->input['encode'] ?? []), ['started_at' => now()->toDateTimeString()])])]);
    }

    /**
     * Pagkatapos ng encode ng row (Runner): kapag hindi PROCEED at naka-on ang Astra (ang box at ang kill switch), si Astra sa
     * parehong row, sa sariling job.
     */
    public function afterEncode(OrderEncode $o): void
    {
        $row = MacroOutput::find($o->macro_output_id);
        if (!$row || self::final($row) || empty($o->input['encode']['astra']) || !$this->settings->spotAstra()) return;
        if ((((array) $o->outputs)['hold']['rule'] ?? null) === 'R5') return; // ang R5 lang ang hold (doble): walang maaayos si Astra

        $o->update(['input' => array_merge((array) $o->input, ['encode' => array_merge((array) $o->input['encode'], ['astra_queued' => now()->toDateTimeString()])])]);
        try {
            RunEncodeAstra::dispatch($o->id); // sariling job sa astra-live: hindi hinahawakan ang encode worker habang si Astra
        } catch (Throwable $e) {
            report($e);
            $this->afterAstra($o, ['status' => 'not_queued']);
        }
    }

    /** Ang RunEncodeAstra job: si Astra sa row. */
    public function astraStep(OrderEncode $o): void
    {
        $this->afterAstra($o, $this->astra($o, (int) $o->macro_output_id));
    }

    /** Ang resulta ni Astra (o ang pagpalya nito) sa order, para sa order_hold_reason at sa /order-encode. */
    public function afterAstra(OrderEncode $o, array $astra): void
    {
        $o->refresh();
        $notes = array_merge((array) ($o->input['live_notes'] ?? []), ['Astra: ' . $astra['status'] . (!empty($astra['final_code']) ? ' ' . $astra['final_code'] : '')]);
        $o->update(['input' => array_merge((array) $o->input, ['astra' => $astra + ['at' => now()->toDateTimeString()], 'live_notes' => $notes])]);
    }

    /** Si Astra sa row (ang parehong run-one-row ng RunSpotAstra at ng night run); hindi naghihintay kapag hawak ng iba ang row. */
    private function astra(OrderEncode $o, int $id): array
    {
        try {
            $out = RowLock::hold($id, 0, function () use ($id, $o) {
                $row = MacroOutput::find($id);
                if (!$row || trim((string) $row->STATUS) !== '') return null;

                return app(AiCheckerRowRunner::class)->run($id, 'astra', (string) config('app.url'), [
                    'source' => 'spot', 'user_id' => null, 'user_name' => LiveOrder::USER, 'batch_id' => 'spot-' . $o->id,
                    'http_timeout' => 120, 'only_when_status_blank' => true,
                ]);
            });
        } catch (LockTimeoutException) {
            return ['status' => 'busy'];
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'failed'];
        }
        $result = is_array($out['result'] ?? null) ? $out['result'] : null;

        return $out === null ? ['status' => 'skipped'] : ['status' => (string) ($result['status'] ?? 'failed'), 'final_code' => $result['final_code'] ?? null, 'log_id' => $out['log_id'] ?? null];
    }

    /**
     * Bakit hindi PROCEED, ang una sa ayos: address, duplicate, name, phone, price, other. Si Astra (kapag tumakbo at may
     * resulta) ang huling salita sa address; kung hindi, ang encode (R2/R4/R6 at ang address check).
     * @param array<string, mixed> $outputs ang outputs ng encode
     * @param array<string, string> $row PROVINCE, CITY, BARANGAY, ADDRESS, FULL NAME, PHONE NUMBER
     */
    public static function holdReason(array $outputs, array $row, ?array $astra): string
    {
        $rule = $outputs['hold']['rule'] ?? null;
        $astraRan = $astra !== null && in_array($astra['status'] ?? '', ['fixed', 'partial'], true);
        $addressOk = $astraRan ? ($astra['final_code'] ?? '') === '✅' : ($outputs['final'] ?? '') === '✅' && !in_array($rule, self::ADDRESS_RULES, true);
        $blank = collect(['PROVINCE', 'CITY', 'BARANGAY', 'ADDRESS'])->contains(fn ($c) => trim((string) ($row[$c] ?? '')) === '');

        return match (true) {
            !$addressOk || $blank => 'address',
            $rule === 'R5' => 'duplicate',
            trim((string) ($row['FULL NAME'] ?? '')) === '' => 'name',
            BotcakeCustomerMatcher::normPhone((string) ($row['PHONE NUMBER'] ?? '')) === '' => 'phone',
            !in_array(trim((string) ($outputs['r'] ?? '')), ['', '✅'], true) => 'price',
            default => 'other',
        };
    }

    /** Final na ang row: may STATUS (hindi CANNOT PROCEED) o waybill. */
    public static function final(MacroOutput $row): bool
    {
        $status = strtoupper(trim((string) $row->STATUS));

        return ($status !== '' && $status !== 'CANNOT PROCEED') || trim((string) $row->waybill) !== '';
    }

    /** Walang ma-encode ngayon: failed port, walang naka-queue. */
    private function failNow(FlowRun $run, string $nodeId, string $note): array
    {
        $run->event('encode_macro', $nodeId, ['result' => 'failed', 'error' => $note]);

        return ['port' => 'failed', 'note' => $note];
    }
}
