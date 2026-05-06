<?php

namespace App\Jobs;

use App\Models\BulkUploadRun;
use App\Models\UploadLogV2;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * CONSUMER half ng V2 producer-consumer architecture.
 *
 * Trabaho:
 *   1. Get the CSV file produced by ProcessJntUploadV2 (sa storage/app/jnt_v2_csv/upload_<id>.csv)
 *   2. DELETE existing staging rows for this upload_log_id (idempotency for retry)
 *   3. LOAD DATA LOCAL INFILE → from_jnts_2_staging (5-10x faster than chunked INSERT)
 *   4. Mark log status = 'done'
 *   5. Cleanup CSV
 *   6. Dispatch ConsolidateAndMergeJntV2Run kung huling file na ng batch
 *
 * Bakit single-worker sa Supervisor (numprocs=1)?
 *   Walang concurrent writers sa from_jnts_2_staging = walang deadlock by design.
 *   LOAD DATA ay napakabilis (~3-10s para sa 100k-500k rows), kaya single worker
 *   ay hindi bottleneck. Yung parsers (3 parallel) ang mas mabagal.
 *
 * Failure modes:
 *   - CSV missing → fail; ProcessJntUploadV2 might have crashed mid-write
 *   - LOAD DATA error (bad CSV format) → fail with clear message
 *   - DB transient error (deadlock with consolidator, etc.) → retry up to 3x
 */
class LoadStagingV2 implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes — LOAD DATA INFILE is fast even for big files
    public $tries   = 3;   // Retry safety para sa transient errors (rare with single writer)

    private int $logId;

    public function __construct(int $logId)
    {
        $this->logId = $logId;
        // Dedicated load queue. Supervisor MUST be configured with numprocs=1
        // sa likha-queue-jnt-v2-load para isang writer lang at any time.
        $this->onQueue('jnt_v2_load');
    }

    public function handle(): void
    {
        /** @var UploadLogV2 $log */
        $log = UploadLogV2::findOrFail($this->logId);

        // Skip if cancelled mid-flight
        $run = $log->bulk_run_id ? BulkUploadRun::find($log->bulk_run_id) : null;
        if ($log->status === 'cancelled' || ($run && $run->status === 'cancelled')) {
            $log->status      = 'cancelled';
            $log->finished_at = Carbon::now('Asia/Manila');
            $log->save();
            $this->cleanupCsv();
            return;
        }

        $csvPath = $this->csvPath();
        if (!file_exists($csvPath)) {
            throw new \RuntimeException("CSV file not found (parser may have failed): {$csvPath}");
        }

        try {
            // Idempotency: pag retry, alisin muna yung existing rows ng same upload_log_id
            DB::table('from_jnts_2_staging')
                ->where('upload_log_id', $this->logId)
                ->delete();

            // LOAD DATA LOCAL INFILE — single fast bulk operation.
            // Wrapped in DB::transaction with 5 attempts as safety net for the rare case
            // ng deadlock sa consolidator (kung umuubra siya kahit single loader).
            $this->loadCsv($csvPath);

            // Get actual row count na na-load (truth from DB, not parser estimate)
            $rowsLoaded = DB::table('from_jnts_2_staging')
                ->where('upload_log_id', $this->logId)
                ->count();

            $log->inserted    = $rowsLoaded;
            $log->status      = 'done';
            $log->finished_at = Carbon::now('Asia/Manila');
            $log->save();

            // Cleanup CSV — load successful, hindi na kailangan
            $this->cleanupCsv();

            // Maybe dispatch consolidate (kung huling file na ng batch)
            $this->maybeDispatchConsolidate($log->bulk_run_id);
        } catch (\Throwable $e) {
            $log->status        = 'failed';
            $log->error_message = mb_substr('Load failed: ' . $e->getMessage(), 0, 500);
            $log->finished_at   = Carbon::now('Asia/Manila');
            $log->save();

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        try {
            // Cleanup CSV after permanent failure (lahat ng retries exhausted)
            $this->cleanupCsv();

            $log = UploadLogV2::find($this->logId);
            if ($log && in_array($log->status, ['queued', 'processing', 'loading'], true)) {
                $log->status        = 'failed';
                $log->error_message = mb_substr('Load job failed: ' . $exception->getMessage(), 0, 500);
                $log->finished_at   = Carbon::now('Asia/Manila');
                $log->save();
            }

            if ($log && $log->bulk_run_id) {
                $this->maybeDispatchConsolidate($log->bulk_run_id);
            }
        } catch (\Throwable $e) {
            \Log::error('LoadStagingV2 failed() handler error: ' . $e->getMessage());
        }
    }

    /**
     * Execute LOAD DATA LOCAL INFILE with deadlock-tolerant retry.
     *
     * Note: PDO::MYSQL_ATTR_LOCAL_INFILE must be enabled sa config/database.php
     * (already set as part of this refactor). Server-side `local_infile=1` MySQL
     * variable din kailangan — verify sa: SHOW GLOBAL VARIABLES LIKE 'local_infile';
     */
    private function loadCsv(string $csvPath): void
    {
        // Path is constructed server-side from upload_log_id (not user input),
        // pero ginagamit pa rin natin yung addslashes for defense in depth.
        $escapedPath = addslashes($csvPath);

        // Column variables prefixed with @ — kinukuha muna sa temp vars,
        // tapos SET clause converts empty strings to NULL para sa nullable columns.
        // bulk_run_id at upload_log_id ay non-nullable kaya direct assignment.
        $sql = "
            LOAD DATA LOCAL INFILE '{$escapedPath}'
            INTO TABLE from_jnts_2_staging
            FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '\"'
            LINES TERMINATED BY '\\n'
            IGNORE 1 LINES
            (@bulk_run_id, @upload_log_id, @submission_time, @waybill_number,
             @receiver, @receiver_cellphone, @sender, @item_name, @cod, @remarks,
             @status, @signingtime, @province, @city, @barangay,
             @total_shipping_cost, @rts_reason, @parsed_at)
            SET
                bulk_run_id         = @bulk_run_id,
                upload_log_id       = @upload_log_id,
                submission_time     = NULLIF(@submission_time, ''),
                waybill_number      = NULLIF(@waybill_number, ''),
                receiver            = NULLIF(@receiver, ''),
                receiver_cellphone  = NULLIF(@receiver_cellphone, ''),
                sender              = NULLIF(@sender, ''),
                item_name           = NULLIF(@item_name, ''),
                cod                 = NULLIF(@cod, ''),
                remarks             = NULLIF(@remarks, ''),
                status              = NULLIF(@status, ''),
                signingtime         = NULLIF(@signingtime, ''),
                province            = NULLIF(@province, ''),
                city                = NULLIF(@city, ''),
                barangay            = NULLIF(@barangay, ''),
                total_shipping_cost = NULLIF(@total_shipping_cost, ''),
                rts_reason          = NULLIF(@rts_reason, ''),
                parsed_at           = NULLIF(@parsed_at, '')
        ";

        // 5 attempts on deadlock — Laravel retries automatically on serialization failure.
        // Should rarely fire kasi single loader, pero safety net for consolidator overlap.
        DB::transaction(function () use ($sql) {
            DB::statement($sql);
        }, 5);
    }

    /**
     * Check kung huling file ng batch ito at dispatch consolidate job kung yes.
     * Same logic as ProcessJntUploadV2::maybeDispatchConsolidate — duplicated here
     * dahil yung consolidate dispatch ay moved from parser → loader (loader ay
     * may authoritative knowledge na done na talaga ang file).
     */
    private function maybeDispatchConsolidate(?int $runId): void
    {
        if (!$runId) return;

        $stillRunning = UploadLogV2::where('bulk_run_id', $runId)
            ->whereIn('status', ['queued', 'processing'])
            ->where('id', '!=', $this->logId)
            ->count();

        if ($stillRunning > 0) {
            return;
        }

        $run = BulkUploadRun::find($runId);
        if (!$run) return;

        if (!in_array($run->status, ['processing', 'queued'], true)) {
            return;
        }

        \App\Jobs\ConsolidateAndMergeJntV2Run::dispatch($runId);
    }

    private function csvPath(): string
    {
        return storage_path('app/jnt_v2_csv' . DIRECTORY_SEPARATOR . 'upload_' . $this->logId . '.csv');
    }

    private function cleanupCsv(): void
    {
        $path = $this->csvPath();
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}
