<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\ItemHoldSnapshot;
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

        return view('jnt.hold_snapshots', [
            'dates'        => $dates,
            'selected'     => $selected,
            'rows'         => $rows,
            'totalUnits'   => $totalUnits,
            'defaultDate'  => $defaultDate,
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

        $res = $svc->snapshot($date, $window);

        return redirect()
            ->route('jnt.hold-snapshots', ['date' => $res['date']])
            ->with('success', "📸 Snapshot saved para sa {$res['date']} — {$res['items']} item(s), {$res['units']} total held units (window {$window}d).");
    }
}
