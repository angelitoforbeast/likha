<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class JntReturnReconciliationController extends Controller
{
    public function index(Request $request)
    {
        $tz     = 'Asia/Manila';
        $driver = DB::getDriverName(); // 'pgsql' | 'mysql'

        // Defaults: this month
        $start = $request->input('start_date') ?? Carbon::now($tz)->startOfMonth()->toDateString();
        $end   = $request->input('end_date')   ?? Carbon::now($tz)->endOfMonth()->toDateString();

        // Inclusive timestamps
        $startTs = Carbon::parse($start, $tz)->startOfDay();
        $endTs   = Carbon::parse($end, $tz)->endOfDay();

        // Build date keys (YYYY-MM-DD) for the header columns
        $dates = [];
        $cursor = Carbon::parse($start, $tz)->copy();
        $endDateOnly = Carbon::parse($end, $tz)->copy();
        while ($cursor->lte($endDateOnly)) {
            $dates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        // Month header segments (for merged month header)
        // label example: 'SEPTEMBER'
        $monthSegments = [];
        $lastLabel = null;
        foreach ($dates as $d) {
            $c = Carbon::parse($d, $tz);
            $label = strtoupper($c->format('F'));
            if ($label !== $lastLabel) {
                $monthSegments[] = ['label' => $label, 'span' => 1];
                $lastLabel = $label;
            } else {
                $monthSegments[count($monthSegments)-1]['span']++;
            }
        }

        // Case-insensitive "Returned%"
        $statusReturned = $driver === 'pgsql'
            ? "status ILIKE 'returned%'"
            : "LOWER(status) LIKE 'returned%'";

        // Unique returned waybills in range (earliest signingtime per waybill)
        $returnedBase = DB::table('from_jnts')
            ->whereNotNull('signingtime')
            ->whereBetween('signingtime', [$startTs, $endTs])
            ->whereRaw($statusReturned)
            ->selectRaw('waybill_number, MIN(signingtime) AS first_signingtime')
            ->groupBy('waybill_number');

        // Detect which column exists in jnt_return_scanned for join (prefer 'waybill')
        $scanWbCol = Schema::hasColumn('jnt_return_scanned', 'waybill') ? 'waybill'
                   : (Schema::hasColumn('jnt_return_scanned', 'waybill_number') ? 'waybill_number' : null);
        if (!$scanWbCol) {
            abort(500, "jnt_return_scanned: missing 'waybill' / 'waybill_number' column.");
        }

        // DATE cast (for display/grouping)
        $dateCast = $driver === 'pgsql' ? 'CAST(r.first_signingtime AS DATE)'
                                        : 'DATE(r.first_signingtime)';

        // Query rows with join to determine Missing vs Existing
        $rows = DB::query()
            ->fromSub($returnedBase, 'r')
            ->leftJoin('jnt_return_scanned AS s', "s.$scanWbCol", '=', 'r.waybill_number')
            ->selectRaw("
                r.waybill_number,
                r.first_signingtime,
                {$dateCast} AS signing_date,
                s.id AS scanned_id
            ")
            ->orderBy('first_signingtime', 'asc')
            ->get();

        // Build per-date counts
        $countsMissing = array_fill_keys($dates, 0);
        $countsExisting = array_fill_keys($dates, 0);

        $missingList = []; // detailed missing rows (for bottom list)

        foreach ($rows as $row) {
            $d = (string) $row->signing_date; // YYYY-MM-DD
            $isExisting = (bool) $row->scanned_id;

            if (isset($countsMissing[$d])) {
                if ($isExisting) $countsExisting[$d]++;
                else {
                    $countsMissing[$d]++;
                    $missingList[] = [
                        'waybill'     => (string) $row->waybill_number,
                        'signingtime' => (string) $row->first_signingtime,
                    ];
                }
            }
        }

        // Row totals
        $totalMissing = array_sum($countsMissing);
        $totalExisting = array_sum($countsExisting);
        $grandTotal = $totalMissing + $totalExisting;

        return view('jnt.return.reconciliation', [
            'start'          => $start,
            'end'            => $end,
            'dates'          => $dates,
            'monthSegments'  => $monthSegments,
            'countsMissing'  => $countsMissing,
            'countsExisting' => $countsExisting,
            'totalMissing'   => $totalMissing,
            'totalExisting'  => $totalExisting,
            'grandTotal'     => $grandTotal,
            'missingList'    => $missingList,
        ]);
    }
}
