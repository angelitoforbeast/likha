<?php

namespace App\Jobs;

use App\Models\UploadLog;
use App\Services\MetaAds\Compare;
use App\Services\MetaAds\Sync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Ang "Compare with uploads" ng /ads_manager/report, sa queue (13 account × paging × backoff ay lampas sa web timeout).
 * Ang report ay nasa upload_logs row (type ads_manager_api_compare, sa protected_details na JSON column), walang
 * isinusulat sa ads_manager_reports.
 */
class CompareMetaAds implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const LOG_TYPE = 'ads_manager_api_compare';

    public int $timeout = 1800; // mas mababa sa retry_after (1900) ng database queue
    public int $tries   = 1;

    public function __construct(public int $uploadLogId, public string $from, public string $to)
    {
    }

    public static function startLog(string $from, string $to, ?int $userId): UploadLog
    {
        return UploadLog::create([
            'type' => self::LOG_TYPE, 'disk' => 'api', 'path' => 'meta', 'original_name' => "Compare {$from} to {$to}",
            'status' => 'queued', 'user_id' => $userId,
            'processed_rows' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'error_rows' => 0,
        ]);
    }

    public function handle(Compare $compare): void
    {
        $log = UploadLog::find($this->uploadLogId);
        if (!$log) return;

        $log->update(['status' => 'processing', 'started_at' => now()]);
        $error = null;
        try {
            $report = $compare->run($this->from, $this->to);
            $log->update([
                'status'            => $report['problems'] ? 'partial' : 'done',
                'protected_details' => $report,
                'processed_rows'    => array_sum(array_column($report['accounts'], 'api_rows')),
                'error_message'     => $report['problems'] ? mb_substr(implode('; ', $report['problems']), 0, 2000) : null,
                'finished_at'       => now(),
            ]);
        } catch (Throwable $e) {
            $error = $e;
            throw $e;
        } finally {
            Sync::endUnfinished($log, $error);
        }
    }

    /** Pinatay (timeout) o nabigo: ang log row ay "failed", hindi naiiwang "processing". */
    public function failed(?Throwable $e = null): void
    {
        $log = UploadLog::find($this->uploadLogId);
        if ($log) Sync::endUnfinished($log, $e);
    }
}
