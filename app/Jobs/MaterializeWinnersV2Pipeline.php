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
 * Pipeline UI Phase 1 — materialize winners from WHOLE staging table (no run filter).
 *
 * Iba ito sa `ConsolidateAndMergeJntV2Run` na per-bulk-run-id. This job processes
 * ANYTHING that's in from_jnts_2_staging right now — useful for manual pipeline
 * UI where user controls each phase explicitly without caring about run boundary.
 *
 * Status tracked sa Laravel Cache (key: jnt_v2_pipeline_state) for live UI polling.
 */
class MaterializeWinnersV2Pipeline implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 7200; // 2 hours
    public $tries   = 1;

    public function __construct()
    {
        // Reuse existing consolidate queue — single worker, no extra supervisor config needed.
        $this->onQueue('jnt_v2_consolidate');
    }

    public function handle(): void
    {
        $startTs = microtime(true);

        $stagingCount = (int) DB::table('from_jnts_2_staging')->count();
        if ($stagingCount === 0) {
            Cache::put('jnt_v2_pipeline_state', [
                'phase'     => 'phase1',
                'status'    => 'done',
                'processed' => 0,
                'total'     => 0,
                'message'   => 'Staging is empty — walang i-pi-process.',
                'started_at'=> Carbon::now('Asia/Manila')->toDateTimeString(),
                'finished_at'=> Carbon::now('Asia/Manila')->toDateTimeString(),
            ], 86400);
            return;
        }

        Cache::put('jnt_v2_pipeline_state', [
            'phase'     => 'phase1',
            'status'    => 'running',
            'processed' => 0,
            'total'     => $stagingCount,
            'message'   => "Phase 1 — materializing winners from {$stagingCount} staging rows...",
            'started_at'=> Carbon::now('Asia/Manila')->toDateTimeString(),
        ], 7200);

        try {
            Log::info("Pipeline Phase 1 START — staging rows: {$stagingCount}");

            // Defensive cleanup — clear winners table before re-materializing
            DB::table('from_jnts_2_winners')->truncate();

            // Winner-pick SQL — same priority logic as ConsolidateAndMergeJntV2Run
            // Phase 1, but WITHOUT the bulk_run_id filter (process whole table).
            // IMPORTANT: gamit IF(YEAR()=0, NULL, col) instead of NULLIF(col, '0000-00-00 00:00:00').
            // Strict mode rejects yung literal '0000-00-00 00:00:00' kahit sa loob lang ng
            // NULLIF expression (parsed before any rows checked). YEAR() returns 0 for sentinel
            // zero-dates — strict-safe kasi walang bad literal sa SQL.
            DB::statement("
                INSERT INTO from_jnts_2_winners (
                    bulk_run_id, submission_time, waybill_number, receiver, receiver_cellphone,
                    sender, item_name, cod, remarks, status, signingtime,
                    province, city, barangay, total_shipping_cost, rts_reason
                )
                SELECT
                    s.bulk_run_id,
                    IF(YEAR(s.submission_time) = 0, NULL, s.submission_time) AS submission_time,
                    s.waybill_number, s.receiver, s.receiver_cellphone,
                    s.sender, s.item_name, s.cod, s.remarks, s.status,
                    IF(YEAR(s.signingtime) = 0, NULL, s.signingtime) AS signingtime,
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
                        WHERE waybill_number IS NOT NULL
                          AND waybill_number != ''
                        GROUP BY waybill_number
                    ) priorities
                      ON inner_s.waybill_number = priorities.waybill_number
                      AND CASE LOWER(TRIM(COALESCE(inner_s.status, '')))
                            WHEN 'returned' THEN 1
                            WHEN 'delivered' THEN 2
                            ELSE 3
                          END = priorities.best_priority
                    GROUP BY inner_s.waybill_number
                ) winners ON s.id = winners.winner_id
            ");

            $winnersCount = (int) DB::table('from_jnts_2_winners')->count();
            $elapsed      = round(microtime(true) - $startTs, 1);

            Log::info("Pipeline Phase 1 DONE — materialized {$winnersCount} winners in {$elapsed}s");

            Cache::put('jnt_v2_pipeline_state', [
                'phase'      => 'phase1',
                'status'     => 'done',
                'processed'  => $winnersCount,
                'total'      => $stagingCount,
                'message'    => "Materialized {$winnersCount} winners from {$stagingCount} staging rows in {$elapsed}s",
                'finished_at'=> Carbon::now('Asia/Manila')->toDateTimeString(),
                'elapsed_s'  => $elapsed,
            ], 86400);
        } catch (\Throwable $e) {
            Log::error('Pipeline Phase 1 FAILED: ' . $e->getMessage());
            Cache::put('jnt_v2_pipeline_state', [
                'phase'      => 'phase1',
                'status'     => 'failed',
                'message'    => 'Phase 1 failed: ' . mb_substr($e->getMessage(), 0, 400),
                'finished_at'=> Carbon::now('Asia/Manila')->toDateTimeString(),
            ], 86400);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Cache::put('jnt_v2_pipeline_state', [
            'phase'      => 'phase1',
            'status'     => 'failed',
            'message'    => 'Phase 1 job failed: ' . mb_substr($exception->getMessage(), 0, 400),
            'finished_at'=> Carbon::now('Asia/Manila')->toDateTimeString(),
        ], 86400);
    }
}
