<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use App\Services\HoldService;

/**
 * Daily HOLD snapshot. Tatakbo tuwing 6 AM PH (via scheduler) at kino-capture
 * ang hold para sa KAHAPON (ang araw na tapos na). Pwede ring patakbuhin nang
 * manual: `php artisan holds:snapshot` o `php artisan holds:snapshot 2026-06-03`.
 */
class SnapshotItemHolds extends Command
{
    protected $signature   = 'holds:snapshot {date? : Y-m-d (default = kahapon PH)} {--window=60 : days lookback for held orders}';
    protected $description = 'Capture daily HOLD snapshot (units per base item) into item_hold_snapshots';

    public function handle(HoldService $svc): int
    {
        $date = (string) ($this->argument('date') ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = Carbon::now('Asia/Manila')->subDay()->toDateString();
        }
        $window = (int) $this->option('window');
        if ($window < 1) $window = 60;

        $res = $svc->snapshot($date, $window);
        $this->info("HOLD snapshot {$res['date']}: {$res['items']} items, {$res['units']} units (window {$window}d).");

        return self::SUCCESS;
    }
}
