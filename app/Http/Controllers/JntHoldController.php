<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class JntHoldController extends Controller
{
    private const MO_TABLE       = 'macro_output';
    private const FJ_TABLE       = 'from_jnts';
    private const MO_ITEM_COL    = 'ITEM_NAME';      // uppercase, quoted per-engine
    private const MO_WAYBILL_COL = 'waybill';
    private const FJ_WAYBILL_COL = 'waybill_number';

    public function index(Request $request)
    {
        $q            = trim((string) $request->input('q', ''));
        $includeBlank = (bool) $request->boolean('include_blank', false);
        $perDate      = (bool) $request->boolean('per_date', false);
        $driver       = DB::getDriverName();                 // 'mysql' or 'pgsql'
        $likeOp       = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';

        // View toggle
        $group = $request->input('group');
        if (!in_array($group, ['item_name', 'item', 'page'], true)) {
            $group = 'item_name';
        }

        // Date filters (main)
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

        // "Within N days (as-of)" filter
        $lookbackDays = (int) $request->input('lookback_days', 3);
        if ($lookbackDays < 1) $lookbackDays = 1;
        $asOfInput    = trim((string) $request->input('as_of_date', ''));
        $asOfDate     = $asOfInput !== '' ? Carbon::createFromFormat('Y-m-d', $asOfInput) : Carbon::today();
        $withinStart  = $asOfDate->copy()->subDays($lookbackDays)->startOfDay(); // as-of - N days
        $withinEnd    = $asOfDate->copy()->subDay()->endOfDay();                // as-of - 1 day
        $asOfDateStr  = $asOfDate->toDateString();

        $mo = self::MO_TABLE . ' as mo';
        $fj = self::FJ_TABLE . ' as fj';

        // Quoted refs for PG/MySQL
        $moItemRef    = $driver === 'pgsql' ? 'mo."' . self::MO_ITEM_COL . '"' : 'mo.`' . self::MO_ITEM_COL . '`';
        $moWaybillRef = $driver === 'pgsql' ? 'mo."' . self::MO_WAYBILL_COL . '"' : 'mo.`' . self::MO_WAYBILL_COL . '`';
        $moPageRef    = $driver === 'pgsql' ? 'mo."PAGE"' : 'mo.`PAGE`';
        $moTsCol      = $driver === 'pgsql' ? 'mo."TIMESTAMP"' : 'mo.`TIMESTAMP`';

        // Parse TIMESTAMP like "21:44 09-06-2025"
        $tsExpr = $driver === 'pgsql'
            ? "to_timestamp($moTsCol, 'HH24:MI DD-MM-YYYY')"
            : "STR_TO_DATE($moTsCol, '%H:%i %d-%m-%Y')";
        $dateExpr = $driver === 'pgsql'
            ? "to_timestamp($moTsCol, 'HH24:MI DD-MM-YYYY')::date"
            : "DATE(STR_TO_DATE($moTsCol, '%H:%i %d-%m-%Y'))";

        // -----------------------------------------------
        // 1) HOLD base query (rows considered "hold")
        // -----------------------------------------------
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
            $base->where(function ($w) use ($q, $likeOp, $moItemRef, $moWaybillRef, $moPageRef) {
                $w->whereRaw("$moItemRef $likeOp ?", ["%{$q}%"])
                  ->orWhereRaw("$moWaybillRef $likeOp ?", ["%{$q}%"])
                  ->orWhereRaw("$moPageRef $likeOp ?", ["%{$q}%"]);
            });
        }

        // Overall holds count (unchanged)
        $holdsCount = (clone $base)->count('mo.' . self::MO_WAYBILL_COL);

        // -----------------------------------------------
        // 2) LABEL UNIVERSE (para isama pati zero-hold rows)
        // -----------------------------------------------
        $universe = collect(); // labels (page | item_name | item base)
        if ($group === 'page') {
            $uv = DB::table($mo)->selectRaw("$moPageRef as label");
            if ($startAt && $endAt) $uv->whereBetween(DB::raw($tsExpr), [$startAt, $endAt]);
            if ($q !== '') {
                $uv->where(function ($w) use ($q, $likeOp, $moItemRef, $moWaybillRef, $moPageRef) {
                    $w->whereRaw("$moItemRef $likeOp ?", ["%{$q}%"])
                      ->orWhereRaw("$moWaybillRef $likeOp ?", ["%{$q}%"])
                      ->orWhereRaw("$moPageRef $likeOp ?", ["%{$q}%"]);
                });
            }
            $universe = $uv->groupByRaw($moPageRef)
                           ->pluck('label')
                           ->map(fn($v) => (string) $v)
                           ->filter(fn($v) => $v !== '')
                           ->unique()->sort()->values();
        } else {
            $uv = DB::table($mo)->selectRaw("$moItemRef as label");
            if ($startAt && $endAt) $uv->whereBetween(DB::raw($tsExpr), [$startAt, $endAt]);
            if ($q !== '') {
                $uv->where(function ($w) use ($q, $likeOp, $moItemRef, $moWaybillRef, $moPageRef) {
                    $w->whereRaw("$moItemRef $likeOp ?", ["%{$q}%"])
                      ->orWhereRaw("$moWaybillRef $likeOp ?", ["%{$q}%"])
                      ->orWhereRaw("$moPageRef $likeOp ?", ["%{$q}%"]);
                });
            }
            $rawNames = $uv->groupByRaw($moItemRef)
                           ->pluck('label')
                           ->map(fn($v) => (string) $v);

            if ($group === 'item_name') {
                $universe = $rawNames->filter(fn($v) => $v !== '')->unique()->sort()->values();
            } else { // 'item' (units) → strip quantity prefix to base item
                $baseSet = [];
                foreach ($rawNames as $name) {
                    $name = trim($name);
                    if ($name === '') { $baseName = '—'; }
                    elseif (preg_match('/^\s*(\d+)\s*[x×]\s*(.+)$/iu', $name, $m)) { $baseName = trim($m[2]); }
                    else { $baseName = $name; }
                    $baseSet[$baseName] = true;
                }
                $universe = collect(array_keys($baseSet))->sort()->values();
            }
        }

        // -----------------------------------------------
        // 3) HOLD aggregations (existing)
        // -----------------------------------------------
        $byItemName = (clone $base)->select([
                DB::raw("$moItemRef as item_name"),
                DB::raw('COUNT(*) as hold_count'),
            ])
            ->groupBy(DB::raw($moItemRef))
            ->get();

        $byPage = (clone $base)
            ->selectRaw("$moPageRef as page, COUNT(*) as hold_count")
            ->groupByRaw($moPageRef)
            ->get();

        // Maps for quick lookup
        $holdMapByItemName = [];
        foreach ($byItemName as $r) $holdMapByItemName[(string)($r->item_name ?? '')] = (int)$r->hold_count;

        $holdMapByPage = [];
        foreach ($byPage as $r) $holdMapByPage[(string)($r->page ?? '')] = (int)$r->hold_count;

        // Prepare $byItem aligned with universe (include zeros) — unsorted here
        if ($group === 'item') {
            // Convert item_name-based counts to base item (units)
            $unitsMap = [];
            foreach ($byItemName as $r) {
                $name  = trim((string)($r->item_name ?? ''));
                $count = (int)$r->hold_count;
                if ($name === '') { $baseName = '—'; $qty = 1; }
                elseif (preg_match('/^\s*(\d+)\s*[x×]\s*(.+)$/iu', $name, $m)) { $qty = max(1, (int)$m[1]); $baseName = trim($m[2]); }
                else { $qty = 1; $baseName = $name; }
                $unitsMap[$baseName] = ($unitsMap[$baseName] ?? 0) + ($count * $qty);
            }

            $byItem = $universe->map(function ($label) use ($unitsMap) {
                return (object)[
                    'item'       => $label,
                    'need_units' => (int)($unitsMap[$label] ?? 0),
                ];
            })->values();
        } elseif ($group === 'page') {
            $byItem = $universe->map(function ($label) use ($holdMapByPage) {
                return (object)[
                    'page'       => $label,
                    'hold_count' => (int)($holdMapByPage[$label] ?? 0),
                ];
            })->values();
        } else { // item_name
            $byItem = $universe->map(function ($label) use ($holdMapByItemName) {
                return (object)[
                    'item_name'  => $label,
                    'hold_count' => (int)($holdMapByItemName[$label] ?? 0),
                ];
            })->values();
        }

        // -----------------------------------------------
        // 4) "Within N days" (actual counts, no waybill logic)
        // -----------------------------------------------
        $recentMap   = [];  // label => count (or units for 'item')
        $recentGrand = 0;

        $recentBase = DB::table($mo)
            ->whereBetween(DB::raw($tsExpr), [
                $withinStart->format('Y-m-d H:i:s'),
                $withinEnd->format('Y-m-d H:i:s')
            ]);

        if ($q !== '') {
            $recentBase->where(function ($w) use ($q, $likeOp, $moItemRef, $moWaybillRef, $moPageRef) {
                $w->whereRaw("$moItemRef $likeOp ?", ["%{$q}%"])
                  ->orWhereRaw("$moWaybillRef $likeOp ?", ["%{$q}%"])
                  ->orWhereRaw("$moPageRef $likeOp ?", ["%{$q}%"]);
            });
        }

        if ($group === 'page') {
            $rows = (clone $recentBase)
                ->selectRaw("$moPageRef as label, COUNT(*) as c")
                ->groupByRaw($moPageRef)->get();
            foreach ($rows as $r) {
                $recentMap[(string)($r->label ?? '')] = (int)$r->c;
            }
        } elseif ($group === 'item_name') {
            $rows = (clone $recentBase)
                ->selectRaw("$moItemRef as label, COUNT(*) as c")
                ->groupByRaw($moItemRef)->get();
            foreach ($rows as $r) {
                $recentMap[(string)($r->label ?? '')] = (int)$r->c;
            }
        } else { // 'item' (units)
            $rows = (clone $recentBase)
                ->selectRaw("$moItemRef as item_name, COUNT(*) as c")
                ->groupByRaw($moItemRef)->get();
            $agg = [];
            foreach ($rows as $r) {
                $name = trim((string)($r->item_name ?? ''));
                $cnt  = (int)$r->c;
                if ($name === '') { $baseName = '—'; $qty = 1; }
                elseif (preg_match('/^\s*(\d+)\s*[x×]\s*(.+)$/iu', $name, $m)) { $qty = max(1, (int)$m[1]); $baseName = trim($m[2]); }
                else { $qty = 1; $baseName = $name; }
                $agg[$baseName] = ($agg[$baseName] ?? 0) + ($cnt * $qty);
            }
            $recentMap = $agg;
        }
        $recentGrand = array_sum($recentMap);

        // -----------------------------------------------
        // 5) SORT non-per-date view using:
        //    (Total + WithinNd) desc, then Label asc
        // -----------------------------------------------
        if ($group === 'item') {
            $arr = $byItem->all();
            usort($arr, function($a, $b) use ($recentMap) {
                $sa = (int)$a->need_units + (int)($recentMap[$a->item] ?? 0);
                $sb = (int)$b->need_units + (int)($recentMap[$b->item] ?? 0);
                if ($sa !== $sb) return $sb <=> $sa;      // sum desc
                return strcmp($a->item, $b->item);        // tie: label asc
            });
            $byItem = collect($arr);
        } elseif ($group === 'page') {
            $arr = $byItem->all();
            usort($arr, function($a, $b) use ($recentMap) {
                $sa = (int)$a->hold_count + (int)($recentMap[$a->page] ?? 0);
                $sb = (int)$b->hold_count + (int)($recentMap[$b->page] ?? 0);
                if ($sa !== $sb) return $sb <=> $sa;      // sum desc
                return strcmp($a->page, $b->page);        // tie: label asc
            });
            $byItem = collect($arr);
        } else { // item_name
            $arr = $byItem->all();
            usort($arr, function($a, $b) use ($recentMap) {
                $sa = (int)$a->hold_count + (int)($recentMap[$a->item_name] ?? 0);
                $sb = (int)$b->hold_count + (int)($recentMap[$b->item_name] ?? 0);
                if ($sa !== $sb) return $sb <=> $sa;          // sum desc
                return strcmp($a->item_name, $b->item_name);  // tie: label asc
            });
            $byItem = collect($arr);
        }

        $itemsWithHoldsCount = $byItem->count(); // includes zero rows

        // -----------------------------------------------
        // 6) PER-DATE PIVOT (expand with universe, include zeros)
        //    + sort with same sum rule
        // -----------------------------------------------
        $pivotRows   = collect();
        $dateKeys    = [];
        $colTotals   = [];
        $grandTotal  = 0;
        $monthGroups = [];
        $dayLabels   = [];

        if ($perDate) {
            if ($startAt && $endAt) {
                $cur = Carbon::parse($startAt)->startOfDay();
                $end = Carbon::parse($endAt)->endOfDay();
                while ($cur->lte($end)) { $dateKeys[] = $cur->toDateString(); $cur->addDay(); }
            }

            if ($group === 'page') {
                $rows = (clone $base)
                    ->selectRaw("$moPageRef as label, $dateExpr as d, COUNT(*) as c")
                    ->groupByRaw("$moPageRef, $dateExpr")->get();
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
                // ensure zeros for universe labels
                foreach ($universe as $label) {
                    if (!isset($matrix[$label])) $matrix[$label] = ['label' => $label, 'dates' => []];
                }
                $pivot = [];
                foreach ($matrix as $item) {
                    $total = 0; $row = ['label' => $item['label'], 'dates' => []];
                    foreach ($dateKeys as $dk) {
                        $v = (int)($item['dates'][$dk] ?? 0);
                        $row['dates'][$dk] = $v; $total += $v; $colTotals[$dk] = ($colTotals[$dk] ?? 0) + $v;
                    }
                    $row['total'] = $total; $grandTotal += $total; $pivot[] = $row;
                }
                // apply sum sort
                usort($pivot, function($a, $b) use ($recentMap) {
                    $sa = (int)$a['total'] + (int)($recentMap[$a['label']] ?? 0);
                    $sb = (int)$b['total'] + (int)($recentMap[$b['label']] ?? 0);
                    if ($sa !== $sb) return $sb <=> $sa;      // sum desc
                    return strcmp($a['label'], $b['label']);  // tie: label asc
                });
                $pivotRows = collect($pivot);
            } else {
                $rows = (clone $base)
                    ->selectRaw("$moItemRef as item_name, $dateExpr as d, COUNT(*) as c")
                    ->groupByRaw("$moItemRef, $dateExpr")->get();

                if (!$dateKeys) {
                    $dateKeys = $rows->pluck('d')->map(fn($d)=>Carbon::parse($d)->toDateString())->unique()->sort()->values()->all();
                }

                if ($group === 'item_name') {
                    $matrix = [];
                    foreach ($rows as $r) {
                        $label = (string)($r->item_name ?? '—');
                        $dkey  = Carbon::parse($r->d)->toDateString(); $c = (int)$r->c;
                        $matrix[$label]['label'] = $label;
                        $matrix[$label]['dates'][$dkey] = ($matrix[$label]['dates'][$dkey] ?? 0) + $c;
                    }
                    foreach ($universe as $label) {
                        if (!isset($matrix[$label])) $matrix[$label] = ['label'=>$label,'dates'=>[]];
                    }

                    $pivot = [];
                    foreach ($matrix as $item) {
                        $total = 0; $row = ['label' => $item['label'], 'dates' => []];
                        foreach ($dateKeys as $dk) {
                            $v = (int)($item['dates'][$dk] ?? 0);
                            $row['dates'][$dk] = $v; $total += $v; $colTotals[$dk] = ($colTotals[$dk] ?? 0) + $v;
                        }
                        $row['total'] = $total; $grandTotal += $total; $pivot[] = $row;
                    }
                    usort($pivot, function($a, $b) use ($recentMap) {
                        $sa = (int)$a['total'] + (int)($recentMap[$a['label']] ?? 0);
                        $sb = (int)$b['total'] + (int)($recentMap[$b['label']] ?? 0);
                        if ($sa !== $sb) return $sb <=> $sa;
                        return strcmp($a['label'], $b['label']);
                    });
                    $pivotRows = collect($pivot);
                } else { // group === 'item' (units)
                    $matrix = [];
                    foreach ($rows as $r) {
                        $name = trim((string)($r->item_name ?? ''));
                        $dkey = Carbon::parse($r->d)->toDateString();
                        $cnt  = (int)$r->c;
                        if ($name === '') { $baseName = '—'; $qty = 1; }
                        elseif (preg_match('/^\s*(\d+)\s*[x×]\s*(.+)$/iu', $name, $m)) { $qty = max(1,(int)$m[1]); $baseName = trim($m[2]); }
                        else { $qty = 1; $baseName = $name; }
                        $units = $cnt * $qty;
                        $matrix[$baseName]['label'] = $baseName;
                        $matrix[$baseName]['dates'][$dkey] = ($matrix[$baseName]['dates'][$dkey] ?? 0) + $units;
                    }
                    foreach ($universe as $label) {
                        if (!isset($matrix[$label])) $matrix[$label] = ['label'=>$label,'dates'=>[]];
                    }

                    $pivot = [];
                    foreach ($matrix as $item) {
                        $total = 0; $row = ['label' => $item['label'], 'dates' => []];
                        foreach ($dateKeys as $dk) {
                            $v = (int)($item['dates'][$dk] ?? 0);
                            $row['dates'][$dk] = $v; $total += $v; $colTotals[$dk] = ($colTotals[$dk] ?? 0) + $v;
                        }
                        $row['total'] = $total; $grandTotal += $total; $pivot[] = $row;
                    }
                    usort($pivot, function($a, $b) use ($recentMap) {
                        $sa = (int)$a['total'] + (int)($recentMap[$a['label']] ?? 0);
                        $sb = (int)$b['total'] + (int)($recentMap[$b['label']] ?? 0);
                        if ($sa !== $sb) return $sb <=> $sa;
                        return strcmp($a['label'], $b['label']);
                    });
                    $pivotRows = collect($pivot);
                }
            }

            // Header helpers
            foreach ($dateKeys as $dk) {
                $c = Carbon::parse($dk);
                $mKey = $c->format('Y-m');
                $monthGroups[$mKey] = $monthGroups[$mKey] ?? ['label' => $c->format('F Y'), 'count' => 0];
                $monthGroups[$mKey]['count']++;
                $dayLabels[$dk] = (int)$c->format('j');
            }
        }

        return view('jnt.hold', compact(
            'byItem', 'holdsCount', 'q', 'includeBlank',
            'uiDate', 'rangeSta', 'rangeEnd', 'dateRange', 'group',
            'itemsWithHoldsCount', 'perDate', 'pivotRows', 'dateKeys',
            'colTotals', 'grandTotal', 'monthGroups', 'dayLabels',
            // NEW:
            'lookbackDays', 'asOfDateStr', 'recentMap', 'recentGrand'
        ));
    }
}
