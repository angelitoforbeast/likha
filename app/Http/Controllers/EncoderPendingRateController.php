<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class EncoderPendingRateController extends Controller
{
    public function index(Request $request)
    {
        // Default: last 7 days INCLUDING today
        // Uses app timezone; if your app timezone is already Asia/Manila, you're good.
        $tz = config('app.timezone', 'Asia/Manila');

        $end   = Carbon::today($tz);
        $start = (clone $end)->subDays(6);

        $startDate = $request->query('start_date', $start->toDateString());
        $endDate   = $request->query('end_date', $end->toDateString());

        // Normalize to dates (safe)
        try {
            $startDateObj = Carbon::parse($startDate, $tz)->startOfDay();
        } catch (\Throwable $e) {
            $startDateObj = $start->startOfDay();
        }

        try {
            $endDateObj = Carbon::parse($endDate, $tz)->startOfDay();
        } catch (\Throwable $e) {
            $endDateObj = $end->startOfDay();
        }

        // Ensure start <= end
        if ($startDateObj->gt($endDateObj)) {
            [$startDateObj, $endDateObj] = [$endDateObj, $startDateObj];
        }

        $rows = DB::table('macro_output')
            ->selectRaw("
                ts_date,
                SUM(CASE WHEN STATUS = 'Proceed' THEN 1 ELSE 0 END) AS proceed_count,
                SUM(CASE WHEN STATUS = 'Cannot Proceed' THEN 1 ELSE 0 END) AS cannot_proceed_count,
                SUM(CASE WHEN STATUS = 'ODZ' THEN 1 ELSE 0 END) AS odz_count,
                SUM(CASE WHEN STATUS IS NULL OR TRIM(STATUS) = '' THEN 1 ELSE 0 END) AS blank_count,
                COUNT(*) AS total_count
            ")
            ->whereNotNull('ts_date')
            ->whereBetween('ts_date', [$startDateObj->toDateString(), $endDateObj->toDateString()])
            ->groupBy('ts_date')
            ->orderBy('ts_date', 'asc')
            ->get();

        // Add rates (avoid division by zero)
        $data = $rows->map(function ($r) {
            $total = (int) ($r->total_count ?? 0);
            $cannot = (int) ($r->cannot_proceed_count ?? 0);
            $odz = (int) ($r->odz_count ?? 0);

            $pendingRate = $total > 0 ? ($cannot / $total) * 100 : 0;
            $totalPendingRate = $total > 0 ? (($cannot + $odz) / $total) * 100 : 0;

            return (object) [
                'ts_date' => $r->ts_date,
                'proceed' => (int) ($r->proceed_count ?? 0),
                'cannot_proceed' => $cannot,
                'odz' => $odz,
                'blank' => (int) ($r->blank_count ?? 0),
                'total' => $total,
                'pending_rate' => $pendingRate,
                'total_pending_rate' => $totalPendingRate,
            ];
        });

        return view('macro_output.pending-rate', [
            'data' => $data,
            'start_date' => $startDateObj->toDateString(),
            'end_date' => $endDateObj->toDateString(),
            'tz' => $tz,
        ]);
    }
}
