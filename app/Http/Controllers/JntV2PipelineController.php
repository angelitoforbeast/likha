<?php

namespace App\Http\Controllers;

use App\Jobs\MaterializeWinnersV2Pipeline;
use App\Jobs\MergeWinnersToFromJnts2Pipeline;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * JNT V2 Pipeline Dashboard — manual per-phase control with live data preview.
 *
 * UI shows 3 cards (staging, winners, from_jnts_2) with row counts + sample rows.
 * Each phase has its own button + progress bar.
 *
 * Phase 1: staging → winners (MaterializeWinnersV2Pipeline job)
 * Phase 2: winners → from_jnts_2 (MergeWinnersToFromJnts2Pipeline job)
 *
 * State tracked sa Laravel Cache (key: jnt_v2_pipeline_state) for live polling.
 */
class JntV2PipelineController extends Controller
{
    private function checkAccess(): void
    {
        if (!Auth::check()) abort(403);
    }

    /** GET /jnt_upload_v2/pipeline — main dashboard. */
    public function index()
    {
        $this->checkAccess();
        return view('jnt_upload_v2.pipeline');
    }

    /** GET /jnt_upload_v2/pipeline/data/staging — staging count + top 5 preview. */
    public function dataStaging()
    {
        $this->checkAccess();
        return response()->json($this->buildTableStats('from_jnts_2_staging', [
            'id', 'bulk_run_id', 'waybill_number', 'status', 'sender',
            'item_name', 'cod', 'submission_time', 'signingtime', 'parsed_at',
        ]));
    }

    /** GET /jnt_upload_v2/pipeline/data/winners — winners count + top 5 preview. */
    public function dataWinners()
    {
        $this->checkAccess();
        return response()->json($this->buildTableStats('from_jnts_2_winners', [
            'id', 'bulk_run_id', 'waybill_number', 'status', 'sender',
            'item_name', 'cod', 'submission_time', 'signingtime',
        ]));
    }

    /** GET /jnt_upload_v2/pipeline/data/final — from_jnts_2 count + top 5 preview. */
    public function dataFinal()
    {
        $this->checkAccess();
        return response()->json($this->buildTableStats('from_jnts_2', [
            'id', 'waybill_number', 'status', 'sender', 'item_name', 'cod',
            'submission_time', 'signingtime', 'updated_at',
        ]));
    }

    /** POST /jnt_upload_v2/pipeline/run-phase1 — dispatch Phase 1 (staging → winners). */
    public function runPhase1(Request $request)
    {
        $this->checkAccess();

        // Guard: don't dispatch if another phase is currently running.
        $state = Cache::get('jnt_v2_pipeline_state');
        if ($state && ($state['status'] ?? null) === 'running') {
            return response()->json([
                'ok'      => false,
                'message' => "Another phase is already running ({$state['phase']}). Wait for it to finish.",
            ], 422);
        }

        // Clear orphaned consolidate jobs (be defensive).
        DB::table('jobs')->where('queue', 'jnt_v2_consolidate')->delete();

        MaterializeWinnersV2Pipeline::dispatch();

        Log::info('JntV2Pipeline: manual run-phase1 by user ' . (Auth::id() ?? 'unknown'));

        return response()->json([
            'ok'      => true,
            'message' => 'Phase 1 dispatched — materializing winners',
        ]);
    }

    /** POST /jnt_upload_v2/pipeline/run-phase2 — dispatch Phase 2 (winners → from_jnts_2). */
    public function runPhase2(Request $request)
    {
        $this->checkAccess();

        $state = Cache::get('jnt_v2_pipeline_state');
        if ($state && ($state['status'] ?? null) === 'running') {
            return response()->json([
                'ok'      => false,
                'message' => "Another phase is already running ({$state['phase']}). Wait for it to finish.",
            ], 422);
        }

        // Sanity check: winners must have data
        $winnersCount = (int) DB::table('from_jnts_2_winners')->count();
        if ($winnersCount === 0) {
            return response()->json([
                'ok'      => false,
                'message' => 'Winners table is empty. Run Phase 1 first.',
            ], 422);
        }

        DB::table('jobs')->where('queue', 'jnt_v2_consolidate')->delete();

        MergeWinnersToFromJnts2Pipeline::dispatch();

        Log::info('JntV2Pipeline: manual run-phase2 by user ' . (Auth::id() ?? 'unknown'));

        return response()->json([
            'ok'      => true,
            'message' => "Phase 2 dispatched — merging {$winnersCount} winners to from_jnts_2",
        ]);
    }

    /** POST /jnt_upload_v2/pipeline/clear/{table} — TRUNCATE staging or winners. */
    public function clearTable(Request $request, string $table)
    {
        $this->checkAccess();

        $allowed = ['staging' => 'from_jnts_2_staging', 'winners' => 'from_jnts_2_winners'];
        if (!isset($allowed[$table])) {
            return response()->json(['ok' => false, 'message' => 'Invalid table'], 422);
        }

        // Don't allow clearing while a phase is running
        $state = Cache::get('jnt_v2_pipeline_state');
        if ($state && ($state['status'] ?? null) === 'running') {
            return response()->json([
                'ok'      => false,
                'message' => "Cannot clear table — phase {$state['phase']} is running.",
            ], 422);
        }

        DB::statement("TRUNCATE TABLE {$allowed[$table]}");

        Log::warning("JntV2Pipeline: TRUNCATE {$allowed[$table]} by user " . (Auth::id() ?? 'unknown'));

        return response()->json([
            'ok'      => true,
            'message' => "Table {$allowed[$table]} cleared (TRUNCATE)",
        ]);
    }

    /** GET /jnt_upload_v2/pipeline/progress — current pipeline state + live stats. */
    public function progress()
    {
        $this->checkAccess();

        $state = Cache::get('jnt_v2_pipeline_state', [
            'phase'  => null,
            'status' => 'idle',
            'message'=> 'No active phase.',
        ]);

        // Add live row counts (cheap)
        $state['stagingCount'] = (int) DB::table('from_jnts_2_staging')->count();
        $state['winnersCount'] = (int) DB::table('from_jnts_2_winners')->count();
        $state['finalCount']   = (int) DB::table('from_jnts_2')->count();
        $state['fetched_at']   = now()->format('Y-m-d H:i:s');

        return response()->json($state);
    }

    /** Helper: count + top 5 sample rows for a table. */
    private function buildTableStats(string $table, array $columns): array
    {
        $count = (int) DB::table($table)->count();

        $rows = DB::table($table)
            ->orderByDesc('id')
            ->limit(5)
            ->get($columns)
            ->toArray();

        // Convert objects to arrays for clean JSON
        $rows = array_map(fn ($r) => (array) $r, $rows);

        // Per-bulk_run_id breakdown for staging + winners (helpful for users)
        $perRun = [];
        if (in_array($table, ['from_jnts_2_staging', 'from_jnts_2_winners'], true)) {
            $perRun = DB::table($table)
                ->select('bulk_run_id', DB::raw('COUNT(*) AS rows_count'))
                ->groupBy('bulk_run_id')
                ->orderBy('bulk_run_id', 'desc')
                ->limit(20)
                ->get()
                ->toArray();
            $perRun = array_map(fn ($r) => (array) $r, $perRun);
        }

        return [
            'table'    => $table,
            'count'    => $count,
            'columns'  => $columns,
            'rows'     => $rows,
            'per_run'  => $perRun,
            'fetched_at' => now()->format('Y-m-d H:i:s'),
        ];
    }
}
