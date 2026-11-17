<?php

namespace App\Console\Commands;

use App\Services\MetaAds\HourlySpend;
use App\Services\MetaAds\Sync;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Spec 288. Spend per page per hour from Meta → cpp_snapshots (H00..H23), for the hourly CPP timeline. Only when write
 * mode is on and ad accounts are selected. --scheduled (every hour at :10 Manila): today, and yesterday on the 00:10
 * run so the last hour of the day is closed. --from/--to: past days, one request per account per day with a pause.
 */
class MetaAdsHourly extends Command
{
    protected $signature = 'meta-ads:hourly {--scheduled : today (and yesterday on the 00:10 run)}
        {--date= : Y-m-d} {--from= : Y-m-d} {--to= : Y-m-d} {--pause=3 : seconds between Meta requests for --from/--to}';

    protected $description = 'Store spend per page per hour from Meta (hourly breakdown) for the CPP timeline';

    /** A backfill covers at most 31 days per run, as the sync range does. */
    public const MAX_DAYS = 31;

    public function handle(HourlySpend $hourly): int
    {
        $now = Carbon::now('Asia/Manila');
        if ($this->option('scheduled')) {
            $days = $now->hour === 0 ? [$now->copy()->subDay()->toDateString(), $now->toDateString()] : [$now->toDateString()];
            $pause = 0;
        } else {
            $from = $this->option('from') ?: ($this->option('date') ?: $now->toDateString());
            $to   = $this->option('to') ?: ($this->option('date') ?: $from);
            try {
                $f = Carbon::parse($from, 'Asia/Manila');
                $t = Carbon::parse($to, 'Asia/Manila');
            } catch (\Throwable) {
                $this->error('Dates must be Y-m-d.');
                return self::FAILURE;
            }
            if ($f->gt($t)) [$f, $t] = [$t, $f];
            if ($t->gt($now)) $t = $now->copy();
            if ($f->diffInDays($t) >= self::MAX_DAYS) {
                $this->error('At most ' . self::MAX_DAYS . ' days per run.');
                return self::FAILURE;
            }
            $days  = Sync::days($f->toDateString(), $t->toDateString());
            $pause = max(0, (int) $this->option('pause'));
        }

        $log = $hourly->run($days, $pause);
        $this->info("Meta hourly #{$log->id} " . implode(', ', [reset($days), end($days)]) . ": {$log->status}, rows {$log->processed_rows}");
        if ($log->error_message) $this->warn($log->error_message);

        return self::SUCCESS;
    }
}
