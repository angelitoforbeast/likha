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

        // 4) Build grid: carry-forward per item using a pointer over sorted COGS rows.
        //    Adds per-day flags:
        //      • anchor[d] = true kung may EXPLICIT cogs row for exactly that day
        //                    (i.e., someone manually set the price on day d).
        //      • change[d] = true kung price ay differs vs the previous day's price
        //                    (signal na nagshift yung effective cost on day d).
        //      • delta[d]  = numeric diff (new − old) when change[d] is true; else null.
        $result = [];
        foreach ($items as $name) {
            $row = [
                'item_name' => $name,
                'prices'    => array_fill(1, $days, null),
                'editable'  => array_fill(1, $days, false),
                'anchor'    => array_fill(1, $days, false),
                'change'    => array_fill(1, $days, false),
                'delta'     => array_fill(1, $days, null),
            ];

            $byItem = ($cogs->get($name) ?? collect())->values(); // sorted asc by date
            // Index per-item cogs rows by Y-m-d so we can mark anchor cells.
            $cogsByDate = [];
            foreach ($byItem as $c) {
                $cogsByDate[$c->date->toDateString()] = $c->unit_cost;
            }
            $k = 0;
            $lastKnown = null;
            $prevDayDisplayed = null;

            for ($d = 1; $d <= $days; $d++) {
                $currDate = $start->copy()->day($d)->toDateString();
                $isPresent = isset($presence[$name][$d]);

                while ($k < $byItem->count() && $byItem[$k]->date->toDateString() <= $currDate) {
                    $lastKnown = $byItem[$k]->unit_cost;
                    $k++;
                }

                $row['prices'][$d]   = $isPresent ? $lastKnown : null;
                $row['editable'][$d] = $isPresent;
                // Anchor: explicit row na exactly equal sa current day.
                $row['anchor'][$d]   = $isPresent && array_key_exists($currDate, $cogsByDate);

                // Change marker: this day's displayed price ≠ previous editable day's.
                if ($isPresent && $lastKnown !== null) {
                    if ($prevDayDisplayed !== null && abs((float)$prevDayDisplayed - (float)$lastKnown) > 0.001) {
                        $row['change'][$d] = true;
                        $row['delta'][$d]  = (float)$lastKnown - (float)$prevDayDisplayed;
                    }
                    $prevDayDisplayed = $lastKnown;
                }
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
     * DELETE ONE COGS ROW
     * - Removes the explicit cogs entry for (item, date).
     * - That day will inherit from the prior cogs row via carry-forward.
     * - Use when a manually-set anchor is redundant (same value as prior day).
     */
    public function delete(Request $req) {
        $data = $req->validate([
            'item_name' => 'required|string',
            'date'      => 'required|date',
        ]);
        $date = Carbon::parse($data['date'])->toDateString();
        $item = trim($data['item_name']);
        if ($item === '') {
            return response()->json(['ok' => false, 'error' => 'Blank item name not allowed.'], 422);
        }

        $deleted = Cogs::where('item_name', $item)->whereDate('date', $date)->delete();

        return response()->json([
            'ok'      => true,
            'deleted' => $deleted,
        ]);
    }

    /**
     * BULK DELETE REDUNDANT ANCHORS
     * - Scope: cogs rows within [month_start, month_end] for items that have
     *   at least one row in that range.
     * - "Redundant" = the row's unit_cost equals the prior cogs entry's value
     *   (whether that prior entry is within the month or earlier). Deleting
     *   doesn't change the displayed/inherited value for that day — it just
     *   removes the no-op explicit row.
     * - Modes:
     *     ?preview=1  → returns the count without deleting (for confirm UI)
     *     (default)   → actually deletes + returns the count
     */
    public function cleanRedundant(Request $req) {
        $data = $req->validate([
            'month'   => 'required|date_format:Y-m',
            'preview' => 'nullable|in:0,1',
        ]);
        $preview = !empty($data['preview']);

        $month = Carbon::parse($data['month'] . '-01');
        $start = $month->copy()->startOfMonth()->toDateString();
        $end   = $month->copy()->endOfMonth()->toDateString();

        // Items na may at least one row sa visible month — limits the scan.
        $items = Cogs::whereBetween('date', [$start, $end])
            ->select('item_name')->distinct()->pluck('item_name');

        $toDelete = [];
        foreach ($items as $item) {
            // Baseline = latest cogs row BEFORE the month — yan ang inheritance
            // value coming into the month. Used to detect first-day redundancy.
            $baseline = Cogs::where('item_name', $item)
                ->where('date', '<', $start)
                ->orderByDesc('date')
                ->first(['unit_cost']);
            $prev = $baseline ? (float) $baseline->unit_cost : null;

            // Walk through this month's rows in date order, marking redundant.
            $rows = Cogs::where('item_name', $item)
                ->whereBetween('date', [$start, $end])
                ->orderBy('date')
                ->get(['id', 'unit_cost']);

            foreach ($rows as $r) {
                if ($prev !== null && abs($prev - (float)$r->unit_cost) < 0.001) {
                    $toDelete[] = $r->id;
                    // prev stays same — the value didn't actually change
                } else {
                    $prev = (float) $r->unit_cost;
                }
            }
        }

        $count = count($toDelete);
        if (!$preview && $count > 0) {
            Cogs::whereIn('id', $toDelete)->delete();
        }

        return response()->json([
            'ok'             => true,
            'preview'        => $preview,
            'deleted'        => $preview ? 0 : $count,
            'redundant_found'=> $count,
            'items_scanned'  => $items->count(),
            'month'          => $month->format('Y-m'),
        ]);
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
