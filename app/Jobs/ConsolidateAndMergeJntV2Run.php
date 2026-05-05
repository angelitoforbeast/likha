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
use Illuminate\Support\Facades\Log;

/**
 * Stage 3 — high-throughput per-batch consolidate using INSERT ... ON DUPLICATE KEY UPDATE.
 *
 * Architecture:
 *   1. Get next batch ng N unique waybills (cursor-based via DISTINCT LIMIT)
 *   2. SINGLE UPSERT SQL na nag-fetch winners + writes to from_jnts_2
 *      with skip rule + status_logs append handled all in SQL
 *   3. DELETE these waybills from staging
 *   4. Update progress counter
 *   5. Check pause/cancel — if set, exit gracefully (resumable)
 *   6. Loop until staging empty
 *
 * Why this is much faster than the previous PHP-loop version:
 *   - 1 SQL per batch instead of 4-5 (SELECT staging, SELECT existing, INSERT, UPDATE, status_logs save loop)
 *   - No PHP-side row decoding/processing
 *   - Status_logs handled inline via JSON_ARRAY_APPEND
 *   - INSERT/UPDATE merged via UNIQUE INDEX + ON DUPLICATE KEY UPDATE
 *   - Larger batch size (5000 vs 1000) — amortizes per-batch overhead
 *
 * Required: from_jnts_2 must have UNIQUE INDEX on waybill_number.
 */
class ConsolidateAndMergeJntV2Run implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 7200;
    public $tries   = 3;

    private int $bulkRunId;

    /** Per-batch size — larger ngayon kasi single SQL per batch. */
    const BATCH_WAYBILLS = 5000;

    public function __construct(int $bulkRunId)
    {
        $this->bulkRunId = $bulkRunId;
        $this->onQueue('jnt_v2_consolidate');
    }

    public function handle(): void
    {
        $run = BulkUploadRun::find($this->bulkRunId);
        if (!$run) {
            Log::warning("Consolidate: run {$this->bulkRunId} not found");
            return;
        }

        if (in_array($run->status, ['done', 'partial', 'cancelled', 'failed'], true)) {
            Log::info("Consolidate: run {$this->bulkRunId} already terminal ({$run->status})");
            return;
        }

        if ($run->paused_at !== null) {
            Log::info("Consolidate: run {$this->bulkRunId} is paused — skipping");
            return;
        }

        // Atomic transition processing → consolidating
        if ($run->status !== 'consolidating') {
            $transitioned = BulkUploadRun::where('id', $this->bulkRunId)
                ->whereIn('status', ['processing', 'queued'])
                ->update([
                    'status'                 => 'consolidating',
                    'consolidate_started_at' => Carbon::now('Asia/Manila'),
                    'message'                => 'Consolidating waybills across files...',
                ]);

            if (!$transitioned) {
                Log::info("Consolidate: run {$this->bulkRunId} could not transition (status={$run->status})");
                return;
            }
            $run->refresh();
        }

        if ($run->consolidate_started_at === null) {
            $run->consolidate_started_at = Carbon::now('Asia/Manila');
            $run->save();
        }

        try {
            // Compute total kung wala pa
            if ($run->consolidate_total === null) {
                $total = $this->computeTotalUniqueWaybills();
                $run->consolidate_total = $total;
                $run->message = sprintf('Consolidating %s unique waybills...', number_format($total));
                $run->save();
                Log::info("Consolidate: run {$this->bulkRunId} total unique waybills = {$total}");
            }

            $this->processInBatches($run);

            // Final state
            $remaining = DB::table('from_jnts_2_staging')
                ->where('bulk_run_id', $this->bulkRunId)
                ->count();

            if ($remaining === 0) {
                $this->finalizeAsDone($run);
            } else {
                $run->refresh();
                if ($run->cancel_requested_at !== null) {
                    $run->status = 'cancelled';
                    $run->finished_at = Carbon::now('Asia/Manila');
                    $run->message = 'Cancelled by user — ' . number_format($remaining) . ' staging rows remaining';
                    $run->save();
                } elseif ($run->paused_at !== null) {
                    $run->message = sprintf(
                        'Paused at %s / %s waybills',
                        number_format($run->consolidate_processed),
                        number_format($run->consolidate_total ?? 0)
                    );
                    $run->save();
                }
            }
        } catch (\Throwable $e) {
            $run->refresh();
            $run->status      = 'failed';
            $run->finished_at = Carbon::now('Asia/Manila');
            $run->message     = 'Consolidate failed: ' . mb_substr($e->getMessage(), 0, 400);
            $run->save();

            Log::error("Consolidate: run {$this->bulkRunId} FAILED — " . $e->getMessage());
            throw $e;
        }
    }

    private function computeTotalUniqueWaybills(): int
    {
        return (int) DB::table('from_jnts_2_staging')
            ->where('bulk_run_id', $this->bulkRunId)
            ->whereNotNull('waybill_number')
            ->where('waybill_number', '!=', '')
            ->distinct('waybill_number')
            ->count('waybill_number');
    }

    private function processInBatches(BulkUploadRun $run): void
    {
        $batchCount = 0;

        while (true) {
            $run->refresh();
            if ($run->paused_at !== null) {
                Log::info("Consolidate: run {$this->bulkRunId} pause requested — exiting at batch {$batchCount}");
                return;
            }
            if ($run->cancel_requested_at !== null) {
                Log::info("Consolidate: run {$this->bulkRunId} cancel requested — exiting at batch {$batchCount}");
                return;
            }

            $waybills = DB::table('from_jnts_2_staging')
                ->where('bulk_run_id', $this->bulkRunId)
                ->whereNotNull('waybill_number')
                ->where('waybill_number', '!=', '')
                ->select('waybill_number')
                ->distinct()
                ->orderBy('waybill_number')
                ->limit(self::BATCH_WAYBILLS)
                ->pluck('waybill_number')
                ->all();

            if (empty($waybills)) {
                return;
            }

            $this->processBatchUpsert($run, $waybills);
            $batchCount++;
        }
    }

    /**
     * Batch UPSERT — single SQL na nag-INSERT new + UPDATE existing
     * (with skip rule for terminal status) + appends sa status_logs.
     *
     * Required schema: UNIQUE INDEX sa from_jnts_2.waybill_number.
     */
    private function processBatchUpsert(BulkUploadRun $run, array $waybills): void
    {
        $count    = count($waybills);
        $runId    = $this->bulkRunId;
        $now      = Carbon::now('Asia/Manila')->format('Y-m-d H:i:s');

        // Track batch start time for duration calculation
        $batchStart = microtime(true);

        // Pre-count existing waybills sa from_jnts_2 para matrack nang accurate
        // ang inserted vs updated counters (UPSERT row counts ay malabo).
        // Lightweight query — uses unique index.
        $existingCount = (int) DB::table('from_jnts_2')
            ->whereIn('waybill_number', $waybills)
            ->count();
        $newCount = $count - $existingCount;

        // Build placeholder string for IN clause
        $placeholders = implode(',', array_fill(0, $count, '?'));

        // Bindings:
        //   2x batch_at literal for status_logs (insert + update branches)
        //   2x runId for status_logs JSON_OBJECT (insert + update branches)
        //   2x runId for the WHERE bulk_run_id clauses
        //   2x waybills array for the IN clauses
        //
        // SQL has placeholders in this order:
        //   1. JSON_ARRAY for new INSERT status_logs: NOW(), ?bulk_run_id
        //   2. inner priority WHERE bulk_run_id = ?, waybill_number IN (?...)
        //   3. outer winners WHERE bulk_run_id = ?, waybill_number IN (?...)
        //   4. ON DUPLICATE KEY status_logs JSON_OBJECT: ?bulk_run_id (for "from old" update branch)
        $bindings = array_merge(
            [$runId],            // 1. JSON_OBJECT bulk_run_id sa SELECT (new inserts)
            [$runId],            // 2. inner priorities WHERE bulk_run_id
            $waybills,           // 2. inner priorities IN (waybills)
            [$runId],            // 3. outer winners WHERE bulk_run_id
            $waybills,           // 3. outer winners IN (waybills)
            [$runId]             // 4. status_logs ON DUPLICATE KEY (update branch)
        );

        $sql = "
            INSERT INTO from_jnts_2 (
                waybill_number, status, signingtime, sender, cod, item_name,
                submission_time, receiver, receiver_cellphone, remarks,
                province, city, barangay, total_shipping_cost, rts_reason,
                status_logs, created_at, updated_at
            )
            SELECT
                s.waybill_number, s.status, s.signingtime, s.sender, s.cod, s.item_name,
                s.submission_time, s.receiver, s.receiver_cellphone, s.remarks,
                s.province, s.city, s.barangay, s.total_shipping_cost, s.rts_reason,
                JSON_ARRAY(JSON_OBJECT(
                    'batch_at', NOW(),
                    'bulk_run_id', ?,
                    'from', NULL,
                    'to', s.status
                )),
                NOW(), NOW()
            FROM from_jnts_2_staging s
            INNER JOIN (
                SELECT inner_s.waybill_number, MAX(inner_s.id) AS winner_id
                FROM from_jnts_2_staging inner_s
                INNER JOIN (
                    SELECT waybill_number,
                        MIN(CASE LOWER(TRIM(COALESCE(status, '')))
                            WHEN 'returned' THEN 1
                            WHEN 'delivered' THEN 2
                            ELSE 3
                        END) AS best_priority
                    FROM from_jnts_2_staging
                    WHERE bulk_run_id = ?
                      AND waybill_number IN ({$placeholders})
                    GROUP BY waybill_number
                ) priorities
                  ON inner_s.waybill_number = priorities.waybill_number
                  AND CASE LOWER(TRIM(COALESCE(inner_s.status, '')))
                        WHEN 'returned' THEN 1
                        WHEN 'delivered' THEN 2
                        ELSE 3
                      END = priorities.best_priority
                WHERE inner_s.bulk_run_id = ?
                  AND inner_s.waybill_number IN ({$placeholders})
                GROUP BY inner_s.waybill_number
            ) winners ON s.id = winners.winner_id
            ON DUPLICATE KEY UPDATE
                status = CASE
                    WHEN LOWER(from_jnts_2.status) IN ('delivered', 'returned')
                    THEN from_jnts_2.status
                    ELSE VALUES(status)
                END,
                signingtime = CASE
                    WHEN LOWER(from_jnts_2.status) IN ('delivered', 'returned')
                    THEN from_jnts_2.signingtime
                    ELSE VALUES(signingtime)
                END,
                rts_reason = CASE
                    WHEN LOWER(from_jnts_2.status) IN ('delivered', 'returned')
                    THEN from_jnts_2.rts_reason
                    ELSE COALESCE(VALUES(rts_reason), from_jnts_2.rts_reason)
                END,
                status_logs = CASE
                    WHEN LOWER(from_jnts_2.status) IN ('delivered', 'returned')
                    THEN from_jnts_2.status_logs
                    WHEN from_jnts_2.status = VALUES(status)
                        AND VALUES(signingtime) IS NULL
                    THEN from_jnts_2.status_logs
                    ELSE JSON_ARRAY_APPEND(
                        COALESCE(from_jnts_2.status_logs, JSON_ARRAY()),
                        '$',
                        JSON_OBJECT(
                            'batch_at', NOW(),
                            'bulk_run_id', ?,
                            'from', from_jnts_2.status,
                            'to', VALUES(status)
                        )
                    )
                END,
                updated_at = NOW()
        ";

        DB::statement($sql, $bindings);

        // DELETE these waybills from staging
        DB::table('from_jnts_2_staging')
            ->where('bulk_run_id', $this->bulkRunId)
            ->whereIn('waybill_number', $waybills)
            ->delete();

        // Compute batch duration (ms)
        $batchDurationMs = (int) round((microtime(true) - $batchStart) * 1000);

        // Update progress + batch tracking
        DB::table('bulk_upload_runs')
            ->where('id', $this->bulkRunId)
            ->update([
                'consolidate_processed'  => DB::raw('consolidate_processed + ' . $count),
                'total_inserted'         => DB::raw('total_inserted + ' . $newCount),
                'total_updated'          => DB::raw('total_updated + ' . $existingCount),
                'last_batch_at'          => Carbon::now('Asia/Manila'),
                'last_batch_duration_ms' => $batchDurationMs,
                'message'                => sprintf(
                    'Consolidating... %s waybills processed (last batch: %ss)',
                    number_format(($run->consolidate_processed ?? 0) + $count),
                    number_format($batchDurationMs / 1000, 1)
                ),
                'updated_at'             => Carbon::now('Asia/Manila'),
            ]);
    }

    private function finalizeAsDone(BulkUploadRun $run): void
    {
        $files = UploadLogV2::where('bulk_run_id', $this->bulkRunId)->get();
        $run->refresh();
        $run->status         = 'done';
        $run->total_errors   = (int) $files->sum('error_rows');
        $run->total_processed = (int) $files->sum('processed_rows');
        $run->files_done     = $files->where('status', 'done')->count();
        $run->files_failed   = $files->where('status', 'failed')->count();
        $run->files_skipped  = $files->whereIn('status', ['skipped', 'cancelled', 'precheck_duplicate'])->count();
        $run->finished_at    = Carbon::now('Asia/Manila');
        $run->message        = sprintf(
            'Consolidated %s unique waybills: %s inserted, %s updated',
            number_format($run->consolidate_total ?? $run->consolidate_processed ?? 0),
            number_format($run->total_inserted ?? 0),
            number_format($run->total_updated ?? 0)
        );
        $run->save();

        Log::info("Consolidate: run {$this->bulkRunId} DONE", [
            'unique'   => $run->consolidate_total,
            'inserted' => $run->total_inserted,
            'updated'  => $run->total_updated,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        try {
            $run = BulkUploadRun::find($this->bulkRunId);
            if ($run && $run->status === 'consolidating') {
                $run->status      = 'failed';
                $run->finished_at = Carbon::now('Asia/Manila');
                $run->message     = 'Consolidate job failed: ' . mb_substr($exception->getMessage(), 0, 400);
                $run->save();
            }
        } catch (\Throwable $e) {
            Log::error('Consolidate failed() handler error: ' . $e->getMessage());
        }
    }
}
