<?php

namespace App\Console\Commands;

use App\Services\Nexus\StockInSync;
use Illuminate\Console\Command;

/**
 * Nexus stock-in → nexus_stock_ins (kada oras sa :10 Manila: kahapon at ngayon; araw-araw sa 02:40: ang huling 7 araw).
 * Ang unang takbo ay kusang bumabalik hanggang sa item_stock_start, isang araw kada request.
 * --from: itanong ulit mula sa isang araw (hindi bago ang start). --days=N: isama ang huling N araw.
 * --accept-empty=YYYY-MM-DD: itanong ulit ang araw na walang laman ang sagot; kung wala pa rin, gawing 0 ang naka-store.
 * --history: isang beses, ang stock-in bago ang item_stock_start mula services.nexus.history_from (range ≤ 31 araw).
 */
class NexusStockSync extends Command
{
    protected $signature = 'nexus:sync-stock
        {--from= : Y-m-d, ask again from this day (never before the stock start)}
        {--days= : also ask the last N days (the daily pass uses 7)}
        {--accept-empty= : Y-m-d, a day Nexus answered empty for: ask again and, if still empty, set its stored items to 0}
        {--history : one-time: the Nexus stock-in before the stock start, from services.nexus.history_from, in ranges of 31 days}';

    protected $description = 'Store the Nexus stock-in per day and item (for the stock on /item)';

    public function handle(StockInSync $sync): int
    {
        if ($this->option('history')) {
            return $this->report($sync->history());
        }
        $accept = $this->option('accept-empty');
        if (is_string($accept) && $accept !== '') {
            return $this->report($sync->acceptEmpty($accept));
        }

        $from = $this->option('from');
        $days = $this->option('days');
        if ($days !== null && $days !== '' && (!ctype_digit((string) $days) || (int) $days < 1 || (int) $days > 31)) {
            $this->warn('--days must be a whole number from 1 to 31. Nothing asked.');
            return self::FAILURE;
        }

        $daysN = $days !== null && $days !== '' ? (int) $days : null;

        // Ang daily pass (--days) ay nire-refresh din ang huling N saradong araw ng /item stock cache.
        return $this->report($sync->run(is_string($from) && $from !== '' ? $from : null, $daysN), $daysN ?? 0);
    }

    private function report(array $res, int $refreshClosedDays = 0): int
    {
        foreach ($res['lines'] as $line) {
            $res['status'] === 'failed' || $res['status'] === 'bad_from' ? $this->warn($line) : $this->line($line);
        }
        // Matagumpay na takbo (bago ang huling sync, o bagong history / tinanggap na araw): painitin agad ang /item.
        // Ang pagbagsak ng warm ay hindi pagbagsak ng sync (ang page ang kukuwenta).
        if ($res['status'] === 'ok' && StockInSync::configured()) {
            StockWarm::report($this, app(\App\Services\ItemStockService::class)->warmInputs($refreshClosedDays));
        }

        return in_array($res['status'], ['failed', 'bad_from', 'no_table'], true) ? self::FAILURE : self::SUCCESS;
    }
}
