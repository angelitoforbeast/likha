<?php

namespace App\Console\Commands;

use App\Services\DailyPrimaryItemService;
use Illuminate\Console\Command;

class SupplyRefreshPrimaryItems extends Command
{
    protected $signature = 'supply:refresh-primary-items
                            {--from= : Start date YYYY-MM-DD}
                            {--to= : End date YYYY-MM-DD}
                            {--last= : Refresh the last N days (overrides from/to if set alone)}';

    protected $description = 'Recompute daily_page_primary_item for a date range (default: last 90 days).';

    public function handle(DailyPrimaryItemService $svc): int
    {
        $tz   = new \DateTimeZone('Asia/Manila');
        $today = (new \DateTime('now', $tz))->format('Y-m-d');

        $from = $this->option('from');
        $to   = $this->option('to');
        $last = $this->option('last');

        if ($last !== null && $last !== '') {
            $n = max(1, (int) $last);
            $to   = $today;
            $from = (new \DateTime($today, $tz))->modify('-' . ($n - 1) . ' days')->format('Y-m-d');
        } elseif (!$from && !$to) {
            // default: last 90 days
            $to   = $today;
            $from = (new \DateTime($today, $tz))->modify('-89 days')->format('Y-m-d');
        } else {
            if (!$from) $from = $to;
            if (!$to)   $to   = $from;
        }

        if (strtotime($from) === false || strtotime($to) === false) {
            $this->error("Invalid date(s): from=$from to=$to");
            return self::FAILURE;
        }
        if ($from > $to) [$from, $to] = [$to, $from];

        $this->info("Recomputing daily_page_primary_item for $from → $to ...");
        $t0 = microtime(true);
        $summary = $svc->recomputeRange($from, $to);
        $elapsed = round(microtime(true) - $t0, 2);

        $this->line('');
        $this->table(
            ['dates', 'pages_seen', 'rows_upserted', 'ties_skipped', 'elapsed_s'],
            [[
                $summary['dates'],
                $summary['pages'],
                $summary['rows_upserted'],
                $summary['ties_skipped'],
                $elapsed,
            ]]
        );

        return self::SUCCESS;
    }
}
