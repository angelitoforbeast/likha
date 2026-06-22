<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use App\Models\ItemHoldSnapshot;
use App\Models\AppSetting;
use App\Services\HoldService;

class ItemHoldSnapshotController extends Controller
{
    /** GET /jnt/hold-snapshots — view captured HOLD snapshots + manual capture. */
    public function index(Request $request)
    {
        $dates = ItemHoldSnapshot::query()
            ->select('snapshot_date')
            ->distinct()
            ->orderByDesc('snapshot_date')
            ->pluck('snapshot_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->values()
            ->all();

        $selected = (string) $request->input('date', $dates[0] ?? Carbon::now('Asia/Manila')->subDay()->toDateString());

        $rows = ItemHoldSnapshot::query()
            ->where('snapshot_date', $selected)
            ->orderByDesc('hold_units')
            ->orderBy('item_name')
            ->get(['item_name', 'hold_units', 'captured_at']);

        $totalUnits = (int) $rows->sum('hold_units');

        $defaultDate = Carbon::now('Asia/Manila')->subDay()->toDateString();

        // Run history (logs) — kelan tumakbo ang snapshot (cron/manual), success/error.
        $logs = collect();
        if (Schema::hasTable('hold_snapshot_logs')) {
            $logs = DB::table('hold_snapshot_logs')->orderByDesc('id')->limit(50)->get();
        }

        $scheduleTime = $this->currentScheduleTime();

        return view('jnt.hold_snapshots', [
            'dates'        => $dates,
            'selected'     => $selected,
            'rows'         => $rows,
            'totalUnits'   => $totalUnits,
            'defaultDate'  => $defaultDate,
            'logs'         => $logs,
            'scheduleTime' => $scheduleTime,
        ]);
    }

    /** POST /jnt/hold-snapshots/run — manual snapshot (para makapag-test agad). */
    public function runNow(Request $request, HoldService $svc)
    {
        $date = (string) $request->input('date', '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = Carbon::now('Asia/Manila')->subDay()->toDateString();
        }
        $window = (int) $request->input('window', 60);
        if ($window < 1)   $window = 1;
        if ($window > 365) $window = 365;

        $res = $svc->snapshotWithLog($date, $window, 'manual');

        return redirect()
            ->route('jnt.hold-snapshots', ['date' => $res['date']])
            ->with('success', "📸 Snapshot saved para sa {$res['date']} — {$res['items']} item(s), {$res['units']} total held units (window {$window}d).");
    }

    /** Current configured cron time (HH:MM, Asia/Manila); default 06:00. */
    private function currentScheduleTime(): string
    {
        $t = AppSetting::get('hold_snapshot_time', '06:00');
        return (is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) ? $t : '06:00';
    }

    /** GET /jnt/hold-snapshots/schedule — edit the daily cron time (UI, not hardcoded). */
    public function scheduleEdit()
    {
        return view('jnt.hold_snapshot_schedule', [
            'time'  => $this->currentScheduleTime(),
            'nowPh' => Carbon::now('Asia/Manila')->format('H:i'),
        ]);
    }

    /** POST /jnt/hold-snapshots/schedule — save the daily cron time. */
    public function scheduleUpdate(Request $request)
    {
        $data = $request->validate([
            'time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        ], [
            'time.regex' => 'Maling oras — gamitin ang 24-hour HH:MM (00:00–23:59).',
        ]);

        AppSetting::set('hold_snapshot_time', $data['time']);

        return redirect()
            ->route('jnt.hold-snapshots.schedule')
            ->with('success', "✅ Na-set ang cron time sa {$data['time']} (PH). Tatakbo ang holds:snapshot araw-araw sa oras na 'to — basta aktibo ang `schedule:run` sa server crontab.");
    }
}
