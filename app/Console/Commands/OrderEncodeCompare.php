<?php

namespace App\Console\Commands;

use App\Models\OrderEncode;
use App\Services\OrderEncode\Comparer;
use App\Services\OrderEncode\Settings;
use App\Services\OrderEncode\Sheets;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Spec 130: ang mga shadow na order (tapos nang i-encode ng Likha, mahigit 15 minuto na, wala pang 48 oras) ay inihahambing sa
 * DATABASE row na may parehong row number sa compare_sheet ng page, at sa DATABASE - MIRRORED O/P. Walang sinusulat sa sheet.
 * Naghihintay hangga't blangko ang R o S ng sheet; pagkalipas ng 48 oras: sheet_not_done.
 */
class OrderEncodeCompare extends Command
{
    protected $signature = 'order-encode:compare';
    protected $description = 'Compare shadow-encoded orders with the DATABASE rows of the sheet (spec 130)';

    public function handle(Settings $settings, Sheets $sheets): int
    {
        if (!Schema::hasTable('order_encodes')) {
            $this->warn('order_encodes does not exist yet (run the migration). Nothing to compare.');

            return self::SUCCESS;
        }

        $after = (int) config('order_encode.compare_after_minutes', 15);
        $giveUp = (int) config('order_encode.compare_give_up_hours', 48);

        $open = fn () => OrderEncode::where('mode', 'shadow')->where(fn ($q) => $q->whereNull('compare_state')->orWhere('compare_state', 'waiting'));

        $gone = $open()->where('created_at', '<', now()->subHours($giveUp))->update(['compare_state' => 'sheet_not_done', 'compared_at' => now()]);

        $due = $open()->where('status', 'done')->whereNotNull('row_number')
            ->where('created_at', '<=', now()->subMinutes($after))->where('created_at', '>=', now()->subHours($giveUp))
            ->orderBy('id')->get()->groupBy(fn ($o) => (string) $settings->sheetId($o->page, 'compare_sheet'));

        $counts = ['done' => 0, 'waiting' => 0, 'row_mismatch' => 0, 'failed_reads' => 0];
        foreach ($due as $sheetId => $orders) {
            if ($sheetId === '') continue; // walang compare_sheet ang page
            $ranges = [];
            foreach ($orders as $o) {
                $ranges[] = "DATABASE!A{$o->row_number}:W{$o->row_number}";
                $ranges[] = "'DATABASE - MIRRORED'!O{$o->row_number}:P{$o->row_number}";
            }
            try {
                $read = $sheets->batchValues($sheetId, $ranges);
            } catch (Throwable $e) {
                report($e);
                $counts['failed_reads'] += count($orders);
                $this->error('Google refused the read for one compare sheet; its orders wait for the next run.');
                continue;
            }
            foreach ($orders as $o) {
                $db = array_pad(array_map('strval', $read["DATABASE!A{$o->row_number}:W{$o->row_number}"][0] ?? []), 23, '');
                $mirror = array_pad(array_map('strval', $read["'DATABASE - MIRRORED'!O{$o->row_number}:P{$o->row_number}"][0] ?? []), 2, '');
                $counts[$this->one($o, $db, $mirror)]++;
            }
        }

        $this->info(sprintf('compared %d, waiting %d, row mismatch %d, sheet not done %d, read failures %d', $counts['done'], $counts['waiting'], $counts['row_mismatch'], $gone, $counts['failed_reads']));

        return $counts['failed_reads'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return 'done'|'waiting'|'row_mismatch' */
    private function one(OrderEncode $o, array $db, array $mirror): string
    {
        // Ang row na hindi pa naisusulat ng encoder ng sheet: maghihintay.
        if (trim(implode('', $db)) === '') return $this->save($o, 'waiting');

        $sheet = ['database' => $db, 'mirrored' => $mirror];
        if (!Comparer::sameOrder($o, $db)) return $this->save($o, 'row_mismatch', ['sheet' => $sheet]);
        if (trim($db[17]) === '' || trim($db[18]) === '') return $this->save($o, 'waiting', ['sheet' => $sheet]); // R o S: hindi pa naaabot ng checker

        $fields = Comparer::fields((array) $o->outputs, $db, $mirror);

        return $this->save($o, 'done', ['sheet' => $sheet + ['notes' => Comparer::notes((array) $o->outputs, $db, $mirror, $fields)], 'compare' => $fields, 'compare_equal' => count(array_unique($fields)) === 1 && reset($fields) === 'same']);
    }

    private function save(OrderEncode $o, string $state, array $more = []): string
    {
        $o->update(['compare_state' => $state, 'compared_at' => now()] + $more);

        return $state;
    }
}
