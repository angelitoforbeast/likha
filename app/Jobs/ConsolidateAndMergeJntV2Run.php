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
 * Stage 3 of the batch upload pipeline.
 *
 * Triggered after all per-file ProcessJntUploadV2 jobs are done.
 * Consolidates the staging rows (one per waybill, latest status), then
 * bulk-merges to from_jnts_2 with skip rule applied.
 *
 * Coordination invariant: only one instance of this job runs per bulk_run_id.
 * Atomic compare-and-set sa bulk_upload_runs.status ensures this.
 */
class ConsolidateAndMergeJntV2Run implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // 1 hour — bulk merge can be slow on huge batches
    public $tries   = 3;

    private int $bulkRunId;

    const MERGE_CHUNK = 2000; // waybills per bulk write to from_jnts_2

    public function __construct(int $bulkRunId)
    {
        $this->bulkRunId = $bulkRunId;
        // Dedicated consolidate queue (1 worker only) — atomic merge sa
        // from_jnts_2. Hindi sila magkokontensya sa parse workers (different queue).
        // Across batches: 1 worker = sequential consolidate (safe vs deadlocks).
        $this->onQueue('jnt_v2_consolidate');
    }

    public function handle(): void
    {
        $run = BulkUploadRun::find($this->bulkRunId);
        if (!$run) {
            Log::warning("ConsolidateAndMerge: run {$this->bulkRunId} not found");
            return;
        }

        // Skip if already consolidated/done — idempotent
        if (in_array($run->status, ['done', 'partial', 'cancelled', 'failed'], true)) {
            Log::info("ConsolidateAndMerge: run {$this->bulkRunId} already in terminal state ({$run->status})");
            return;
        }

        // Atomic transition: processing → consolidating
        // Only one job can win this transition (concurrency guard)
        $transitioned = BulkUploadRun::where('id', $this->bulkRunId)
            ->whereIn('status', ['processing', 'queued'])
            ->update([
                'status'  => 'consolidating',
                'message' => 'Consolidating waybills across files...',
            ]);

        if (!$transitioned) {
            // Another instance won the race, or run is already consolidating
            Log::info("ConsolidateAndMerge: run {$this->bulkRunId} already consolidating or terminal");
            return;
        }

        try {
            // Cleanup any leftover staging rows for this run (defensive — sa case
            // ng previous failed consolidate retries)
            // Actually, no — we want to KEEP existing rows. Cleanup is sa end lang.

            $stats = $this->consolidateAndMerge();

            // Update run totals from per-file aggregates
            $files = UploadLogV2::where('bulk_run_id', $this->bulkRunId)->get();
            $run->refresh();
            $run->status         = 'done';
            $run->total_inserted = (int) $stats['inserted'];
            $run->total_updated  = (int) $stats['updated'];
            $run->total_skipped  = (int) $stats['skipped'];
            $run->total_errors   = (int) $files->sum('error_rows');
            $run->total_processed = (int) $files->sum('processed_rows');
            $run->files_done     = $files->where('status', 'done')->count();
            $run->files_failed   = $files->where('status', 'failed')->count();
            $run->files_skipped  = $files->whereIn('status', ['skipped', 'cancelled', 'precheck_duplicate'])->count();
            $run->finished_at    = Carbon::now('Asia/Manila');
            $run->message        = sprintf(
                'Consolidated %d unique waybills: %d inserted, %d updated, %d skipped',
                $stats['unique_waybills'],
                $stats['inserted'],
                $stats['updated'],
                $stats['skipped']
            );
            $run->save();

            // Cleanup staging rows for this run
            DB::table('from_jnts_2_staging')
                ->where('bulk_run_id', $this->bulkRunId)
                ->delete();

            Log::info("ConsolidateAndMerge: run {$this->bulkRunId} done", $stats);
        } catch (\Throwable $e) {
            $run->refresh();
            $run->status      = 'failed';
            $run->finished_at = Carbon::now('Asia/Manila');
            $run->message     = 'Consolidate failed: ' . $e->getMessage();
            $run->save();

            Log::error("ConsolidateAndMerge: run {$this->bulkRunId} FAILED — " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Core consolidation logic.
     *
     * Step A: Pick latest row per waybill from staging (within bulk_run_id)
     * Step B: For each consolidated waybill, decide insert/update/skip
     * Step C: Bulk write to from_jnts_2 + maintain status_logs
     */
    private function consolidateAndMerge(): array
    {
        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;
        $uniqueCount = 0;

        // Step A: get unique waybills + their winning row from staging.
        //
        // Sorting rules (locked with user):
        //   Priority 1: status = "returned"  (TOP — even kung may "delivered" entry)
        //   Priority 2: status = "delivered"
        //   Priority 3: any other status (in transit, delivering, pickup, etc.)
        //
        // Tiebreaker within same priority: highest id wins (latest insertion to staging)
        //
        // Two-step subquery approach (compatible across MySQL/MariaDB):
        //   1. Per waybill, find the minimum priority (best status)
        //   2. Within that priority, pick the row with MAX(id)
        //
        // Stream this in chunks para hindi mag-OOM kahit milyong rows.

        $runId = (int) $this->bulkRunId;
        $latestQuery = DB::table('from_jnts_2_staging as s')
            ->join(DB::raw("(
                SELECT inner_s.waybill_number, MAX(inner_s.id) as winner_id
                FROM from_jnts_2_staging inner_s
                INNER JOIN (
                    SELECT waybill_number,
                        MIN(CASE LOWER(TRIM(COALESCE(status, '')))
                            WHEN 'returned'  THEN 1
                            WHEN 'delivered' THEN 2
                            ELSE 3
                        END) AS best_priority
                    FROM from_jnts_2_staging
                    WHERE bulk_run_id = {$runId}
                      AND waybill_number IS NOT NULL
                      AND waybill_number != ''
                    GROUP BY waybill_number
                ) priorities ON inner_s.waybill_number = priorities.waybill_number
                    AND CASE LOWER(TRIM(COALESCE(inner_s.status, '')))
                        WHEN 'returned'  THEN 1
                        WHEN 'delivered' THEN 2
                        ELSE 3
                    END = priorities.best_priority
                WHERE inner_s.bulk_run_id = {$runId}
                GROUP BY inner_s.waybill_number
            ) as latest"), 's.id', '=', 'latest.winner_id');

        // Process in chunks of 2000 waybills at a time
        $latestQuery->orderBy('s.id')->chunk(self::MERGE_CHUNK, function ($rows) use (&$inserted, &$updated, &$skipped, &$uniqueCount) {
            $waybills = $rows->pluck('waybill_number')->all();
            $existing = FromJnt2::query()
                ->select(['waybill_number', 'status', 'status_logs'])
                ->whereIn('waybill_number', $waybills)
                ->get()
                ->keyBy('waybill_number');

            $toInsert = [];
            $toUpdate = [];
            $statusInfo = [];
            $now = Carbon::now('Asia/Manila')->format('Y-m-d H:i:s');

            foreach ($rows as $r) {
                $wb = $r->waybill_number;
                $uniqueCount++;

                if (isset($existing[$wb])) {
                    $oldStatusRaw = (string) ($existing[$wb]->status ?: '');
                    $cur = strtolower($oldStatusRaw);

                    // Skip rule: kung delivered/returned na, hindi i-update
                    if (in_array($cur, ['delivered', 'returned'], true)) {
                        $skipped++;
                        continue;
                    }

                    $newStatusRaw = (string) ($r->status ?? '');
                    if ($oldStatusRaw === $newStatusRaw && $r->signingtime === null) {
                        $skipped++;
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
                    // New waybill — insert
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
                            'batch_at' => $now,
                            'bulk_run_id' => $this->bulkRunId,
                            'from'    => null,
                            'to'      => (string) $r->status,
                        ]]),
                        'last_uploaded_by_user_id' => null,
                        'last_upload_log_id' => null,
                        'created_at'         => $now,
                        'updated_at'         => $now,
                    ];
                }
            }

            DB::transaction(function () use ($toInsert, $toUpdate, $statusInfo, &$inserted, &$updated) {
                if (!empty($toInsert)) {
                    FromJnt2::insert($toInsert);
                    $inserted += count($toInsert);
                }

                if (!empty($toUpdate)) {
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
                                SET " . implode(",\n                                    ", $setParts) . "
                                WHERE waybill_number IN ({$inList})
                                  AND LOWER(status) NOT IN ('delivered','returned')";
                        DB::statement($sql);
                        $updated += count($chunkKeys);
                    }
                }
            });

            // Status_logs append (outside transaction — same pattern as V1)
            if (!empty($statusInfo)) {
                $rowsForLogs = FromJnt2::whereIn('waybill_number', array_keys($statusInfo))
                    ->select(['id', 'waybill_number', 'status_logs'])
                    ->get();

                $batchAt = Carbon::now('Asia/Manila');
                foreach ($rowsForLogs as $row) {
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
        });

        return [
            'unique_waybills' => $uniqueCount,
            'inserted'        => $inserted,
            'updated'         => $updated,
            'skipped'         => $skipped,
        ];
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
            Log::error('ConsolidateAndMerge failed() handler error: ' . $e->getMessage());
        }
    }
}
