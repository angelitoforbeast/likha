<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Orders-per-item (per date) report — /macro/orders.
 *
 * Counts ALL orders (rows sa macro_output) per BASE item name — tinatanggal ang
 * "N x" quantity prefix at SINUSUMA ang ORDER COUNT (hindi units / hindi ×qty):
 *
 *   "1 x FLASHLIGHT" = 5 orders  +  "2 x FLASHLIGHT" = 2 orders  →  FLASHLIGHT = 7
 *
 * Pivot per date. Default range = last 7 days EXCLUDING today. Rightmost columns
 * = Total + Average (per day).
 */
class MacroOrdersController extends Controller
{
    private const MO_TABLE    = 'macro_output';
    private const MO_ITEM_COL = 'ITEM_NAME';   // uppercase, quoted per-engine

    public function index(Request $request)
    {
        $tz = 'Asia/Manila';

        // Fresh visit (walang params) → default last 7 days EXCLUDING today
        // (today-7 .. today-1 = kahapon pabalik ng 7 araw).
        if (!$request->hasAny(['date_range', 'q', 'by'])) {
            $start = Carbon::now($tz)->subDays(7)->toDateString();
            $end   = Carbon::now($tz)->subDay()->toDateString();
            return redirect()->route('macro.orders', [
                'q'          => '',
                'date_range' => $start . ' to ' . $end,
            ]);
        }

        $q      = trim((string) $request->input('q', ''));
        // Count by raw item name (default) o by ALIAS (merge variants na iisa ang alias).
        $by     = $request->input('by') === 'alias' ? 'alias' : 'name';
        $driver = DB::getDriverName();                 // 'mysql' | 'pgsql'
        $likeOp = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';

        // ── Date range (default last 7 days excl today kapag invalid/wala) ──────
        $dateRange = trim((string) $request->input('date_range', ''));
        $startStr = $endStr = null;
        if ($dateRange !== '') {
            $parts    = preg_split('/\s+(?:to|-)\s+/i', $dateRange);
            $startStr = $parts[0] ?? null;
            $endStr   = $parts[1] ?? $parts[0] ?? null;
        }
        $validDate = fn ($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
        if (!$validDate($startStr) || !$validDate($endStr)) {
            $startStr = Carbon::now($tz)->subDays(7)->toDateString();
            $endStr   = Carbon::now($tz)->subDay()->toDateString();
        }
        if ($startStr > $endStr) { [$startStr, $endStr] = [$endStr, $startStr]; }

        $startAt = Carbon::createFromFormat('Y-m-d', $startStr)->startOfDay()->format('Y-m-d H:i:s');
        $endAt   = Carbon::createFromFormat('Y-m-d', $endStr)->endOfDay()->format('Y-m-d H:i:s');

        // Quoted refs + TIMESTAMP parsing ("HH:MM DD-MM-YYYY"), tugma sa JntHold.
        $moItemRef = $driver === 'pgsql' ? 'mo."' . self::MO_ITEM_COL . '"' : 'mo.`' . self::MO_ITEM_COL . '`';
        $moPageRef = $driver === 'pgsql' ? 'mo."PAGE"' : 'mo.`PAGE`';
        $moTsCol   = $driver === 'pgsql' ? 'mo."TIMESTAMP"' : 'mo.`TIMESTAMP`';
        $tsExpr    = $driver === 'pgsql'
            ? "to_timestamp($moTsCol, 'HH24:MI DD-MM-YYYY')"
            : "STR_TO_DATE($moTsCol, '%H:%i %d-%m-%Y')";
        $dateExpr  = $driver === 'pgsql'
            ? "to_timestamp($moTsCol, 'HH24:MI DD-MM-YYYY')::date"
            : "DATE(STR_TO_DATE($moTsCol, '%H:%i %d-%m-%Y'))";

        // ── Base query — LAHAT ng orders (WALANG hold filter / from_jnts join) ──
        $baseQ = DB::table(self::MO_TABLE . ' as mo')
            ->whereBetween(DB::raw($tsExpr), [$startAt, $endAt]);
        if ($q !== '') {
            $baseQ->where(function ($w) use ($q, $likeOp, $moItemRef, $moPageRef) {
                $w->whereRaw("$moItemRef $likeOp ?", ["%{$q}%"])
                  ->orWhereRaw("$moPageRef $likeOp ?", ["%{$q}%"]);
            });
        }

        // Per (item_name, date) order counts.
        $rows = (clone $baseQ)
            ->selectRaw("$moItemRef as item_name, $dateExpr as d, COUNT(*) as c")
            ->groupByRaw("$moItemRef, $dateExpr")
            ->get();

        // Date columns — bawat araw sa range (ascending).
        $dateKeys = [];
        $cur = Carbon::parse($startStr)->startOfDay();
        $lim = Carbon::parse($endStr)->startOfDay();
        while ($cur->lte($lim)) { $dateKeys[] = $cur->toDateString(); $cur->addDay(); }
        $numDays = max(1, count($dateKeys));

        // ── Strip "N x" prefix → base name; SUMmin ang ORDER count (HINDI ×qty) ──
        // by=alias → pagsamahin ang variants na iisa ang canonical alias (ItemAliasResolver).
        // Sa likha (walang mappings) ang canonicalKey == base name, kaya walang epekto.
        $aliases = $by === 'alias' ? new \App\Services\ItemAliasResolver() : null;
        $matrix  = [];
        foreach ($rows as $r) {
            $name = trim((string) ($r->item_name ?? ''));
            if ($name === '') {
                $baseName = '—';
            } elseif (preg_match('/^\s*(\d+)\s*[x×]\s*(.+)$/iu', $name, $m)) {
                $baseName = trim($m[2]);     // tanggalin ang "N x" → base item name
            } else {
                $baseName = $name;
            }
            // Group key + display label: by alias (canonical) o by raw base name.
            if ($aliases) {
                $key   = $aliases->canonicalKey($baseName);
                $label = $aliases->canonicalLabel($baseName);
                if ($key === '') { $key = $baseName; $label = $baseName; }
            } else {
                $key = $baseName; $label = $baseName;
            }
            $dkey = Carbon::parse($r->d)->toDateString();
            if (!isset($matrix[$key])) $matrix[$key] = ['label' => $label, 'dates' => []];
            $matrix[$key]['dates'][$dkey] = ($matrix[$key]['dates'][$dkey] ?? 0) + (int) $r->c;
        }

        // Pivot rows + per-row Total + Average; column totals.
        $colTotals  = [];
        $grandTotal = 0;
        $pivot      = [];
        foreach ($matrix as $item) {
            $total  = 0;
            $active = 0;   // bilang ng araw na MAY order (>0) — denominator ng average
            $row = ['label' => $item['label'], 'dates' => []];
            foreach ($dateKeys as $dk) {
                $v = (int) ($item['dates'][$dk] ?? 0);
                $row['dates'][$dk] = $v;
                $total += $v;
                if ($v > 0) $active++;
                $colTotals[$dk] = ($colTotals[$dk] ?? 0) + $v;
            }
            $row['total'] = $total;
            // Average = Total ÷ ACTIVE days (araw na may order), HINDI lahat ng araw.
            $row['avg']         = $active > 0 ? round($total / $active, 1) : 0;
            $row['active_days'] = $active;
            $grandTotal        += $total;
            $pivot[] = $row;
        }
        usort($pivot, function ($a, $b) {
            if ($a['total'] !== $b['total']) return $b['total'] <=> $a['total'];   // total desc
            return strcmp($a['label'], $b['label']);                              // tie: label asc
        });
        $pivotRows = collect($pivot);

        // Header month/day labels.
        $monthGroups = [];
        $dayLabels   = [];
        foreach ($dateKeys as $dk) {
            $c = Carbon::parse($dk);
            $mKey = $c->format('Y-m');
            $monthGroups[$mKey] = $monthGroups[$mKey] ?? ['label' => $c->format('F Y'), 'count' => 0];
            $monthGroups[$mKey]['count']++;
            $dayLabels[$dk] = (int) $c->format('j');
        }
        // Grand average = grandTotal ÷ araw na may kahit anong order (active days).
        $gActive = 0;
        foreach ($dateKeys as $dk) { if ((int) ($colTotals[$dk] ?? 0) > 0) $gActive++; }
        $grandAvg = $gActive > 0 ? round($grandTotal / $gActive, 1) : 0;

        return view('macro.orders', compact(
            'q', 'by', 'startStr', 'endStr', 'pivotRows', 'dateKeys',
            'colTotals', 'grandTotal', 'grandAvg', 'monthGroups', 'dayLabels', 'numDays'
        ));
    }
}
