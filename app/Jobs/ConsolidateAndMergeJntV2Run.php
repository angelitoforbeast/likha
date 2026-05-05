<?php

namespace App\Jobs;

use App\Models\BulkUploadRun;
use App\Models\FromJnt2;
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
 * Stage 3 of the batch upload pipeline — per-batch incremental consolidate.
 *
 * Approach (after the chunk()-with-OFFSET disaster):
 *   1. Get next batch of N unique waybills from staging (cursor-based)
 *   2. Pick winner per waybill in PHP (priority + MAX id)
 *   3. INSERT/UPDATE/SKIP sa from_jnts_2 (with skip rule)
 *   4. DELETE these waybills from staging (na-process na, di na need)
 *   5. Update consolidate_processed counter
 *   6. Check pause/cancel flags — if set, exit gracefully (resumable)
 *   7. Loop until staging empty for this run
 *
 * Why this approach (vs the broken chunk()):
 *   - Walang complex 3-level nested GROUP BY na re-runs sa bawat chunk
 *   - Staging shrinks visibly — natural progress signal
 *   - Pausable / resumable / cancelable
 *   - Simple winner-picking sa PHP, walang fancy SQL
 *
 * Coordination: only 1 consolidate worker — no parallel races.
 * Idempotent: pwede mag-retry from any point. Yung skip rule sa from_jnts_2
 * handles na yung mga na-merge na waybills.
 */
class ConsolidateAndMergeJntV2Run implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 7200; // 2 hours per slice — pero job mismo pwede pausable
    public $tries   = 3;

    private int $bulkRunId;

    /**
     * Per-batch size — how many unique waybills processed per loop iteration.
     * Smaller = mas frequent progress updates pero more overhead per batch.
     * Bigger = mas konting trips pero rougher pause granularity.
     * 1000 ay sweet spot — ~5-10 sec per batch.
     */
    const BATCH_WAYBILLS = 1000;

    /**
     * Update progress UI every N batches (avoid hot updates sa bulk_upload_runs).
     * 1 = update every batch (most responsive)
     * 5 = update every 5 batches (less DB writes)
     */
    const PROGRESS_UPDATE_EVERY = 1;

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

        // Skip if already in terminal state
        if (in_array($run->status, ['done', 'partial', 'cancelled', 'failed'], true)) {
            Log::info("Consolidate: run {$this->bulkRunId} already terminal ({$run->status})");
            return;
        }

        // Skip if user paused
        if ($run->paused_at !== null) {
            Log::info("Consolidate: run {$this->bulkRunId} is paused — skipping");
            return;
        }

        // Atomic transition para isang job lang ang nag-cclaim ng "consolidating" status
        // Pero — kung naka-consolidating na (resumed work), OK lang i-continue
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

        // Backfill consolidate_started_at if NULL (e.g., run #91 na nasimulan na bago to dineploy)
        if ($run->consolidate_started_at === null) {
            $run->consolidate_started_at = Carbon::now('Asia/Manila');
            $run->save();
        }

        try {
            // Compute total kung wala pa (one-time, mahal-mahal pero OK kasi
            // useful para sa progress bar). Skip if already computed.
            if ($run->consolidate_total === null) {
                $total = $this->computeTotalUniqueWaybills();
                $run->consolidate_total = $total;
                $run->message = sprintf('Consolidating %s unique waybills...', number_format($total));
                $run->save();
                Log::info("Consolidate: run {$this->bulkRunId} total unique waybills = {$total}");
            }

            $this->processInBatches($run);

            // Final state — kung nakatapos lahat (staging empty for this run)
            $remaining = DB::table('from_jnts_2_staging')
                ->where('bulk_run_id', $this->bulkRunId)
                ->count();

            if ($remaining === 0) {
                $this->finalizeAsDone($run);
            } else {
                // May naiwan — pause or cancel ang nangyari
                $run->refresh();
                if ($run->cancel_requested_at !== null) {
                    $run->status = 'cancelled';
                    $run->finished_at = Carbon::now('Asia/Manila');
                    $run->message = 'Cancelled by user — ' . number_format($remaining) . ' staging rows remaining';
                    $run->save();
                    Log::info("Consolidate: run {$this->bulkRunId} cancelled with {$remaining} rows left");
                } elseif ($run->paused_at !== null) {
                    $run->message = sprintf(
                        'Paused at %s / %s waybills',
                        number_format($run->consolidate_processed),
                        number_format($run->consolidate_total ?? 0)
                    );
                    $run->save();
                    Log::info("Consolidate: run {$this->bulkRunId} paused");
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

    /**
     * One-time count para sa progress bar denominator.
     * Mahal sa malaking staging (1-2 min sa 14M rows) pero need lang isang beses.
     */
    private function computeTotalUniqueWaybills(): int
    {
        return (int) DB::table('from_jnts_2_staging')
            ->where('bulk_run_id', $this->bulkRunId)
            ->whereNotNull('waybill_number')
            ->where('waybill_number', '!=', '')
            ->distinct('waybill_number')
            ->count('waybill_number');
    }

    /**
     * Main loop — keep grabbing batches of waybills, process them,
     * delete from staging. Exit when:
     *   - Staging empty for this run (success)
     *   - User paused (return gracefully — resumable)
     *   - User requested cancel (return gracefully — finalize as cancelled)
     *   - Job timeout reached (Laravel will retry)
     */
    private function processInBatches(BulkUploadRun $run): void
    {
        $batchCount = 0;

        while (true) {
            // Check pause/cancel flags fresh every batch
            $run->refresh();
            if ($run->paused_at !== null) {
                Log::info("Consolidate: run {$this->bulkRunId} pause requested — exiting at batch {$batchCount}");
                return;
            }
            if ($run->cancel_requested_at !== null) {
                Log::info("Consolidate: run {$this->bulkRunId} cancel requested — exiting at batch {$batchCount}");
                return;
            }

            // Get next batch of distinct waybills
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
                // Tapos na — staging empty for this run
                return;
            }

            $this->processBatch($run, $waybills);

            $batchCount++;
            if ($batchCount % self::PROGRESS_UPDATE_EVERY === 0) {
                // Already updated counter sa loob ng processBatch
                // pero pwede ko rin ilipat dito kung mas mabilis yung processing
            }
        }
    }

    /**
     * Process a batch of N unique waybills:
     *   1. SELECT all rows from staging for these waybills (multiple per wb)
     *   2. Pick winner per waybill in PHP (priority + MAX id)
     *   3. SELECT existing rows from from_jnts_2 for these waybills
     *   4. Categorize: insert / update / skip (terminal status)
     *   5. INSERT new + UPDATE existing in from_jnts_2
     *   6. DELETE these waybills from staging
     *   7. Append status_logs (outside transaction)
     *   8. Increment progress counter
     */
    private function processBatch(BulkUploadRun $run, array $waybills): void
    {
        $count = count($waybills);

        // Step 1 — get all staging rows for these waybills
        $stagingRows = DB::table('from_jnts_2_staging')
            ->where('bulk_run_id', $this->bulkRunId)
            ->whereIn('waybill_number', $waybills)
            ->orderBy('id')
            ->get();

        if ($stagingRows->isEmpty()) {
            // Should not happen pero safety lang
            return;
        }

        // Step 2 — pick winner per waybill (priority CASE + MAX id)
        $winners = [];
        foreach ($stagingRows->groupBy('waybill_number') as $wb => $rows) {
            $winners[$wb] = $this->pickWinner($rows);
        }

        // Step 3 — get existing from_jnts_2 rows for these waybills
        $existing = FromJnt2::query()
            ->select(['waybill_number', 'status', 'status_logs'])
            ->whereIn('waybill_number', array_keys($winners))
            ->get()
            ->keyBy('waybill_number');

        // Step 4 — categorize
        $toInsert    = [];
        $toUpdate    = [];
        $statusInfo  = [];
        $skippedHere = 0;
        $now         = Carbon::now('Asia/Manila')->format('Y-m-d H:i:s');

        foreach ($winners as $wb => $r) {
            if (isset($existing[$wb])) {
                $oldStatusRaw = (string) ($existing[$wb]->status ?: '');
                $cur = strtolower($oldStatusRaw);

                // Skip rule — terminal status di na ina-update
                if (in_array($cur, ['delivered', 'returned'], true)) {
                    $skippedHere++;
                    continue;
                }

                $newStatusRaw = (string) ($r->status ?? '');
                if ($oldStatusRaw === $newStatusRaw && $r->signingtime === null) {
                    $skippedHere++;
                    continue;
                }

                $toUpdate[$wb] = [
                    'status'      => $newStatusRaw,
                    'signingtime' => $r->signingtime,
                    'rts_reason'  => trim((string) ($r->rts_reason ?? '')),
                    'updated_at'  => $now,
                ];
                $statusInfo[$wb] = [
                    'from' => $oldStatusRaw,
                    'to'   => $newStatusRaw,
                ];
            } else {
                $toInsert[] = [
                    'waybill_number'     => $r->waybill_number,
                    'sender'             => $r->sender,
                    'cod'                => $r->cod,
                    'status'             => $r->status,
                    'item_name'          => $r->item_name,
                    'submission_time'    => $r->submission_time,
                    'receiver'           => $r->receiver,
                    'receiver_cellphone' => $r->receiver_cellphone,
                    'signingtime'        => $r->signingtime,
                    'remarks'            => $r->remarks,
                    'province'           => $r->province,
                    'city'               => $r->city,
                    'barangay'           => $r->barangay,
                    'total_shipping_cost'=> $r->total_shipping_cost,
                    'rts_reason'         => $r->rts_reason,
                    'status_logs'        => json_encode([[
                        'batch_at'    => $now,
                        'bulk_run_id' => $this->bulkRunId,
                        'from'        => null,
                        'to'          => (string) $r->status,
                    ]]),
                    'last_uploaded_by_user_id' => null,
                    'last_upload_log_id' => null,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ];
            }
        }

        // Step 5 — bulk INSERT + bulk UPDATE inside transaction
        DB::transaction(function () use ($toInsert, $toUpdate, $waybills) {
            if (!empty($toInsert)) {
                FromJnt2::insert($toInsert);
            }

            if (!empty($toUpdate)) {
                $this->bulkUpdateFromJnts2($toUpdate);
            }

            // Step 6 — DELETE processed waybills from staging
            // After successful merge — staging shrinks visibly
            DB::table('from_jnts_2_staging')
                ->where('bulk_run_id', $this->bulkRunId)
                ->whereIn('waybill_number', $waybills)
                ->delete();
        });

        // Step 7 — append status_logs (outside transaction, same pattern as V1)
        if (!empty($statusInfo)) {
            $this->appendStatusLogs($statusInfo);
        }

        // Step 8 — update progress
        $insertedHere = count($toInsert);
        $updatedHere  = count($toUpdate);

        DB::table('bulk_upload_runs')
            ->where('id', $this->bulkRunId)
            ->update([
                'consolidate_processed' => DB::raw('consolidate_processed + ' . $count),
                'total_inserted'        => DB::raw('total_inserted + ' . $insertedHere),
                'total_updated'         => DB::raw('total_updated + ' . $updatedHere),
                'total_skipped'         => DB::raw('total_skipped + ' . $skippedHere),
                'message'               => sprintf(
                    'Consolidating... %s waybills processed',
                    number_format(($run->consolidate_processed ?? 0) + $count)
                ),
                'updated_at'            => Carbon::now('Asia/Manila'),
            ]);
    }

    /**
     * Pick winner from a collection of staging rows for a single waybill.
     * Priority: returned > delivered > others.
     * Tiebreaker: MAX(id) — last INSERT wins.
     */
    private function pickWinner($rows)
    {
        $best = null;
        $bestPriority = PHP_INT_MAX;
        $bestId = -1;

        foreach ($rows as $r) {
            $statusLower = strtolower(trim((string) ($r->status ?? '')));
            $priority = match ($statusLower) {
                'returned'  => 1,
                'delivered' => 2,
                default     => 3,
            };

            if ($priority < $bestPriority || ($priority === $bestPriority && (int) $r->id > $bestId)) {
                $best = $r;
                $bestPriority = $priority;
                $bestId = (int) $r->id;
            }
        }

        return $best;
    }

    /**
     * Bulk UPDATE sa from_jnts_2 using CASE expression — single SQL per chunk
     * of 500 waybills (avoids gigantic single SQL strings).
     */
    private function bulkUpdateFromJnts2(array $toUpdate): void
    {
        foreach (array_chunk(array_keys($toUpdate), 500) as $chunkKeys) {
            $statusCase = "CASE waybill_number\n";
            $timeCase   = "CASE waybill_number\n";
            $rtsCase    = "CASE waybill_number\n";
            $hasRts     = false;

            foreach ($chunkKeys as $wb) {
                $u = $toUpdate[$wb];
                $s = str_replace("'", "''", (string) $u['status']);
                $t = $u['signingtime'];
                $tSql  = $t ? ("'" . str_replace("'", "''", $t) . "'") : "NULL";
                $wbSql = "'" . str_replace("'", "''", $wb) . "'";

                $statusCase .= "WHEN {$wbSql} THEN '{$s}'\n";
                $timeCase   .= "WHEN {$wbSql} THEN {$tSql}\n";

                if (!empty($u['rts_reason'])) {
                    $hasRts = true;
                    $rr = str_replace("'", "''", (string) $u['rts_reason']);
                    $rtsCase .= "WHEN {$wbSql} THEN '{$rr}'\n";
                }
            }

            $statusCase .= "ELSE status END";
            $timeCase   .= "ELSE signingtime END";
            $rtsCase    .= "ELSE rts_reason END";

            $inList = implode(',', array_map(function ($wb) {
                return "'" . str_replace("'", "''", $wb) . "'";
            }, $chunkKeys));

            $setParts = [
                "status = {$statusCase}",
                "signingtime = {$timeCase}",
                "updated_at = NOW()",
            ];
            if ($hasRts) {
                $setParts[] = "rts_reason = {$rtsCase}";
            }

            $sql = "UPDATE " . (new FromJnt2)->getTable() . "
                    SET " . implode(",\n                        ", $setParts) . "
                    WHERE waybill_number IN ({$inList})
                      AND LOWER(status) NOT IN ('delivered','returned')";
            DB::statement($sql);
        }
    }

    /**
     * Append entries sa from_jnts_2.status_logs JSON column.
     * Done outside the main transaction para hindi mag-block ng main writes.
     */
    private function appendStatusLogs(array $statusInfo): void
    {
        $rows = FromJnt2::whereIn('waybill_number', array_keys($statusInfo))
            ->select(['id', 'waybill_number', 'status_logs'])
            ->get();

        $batchAt = Carbon::now('Asia/Manila');
        foreach ($rows as $row) {
            $wb = $row->waybill_number;
            if (!isset($statusInfo[$wb])) continue;

            $oldStatus = $statusInfo[$wb]['from'];
            $newStatus = $statusInfo[$wb]['to'];

            $logs = $row->status_logs ?: [];
            if (!is_array($logs)) {
                $decoded = json_decode($logs, true);
                $logs = is_array($decoded) ? $decoded : [];
            }

            if ($oldStatus !== $newStatus) {
                $logs[] = [
                    'batch_at'    => $batchAt->format('Y-m-d H:i:s'),
                    'bulk_run_id' => $this->bulkRunId,
                    'from'        => $oldStatus,
                    'to'          => $newStatus,
                ];
                $row->status_logs = $logs;
                try {
                    $row->save();
                } catch (\Throwable $e) {
                    Log::warning('JntV2 consolidate: status_logs save failed for waybill ' . $wb . ': ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * Mark the run as done after staging is fully drained.
     * Updates totals from per-file aggregates + the consolidate-level counters.
     */
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
            'Consolidated %s unique waybills: %s inserted, %s updated, %s skipped',
            number_format($run->consolidate_total ?? $run->consolidate_processed ?? 0),
            number_format($run->total_inserted ?? 0),
            number_format($run->total_updated ?? 0),
            number_format($run->total_skipped ?? 0)
        );
        $run->save();

        Log::info("Consolidate: run {$this->bulkRunId} DONE", [
            'unique'   => $run->consolidate_total,
            'inserted' => $run->total_inserted,
            'updated'  => $run->total_updated,
            'skipped'  => $run->total_skipped,
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
