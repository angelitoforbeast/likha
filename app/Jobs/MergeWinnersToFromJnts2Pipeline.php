<?php

namespace App\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pipeline UI Phase 2 — merge WHOLE winners table → from_jnts_2 (no run filter).
 *
 * Batched UPSERT (5000 waybills per batch) with progress tracking sa Cache.
 * Counterpart of MaterializeWinnersV2Pipeline.
 */
class MergeWinnersToFromJnts2Pipeline implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 7200; // 2 hours
    public $tries   = 1;

    const BATCH_SIZE = 5000;

    public function __construct()
    {
        $this->onQueue('jnt_v2_consolidate');
    }

    public function handle(): void
    {
        $startTs       = microtime(true);
        $totalWinners  = (int) DB::table('from_jnts_2_winners')->count();

        if ($totalWinners === 0) {
            Cache::put('jnt_v2_pipeline_state', [
                'phase'      => 'phase2',
                'status'     => 'done',
                'processed'  => 0,
                'total'      => 0,
                'message'    => 'Winners is empty — walang i-pi-process.',
                'started_at' => Carbon::now('Asia/Manila')->toDateTimeString(),
                'finished_at'=> Carbon::now('Asia/Manila')->toDateTimeString(),
            ], 86400);
            return;
        }

        Cache::put('jnt_v2_pipeline_state', [
            'phase'      => 'phase2',
            'status'     => 'running',
            'processed'  => 0,
            'total'      => $totalWinners,
            'pct'        => 0,
            'message'    => "Phase 2 — merging {$totalWinners} winners → from_jnts_2 in batches of " . self::BATCH_SIZE,
            'started_at' => Carbon::now('Asia/Manila')->toDateTimeString(),
        ], 7200);

        try {
            Log::info("Pipeline Phase 2 START — winners: {$totalWinners}");

            $processed = 0;
            $inserted  = 0;
            $updated   = 0;
            $batchNum  = 0;

            while (true) {
                $batch = DB::table('from_jnts_2_winners')
                    ->orderBy('id')
                    ->limit(self::BATCH_SIZE)
                    ->get(['id', 'waybill_number']);

                if ($batch->isEmpty()) break;

                $batchNum++;
                $waybills = $batch->pluck('waybill_number')->filter()->all();

                if (empty($waybills)) {
                    // Skip batch with no valid waybills, but delete the rows to make progress
                    DB::table('from_jnts_2_winners')
                        ->whereIn('id', $batch->pluck('id')->all())
                        ->delete();
                    $processed += $batch->count();
                    continue;
                }

                // UPSERT this batch's waybills to from_jnts_2.
                // Same skip-rule logic as ConsolidateAndMergeJntV2Run Phase 2:
                // - if existing row is delivered/returned, KEEP its status/signingtime
                // - else, OVERWRITE with new values
                // - rts_reason: keep existing if delivered/returned, else COALESCE
                // NULLIF defensive on existing row's date to handle pre-existing bad data.
                $placeholders = implode(',', array_fill(0, count($waybills), '?'));
                $bindings     = array_merge($waybills);

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
                            'bulk_run_id', w.bulk_run_id,
                            'from', NULL,
                            'to', w.status
                        )),
                        NOW(), NOW()
                    FROM from_jnts_2_winners w
                    WHERE w.waybill_number IN ({$placeholders})
                    ON DUPLICATE KEY UPDATE
                        status = CASE
                            WHEN LOWER(from_jnts_2.status) IN ('delivered', 'returned')
                            THEN from_jnts_2.status
                            ELSE VALUES(status)
                        END,
                        signingtime = CASE
                            WHEN LOWER(from_jnts_2.status) IN ('delivered', 'returned')
                            THEN NULLIF(from_jnts_2.signingtime, '0000-00-00 00:00:00')
                            ELSE VALUES(signingtime)
                        END,
                        submission_time = COALESCE(VALUES(submission_time), NULLIF(from_jnts_2.submission_time, '0000-00-00 00:00:00')),
                        rts_reason = CASE
                            WHEN LOWER(from_jnts_2.status) IN ('delivered', 'returned')
                            THEN from_jnts_2.rts_reason
                            ELSE COALESCE(VALUES(rts_reason), from_jnts_2.rts_reason)
                        END,
                        updated_at = NOW()
                ";

                DB::statement($sql, $bindings);

                // Delete processed winners
                DB::table('from_jnts_2_winners')
                    ->whereIn('waybill_number', $waybills)
                    ->delete();

                $processed += $batch->count();
                $pct        = $totalWinners > 0 ? round($processed / $totalWinners * 100, 1) : 0;

                // Update progress every batch
                Cache::put('jnt_v2_pipeline_state', [
                    'phase'      => 'phase2',
                    'status'     => 'running',
                    'processed'  => $processed,
                    'total'      => $totalWinners,
                    'pct'        => $pct,
                    'message'    => "Batch {$batchNum} — {$processed}/{$totalWinners} ({$pct}%)",
                    'started_at' => Cache::get('jnt_v2_pipeline_state.started_at') ?? Carbon::now('Asia/Manila')->toDateTimeString(),
                ], 7200);

                if ($batchNum % 10 === 0) {
                    Log::info("Pipeline Phase 2 progress: batch {$batchNum}, {$processed}/{$totalWinners}");
                }
            }

            $elapsed = round(microtime(true) - $startTs, 1);
            Log::info("Pipeline Phase 2 DONE — processed {$processed} waybills in {$elapsed}s");

            Cache::put('jnt_v2_pipeline_state', [
                'phase'      => 'phase2',
                'status'     => 'done',
                'processed'  => $processed,
                'total'      => $totalWinners,
                'pct'        => 100,
                'message'    => "Merged {$processed} waybills to from_jnts_2 in {$elapsed}s",
                'finished_at'=> Carbon::now('Asia/Manila')->toDateTimeString(),
                'elapsed_s'  => $elapsed,
            ], 86400);
        } catch (\Throwable $e) {
            Log::error('Pipeline Phase 2 FAILED: ' . $e->getMessage());
            Cache::put('jnt_v2_pipeline_state', [
                'phase'      => 'phase2',
                'status'     => 'failed',
                'message'    => 'Phase 2 failed: ' . mb_substr($e->getMessage(), 0, 400),
                'finished_at'=> Carbon::now('Asia/Manila')->toDateTimeString(),
            ], 86400);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Cache::put('jnt_v2_pipeline_state', [
            'phase'      => 'phase2',
            'status'     => 'failed',
            'message'    => 'Phase 2 job failed: ' . mb_substr($exception->getMessage(), 0, 400),
            'finished_at'=> Carbon::now('Asia/Manila')->toDateTimeString(),
        ], 86400);
    }
}
