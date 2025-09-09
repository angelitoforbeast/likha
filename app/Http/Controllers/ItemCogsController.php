<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Cogs;
use Carbon\Carbon;

class ItemCogsController extends Controller
{
    // ⬇️ Change these if your schema differs
    private const MACRO_TABLE = 'macro_output';
    private const COL_ITEM    = 'ITEM_NAME';   // e.g. 'ITEM_NAME' or 'item_name'
    private const COL_DATE    = 'TIMESTAMP';   // literal column name (reserved word)

    public function index(Request $req) {
        $month = $req->query('month', now()->format('Y-m')); // YYYY-MM
        return view('item.cogs', compact('month'));
    }

    /**
     * GRID DATA (JSON)
     * - Rows/items and day presence come from macro_output.
     * - Cell value = last known COGS (exact day if exists, else carry-forward from the nearest prior day).
     * - We DO NOT save daily values; only when user edits a cell we upsert into `cogs`.
     * - Cells are editable only on days where (item, date) exists in macro_output.
     */
    public function grid(Request $req) {
        $month = Carbon::parse($req->query('month', now()->format('Y-m')));
        $start = $month->copy()->startOfMonth();
        $end   = $month->copy()->endOfMonth();
        $days  = $start->daysInMonth;

        $dateExpr = $this->dateExpr(self::COL_DATE);

        // 1) Items present at least once in the month (from macro_output)
        $items = DB::table(self::MACRO_TABLE)
            ->select(self::COL_ITEM.' as item_name')
            ->whereRaw($dateExpr.' BETWEEN ? AND ?', [$start->toDateString(), $end->toDateString()])
            ->pluck('item_name')
            ->filter(fn($n)=> trim((string)$n) !== '')
            ->unique()
            ->sort()
            ->values();

        // 2) Presence map: item -> [day => true] for that month
        $presence = DB::table(self::MACRO_TABLE)
            ->select(self::COL_ITEM.' as item_name', DB::raw($dateExpr.' as d'))
            ->whereRaw($dateExpr.' BETWEEN ? AND ?', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy('item_name')
            ->map(function($rows){
                return collect($rows)->pluck('d')
                    ->map(fn($d)=> Carbon::parse($d)->day)
                    ->mapWithKeys(fn($day)=>[$day => true])
                    ->all();
            });

        // 3) Prefetch ALL COGS rows for these items up to end-of-month (to allow deep carry-forward)
        $cogs = Cogs::query()
            ->whereIn('item_name', $items)
            ->where('date', '<=', $end->toDateString())
            ->orderBy('item_name')->orderBy('date')
            ->get()
            ->groupBy('item_name');

        // 4) Build grid: carry-forward per item using a pointer over sorted COGS rows
        $result = [];
        foreach ($items as $name) {
            $row = [
                'item_name' => $name,
                'prices'    => array_fill(1, $days, null),   // display only
                'editable'  => array_fill(1, $days, false),  // only true if present that day
            ];

            $byItem = ($cogs->get($name) ?? collect())->values(); // sorted asc by date
            $k = 0;                              // pointer into COGS rows
            $lastKnown = null;                   // carried price as-of current day

            for ($d = 1; $d <= $days; $d++) {
                $currDate = $start->copy()->day($d)->toDateString();
                $isPresent = isset($presence[$name][$d]);

                // advance pointer for all COGS rows whose date <= currDate
                while ($k < $byItem->count() && $byItem[$k]->date->toDateString() <= $currDate) {
                    $lastKnown = $byItem[$k]->unit_cost; // exact day overrides; else it becomes carry-forward
                    $k++;
                }

                // Display value ONLY if item is present that day; otherwise blank
                $row['prices'][$d]   = $isPresent ? $lastKnown : null;
                $row['editable'][$d] = $isPresent;
            }

            $result[] = $row;
        }

        return response()->json([
            'month' => $month->format('Y-m'),
            'days'  => $days,
            'rows'  => $result,
        ]);
    }

    /**
     * UPDATE ONE CELL
     * - Allowed only if (item_name, date) exists in macro_output (same calendar day).
     * - Upserts a single row into `cogs` for that (item, date).
     */
    public function update(Request $req) {
        $data = $req->validate([
            'item_name' => 'required|string',
            'date'      => 'required|date',              // YYYY-MM-DD
            'price'     => 'required|numeric|min:0',
        ]);

        $date = Carbon::parse($data['date'])->toDateString();
        $item = trim($data['item_name']);
        if ($item === '') {
            return response()->json(['ok' => false, 'error' => 'Blank item name not allowed.'], 422);
        }

        $dateExpr = $this->dateExpr(self::COL_DATE);

        // Edit allowed only when the item is present on that date in macro_output
        $present = DB::table(self::MACRO_TABLE)
            ->where(self::COL_ITEM, $item)
            ->whereRaw($dateExpr.' = ?', [$date])
            ->exists();

        if (!$present) {
            return response()->json([
                'ok' => false,
                'error' => 'Not allowed: item/date not present in macro_output.'
            ], 422);
        }

        // Upsert exact day in COGS
        Cogs::updateOrCreate(
            ['item_name' => $item, 'date' => $date],
            ['unit_cost' => $data['price'], 'history_logs' => null]
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Portable date extractor for MySQL and Postgres,
     * including string formats like '21:44 09-06-2025'.
     */
    private function dateExpr(string $col): string
    {
        $driver = DB::getDriverName(); // 'mysql' or 'pgsql'

        if ($driver === 'mysql') {
            return "COALESCE(
                DATE(`{$col}`),
                DATE(STR_TO_DATE(`{$col}`, '%H:%i %d-%m-%Y')),
                DATE(STR_TO_DATE(`{$col}`, '%d-%m-%Y %H:%i')),
                DATE(STR_TO_DATE(`{$col}`, '%Y-%m-%d %H:%i:%s'))
            )";
        } else {
            return "COALESCE(
                (CASE WHEN pg_typeof(\"{$col}\")::text IN ('timestamp without time zone','timestamp with time zone','date')
                      THEN \"{$col}\"::date ELSE NULL END),
                to_date(\"{$col}\", 'HH24:MI DD-MM-YYYY'),
                to_date(\"{$col}\", 'DD-MM-YYYY HH24:MI'),
                to_date(\"{$col}\", 'YYYY-MM-DD HH24:MI:SS')
            )";
        }
    }
}
