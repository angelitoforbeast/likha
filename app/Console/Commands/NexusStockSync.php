<?php

namespace App\Console\Commands;

use App\Services\Nexus\StockInSync;
use Illuminate\Console\Command;

/**
 * Nexus stock-in → nexus_stock_ins (kada oras sa :10 Manila). Ang unang takbo ay kusang bumabalik hanggang sa
 * item_stock_start, isang araw kada request. --from: itanong ulit mula sa isang araw (hindi bago ang start).
 */
class NexusStockSync extends Command
{
    protected $signature = 'nexus:sync-stock {--from= : Y-m-d, ask again from this day (never before the stock start)}';

    protected $description = 'Store the Nexus stock-in per day and item (for the stock on /item)';

    public function handle(StockInSync $sync): int
    {
        $from = $this->option('from');
        $res  = $sync->run(is_string($from) && $from !== '' ? $from : null);

        foreach ($res['lines'] as $line) {
            $res['status'] === 'failed' || $res['status'] === 'bad_from' ? $this->warn($line) : $this->line($line);
        }

        return in_array($res['status'], ['failed', 'bad_from', 'no_table'], true) ? self::FAILURE : self::SUCCESS;
    }
}
