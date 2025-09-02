<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class JntHoldController extends Controller
{
    private const MO_TABLE       = 'macro_output';
    private const FJ_TABLE       = 'from_jnts';
    private const MO_ITEM_COL    = 'item_name';
    private const MO_WAYBILL_COL = 'waybill';
    private const FJ_WAYBILL_COL = 'waybill_number'; // from_jnts column

    public function index(Request $request)
    {
        $q            = trim((string) $request->input('q', ''));
        $includeBlank = (bool) $request->boolean('include_blank', false);
        $perDate      = (bool) $request->boolean('per_date', false);
        $driver       = DB::getDriverName();                 // 'mysql' or 'pgsql'
        $likeOp       = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';

        // View toggle: item_name (raw) | item (units) | page
        $group = $request->input('group');
        if (!in_array($group, ['item_name', 'item', 'page'], true)) {
            $group = 'item_name'; // default
        }

        // Date inputs
        $dateRange = trim((string) $request->input('date_range', ''));
        $uiDate    = $request->input('date');
        $rangeSta  = $request->input('start');
        $rangeEnd  = $request->input('end');

        $startAt = $endAt = null;

        if ($dateRange !== '') {
            $parts    = preg_split('/\s+(?:to|-)\s+/i', $dateRange);
            $startStr = $parts[0] ?? null;
            $endStr   = $parts[1] ?? $parts[0] ?? null;

            if ($startStr) $startAt = Carbon::createFromFormat('Y-m-d', $startStr)->startOfDay()->format('Y-m-d H:i:s');
            if ($endStr)   $endAt   = Carbon::createFromFormat('Y-m-d', $endStr)->endOfDay()->format('Y-m-d H:i:s');

            $rangeSta = $parts[0] ?? null;
            $rangeEnd = $parts[1] ?? $parts[0] ?? null;
        } elseif ($rangeSta && $rangeEnd) {
            $startAt = Carbon::parse($rangeSta)->startOfDay()->format('Y-m-d H:i:s');
            $endAt   = Carbon::parse($rangeEnd)->endOfDay()->format('Y-m-d H:i:s');
        } elseif ($uiDate) {
            $startAt  = Carbon::parse($uiDate)->startOfDay()->format('Y-m-d H:i:s');
            $endAt    = Carbon::parse($uiDate)->endOfDay()->format('Y-m-d H:i:s');
            $rangeSta = $uiDate;
            $rangeEnd = $uiDate;
        }

        $mo = self::MO_TABLE . ' as mo';
        $fj = self::FJ_TABLE . ' as fj';

        // Properly-quoted column refs for PG/MySQL
        $tsCol   = $driver === 'pgsql' ? 'mo."TIMESTAMP"' : 'mo.`TIMESTAMP`';
        $pageCol = $driver === 'pgsql' ? 'mo."PAGE"'      : 'mo.`PAGE`';

        // Parse mo.TIMESTAMP like "21:44 09-06-2025" (HH:MM DD-MM-YYYY)
        $tsExpr = $driver === 'pgsql'
            ? "to_timestamp($tsCol, 'HH24:MI DD-MM-YYYY')"
            : "STR_TO_DATE($tsCol, '%H:%i %d-%m-%Y')";
        $dateExpr = $driver === 'pgsql'
            ? "to_timestamp($tsCol, 'HH24:MI DD-MM-YYYY')::date"
            : "DATE(STR_TO_DATE($tsCol, '%H:%i %d-%m-%Y'))";

        // HOLD base query (waybill missing in from_jnts)
        $base = DB::table($mo)
            ->leftJoin($fj, 'fj.' . self::FJ_WAYBILL_COL, '=', 'mo.' . self::MO_WAYBILL_COL)
            ->whereNull('fj.' . self::FJ_WAYBILL_COL);

        if (!$includeBlank) {
            $base->whereRaw("NULLIF(TRIM(mo." . self::MO_WAYBILL_COL . "), '') IS NOT NULL");
        }

        if ($startAt && $endAt) {
            $base->whereBetween(DB::raw($tsExpr), [$startAt, $endAt]);
        }

        if ($q !== '') {
            $base->where(function ($w) use ($q, $likeOp, $pageCol) {
                $w->where('mo.' . self::MO_ITEM_COL, $likeOp, "%{$q}%")
                  ->orWhere('mo.' . self::MO_WAYBILL_COL, $likeOp, "%{$q}%")
                  ->orWhereRaw("$pageCol $likeOp ?", ["%{$q}%"]); // search PAGE too
            });
        }

        // Totals (overall)
        $holdsCount = (clone $base)->count('mo.' . self::MO_WAYBILL_COL);

        // Precompute: by item_name (raw)
        $byItemName = (clone $base)->select([
                'mo.' . self::MO_ITEM_COL . ' as item_name',
                DB::raw('COUNT(*) as hold_count'),
            ])
            ->groupBy('mo.' . self::MO_ITEM_COL)
            ->orderByDesc('hold_count')
            ->orderBy('item_name')
            ->get();

        // Precompute: by page
        $byPage = (clone $base)
            ->selectRaw("$pageCol as page, COUNT(*) as hold_count")
            ->groupByRaw($pageCol)
            ->orderByDesc('hold_count')
            ->orderBy('page')
            ->get();

        // Decide main dataset for non-per-date view
        if ($group === 'item') {
            // Normalize ITEM NAME to base item and compute needed units
            $agg = [];
            foreach ($byItemName as $row) {
                $name = trim((string)($row->item_name ?? ''));
                if ($name === '') {
                    $baseName = '—';
                    $qty = 1;
                } else {
                    if (preg_match('/^\s*(\d+)\s*[x×]\s*(.+)$/iu', $name, $m)) {
                        $qty = max(1, (int)$m[1]);
                        $baseName = trim($m[2]);
                    } else {
                        $qty = 1;
                        $baseName = $name;
                    }
                }
                $units = (int)$row->hold_count * $qty;
                $agg[$baseName] = ($agg[$baseName] ?? 0) + $units;
            }
            $byItem = collect($agg)
                ->map(fn($units, $item) => (object)['item' => $item, 'need_units' => (int)$units])
                ->sortBy([['need_units','desc'], ['item','asc']])
                ->values();
        } elseif ($group === 'page') {
            $byItem = $byPage;
        } else {
            $byItem = $byItemName;
        }

        $itemsWithHoldsCount = $byItem->count();

        // ---------- PER-DATE PIVOT (with month/day header) ----------
        $pivotRows   = collect();
        $dateKeys    = [];   // ['2025-08-01', ...]
        $colTotals   = [];   // date => total
        $grandTotal  = 0;
        $monthGroups = [];   // ['2025-08' => ['label'=>'August 2025','count'=>9], ...]
        $dayLabels   = [];   // ['2025-08-01' => 1, ...]

        if ($perDate) {
            // Build date keys from range if present
            if ($startAt && $endAt) {
                $cur = Carbon::parse($startAt)->startOfDay();
                $end = Carbon::parse($endAt)->endOfDay();
                while ($cur->lte($end)) {
                    $dateKeys[] = $cur->toDateString(); // Y-m-d
                    $cur->addDay();
                }
            }

            if ($group === 'page') {
                // Query per page per date
                $rows = (clone $base)
                    ->selectRaw("$pageCol as label, $dateExpr as d, COUNT(*) as c")
                    ->groupByRaw("$pageCol, $dateExpr")
                    ->get();
                if (!$dateKeys) {
                    $dateKeys = $rows->pluck('d')->map(fn($d)=>Carbon::parse($d)->toDateString())->unique()->sort()->values()->all();
                }
                $matrix = [];
                foreach ($rows as $r) {
                    $label = (string)($r->label ?? '—');
                    $dkey  = Carbon::parse($r->d)->toDateString();
                    $c     = (int)$r->c;
                    $matrix[$label]['label'] = $label;
                    $matrix[$label]['dates'][$dkey] = ($matrix[$label]['dates'][$dkey] ?? 0) + $c;
                }
                $pivot = [];
                foreach ($matrix as $item) {
                    $total = 0;
                    $row = ['label' => $item['label'], 'dates' => []];
                    foreach ($dateKeys as $dk) {
                        $v = (int)($item['dates'][$dk] ?? 0);
                        $row['dates'][$dk] = $v;
                        $total += $v;
                        $colTotals[$dk] = ($colTotals[$dk] ?? 0) + $v;
                    }
                    $row['total'] = $total;
                    $grandTotal += $total;
                    $pivot[] = $row;
                }
                usort($pivot, function($a,$b){
                    if ($a['total'] === $b['total']) return strcmp($a['label'], $b['label']);
                    return $b['total'] <=> $a['total'];
                });
                $pivotRows = collect($pivot);
            } else {
                // ITEM NAME basis per date
                $rows = (clone $base)
                    ->selectRaw("mo." . self::MO_ITEM_COL . " as item_name, $dateExpr as d, COUNT(*) as c")
                    ->groupBy('mo.' . self::MO_ITEM_COL)
                    ->groupBy(DB::raw($dateExpr))
                    ->get();

                if (!$dateKeys) {
                    $dateKeys = $rows->pluck('d')->map(fn($d)=>Carbon::parse($d)->toDateString())->unique()->sort()->values()->all();
                }

                if ($group === 'item_name') {
                    // Pivot raw item_name
                    $matrix = [];
                    foreach ($rows as $r) {
                        $label = (string)($r->item_name ?? '—');
                        $dkey  = Carbon::parse($r->d)->toDateString();
                        $c     = (int)$r->c;
                        $matrix[$label]['label'] = $label;
                        $matrix[$label]['dates'][$dkey] = ($matrix[$label]['dates'][$dkey] ?? 0) + $c;
                    }
                    $pivot = [];
                    foreach ($matrix as $item) {
                        $total = 0;
                        $row = ['label' => $item['label'], 'dates' => []];
                        foreach ($dateKeys as $dk) {
                            $v = (int)($item['dates'][$dk] ?? 0);
                            $row['dates'][$dk] = $v;
                            $total += $v;
                            $colTotals[$dk] = ($colTotals[$dk] ?? 0) + $v;
                        }
                        $row['total'] = $total;
                        $grandTotal += $total;
                        $pivot[] = $row;
                    }
                    usort($pivot, function($a,$b){
                        if ($a['total'] === $b['total']) return strcmp($a['label'], $b['label']);
                        return $b['total'] <=> $a['total'];
                    });
                    $pivotRows = collect($pivot);
                } else { // $group === 'item' (units)
                    // Transform to base item + units per date
                    $matrix = []; // [baseItem]['dates'][d] = units
                    foreach ($rows as $r) {
                        $name = trim((string)($r->item_name ?? ''));
                        $dkey = Carbon::parse($r->d)->toDateString();
                        $cnt  = (int)$r->c;

                        if ($name === '') {
                            $baseName = '—';
                            $qty = 1;
                        } else if (preg_match('/^\s*(\d+)\s*[x×]\s*(.+)$/iu', $name, $m)) {
                            $qty = max(1, (int)$m[1]);
                            $baseName = trim($m[2]);
                        } else {
                            $qty = 1;
                            $baseName = $name;
                        }

                        $units = $cnt * $qty;
                        $matrix[$baseName]['label'] = $baseName;
                        $matrix[$baseName]['dates'][$dkey] = ($matrix[$baseName]['dates'][$dkey] ?? 0) + $units;
                    }

                    $pivot = [];
                    foreach ($matrix as $item) {
                        $total = 0;
                        $row = ['label' => $item['label'], 'dates' => []];
                        foreach ($dateKeys as $dk) {
                            $v = (int)($item['dates'][$dk] ?? 0);
                            $row['dates'][$dk] = $v;
                            $total += $v;
                            $colTotals[$dk] = ($colTotals[$dk] ?? 0) + $v;
                        }
                        $row['total'] = $total;
                        $grandTotal += $total;
                        $pivot[] = $row;
                    }
                    usort($pivot, function($a,$b){
                        if ($a['total'] === $b['total']) return strcmp($a['label'], $b['label']);
                        return $b['total'] <=> $a['total'];
                    });
                    $pivotRows = collect($pivot);
                }
            }

            // Build month groups + day labels for the 2-row header
            foreach ($dateKeys as $dk) {
                $c = Carbon::parse($dk);
                $mKey = $c->format('Y-m');
                $monthGroups[$mKey] = $monthGroups[$mKey]
                    ?? ['label' => $c->format('F Y'), 'count' => 0];
                $monthGroups[$mKey]['count']++;
                $dayLabels[$dk] = (int)$c->format('j'); // 1..31
            }
        }

        return view('jnt.hold', compact(
            'byItem', 'byItemName', 'byPage', 'holdsCount', 'q', 'includeBlank',
            'uiDate', 'rangeSta', 'rangeEnd', 'dateRange', 'group',
            'itemsWithHoldsCount', 'perDate', 'pivotRows', 'dateKeys',
            'colTotals', 'grandTotal', 'monthGroups', 'dayLabels'
        ));
    }
}
