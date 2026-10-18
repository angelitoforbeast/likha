<?php

namespace App\Http\Controllers;

use App\Jobs\SyncFromJntStatusByTrack;
use App\Models\AppSetting;
use App\Services\Jnt\JntAutoTrackSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

/**
 * /jnt/track-sync — API-based status refresh ng from_jnts gamit ang J&T TRACKQUERY.
 * Concept ng /jnt_upload, pero API ang source (hindi CSV). Same access gate.
 */
class JntTrackSyncController extends Controller
{
    /** @return bool kung CEO */
    private function checkAccess(): bool
    {
        $raw  = Auth::user()?->employeeProfile?->role ?? '';
        $norm = preg_replace('/\s+/u', ' ', trim((string) $raw));
        $isCEO       = preg_match('/^ceo$/iu', $norm) === 1;
        $isMOIC      = preg_match('/^marketing\s*[-–—]\s*oic$/iu', $norm) === 1;
        $isMarketing = preg_match('/^marketing$/iu', $norm) === 1;
        if (!($isCEO || $isMOIC || $isMarketing)) abort(404);

        return $isCEO;
    }

    public function index()
    {
        $isCeo = $this->checkAccess();
        $tz    = 'Asia/Manila';
        $today = Carbon::now($tz)->toDateString();
        // Default = simula ng NAKARAANG buwan → ngayon (gaya ng /jnt/hold).
        $first = Carbon::now($tz)->startOfMonth()->subMonth()->toDateString();

        // Ang mga kusang track-sync kada oras (kind = auto), pinakabago muna.
        $hasKind = Schema::hasColumn('jnt_track_sync_runs', 'kind'); // hindi pa na-migrate: walang listahan
        $autoRuns = !$hasKind ? collect() : DB::table('jnt_track_sync_runs')->where('kind', JntAutoTrackSync::KIND)->orderByDesc('id')->limit(12)->get()
            ->map(function ($r) { $r->sample = $r->result_sample ? (array) json_decode($r->result_sample, true) : []; return $r; });

        return view('jnt.track-sync.index', [
            'defaultFrom' => $first,
            'defaultTo'   => $today,
            'autoRuns'    => $autoRuns,
            'autoOn'      => JntAutoTrackSync::enabled(),
            'autoNext'    => JntAutoTrackSync::nextRun(),
            'isCeo'       => $isCeo,
        ]);
    }

    /** POST — i-on o i-off ang kusang track-sync (CEO lang). Nasa app_settings; nananaig sa JNT_TRACK_SYNC_AUTO. */
    public function auto(Request $request)
    {
        if (!$this->checkAccess()) abort(403);
        $on = $request->validate(['on' => 'required|boolean'])['on'];
        AppSetting::set(JntAutoTrackSync::SETTING, $on ? '1' : '0');

        return response()->json(['ok' => true, 'on' => JntAutoTrackSync::enabled()]);
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
            ->whereNotNull('date_from') // ang mga manual na run lang; ang awtomatiko ay nasa itaas ng page
            ->orderByDesc('id')->limit(25)->get();

        return response()->json(['ok' => true, 'runs' => $rows]);
    }
}
