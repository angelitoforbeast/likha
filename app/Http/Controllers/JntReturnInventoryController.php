<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema; // << add this
use Carbon\Carbon;

class JntReturnInventoryController extends Controller
{
    public function index(Request $request)
    {
        $tz     = 'Asia/Manila';
        $driver = DB::getDriverName(); // 'pgsql' | 'mysql'

        // Inputs: default this month
        $start = $request->input('start_date') ?? Carbon::now($tz)->startOfMonth()->toDateString();
        $end   = $request->input('end_date')   ?? Carbon::now($tz)->endOfMonth()->toDateString();
        $mode  = $request->input('mode', 'mod'); // 'raw' | 'mod'
        $useRaw = ($mode === 'raw');

        // Inclusive range on scanned_at
        $startTs = Carbon::parse($start, $tz)->startOfDay();
        $endTs   = Carbon::parse($end, $tz)->endOfDay();

        // Build date list for columns
        $dates = [];
        $cursor = Carbon::parse($start, $tz)->copy();
        $endDateOnly = Carbon::parse($end, $tz)->copy();
        while ($cursor->lte($endDateOnly)) {
            $dates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        // Month header segments (merged months row)
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

        // Subquery: one item_name per waybill_number (avoid dup rows)
        $fj = DB::table('from_jnts as f')
            ->selectRaw('f.waybill_number, MIN(f.item_name) AS item_name')
            ->groupBy('f.waybill_number');

        // 🔎 Auto-detect join column on jnt_return_scanned
        $scanWbCol = Schema::hasColumn('jnt_return_scanned', 'waybill')
            ? 'waybill'
            : (Schema::hasColumn('jnt_return_scanned', 'waybill_number') ? 'waybill_number' : null);

        if (!$scanWbCol) {
            abort(500, "jnt_return_scanned: missing 'waybill' / 'waybill_number' column.");
        }

        // Base: scanned returns within range
        $rows = DB::table('jnt_return_scanned as s')
            ->whereNotNull('s.scanned_at')
            ->whereBetween('s.scanned_at', [$startTs, $endTs])
            ->joinSub($fj, 'fj', function ($join) use ($scanWbCol) {
                $join->on('fj.waybill_number', '=', "s.$scanWbCol");
            })
            ->select('s.scanned_at', 'fj.item_name')
            ->orderBy('s.scanned_at', 'asc')
            ->get();

        // Aggregate per item per date
        $perItem = []; // itemLabel => ['per' => ['YYYY-MM-DD' => qty], 'total' => qty]
        foreach ($rows as $r) {
            $dateKey = Carbon::parse($r->scanned_at, $tz)->toDateString();
            $rawName = trim((string)($r->item_name ?? ''));
            if ($rawName === '') continue;

            // Parse "N x NAME" at the start; quantity=1 if no match
            $qty = 1;
            $nameMod = $rawName;
            if (preg_match('/^\s*(\d+)\s*x\s*(.+)$/iu', $rawName, $m)) {
                $qty = max(1, (int)$m[1]);
                $nameMod = trim($m[2]);
            }

            $label = $useRaw ? $rawName : $nameMod;

            if (!isset($perItem[$label])) {
                $perItem[$label] = ['per' => array_fill_keys($dates, 0), 'total' => 0];
            }
            if (isset($perItem[$label]['per'][$dateKey])) {
                $perItem[$label]['per'][$dateKey] += $qty;
                $perItem[$label]['total']        += $qty;
            }
        }

        // Sort items by total desc, then by name asc
        uksort($perItem, function ($a, $b) use ($perItem) {
            $ta = $perItem[$a]['total'] ?? 0;
            $tb = $perItem[$b]['total'] ?? 0;
            if ($ta === $tb) return strcasecmp($a, $b);
            return ($tb <=> $ta);
        });

        return view('jnt.return.inventory', [
            'start'         => $start,
            'end'           => $end,
            'mode'          => $useRaw ? 'raw' : 'mod',
            'dates'         => $dates,
            'monthSegments' => $monthSegments,
            'perItem'       => $perItem,
        ]);
    }
}
