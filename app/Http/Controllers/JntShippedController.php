<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class JntShippedController extends Controller
{
    public function index(Request $request)
    {
        $tz     = 'Asia/Manila';
        $driver = DB::getDriverName(); // 'mysql' | 'pgsql'

        // === Date range: default to yesterday (single day) ===
        $start = $request->input('start_date');
        $end   = $request->input('end_date');

        if (!$start && !$end) {
            $yesterday = Carbon::yesterday($tz)->toDateString();
            $start = $yesterday;
            $end   = $yesterday;
        } else {
            if ($start && !$end)  $end = $start;
            if (!$start && $end)  $start = $end;
        }

        // === Mode toggle: raw vs qty-aware ("2 x NAME" => qty=2, name=NAME) ===
        // values: 'raw' | 'qty' (default 'qty')
        $mode = strtolower($request->query('mode', 'qty'));
        if (!in_array($mode, ['raw','qty'], true)) $mode = 'qty';
        $normalize = ($mode === 'qty');

        // === SQL DATE(submission_time) expr ===
        $dateSubExpr = $driver === 'pgsql' ? "CAST(j.submission_time AS DATE)" : "DATE(j.submission_time)";

        // Utility to pick a column name from a table
        $pickCol = function (string $table, array $candidates) use ($driver) {
            if ($driver === 'pgsql') {
                $rows = DB::select(
                    "SELECT column_name FROM information_schema.columns
                     WHERE table_schema = current_schema() AND table_name = ?",
                    [$table]
                );
            } else { // mysql
                $rows = DB::select(
                    "SELECT COLUMN_NAME AS column_name
                     FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = ?",
                    [$table]
                );
            }
            $cols = array_map(fn($r) => $r->column_name, $rows);
            foreach ($candidates as $c) if (in_array($c, $cols, true)) return $c;
            return null;
        };

        // Item source: prefer from_jnts.item_name; else join macro_output by waybill
        $itemExpr = null;
        $hasItemInJnts = false;

        $jItemCol = $pickCol('from_jnts', ['item_name','ITEM_NAME','Item_Name','item','Item','product','Product','Product_Name']);
        if ($jItemCol) {
            $itemExpr = "j." . ($driver === 'pgsql' ? "\"$jItemCol\"" : "`$jItemCol`");
            $hasItemInJnts = true;
        }

        $jWaybillCol  = $pickCol('from_jnts', ['waybill_number','Waybill_Number','WAYBILL_NUMBER','waybill','Waybill','WAYBILL']) ?? 'waybill_number';
        $moWaybillCol = $pickCol('macro_output', ['waybill','WAYBILL','Waybill']);
        $moItemCol    = $pickCol('macro_output', ['ITEM_NAME','item_name','Item_Name','Product','product_name','ITEM','item']);

        $needJoinMo = false;
        if (!$hasItemInJnts) {
            if ($moWaybillCol && $moItemCol && $jWaybillCol) {
                $itemExpr = "mo." . ($driver === 'pgsql' ? "\"$moItemCol\"" : "`$moItemCol`");
                $needJoinMo = true;
            } else {
                $itemExpr = "''";
            }
        }

        // Base query filtered by DATE(submission_time)
        $q = DB::table('from_jnts as j')
            ->whereNotNull('j.submission_time')
            ->whereBetween(DB::raw($dateSubExpr), [$start, $end]);

        if ($needJoinMo) {
            $jW = "j." . ($driver === 'pgsql' ? "\"$jWaybillCol\"" : "`$jWaybillCol`");
            $mW = "mo." . ($driver === 'pgsql' ? "\"$moWaybillCol\"" : "`$moWaybillCol`");
            $q->leftJoin('macro_output as mo', DB::raw($jW), '=', DB::raw($mW));
        }

        // Pull raw rows (date + item_name)
        $rows = $q->selectRaw("$dateSubExpr AS d, $itemExpr AS item_name")
                  ->orderBy('d', 'asc')
                  ->get();

        // Normalize / aggregate
        $norm = function ($raw) use ($normalize) {
            $s = trim((string)$raw);
            if ($s === '') return [1, '—'];
            if (!$normalize) return [1, $s];

            // Patterns: "2 x NAME" / "2x NAME" / "2× NAME"
            if (preg_match('/^\s*(\d+)\s*[x×]\s*(.+)$/iu', $s, $m)) {
                $qty  = max(1, (int)$m[1]);
                $name = trim($m[2]) !== '' ? trim($m[2]) : '—';
                return [$qty, $name];
            }
            return [1, $s];
        };

        $itemsMap  = []; // name => ['total'=>int, 'per_date'=>[date=>int]]
        $datesSet  = []; // set of date strings we saw

        foreach ($rows as $r) {
            $date = (string)$r->d;
            [$qty, $name] = $norm($r->item_name);

            $itemsMap[$name]['total'] = ($itemsMap[$name]['total'] ?? 0) + $qty;
            $itemsMap[$name]['per_date'][$date] = ($itemsMap[$name]['per_date'][$date] ?? 0) + $qty;

            $datesSet[$date] = true;
        }

        // Sort dates asc
        $dates = array_keys($datesSet);
        sort($dates);

        // Flatten items sorted by total desc, then name asc
        uksort($itemsMap, function ($a, $b) use ($itemsMap) {
            $ta = $itemsMap[$a]['total'] ?? 0;
            $tb = $itemsMap[$b]['total'] ?? 0;
            if ($tb === $ta) return strcmp($a, $b);
            return $tb <=> $ta;
        });

        $items = [];
        $grandTotal = 0;
        foreach ($itemsMap as $name => $d) {
            $items[] = [
                'name'     => $name,
                'total'    => (int)($d['total'] ?? 0),
                'per_date' => $d['per_date'] ?? [],
            ];
            $grandTotal += (int)($d['total'] ?? 0);
        }

        return view('jnt.shipped', [
            'items'      => $items,
            'dates'      => $dates,
            'grandTotal' => $grandTotal,
            'start'      => $start,
            'end'        => $end,
            'mode'       => $mode,        // 'raw' | 'qty'
            'normalize'  => $normalize,   // bool
        ]);
    }
}
