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
 * Stage 3 — high-throughput 3-phase consolidate.
 *
 * The previous chunk()-with-OFFSET approach re-ran the complex priority
 * subquery PER BATCH. For 14M staging rows × 220 batches, that's ~9 hours
 * of redundant subquery work.
 *
 * NEW APPROACH — Materialize once, iterate fast:
 *
 *   PHASE 1 (one-time, ~3-5 min):
 *     - Run priority subquery ONCE
 *     - Insert chosen winners into from_jnts_2_winners table (~1M rows)
 *
 *   PHASE 2 (chunked, ~15-20 min):
 *     - Cursor through winners table (id > last_id LIMIT 5000)
 *     - Simple UPSERT to from_jnts_2 (no subquery)
 *     - DELETE processed rows from winners + corresponding staging rows
 *     - Update progress + last_batch_at counters
 *     - Pausable / cancelable per batch
 *
 *   PHASE 3 (cleanup):
 *     - Delete any leftover staging rows (defensive)
 *     - TRUNCATE from_jnts_2_winners for this run
 *     - Mark run as 'done'
 *
 * Total speedup vs previous: ~30-40x (14 hours → 25 mins for 1.1M waybills).
 *
 * Dropped per user decision: status_logs append on UPDATE branch.
 * Initial status_logs JSON is still populated on new INSERTs (single entry).
 */
class ConsolidateAndMergeJntV2Run implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 7200; // 2 hours per slice, but typically much shorter
    public $tries   = 3;

    private int $bulkRunId;

    /** Per-batch size for Phase 2 cursor iteration. */
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
                    'message'                => 'Starting consolidation...',
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
            // Resume logic — pick up from current phase
            $phase = $run->consolidate_phase;

            // Phase 1: Materialize winners (kung wala pa)
            if ($phase === null || $phase === 'materializing') {
                $this->phase1Materialize($run);
                $run->refresh();
            }

            // Re-check pause/cancel before Phase 2
            if ($run->paused_at !== null || $run->cancel_requested_at !== null) {
                $this->handlePauseOrCancel($run);
                return;
            }

            // Phase 2: Iterate winners → upsert
            if (in_array($run->consolidate_phase, ['merging', null], true)) {
                $this->phase2Merge($run);
                $run->refresh();
            }

            // Re-check pause/cancel before Phase 3
            if ($run->paused_at !== null || $run->cancel_requested_at !== null) {
                $this->handlePauseOrCancel($run);
                return;
            }

            // Phase 3: Cleanup
            if ($run->consolidate_phase === 'cleanup' || $run->consolidate_phase === 'merging') {
                $this->phase3Cleanup($run);
            }
        } catch (\Throwable $e) {
            $run->refresh();
            $run->status      = 'failed';
            $run->finished_at = Carbon::now('Asia/Manila');
            // Include phase sa message para malaman agad kung saan nag-fail (debugging aid).
            $phaseLabel       = $run->consolidate_phase ?? 'unknown';
            $run->message     = "Consolidate failed in phase '{$phaseLabel}': " . mb_substr($e->getMessage(), 0, 350);
            $run->save();

            Log::error("Consolidate: run {$this->bulkRunId} FAILED in phase '{$phaseLabel}' — " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * PHASE 1 — Materialize winners.
     *
     * Single big SQL na nag-pipili ng winner per waybill (priority + MAX id),
     * then INSERTs sa from_jnts_2_winners table. Yung complex priority logic
     * runs ONCE here, hindi paulit-ulit per batch like sa old code.
     *
     * Idempotent: kung naka-run na before (e.g., job retried), tinitignan kung
     * may existing rows. Kung meron, skip — assumes good state. Kung wala,
     * truncate (defensive) + re-materialize.
     */
    private function phase1Materialize(BulkUploadRun $run): void
    {
        $runId = $this->bulkRunId;

        // Mark phase = materializing
        DB::table('bulk_upload_runs')->where('id', $runId)->update([
            'consolidate_phase' => 'materializing',
            'message'           => 'Phase 1/3 — materializing winners (this takes 3-5 min)...',
            'updated_at'        => Carbon::now('Asia/Manila'),
        ]);

        // Check if winners already populated for this run (resume case)
        $existingWinners = (int) DB::table('from_jnts_2_winners')
            ->where('bulk_run_id', $runId)
            ->count();

        if ($existingWinners > 0) {
            Log::info("Consolidate: run {$runId} winners already materialized ({$existingWinners} rows), skipping Phase 1");
        } else {
            $startTs = microtime(true);
            Log::info("Consolidate: run {$runId} starting Phase 1 — materializing winners");

            // Defensive cleanup of partial state for this run
            DB::table('from_jnts_2_winners')->where('bulk_run_id', $runId)->delete();

            // The winner-picking SQL — runs once, materializes ~1M rows.
            // NULLIF on date columns: JNT data sometimes contains '0000-00-00 00:00:00'
            // which is invalid under MySQL strict mode (NO_ZERO_DATE). Staging accepts
            // it because LOAD DATA INFILE is lenient, but from_jnts_2_winners (strict)
            // rejects it. Convert sentinel-zero dates to actual NULL for nullable columns.
            DB::statement("
                INSERT INTO from_jnts_2_winners (
                    bulk_run_id, submission_time, waybill_number, receiver, receiver_cellphone,
                    sender, item_name, cod, remarks, status, signingtime,
                    province, city, barangay, total_shipping_cost, rts_reason
                )
                SELECT
                    s.bulk_run_id,
                    NULLIF(s.submission_time, '0000-00-00 00:00:00') AS submission_time,
                    s.waybill_number, s.receiver, s.receiver_cellphone,
                    s.sender, s.item_name, s.cod, s.remarks, s.status,
                    NULLIF(s.signingtime, '0000-00-00 00:00:00') AS signingtime,
                    s.province, s.city, s.barangay, s.total_shipping_cost, s.rts_reason
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
                          AND waybill_number IS NOT NULL
                          AND waybill_number != ''
                        GROUP BY waybill_number
                    ) priorities
                      ON inner_s.waybill_number = priorities.waybill_number
                      AND CASE LOWER(TRIM(COALESCE(inner_s.status, '')))
                            WHEN 'returned' THEN 1
                            WHEN 'delivered' THEN 2
                            ELSE 3
                          END = priorities.best_priority
                    WHERE inner_s.bulk_run_id = ?
                    GROUP BY inner_s.waybill_number
                ) winners ON s.id = winners.winner_id
                WHERE s.bulk_run_id = ?
            ", [$runId, $runId, $runId]);

            $elapsed = round((microtime(true) - $startTs), 1);
            $winnersCount = (int) DB::table('from_jnts_2_winners')->where('bulk_run_id', $runId)->count();
            Log::info("Consolidate: run {$runId} Phase 1 complete — materialized {$winnersCount} winners in {$elapsed}s");
        }

        // Set total based on winners (more accurate than the earlier estimate)
        $totalUnique = (int) DB::table('from_jnts_2_winners')
            ->where('bulk_run_id', $runId)
            ->count();

        // Adjust consolidate_total to reflect remaining work + already processed
        // total = already_processed + new_winners_to_process
        $existingProcessed = (int) ($run->consolidate_processed ?? 0);

        DB::table('bulk_upload_runs')->where('id', $runId)->update([
            'consolidate_total' => $existingProcessed + $totalUnique,
            'consolidate_phase' => 'merging',
            'message'           => sprintf(
                'Phase 2/3 — merging %s waybills...',
                number_format($totalUnique)
            ),
            'updated_at'        => Carbon::now('Asia/Manila'),
        ]);
    }

    /**
     * PHASE 2 — Iterate winners table, UPSERT to from_jnts_2 in chunks.
     *
     * Cursor-based pagination via id > last_id. No complex subquery —
     * winners table is already pre-computed so each batch is a simple UPSERT.
     *
     * Per batch:
     *   1. SELECT next 5000 rows from from_jnts_2_winners (cursor)
     *   2. INSERT...ON DUPLICATE KEY UPDATE to from_jnts_2 (with skip rule)
     *   3. Collect waybill_numbers we just processed
     *   4. DELETE these rows from from_jnts_2_winners
     *   5. DELETE corresponding rows from from_jnts_2_staging (cleanup)
     *   6. Update progress + last_batch_at
     *   7. Check pause/cancel — exit if requested
     */
    private function phase2Merge(BulkUploadRun $run): void
    {
        $runId = $this->bulkRunId;
        $batchCount = 0;

        while (true) {
            // Fresh state check
            $run->refresh();
            if ($run->paused_at !== null) {
                Log::info("Consolidate: run {$runId} pause requested at batch {$batchCount}");
                return;
            }
            if ($run->cancel_requested_at !== null) {
                Log::info("Consolidate: run {$runId} cancel requested at batch {$batchCount}");
                return;
            }

            // Get next batch of winners (cursor-based — order by id, take next 5000)
            $winners = DB::table('from_jnts_2_winners')
                ->where('bulk_run_id', $runId)
                ->orderBy('id')
                ->limit(self::BATCH_WAYBILLS)
                ->get();

            if ($winners->isEmpty()) {
                // Phase 2 done — move to Phase 3
                DB::table('bulk_upload_runs')->where('id', $runId)->update([
                    'consolidate_phase' => 'cleanup',
                    'updated_at'        => Carbon::now('Asia/Manila'),
                ]);
                Log::info("Consolidate: run {$runId} Phase 2 complete after {$batchCount} batches");
                return;
            }

            $this->processWinnersBatch($run, $winners);
            $batchCount++;
        }
    }

    /**
     * Process a single batch of pre-materialized winners.
     */
    private function processWinnersBatch(BulkUploadRun $run, $winners): void
    {
        $runId = $this->bulkRunId;
        $batchStart = microtime(true);

        $waybills = $winners->pluck('waybill_number')->all();
        $count    = count($waybills);

        if ($count === 0) {
            return;
        }

        // Pre-count existing for accurate inserted/updated tracking
        $existingCount = (int) DB::table('from_jnts_2')
            ->whereIn('waybill_number', $waybills)
            ->count();
        $newCount = $count - $existingCount;

        $now = Carbon::now('Asia/Manila')->format('Y-m-d H:i:s');

        // Build placeholders for the IN clauses
        $placeholders = implode(',', array_fill(0, $count, '?'));

        // Bindings:
        //   1. JSON_OBJECT bulk_run_id (for new INSERT status_logs init)
        //   2. winners.bulk_run_id WHERE
        //   3. winners.id IN (waybills) — actually we use waybill_number IN
        //
        // Wait, mas simple: filter by id range or waybill_number. Let's use
        // waybill_number since we have that list anyway.
        $bindings = array_merge(
            [$runId],            // 1. status_logs init bulk_run_id
            [$runId],            // 2. winners.bulk_run_id WHERE
            $waybills            // 3. winners.waybill_number IN (...)
        );

        // The simplified UPSERT — winners table already has the chosen rows,
        // just bulk INSERT with skip rule on conflict.
        // Status_logs init only on INSERT (not on UPDATE — dropped per user decision).
        $sql = "
            INSERT INTO from_jnts_2 (
                waybill_number, status, signingtime, sender, cod, item_name,
                submission_time, receiver, receiver_cellphone, remarks,
                province, city, barangay, total_shipping_cost, rts_reason,
                status_logs, created_at, updated_at
            )
            SELECT
                w.waybill_number, w.status, w.signingtime, w.sender, w.cod, w.item_name,
                w.submission_time, w.receiver, w.receiver_cellphone, w.remarks,
                w.province, w.city, w.barangay, w.total_shipping_cost, w.rts_reason,
                JSON_ARRAY(JSON_OBJECT(
                    'batch_at', NOW(),
                    'bulk_run_id', ?,
                    'from', NULL,
                    'to', w.status
                )),
                NOW(), NOW()
            FROM from_jnts_2_winners w
            WHERE w.bulk_run_id = ?
              AND w.waybill_number IN ({$placeholders})
            ON DUPLICATE KEY UPDATE
                status = CASE
                    WHEN LOWER(from_jnts_2.status) IN ('delivered', 'returned')
                    THEN from_jnts_2.status
                    ELSE VALUES(status)
                END,
                -- NULLIF defensive: pag may pre-existing row with bad date '0000-00-00 00:00:00'
                -- (galing sa old data bago naka-deploy ang Phase 1 NULLIF), strict mode
                -- mag-r-reject pag dinala ulit yung literal sa UPDATE clause. Convert to NULL.
                signingtime = CASE
                    WHEN LOWER(from_jnts_2.status) IN ('delivered', 'returned')
                    THEN NULLIF(from_jnts_2.signingtime, '0000-00-00 00:00:00')
                    ELSE VALUES(signingtime)
                END,
                -- Sanitize submission_time on every UPDATE — old bad values become NULL,
                -- bagong valid values from VALUES() take precedence kung meron.
                submission_time = COALESCE(VALUES(submission_time), NULLIF(from_jnts_2.submission_time, '0000-00-00 00:00:00')),
                rts_reason = CASE
                    WHEN LOWER(from_jnts_2.status) IN ('delivered', 'returned')
                    THEN from_jnts_2.rts_reason
                    ELSE COALESCE(VALUES(rts_reason), from_jnts_2.rts_reason)
                END,
                updated_at = NOW()
        ";

        DB::statement($sql, $bindings);

        // DELETE these waybills from winners table (processed) — small + fast
        DB::table('from_jnts_2_winners')
            ->where('bulk_run_id', $runId)
            ->whereIn('waybill_number', $waybills)
            ->delete();

        // NOTE: Staging DELETE per-batch removed. Cumulative B-tree fragmentation
        // mula sa per-batch DELETEs causes 30-60x slowdown by mid-run. Better to
        // do single bulk cleanup sa Phase 3 (TRUNCATE if safe, else DELETE WHERE).
        // Trade-off: staging keeps full size during Phase 2 (~5-10 GB temporary).

        // Compute batch duration
        $batchDurationMs = (int) round((microtime(true) - $batchStart) * 1000);

        // Update progress + batch tracking
        $newTotal = ($run->consolidate_processed ?? 0) + $count;
        DB::table('bulk_upload_runs')
            ->where('id', $runId)
            ->update([
                'consolidate_processed'  => $newTotal,
                'total_inserted'         => DB::raw('total_inserted + ' . $newCount),
                'total_updated'          => DB::raw('total_updated + ' . $existingCount),
                'last_batch_at'          => Carbon::now('Asia/Manila'),
                'last_batch_duration_ms' => $batchDurationMs,
                'message'                => sprintf(
                    'Phase 2/3 — %s waybills merged (last batch: %ss)',
                    number_format($newTotal),
                    number_format($batchDurationMs / 1000, 1)
                ),
                'updated_at'             => Carbon::now('Asia/Manila'),
            ]);
    }

    /**
     * PHASE 3 — Final cleanup + mark done.
     *
     * Smart staging cleanup strategy:
     *   - If only THIS run's data sa staging → TRUNCATE TABLE (instant)
     *   - If multiple bulk_run_ids → DELETE WHERE bulk_run_id (safe, slower)
     *
     * TRUNCATE is dramatically faster (sub-second vs 5-15 mins for DELETE
     * of 12M rows) at completely resets fragmentation. But TRUNCATE wipes
     * the entire table, kaya safety check muna kung walang concurrent run.
     */
    private function phase3Cleanup(BulkUploadRun $run): void
    {
        $runId = $this->bulkRunId;

        DB::table('bulk_upload_runs')->where('id', $runId)->update([
            'consolidate_phase' => 'cleanup',
            'message'           => 'Phase 3/3 — cleanup...',
            'updated_at'        => Carbon::now('Asia/Manila'),
        ]);

        // Smart staging cleanup — use TRUNCATE kung safe, else DELETE WHERE
        $stagingCleanupStart = microtime(true);
        $distinctRunsRow = DB::selectOne(
            "SELECT COUNT(DISTINCT bulk_run_id) as cnt FROM from_jnts_2_staging"
        );
        $distinctRuns = (int) ($distinctRunsRow->cnt ?? 0);

        if ($distinctRuns <= 1) {
            // Only this run's data (or empty) — TRUNCATE = instant + resets fragmentation
            DB::statement('TRUNCATE TABLE from_jnts_2_staging');
            $stagingMethod = 'TRUNCATE';
            $stagingDeleted = 0; // TRUNCATE doesn't return affected rows
        } else {
            // Multiple runs in staging — DELETE WHERE para hindi tamaan iba
            $stagingDeleted = DB::table('from_jnts_2_staging')
                ->where('bulk_run_id', $runId)
                ->delete();
            $stagingMethod = 'DELETE';
        }
        $stagingElapsed = round(microtime(true) - $stagingCleanupStart, 1);

        // Same approach sa winners table
        $winnersCleanupStart = microtime(true);
        $distinctWinnersRunsRow = DB::selectOne(
            "SELECT COUNT(DISTINCT bulk_run_id) as cnt FROM from_jnts_2_winners"
        );
        $distinctWinnersRuns = (int) ($distinctWinnersRunsRow->cnt ?? 0);

        if ($distinctWinnersRuns <= 1) {
            DB::statement('TRUNCATE TABLE from_jnts_2_winners');
            $winnersMethod = 'TRUNCATE';
            $winnersDeleted = 0;
        } else {
            $winnersDeleted = DB::table('from_jnts_2_winners')
                ->where('bulk_run_id', $runId)
                ->delete();
            $winnersMethod = 'DELETE';
        }
        $winnersElapsed = round(microtime(true) - $winnersCleanupStart, 1);

        Log::info("Consolidate: run {$runId} Phase 3 cleanup", [
            'staging_method'  => $stagingMethod,
            'staging_deleted' => $stagingDeleted,
            'staging_elapsed' => "{$stagingElapsed}s",
            'winners_method'  => $winnersMethod,
            'winners_deleted' => $winnersDeleted,
            'winners_elapsed' => "{$winnersElapsed}s",
        ]);

        $this->finalizeAsDone($run);
    }

    /**
     * Handle pause or cancel — update run state + exit gracefully.
     */
    private function handlePauseOrCancel(BulkUploadRun $run): void
    {
        $run->refresh();
        if ($run->cancel_requested_at !== null) {
            $run->status = 'cancelled';
            $run->finished_at = Carbon::now('Asia/Manila');
            $run->message = sprintf(
                'Cancelled at %s/%s waybills (phase: %s)',
                number_format($run->consolidate_processed ?? 0),
                number_format($run->consolidate_total ?? 0),
                $run->consolidate_phase ?? 'unknown'
            );
            $run->save();
        } elseif ($run->paused_at !== null) {
            $run->message = sprintf(
                'Paused at %s/%s waybills (phase: %s)',
                number_format($run->consolidate_processed ?? 0),
                number_format($run->consolidate_total ?? 0),
                $run->consolidate_phase ?? 'unknown'
            );
            $run->save();
        }
    }

    private function finalizeAsDone(BulkUploadRun $run): void
    {
        $files = UploadLogV2::where('bulk_run_id', $this->bulkRunId)->get();
        $run->refresh();
        $run->status         = 'done';
        $run->consolidate_phase = null;
        $run->total_errors   = (int) $files->sum('error_rows');
        $run->total_processed = (int) $files->sum('processed_rows');
        $run->files_done     = $files->where('status', 'done')->count();
        $run->files_failed   = $files->where('status', 'failed')->count();
        $run->files_skipped  = $files->whereIn('status', ['skipped', 'cancelled', 'precheck_duplicate'])->count();
        $run->finished_at    = Carbon::now('Asia/Manila');
        $run->message        = sprintf(
            'Consolidated %s waybills: %s inserted, %s updated',
            number_format($run->consolidate_total ?? 0),
            number_format($run->total_inserted ?? 0),
            number_format($run->total_updated ?? 0)
        );
        $run->save();

        Log::info("Consolidate: run {$this->bulkRunId} DONE", [
            'total'    => $run->consolidate_total,
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
