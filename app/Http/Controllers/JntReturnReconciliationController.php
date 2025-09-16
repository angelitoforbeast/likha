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

        // Inputs: default this month
        $start   = $request->input('start_date') ?? Carbon::now($tz)->startOfMonth()->toDateString();
        $end     = $request->input('end_date')   ?? Carbon::now($tz)->endOfMonth()->toDateString();
        $perDate = (bool) $request->boolean('per_date', false);

        // Inclusive timestamps
        $startTs = Carbon::parse($start, $tz)->startOfDay();
        $endTs   = Carbon::parse($end, $tz)->endOfDay();

        // Case-insensitive "Returned%"
        $statusReturned = $driver === 'pgsql'
            ? "status ILIKE 'returned%'"
            : "LOWER(status) LIKE 'returned%'";

        // Unique returned waybills in range (earliest signingtime)
        $returnedBase = DB::table('from_jnts')
            ->whereNotNull('signingtime')
            ->whereBetween('signingtime', [$startTs, $endTs])
            ->whereRaw($statusReturned)
            ->selectRaw('waybill_number, MIN(signingtime) AS first_signingtime')
            ->groupBy('waybill_number');

        // DATE cast
        $dateCast = $driver === 'pgsql'
            ? 'CAST(r.first_signingtime AS DATE)'
            : 'DATE(r.first_signingtime)';

        // ---- Detect columns in jnt_return_scanned ----
        $scanWbCol = Schema::hasColumn('jnt_return_scanned', 'waybill') ? 'waybill'
                   : (Schema::hasColumn('jnt_return_scanned', 'waybill_number') ? 'waybill_number' : null);

        if (!$scanWbCol) {
            // Hard fail with a clear message so it's easy to fix schema
            abort(500, "jnt_return_scanned: missing waybill/waybill_number column.");
        }

        $scanAtCol = Schema::hasColumn('jnt_return_scanned', 'scanned_at') ? 'scanned_at'
                   : (Schema::hasColumn('jnt_return_scanned', 'created_at') ? 'created_at' : null);

        $scanByCol = Schema::hasColumn('jnt_return_scanned', 'scanned_by') ? 'scanned_by' : null;

        // Join using the detected waybill column
        $rowsQ = DB::query()
            ->fromSub($returnedBase, 'r')
            ->leftJoin('jnt_return_scanned AS s', function ($join) use ($scanWbCol) {
                $join->on("s.$scanWbCol", '=', 'r.waybill_number');
            })
            ->selectRaw("
                r.waybill_number,
                r.first_signingtime,
                {$dateCast} AS signing_date,
                s.id AS scanned_id
            ");

        // Add dynamic selects safely
        $rowsQ->addSelect(DB::raw("s.$scanWbCol AS scanned_waybill"));
        if ($scanAtCol) $rowsQ->addSelect(DB::raw("s.$scanAtCol AS scanned_at"));
        if ($scanByCol) $rowsQ->addSelect(DB::raw("s.$scanByCol AS scanned_by"));

        $rows = $rowsQ->orderBy('first_signingtime', 'asc')->get();

        // Split existing/missing
        $existing = [];
        $missing  = [];

        foreach ($rows as $row) {
            $rec = [
                'waybill'      => (string) ($row->waybill_number ?? $row->scanned_waybill ?? ''),
                'signingtime'  => (string) $row->first_signingtime,
                'signing_date' => (string) $row->signing_date,
                'scanned_at'   => isset($row->scanned_at) ? (string) $row->scanned_at : null,
                'scanned_by'   => isset($row->scanned_by) ? (string) $row->scanned_by : null,
            ];
            if ($row->scanned_id) $existing[] = $rec; else $missing[] = $rec;
        }

        // Build per-date groupings & counts
        $byDate = [
            'missing'  => [],
            'existing' => [],
            'counts'   => ['missing' => [], 'existing' => []],
            'dates'    => [],
        ];
        $datesSet = [];

        foreach ($missing as $r) {
            $d = $r['signing_date'];
            $byDate['missing'][$d]   ??= [];
            $byDate['missing'][$d][]  = $r;
            $byDate['counts']['missing'][$d] = 1 + ($byDate['counts']['missing'][$d] ?? 0);
            $datesSet[$d] = true;
        }
        foreach ($existing as $r) {
            $d = $r['signing_date'];
            $byDate['existing'][$d]   ??= [];
            $byDate['existing'][$d][]  = $r;
            $byDate['counts']['existing'][$d] = 1 + ($byDate['counts']['existing'][$d] ?? 0);
            $datesSet[$d] = true;
        }

        $dates = array_keys($datesSet);
        sort($dates);
        $byDate['dates'] = $dates;

        return view('jnt.return.reconciliation', [
            'start'    => $start,
            'end'      => $end,
            'perDate'  => $perDate,
            'existing' => $existing,
            'missing'  => $missing,
            'byDate'   => $byDate,
        ]);
    }
}
