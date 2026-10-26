<?php

namespace App\Jobs;

use App\Models\UploadLog;
use App\Services\MetaAds\Sync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Ang "Sync now" ng /ads_manager/report: isang run ng Meta Ads API → ads_manager_reports, sa queue. */
class SyncMetaAdsReports implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries   = 1;

    public function __construct(public int $uploadLogId, public string $from, public string $to)
    {
    }

    public function handle(Sync $sync): void
    {
        $log = UploadLog::find($this->uploadLogId);
        if ($log) $sync->run($log, $this->from, $this->to);
    }
}
