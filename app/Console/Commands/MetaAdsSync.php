<?php

namespace App\Console\Commands;

use App\Services\MetaAds\Sync;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Ang Ads Manager report mula sa Meta API → ads_manager_reports (day + ad_id). Gumagana lang kapag naka-on ang write
 * mode. --scheduled: tinatawag kada minuto ng scheduler; tumatakbo lang sa oras ng schedule (Manila).
 */
class MetaAdsSync extends Command
{
    protected $signature = 'meta-ads:sync {--from= : Y-m-d} {--to= : Y-m-d} {--scheduled : tumakbo lang sa oras ng schedule}';

    protected $description = 'Write the Meta Ads API report into ads_manager_reports (only when write mode is on)';

    public function handle(Sync $sync): int
    {
        if (!Sync::writeMode()) {
            if (!$this->option('scheduled')) $this->warn('Write mode is off: nothing written. Use meta-ads:compare.');
            return self::SUCCESS;
        }
        if ($this->option('scheduled') && !in_array(Carbon::now('Asia/Manila')->format('H:i'), Sync::schedule(), true)) {
            return self::SUCCESS;
        }

        [$from, $to] = Sync::range($this->option('from') ?: null, $this->option('to') ?: null);
        $log = $sync->run(Sync::startLog($from, $to, null), $from, $to);
        $this->info("Meta API sync #{$log->id} {$from} to {$to}: {$log->status}, inserted {$log->inserted}, updated {$log->updated}, skipped {$log->skipped}");
        if ($log->error_message) $this->warn($log->error_message);

        return self::SUCCESS;
    }
}
