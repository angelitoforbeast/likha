<?php

namespace App\Http\Controllers;

use App\Jobs\SyncFromJntStatusByTrack;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * /jnt/track-sync — API-based status refresh ng from_jnts gamit ang J&T TRACKQUERY.
 * Concept ng /jnt_upload, pero API ang source (hindi CSV). Same access gate.
 */
class JntTrackSyncController extends Controller
{
    private function checkAccess(): void
    {
        $raw  = Auth::user()?->employeeProfile?->role ?? '';
        $norm = preg_replace('/\s+/u', ' ', trim((string) $raw));
        $isCEO       = preg_match('/^ceo$/iu', $norm) === 1;
        $isMOIC      = preg_match('/^marketing\s*[-–—]\s*oic$/iu', $norm) === 1;
        $isMarketing = preg_match('/^marketing$/iu', $norm) === 1;
        if (!($isCEO || $isMOIC || $isMarketing)) abort(404);
    }

    public function index()
    {
        $this->checkAccess();
        $tz    = 'Asia/Manila';
        $today = Carbon::now($tz)->toDateString();
        // Default = simula ng NAKARAANG buwan → ngayon (gaya ng /jnt/hold).
        $first = Carbon::now($tz)->startOfMonth()->subMonth()->toDateString();

        return view('jnt.track-sync.index', [
            'defaultFrom' => $first,
            'defaultTo'   => $today,
        ]);
    }

    /** POST — magsimula ng preview (dry-run) o apply. */
    public function start(Request $request)
    {
        $this->checkAccess();
        $data = $request->validate([
            'date_from' => 'required|date',
            'date_to'   => 'required|date|after_or_equal:date_from',
            'dry_run'   => 'nullable|boolean',
        ]);

        $dryRun = $request->boolean('dry_run', true);

        $runId = DB::table('jnt_track_sync_runs')->insertGetId([
            'date_from'  => $data['date_from'],
            'date_to'    => $data['date_to'],
            'dry_run'    => $dryRun,
            'status'     => 'queued',
            'created_at' => now('Asia/Manila'),
            'updated_at' => now('Asia/Manila'),
        ]);

        SyncFromJntStatusByTrack::dispatch($runId, $data['date_from'], $data['date_to'], $dryRun);

        return response()->json(['ok' => true, 'run_id' => $runId, 'dry_run' => $dryRun]);
    }

    /** GET — status/progress ng isang run (+ result sample). */
    public function status(int $run)
    {
        $this->checkAccess();
        $row = DB::table('jnt_track_sync_runs')->where('id', $run)->first();
        if (!$row) return response()->json(['ok' => false, 'message' => 'Run not found'], 404);

        $row->result_sample = $row->result_sample ? json_decode($row->result_sample, true) : null;
        return response()->json(['ok' => true, 'run' => $row]);
    }

    /** GET — recent runs (history). */
    public function history()
    {
        $this->checkAccess();
        $rows = DB::table('jnt_track_sync_runs')
            ->select('id', 'date_from', 'date_to', 'dry_run', 'status', 'total', 'processed',
                     'updated', 'unchanged', 'skipped', 'unmapped', 'failed', 'created_at', 'finished_at')
            ->orderByDesc('id')->limit(25)->get();

        return response()->json(['ok' => true, 'runs' => $rows]);
    }
}
