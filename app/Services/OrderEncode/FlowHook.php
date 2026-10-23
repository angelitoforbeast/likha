<?php

namespace App\Services\OrderEncode;

use App\Jobs\EncodeOrder;
use App\Models\OrderEncode;
use App\Services\Chatbot\FlowRun;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ang tulay ng sheet step ng flow papunta sa order encode. Hindi kailanman nagpapabagsak ng flow: anumang error ay itinatala sa log
 * at ang flow ay nagpapatuloy (shadow: wala lang encode; live: ang row ay isusulat sa sheet gaya ng dati).
 */
class FlowHook
{
    public const NOTE = 'encoded in Likha';

    public function __construct(private Settings $settings)
    {
    }

    /** off / shadow / live ng page ng takbo. Ang Test tab ay laging off: walang order na nagagawa roon. */
    public function mode(FlowRun $run): string
    {
        if ($run->test) return 'off';
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

    /** Live: bago ang sheet. @return bool true kapag naka-queue na ang encode (hindi na isusulat sa sheet) */
    public function live(FlowRun $run, string $sheetId, string $tab, array $row): bool
    {
        return $this->queue($run, 'live', $sheetId, $tab, $row, null);
    }

    private function queue(FlowRun $run, string $mode, string $sheetId, string $tab, array $row, ?int $number): bool
    {
        try {
            $attrs = ['spreadsheet_id' => $sheetId, 'tab' => $tab, 'row_number' => $number];
            $values = [
                'page' => $run->pageName, 'psid' => (int) $run->fields->psid, 'chat_id' => $run->chatId, 'flow_id' => $run->flowId, 'run_id' => $run->runId,
                'mode' => $mode, 'status' => 'queued', 'input' => ['row' => array_values($row)],
            ];
            // Shadow: ang parehong row ng sheet ay isang beses lang. Live: bawat takbo ng step ay sariling order.
            $order = $number === null ? OrderEncode::create($attrs + $values) : OrderEncode::firstOrCreate($attrs, $values);
            if ($order->wasRecentlyCreated) EncodeOrder::dispatch($order->id);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
