<?php

namespace App\Console\Commands;

use App\Services\ItemStockService;
use App\Services\Stock\JntReturnedSource;
use App\Services\Stock\NexusRtsReturnedSource;
use App\Services\Stock\ReturnedSources;
use App\Support\ItemBaseKey;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Read-only: J&T returned at Nexus RTS returned kada item, magkatabi, mula item_stock_start — para maikumpara ng
 * owner ang dalawa bago palitan ang app_settings.stock_returned_source. Walang isinusulat.
 */
class StockReturnedCompare extends Command
{
    protected $signature = 'stock:returned-compare';

    protected $description = 'Read-only: J&T returned and Nexus RTS returned pieces per item, side by side';

    public function handle(ReturnedSources $sources): int
    {
        $start = ItemStockService::startSetting();
        if ($start === null) {
            $this->line('No valid item_stock_start: nothing to compare.');
            return self::SUCCESS;
        }
        $until = Carbon::now('Asia/Manila')->format('Y-m-d H:i:s');
        $jnt   = app(JntReturnedSource::class)->returned($start, $until);
        $nexus = app(NexusRtsReturnedSource::class)->returned($start, $until);

        $nexusByKey = [];
        foreach ($nexus->pieces as $name => $n) {
            $k = $nexus->likhaKeys ? (string) $name : ItemBaseKey::key((string) $name);
            if ($k !== '') $nexusByKey[$k] = ($nexusByKey[$k] ?? 0) + (int) $n;
        }

        $keys = array_unique(array_merge(array_keys($jnt->pieces), array_keys($nexusByKey)));
        sort($keys);
        $rows = [];
        foreach ($keys as $k) {
            $rows[] = [$k, $jnt->pieces[$k] ?? 0, $nexus->available ? ($nexusByKey[$k] ?? 0) : 'not available'];
        }
        $rows[] = ['TOTAL', array_sum($jnt->pieces), $nexus->available ? array_sum($nexusByKey) : 'not available'];

        $this->line("Returned since $start (now: $until). Current source: " . $sources->current()['setting']);
        $this->table(['Item', 'J&T returned', 'Nexus RTS returned'], $rows);
        $flags = app(JntReturnedSource::class)->flags($until);
        if ($flags['unsigned'] || $flags['future']) {
            $this->line('J&T Returned not counted (all items): ' . $flags['unsigned'] . ' without signingtime, '
                . $flags['future'] . ' with a future signingtime.');
        }

        return self::SUCCESS;
    }
}
