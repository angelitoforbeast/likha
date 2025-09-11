<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;

class SummaryOverallController extends Controller
{
    public function index()
    {
        $driver = DB::getDriverName();
        $trimFn = $driver === 'pgsql' ? 'BTRIM' : 'TRIM';

        $pages = DB::table('ads_manager_reports')
            ->whereNotNull('page_name')
            ->selectRaw("$trimFn(page_name) AS page_name")
            ->distinct()
            ->orderBy('page_name')
            ->pluck('page_name')
            ->toArray();

        // role detection
        $userRoleRaw    = Auth::user()?->employeeProfile?->role ?? '';
        $roleNorm       = preg_replace('/\s+/u', ' ', trim((string)$userRoleRaw));
        $isMarketingOIC = preg_match('/^marketing\s*[-–—]\s*oic$/iu', $roleNorm) === 1;
        $isCEO          = preg_match('/^ceo$/iu', $roleNorm) === 1;

        return view('summary.overall', compact('pages', 'isCEO', 'isMarketingOIC'));
    }

    public function data(Request $request)
    {
        $start    = $request->input('start_date');
        $end      = $request->input('end_date');
        $pageName = $request->input('page_name', 'all');

        $driver = DB::getDriverName(); // 'mysql' | 'pgsql'
        $trimFn = $driver === 'pgsql' ? 'BTRIM' : 'TRIM';

        // === CONSTS ===
        $SHIPPING_PER_SHIPPED = 37.0;

        // helpers
        $quote = fn(string $col) => $driver === 'pgsql' ? '"' . $col . '"' : '`' . $col . '`';

        $pgColumns = function (string $table): array {
            if (DB::getDriverName() === 'pgsql') {
                $rows = DB::select(
                    "SELECT column_name FROM information_schema.columns
                     WHERE table_schema = current_schema() AND table_name = ?",
                    [$table]
                );
                return array_map(fn($r) => $r->column_name, $rows);
            }
            return [];
        };

        $pickCol = function (string $table, array $candidates) use ($pgColumns) {
            if (DB::getDriverName() === 'pgsql') {
                $cols = $pgColumns($table);
                foreach ($candidates as $c) if (in_array($c, $cols, true)) return $c;
                return null;
            } else {
                foreach ($candidates as $c) if (Schema::hasColumn($table, $c)) return $c;
                return null;
            }
        };

        // money sanitizer for PG/MySQL
        $castMoney = function (string $expr) use ($driver) {
            return $driver === 'pgsql'
                ? "COALESCE(NULLIF(REGEXP_REPLACE(COALESCE(($expr)::text, ''), '[^0-9\\.\\-]', '', 'g'), '')::numeric, 0)"
                : "CAST(REPLACE(REPLACE(REPLACE(COALESCE($expr,''), '₱',''), ',', ''), ' ', '') AS DECIMAL(18,2))";
        };

        // resolve macro_output columns
        $pageColName = $pickCol('macro_output', ['PAGE','page','page_name','Page','Page_Name']);
        if (!$pageColName) throw new \RuntimeException('macro_output: page column not found');
        $moPage   = 'mo.' . $quote($pageColName);
        $pageExpr = "$trimFn(COALESCE($moPage,''))";

        $statusColName = $pickCol('macro_output', ['STATUS','status','Status']) ?? 'status';
        $statusExpr    = 'mo.' . $quote($statusColName);
        $statusNorm    = "LOWER(REPLACE(REPLACE($trimFn($statusExpr),' ',''),'_',''))";

        $wbColName = $pickCol('macro_output', ['waybill','Waybill','WAYBILL']) ?? 'waybill';
        $moWaybill = 'mo.' . $quote($wbColName);

        $itemColName = $pickCol('macro_output', ['ITEM_NAME','item_name','Product','product_name','ITEM','item']);
        if (!$itemColName) throw new \RuntimeException('macro_output: item column not found');
        $moItemExpr = 'mo.' . $quote($itemColName);
        $itemLabel  = "$trimFn(COALESCE($moItemExpr,''))";
        $itemNorm   = "LOWER(REPLACE(REPLACE(REPLACE($itemLabel,' ',''),'-',''),'_',''))";

        $tsCols = [];
        foreach (['TIMESTAMP','timestamp'] as $c) if ($pickCol('macro_output', [$c])) $tsCols[] = $c;

        // DATE expression
        if ($driver === 'mysql') {
            if (!empty($tsCols)) {
                $ts = 'mo.' . $quote($tsCols[0]);
                $dateExpr = "COALESCE(
                    DATE(STR_TO_DATE($ts, '%H:%i %d-%m-%Y')),
                    DATE(STR_TO_DATE($ts, '%H:%i %m-%d-%Y')),
                    DATE(mo.`created_at`)
                )";
            } else {
                $dateExpr = "DATE(mo.`created_at`)";
            }
        } else { // pgsql
            $pgParts = [];
            foreach ($tsCols as $c) {
                $ref = 'mo.' . $quote($c);
                $pgParts[] = "TO_TIMESTAMP(NULLIF($ref, ''), 'HH24:MI DD-MM-YYYY')";
                $pgParts[] = "TO_TIMESTAMP(NULLIF($ref, ''), 'HH24:MI MM-DD-YYYY')";
            }
            $pgParts[] = 'mo."created_at"';
            $dateExpr  = 'DATE(COALESCE(' . implode(', ', $pgParts) . '))';
        }

        // Ad spend cast (sanitize ₱, commas, spaces)
        $castSpend = $castMoney('amount_spent_php');

        // from_jnts columns
        $jCodColName = $pickCol('from_jnts', ['cod','COD','cod_amount','cod_amt','cod_php','Cod','CODAmt']) ?? 'cod';
        $jCodExpr    = 'j.' . $quote($jCodColName);
        $castCOD     = $castMoney($jCodExpr);

        $jSubmitColName = $pickCol('from_jnts', ['submission_time','submitted_at','submission_datetime','submissiondate','submission']) ?? 'submission_time';
        $jSubmitExpr    = 'j.' . $quote($jSubmitColName);

        // cogs columns
        $cogsItemColName = $pickCol('cogs', ['item_name','ITEM_NAME','product','Product','Product_Name']) ?? 'item_name';
        $cogsItemExpr    = 'c.' . $quote($cogsItemColName);
        $cogsItemNorm    = "LOWER(REPLACE(REPLACE(REPLACE($trimFn(COALESCE($cogsItemExpr,'')),' ',''),'-',''),'_',''))";

        $cogsDateColName = $pickCol('cogs', ['effective_date','date','valid_from','cogs_date']) ?? 'effective_date';
        $cogsDateExpr    = 'c.' . $quote($cogsDateColName);

        $cogsUnitColName = $pickCol('cogs', ['unit_cost','cost','unitprice','unit_price','price']) ?? 'unit_cost';
        $cogsUnitExpr    = 'c.' . $quote($cogsUnitColName);

        // aggregate mode: All Pages
        $AGGREGATE_RANGE = ($pageName === 'all');

        // ======================
        // ADS (ads_manager_reports)
        // ======================
        $adsBase = DB::table('ads_manager_reports');

        if ($start && $end) {
            $adsBase->whereRaw('DATE(day) BETWEEN ? AND ?', [$start, $end]);
        } elseif ($start) {
            $adsBase->whereRaw('DATE(day) >= ?', [$start]);
        } elseif ($end) {
            $adsBase->whereRaw('DATE(day) <= ?', [$end]);
        }

        if (!$AGGREGATE_RANGE) { // specific page
            if ($driver === 'pgsql') {
                $adsBase->whereRaw("$trimFn(page_name) ILIKE $trimFn(?)", [$pageName]);
            } else {
                $adsBase->whereRaw("LOWER($trimFn(page_name)) = LOWER($trimFn(?))", [$pageName]);
            }
        }

        if ($AGGREGATE_RANGE) {
            $adsRows = (clone $adsBase)
                ->selectRaw("$trimFn(COALESCE(page_name, '')) AS page_key, SUM($castSpend) AS adspent")
                ->groupByRaw("$trimFn(COALESCE(page_name,''))")
                ->havingRaw("SUM($castSpend) > 0")
                ->orderBy('page_key')
                ->get();
        } else {
            $adsRows = (clone $adsBase)
                ->whereNotNull('day')
                ->selectRaw("DATE(day) AS day_key, $trimFn(COALESCE(page_name, '')) AS page_key, SUM($castSpend) AS adspent")
                ->groupByRaw("DATE(day), $trimFn(COALESCE(page_name,''))")
                ->havingRaw("SUM($castSpend) > 0")
                ->orderBy('day_key', 'asc')
                ->orderBy('page_key', 'asc')
                ->get();
        }

        $adsMap = [];
        foreach ($adsRows as $r) {
            if ($AGGREGATE_RANGE) {
                $adsMap[(string)$r->page_key] = (float)($r->adspent ?? 0);
            } else {
                $adsMap[(string)$r->day_key . '|' . (string)$r->page_key] = (float)($r->adspent ?? 0);
            }
        }

        // ======================
        // ORDERS & STATUS (macro_output)
        // ======================
        $mo = DB::table('macro_output as mo');

        if ($start && $end) {
            $mo->whereRaw("$dateExpr BETWEEN ? AND ?", [$start, $end]);
        } elseif ($start) {
            $mo->whereRaw("$dateExpr >= ?", [$start]);
        } elseif ($end) {
            $mo->whereRaw("$dateExpr <= ?", [$end]);
        }

        if (!$AGGREGATE_RANGE) { // only when specific page
            if ($driver === 'pgsql') {
                $mo->whereRaw("$pageExpr ILIKE $trimFn(?)", [$pageName]);
            } else {
                $mo->whereRaw("LOWER($pageExpr) = LOWER($trimFn(?))", [$pageName]);
            }
        }

        $selectKey   = $AGGREGATE_RANGE ? "$pageExpr AS page_key" : "$dateExpr AS day_key, $pageExpr AS page_key";
        $groupByKey  = $AGGREGATE_RANGE ? "$pageExpr" : "$dateExpr, $pageExpr";

        // All Orders
        $ordersRows = (clone $mo)
            ->selectRaw("$selectKey, COUNT(*) AS orders_total")
            ->groupByRaw($groupByKey)
            ->get();

        $ordersMap = [];
        foreach ($ordersRows as $r) {
            $k = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)$r->day_key . '|' . (string)$r->page_key);
            $ordersMap[$k] = (int)($r->orders_total ?? 0);
        }

        // Proceed
        $proceedRows = (clone $mo)
            ->whereRaw("$statusNorm = 'proceed'")
            ->selectRaw("$selectKey, COUNT(*) AS proceed_total")
            ->groupByRaw($groupByKey)
            ->get();

        $proceedMap = [];
        foreach ($proceedRows as $r) {
            $k = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)$r->day_key . '|' . (string)$r->page_key);
            $proceedMap[$k] = (int)($r->proceed_total ?? 0);
        }

        // Cannot Proceed
        $cannotRows = (clone $mo)
            ->whereRaw("$statusNorm = 'cannotproceed'")
            ->selectRaw("$selectKey, COUNT(*) AS cannot_total")
            ->groupByRaw($groupByKey)
            ->get();

        $cannotMap = [];
        foreach ($cannotRows as $r) {
            $k = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)$r->day_key . '|' . (string)$r->page_key);
            $cannotMap[$k] = (int)($r->cannot_total ?? 0);
        }

        // ODZ
        $odzRows = (clone $mo)
            ->whereRaw("$statusNorm = 'odz'")
            ->selectRaw("$selectKey, COUNT(*) AS odz_total")
            ->groupByRaw($groupByKey)
            ->get();

        $odzMap = [];
        foreach ($odzRows as $r) {
            $k = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)$r->day_key . '|' . (string)$r->page_key);
            $odzMap[$k] = (int)($r->odz_total ?? 0);
        }

        // ======================
        // SHIPPING & STATUS COUNTS (join mo <-> j w/o status filter)
        // ======================
        $jStatusNorm = "LOWER(REPLACE(REPLACE(REPLACE($trimFn(COALESCE(j.status,'')),' ',''),'-',''),'_',''))";

        $joinedBase = (clone $mo)
            ->whereRaw("$moWaybill IS NOT NULL")
            ->whereRaw("$trimFn($moWaybill) <> ''")
            ->join('from_jnts as j', function ($join) use ($trimFn, $moWaybill) {
                $join->on(DB::raw("$trimFn($moWaybill)"), '=', DB::raw("$trimFn(j.waybill_number)"));
            });

        // SHIPPED = count of distinct waybills present in j (any status)
        $shippedRows = (clone $joinedBase)
            ->selectRaw("$selectKey, COUNT(DISTINCT $trimFn($moWaybill)) AS shipped_total")
            ->groupByRaw($groupByKey)
            ->get();

        $shippedMap = [];
        foreach ($shippedRows as $r) {
            $k = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)$r->day_key . '|' . (string)$r->page_key);
            $shippedMap[$k] = (int)($r->shipped_total ?? 0);
        }

        // DELIVERED / RETURNED / FOR RETURN / IN TRANSIT
        $deliveredRows = (clone $joinedBase)
            ->whereRaw("$jStatusNorm LIKE 'delivered%'")
            ->selectRaw("$selectKey, COUNT(DISTINCT $trimFn($moWaybill)) AS delivered_total")
            ->groupByRaw($groupByKey)
            ->get();

        $returnedRows = (clone $joinedBase)
            ->whereRaw("$jStatusNorm LIKE 'returned%'")
            ->selectRaw("$selectKey, COUNT(DISTINCT $trimFn($moWaybill)) AS returned_total")
            ->groupByRaw($groupByKey)
            ->get();

        $forReturnRows = (clone $joinedBase)
            ->whereRaw("$jStatusNorm LIKE 'forreturn%'")
            ->selectRaw("$selectKey, COUNT(DISTINCT $trimFn($moWaybill)) AS for_return_total")
            ->groupByRaw($groupByKey)
            ->get();

        $inTransitRows = (clone $joinedBase)
            ->whereRaw("$jStatusNorm LIKE 'intransit%'")
            ->selectRaw("$selectKey, COUNT(DISTINCT $trimFn($moWaybill)) AS in_transit_total")
            ->groupByRaw($groupByKey)
            ->get();

        $deliveredMap = $returnedMap = $forReturnMap = $inTransitMap = [];
        foreach ($deliveredRows as $r) {
            $k = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)$r->day_key . '|' . (string)$r->page_key);
            $deliveredMap[$k] = (int)($r->delivered_total ?? 0);
        }
        foreach ($returnedRows as $r) {
            $k = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)$r->day_key . '|' . (string)$r->page_key);
            $returnedMap[$k] = (int)($r->returned_total ?? 0);
        }
        foreach ($forReturnRows as $r) {
            $k = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)$r->day_key . '|' . (string)$r->page_key);
            $forReturnMap[$k] = (int)($r->for_return_total ?? 0);
        }
        foreach ($inTransitRows as $r) {
            $k = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)$r->day_key . '|' . (string)$r->page_key);
            $inTransitMap[$k] = (int)($r->in_transit_total ?? 0);
        }

        // ======================
        // DELIVERED-ONLY base for Gross Sales, COGS, Items
        // ======================
        $deliveredBase = (clone $joinedBase)
            ->whereRaw("$jStatusNorm LIKE 'delivered%'");

        // GROSS SALES (Delivered-only)
        $innerDistinct = (clone $deliveredBase)
            ->selectRaw("DISTINCT $selectKey, $trimFn($moWaybill) AS wb, $castCOD AS cod_clean");

        if ($AGGREGATE_RANGE) {
            $grossRows = DB::query()
                ->fromSub($innerDistinct, 'd')
                ->selectRaw("page_key, SUM(cod_clean) AS gross_sales")
                ->groupBy('page_key')
                ->get();
        } else {
            $grossRows = DB::query()
                ->fromSub($innerDistinct, 'd')
                ->selectRaw("day_key, page_key, SUM(cod_clean) AS gross_sales")
                ->groupBy('day_key', 'page_key')
                ->get();
        }

        $grossMap = [];
        foreach ($grossRows as $r) {
            if ($AGGREGATE_RANGE) {
                $grossMap[(string)$r->page_key] = (float)($r->gross_sales ?? 0);
            } else {
                $grossMap[(string)$r->day_key . '|' . (string)$r->page_key] = (float)($r->gross_sales ?? 0);
            }
        }

        // ===== Sum of COD for ALL shipped (any status) =====
        $innerAllCod = (clone $joinedBase)
            ->selectRaw("DISTINCT $selectKey, $trimFn($moWaybill) AS wb, $castCOD AS cod_clean");

        if ($AGGREGATE_RANGE) {
            $allCodRows = DB::query()
                ->fromSub($innerAllCod, 'd')
                ->selectRaw("page_key, SUM(cod_clean) AS all_cod")
                ->groupBy('page_key')
                ->get();
        } else {
            $allCodRows = DB::query()
                ->fromSub($innerAllCod, 'd')
                ->selectRaw("day_key, page_key, SUM(cod_clean) AS all_cod")
                ->groupBy('day_key','page_key')
                ->get();
        }

        $allCodMap = [];
        foreach ($allCodRows as $r) {
            $k = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)$r->day_key.'|'.(string)$r->page_key);
            $allCodMap[$k] = (float)($r->all_cod ?? 0);
        }

        // COGS (Delivered-only)
        $innerDeliveredItems = (clone $deliveredBase)
            ->selectRaw("DISTINCT $selectKey, $dateExpr AS order_date, $trimFn($moWaybill) AS wb, $itemNorm AS item_key");

        $unitCostSub = "COALESCE((
            SELECT " . $castMoney($cogsUnitExpr) . "
            FROM cogs c
            WHERE $cogsItemNorm = d.item_key
              AND DATE($cogsDateExpr) <= d.order_date
            ORDER BY DATE($cogsDateExpr) DESC
            LIMIT 1
        ), 0)";

        if ($AGGREGATE_RANGE) {
            $cogsRows = DB::query()
                ->fromSub($innerDeliveredItems, 'd')
                ->selectRaw("page_key, SUM($unitCostSub) AS cogs_total")
                ->groupBy('page_key')
                ->get();
        } else {
            $cogsRows = DB::query()
                ->fromSub($innerDeliveredItems, 'd')
                ->selectRaw("day_key, page_key, SUM($unitCostSub) AS cogs_total")
                ->groupBy('day_key', 'page_key')
                ->get();
        }

        $cogsMap = [];
        foreach ($cogsRows as $r) {
            if ($AGGREGATE_RANGE) {
                $cogsMap[(string)$r->page_key] = (float)($r->cogs_total ?? 0);
            } else {
                $cogsMap[(string)$r->day_key . '|' . (string)$r->page_key] = (float)($r->cogs_total ?? 0);
            }
        }

        // ITEMS + UNIT COST LISTS for UI
        $itemsBase = (clone $deliveredBase)
            ->selectRaw("DISTINCT $selectKey, $dateExpr AS order_date, $trimFn($moWaybill) AS wb, $itemNorm AS item_key, $itemLabel AS item_label");

        if ($AGGREGATE_RANGE) {
            $groupedSub = DB::query()
                ->fromSub($itemsBase, 'x')
                ->selectRaw("page_key, item_key, MIN(item_label) AS item_label, COUNT(DISTINCT wb) AS qty, MAX(order_date) AS last_order_date")
                ->groupBy('page_key','item_key');
        } else {
            $groupedSub = DB::query()
                ->fromSub($itemsBase, 'x')
                ->selectRaw("day_key, page_key, item_key, MIN(item_label) AS item_label, COUNT(DISTINCT wb) AS qty, MAX(order_date) AS last_order_date")
                ->groupBy('day_key','page_key','item_key');
        }

        $unitCostDispSub = "COALESCE((
            SELECT " . $castMoney($cogsUnitExpr) . "
            FROM cogs c
            WHERE $cogsItemNorm = d.item_key
              AND DATE($cogsDateExpr) <= d.last_order_date
            ORDER BY DATE($cogsDateExpr) DESC
            LIMIT 1
        ), 0)";

        if ($AGGREGATE_RANGE) {
            $itemsCostRows = DB::query()
                ->fromSub($groupedSub, 'd')
                ->selectRaw("page_key, item_key, item_label, qty, $unitCostDispSub AS unit_cost_disp")
                ->get();
        } else {
            $itemsCostRows = DB::query()
                ->fromSub($groupedSub, 'd')
                ->selectRaw("day_key, page_key, item_key, item_label, qty, $unitCostDispSub AS unit_cost_disp")
                ->get();
        }

        $itemsListMap = [];
        foreach ($itemsCostRows as $r) {
            $key = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)$r->day_key . '|' . (string)$r->page_key);
            $itemsListMap[$key] ??= [];
            $itemsListMap[$key][] = [
                'label'     => (string)($r->item_label ?? ''),
                'qty'       => (int)($r->qty ?? 0),
                'unit_cost' => (float)($r->unit_cost_disp ?? 0),
            ];
        }

        // ======================
        // DELAY (avg days per unique waybill)
        // ======================
        if ($driver === 'mysql') {
            $jSubmitTs   = "COALESCE(
                STR_TO_DATE($jSubmitExpr, '%Y-%m-%d %H:%i:%s'),
                STR_TO_DATE($jSubmitExpr, '%Y/%m/%d %H:%i:%s'),
                STR_TO_DATE($jSubmitExpr, '%Y-%m-%d'),
                j.`created_at`
            )";
            $jSubmitDate = "DATE($jSubmitTs)";
            $delayDays   = "DATEDIFF($jSubmitDate, $dateExpr)";
        } else { // pgsql
            $jSubmitTs   = "COALESCE(
                TO_TIMESTAMP(NULLIF($jSubmitExpr,''), 'YYYY-MM-DD HH24:MI:SS'),
                TO_TIMESTAMP(NULLIF($jSubmitExpr,''), 'YYYY/MM/DD HH24:MI:SS'),
                TO_TIMESTAMP(NULLIF($jSubmitExpr,''), 'YYYY-MM-DD'),
                j.\"created_at\"
            )";
            $jSubmitDate = "DATE($jSubmitTs)";
            $delayDays   = "($jSubmitDate - $dateExpr)";
        }

        $delayRaw = (clone $joinedBase)
            ->selectRaw("$selectKey, $trimFn($moWaybill) AS wb, $delayDays AS delay_days");

        if ($AGGREGATE_RANGE) {
            $delayDistinct = DB::query()
                ->fromSub($delayRaw, 'r')
                ->selectRaw("page_key, wb, MIN(delay_days) AS delay_days")
                ->groupBy('page_key','wb');

            $delayAvgRows = DB::query()
                ->fromSub($delayDistinct, 'd')
                ->selectRaw("page_key, AVG(delay_days) AS avg_delay_days")
                ->groupBy('page_key')
                ->get();
        } else {
            $delayDistinct = DB::query()
                ->fromSub($delayRaw, 'r')
                ->selectRaw("day_key, page_key, wb, MIN(delay_days) AS delay_days")
                ->groupBy('day_key','page_key','wb');

            $delayAvgRows = DB::query()
                ->fromSub($delayDistinct, 'd')
                ->selectRaw("day_key, page_key, AVG(delay_days) AS avg_delay_days")
                ->groupBy('day_key','page_key')
                ->get();
        }

        $avgDelayMap = [];
        foreach ($delayAvgRows as $r) {
            $k = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)$r->day_key . '|' . (string)$r->page_key);
            $avgDelayMap[$k] = (float)($r->avg_delay_days ?? 0);
        }

        // ======================
        // Merge (+ derived metrics)
        // ======================
        $keys = array_unique(array_merge(
            array_keys($adsMap),
            array_keys($ordersMap),
            array_keys($proceedMap),
            array_keys($cannotMap),
            array_keys($odzMap),
            array_keys($shippedMap),
            array_keys($deliveredMap),
            array_keys($returnedMap),
            array_keys($forReturnMap),
            array_keys($inTransitMap),
            array_keys($grossMap),
            array_keys($cogsMap),
            array_keys($itemsListMap),
            array_keys($avgDelayMap),
            array_keys($allCodMap) // include all COD keys
        ));

        $rangeLabel = '—';
        if ($AGGREGATE_RANGE) {
            if ($start && $end)      $rangeLabel = "$start – $end";
            elseif ($start)          $rangeLabel = "$start – …";
            elseif ($end)            $rangeLabel = "… – $end";
        }

        $rows = [];
        foreach ($keys as $key) {
            $adspent = $adsMap[$key] ?? 0.0;
            if ($adspent <= 0) continue; // UI shows only rows with ad spend

            // Items display + unit costs
            $itemsDisplay = null;
            $unitCostsArr = [];
            if (!empty($itemsListMap[$key])) {
                $items = $itemsListMap[$key];
                usort($items, fn($a,$b) => strcmp($a['label'], $b['label']));
                $many = count($items) > 1;
                $labels = [];
                foreach ($items as $it) {
                    $lbl = $it['label'] ?? '';
                    if ($many) $lbl .= '(' . (int)$it['qty'] . ')';
                    $labels[] = $lbl;
                    $unitCostsArr[] = (float)$it['unit_cost'];
                }
                $itemsDisplay = implode(' / ', $labels);
            }

            if ($AGGREGATE_RANGE) {
                $page      = $key;
                $orders    = $ordersMap[$key]      ?? 0;
                $proc      = $proceedMap[$key]     ?? 0;
                $cannot    = $cannotMap[$key]      ?? 0;
                $odz       = $odzMap[$key]         ?? 0;
                $shipped   = $shippedMap[$key]     ?? 0;
                $delivered = $deliveredMap[$key]   ?? 0;
                $returned  = $returnedMap[$key]    ?? 0;
                $forRet    = $forReturnMap[$key]   ?? 0;
                $inTrans   = $inTransitMap[$key]   ?? 0;
                $gross     = $grossMap[$key]       ?? 0.0; // delivered-only
                $cogs      = $cogsMap[$key]        ?? 0.0; // delivered-only
                $all_cod   = $allCodMap[$key]      ?? 0.0; // ALL shipped
                $avgDelay  = $avgDelayMap[$key]    ?? null;

                $shipping_fee   = $SHIPPING_PER_SHIPPED * $shipped;
                $cpp            = $orders  > 0 ? ($adspent / $orders) * 1.0 : null;
                $proceed_cpp    = $proc    > 0 ? ($adspent / $proc)   * 1.0 : null;
                $rts_pct        = $shipped > 0 ? (($returned + $forRet) / $shipped) * 100.0 : null;
                $in_transit_pct = $shipped > 0 ? ($inTrans / $shipped) * 100.0 : null;
                $tcpr           = $orders  > 0 ? (1 - ($proc / $orders)) * 100.0 : null;

                $net_profit     = $gross - $adspent - $shipping_fee - $cogs;
                $net_profit_pct = $all_cod > 0 ? ($net_profit / $all_cod) * 100.0 : null; // denominator = Σ COD (all shipped)
                $hold           = $proc - $shipped;

                $rows[] = [
                    'date'            => $rangeLabel,
                    'page'            => $page !== '' ? $page : null,
                    'adspent'         => $adspent,
                    'orders'          => $orders,
                    'proceed'         => $proc,
                    'cannot_proceed'  => $cannot,
                    'odz'             => $odz,
                    'shipped'         => $shipped,
                    'delivered'       => $delivered,
                    'avg_delay_days'  => $avgDelay,
                    'items_display'   => $itemsDisplay,
                    'unit_costs'      => $unitCostsArr,
                    'gross_sales'     => $gross,
                    'shipping_fee'    => $shipping_fee,
                    'cogs'            => $cogs,
                    'net_profit'      => $net_profit,
                    'net_profit_pct'  => $net_profit_pct,
                    'returned'        => $returned,
                    'for_return'      => $forRet,
                    'in_transit'      => $inTrans,
                    'cpp'             => $cpp,
                    'proceed_cpp'     => $proceed_cpp,
                    'rts_pct'         => $rts_pct,
                    'in_transit_pct'  => $in_transit_pct,
                    'tcpr'            => $tcpr,
                    'hold'            => $hold,
                    'is_total'        => false,
                    'all_cod'         => $all_cod, // used in totals
                ];
            } else {
                [$d, $p] = explode('|', $key, 2);
                $orders    = $ordersMap[$key]      ?? 0;
                $proc      = $proceedMap[$key]     ?? 0;
                $cannot    = $cannotMap[$key]      ?? 0;
                $odz       = $odzMap[$key]         ?? 0;
                $shipped   = $shippedMap[$key]     ?? 0;
                $delivered = $deliveredMap[$key]   ?? 0;
                $returned  = $returnedMap[$key]    ?? 0;
                $forRet    = $forReturnMap[$key]   ?? 0;
                $inTrans   = $inTransitMap[$key]   ?? 0;
                $gross     = $grossMap[$key]       ?? 0.0; // delivered-only
                $cogs      = $cogsMap[$key]        ?? 0.0; // delivered-only
                $all_cod   = $allCodMap[$key]      ?? 0.0; // ALL shipped
                $avgDelay  = $avgDelayMap[$key]    ?? null;

                $shipping_fee   = $SHIPPING_PER_SHIPPED * $shipped;
                $cpp            = $orders  > 0 ? ($adspent / $orders) * 1.0 : null;
                $proceed_cpp    = $proc    > 0 ? ($adspent / $proc)   * 1.0 : null;
                $rts_pct        = $shipped > 0 ? (($returned + $forRet) / $shipped) * 100.0 : null;
                $in_transit_pct = $shipped > 0 ? ($inTrans / $shipped) * 100.0 : null;
                $tcpr           = $orders  > 0 ? (1 - ($proc / $orders)) * 100.0 : null;

                $net_profit     = $gross - $adspent - $shipping_fee - $cogs;
                $net_profit_pct = $all_cod > 0 ? ($net_profit / $all_cod) * 100.0 : null; // CHANGED here too
                $hold           = $proc - $shipped;

                $rows[] = [
                    'date'            => $d,
                    'page'            => $p !== '' ? $p : null,
                    'adspent'         => $adspent,
                    'orders'          => $orders,
                    'proceed'         => $proc,
                    'cannot_proceed'  => $cannot,
                    'odz'             => $odz,
                    'shipped'         => $shipped,
                    'delivered'       => $delivered,
                    'avg_delay_days'  => $avgDelay,
                    'items_display'   => $itemsDisplay,
                    'unit_costs'      => $unitCostsArr,
                    'gross_sales'     => $gross,
                    'shipping_fee'    => $shipping_fee,
                    'cogs'            => $cogs,
                    'net_profit'      => $net_profit,
                    'net_profit_pct'  => $net_profit_pct,
                    'returned'        => $returned,
                    'for_return'      => $forRet,
                    'in_transit'      => $inTrans,
                    'cpp'             => $cpp,
                    'proceed_cpp'     => $proceed_cpp,
                    'rts_pct'         => $rts_pct,
                    'in_transit_pct'  => $in_transit_pct,
                    'tcpr'            => $tcpr,
                    'hold'            => $hold,
                    'is_total'        => false,
                    'all_cod'         => $all_cod, // used in totals
                ];
            }
        }

        // sort rows
        if ($AGGREGATE_RANGE) {
            usort($rows, fn($a,$b) => strcmp($a['page'] ?? '', $b['page'] ?? ''));
        } else {
            usort($rows, function ($a, $b) {
                if ($a['date'] === $b['date']) {
                    return strcmp($a['page'] ?? '', $b['page'] ?? '');
                }
                return strcmp($a['date'], $b['date']);
            });
        }

        // ===== Actual RTS: compute from the SAME rows that are shown in the table
        // Filter rows with In-Transit% < 3%. Denominator = Delivered + Returned + For Return.
        $actualRtsPct = null;
        if (!$AGGREGATE_RANGE) {
            $num = 0; // Σ(Returned + For Return)
            $den = 0; // Σ(Delivered + Returned + For Return)

            foreach ($rows as $r) {
                if (!empty($r['is_total'])) continue;
                $inPct = $r['in_transit_pct'] ?? null;
                if ($inPct !== null && $inPct < 3.0) {
                    $ret = (int)($r['returned']   ?? 0);
                    $fr  = (int)($r['for_return'] ?? 0);
                    $del = (int)($r['delivered']  ?? 0);
                    $num += ($ret + $fr);
                    $den += ($del + $ret + $fr);
                }
            }
            $actualRtsPct = $den > 0 ? ($num / $den) * 100.0 : null;
        }

        // ===== TOTAL ROW =====
        if (!empty($rows)) {
            $sum = [
                'adspent' => 0.0,
                'orders' => 0, 'proceed' => 0, 'cannot_proceed' => 0, 'odz' => 0,
                'shipped' => 0, 'delivered' => 0, 'returned' => 0, 'for_return' => 0, 'in_transit' => 0,
                'gross_sales' => 0.0, 'cogs' => 0.0,
                'all_cod' => 0.0, // NEW
            ];
            $delayWeightedSum = 0.0;
            $delayShipCount   = 0;

            foreach ($rows as $r) {
                $sum['adspent']        += (float)($r['adspent'] ?? 0);
                $sum['orders']         += (int)  ($r['orders'] ?? 0);
                $sum['proceed']        += (int)  ($r['proceed'] ?? 0);
                $sum['cannot_proceed'] += (int)  ($r['cannot_proceed'] ?? 0);
                $sum['odz']            += (int)  ($r['odz'] ?? 0);
                $sum['shipped']        += (int)  ($r['shipped'] ?? 0);
                $sum['delivered']      += (int)  ($r['delivered'] ?? 0);
                $sum['returned']       += (int)  ($r['returned'] ?? 0);
                $sum['for_return']     += (int)  ($r['for_return'] ?? 0);
                $sum['in_transit']     += (int)  ($r['in_transit'] ?? 0);
                $sum['gross_sales']    += (float)($r['gross_sales'] ?? 0);
                $sum['cogs']           += (float)($r['cogs'] ?? 0);
                $sum['all_cod']        += (float)($r['all_cod'] ?? 0); // NEW

                if (isset($r['avg_delay_days']) && $r['avg_delay_days'] !== null && ($r['shipped'] ?? 0) > 0 && empty($r['is_total'])) {
                    $delayWeightedSum += (float)$r['avg_delay_days'] * (int)$r['shipped'];
                    $delayShipCount   += (int)$r['shipped'];
                }
            }

            $total_cpp            = $sum['orders']  > 0 ? ($sum['adspent'] / $sum['orders']) * 1.0 : null;
            $total_proceed_cpp    = $sum['proceed'] > 0 ? ($sum['adspent'] / $sum['proceed']) * 1.0 : null;
            $total_rts_pct        = $sum['shipped'] > 0 ? (($sum['returned'] + $sum['for_return']) / $sum['shipped']) * 100.0 : null;
            $total_in_transit_pct = $sum['shipped'] > 0 ? ($sum['in_transit'] / $sum['shipped']) * 100.0 : null;
            $total_tcpr           = $sum['orders']  > 0 ? (1 - ($sum['proceed'] / $sum['orders'])) * 100.0 : null;
            $total_shipping_fee   = $SHIPPING_PER_SHIPPED * $sum['shipped'];

            $total_net_profit     = $sum['gross_sales'] - $sum['adspent'] - $total_shipping_fee - $sum['cogs'];
            $total_net_profit_pct = $sum['all_cod'] > 0 ? ($total_net_profit / $sum['all_cod']) * 100.0 : null; // CHANGED

            $total_avg_delay      = $delayShipCount > 0 ? ($delayWeightedSum / $delayShipCount) : null;

            // Build items/costs for total when specific page
            $totalItemsDisplay = '—'; $totalUnitCostsArr = []; $totalPageLabel = 'TOTAL';
            if (!$AGGREGATE_RANGE && $pageName && strtolower($pageName) !== 'all') {
                $totalPageLabel = $pageName;
                $itemsBaseTotals = (clone $deliveredBase)
                    ->selectRaw("DISTINCT $pageExpr AS page_key, $dateExpr AS order_date, $trimFn($moWaybill) AS wb, $itemNorm AS item_key, $itemLabel AS item_label");
                $groupedTotals = DB::query()->fromSub($itemsBaseTotals,'x')
                    ->selectRaw("page_key, item_key, MIN(item_label) AS item_label, COUNT(DISTINCT wb) AS qty, MAX(order_date) AS last_order_date")
                    ->groupBy('page_key','item_key');
                $itemsTotalsWithCost = DB::query()->fromSub($groupedTotals,'d')
                    ->selectRaw("page_key, item_key, item_label, qty, COALESCE((
                        SELECT " . $castMoney($cogsUnitExpr) . "
                        FROM cogs c
                        WHERE $cogsItemNorm = d.item_key
                          AND DATE($cogsDateExpr) <= d.last_order_date
                        ORDER BY DATE($cogsDateExpr) DESC
                        LIMIT 1
                    ), 0) AS unit_cost_disp")->get();

                $acc = [];
                foreach ($itemsTotalsWithCost as $r) $acc[] = ['label'=>(string)($r->item_label ?? ''),'qty'=>(int)($r->qty ?? 0),'unit_cost'=>(float)($r->unit_cost_disp ?? 0)];
                if (!empty($acc)) {
                    usort($acc, fn($a,$b)=>strcmp($a['label'],$b['label']));
                    $many = count($acc) > 1; $labels = []; $costs = [];
                    foreach ($acc as $it) { $lbl=$it['label']; if ($many) $lbl.="(".(int)$it['qty'].")"; $labels[]=$lbl; $costs[]=(float)$it['unit_cost']; }
                    $totalItemsDisplay = implode(' / ', $labels);
                    $totalUnitCostsArr = $costs;
                }
            }

            $rows[] = [
                'date'            => $AGGREGATE_RANGE ? $rangeLabel : 'Total',
                'page'            => (!$AGGREGATE_RANGE && $pageName && strtolower($pageName) !== 'all') ? $totalPageLabel : 'TOTAL',
                'adspent'         => $sum['adspent'],
                'orders'          => $sum['orders'],
                'proceed'         => $sum['proceed'],
                'cannot_proceed'  => $sum['cannot_proceed'],
                'odz'             => $sum['odz'],
                'shipped'         => $sum['shipped'],
                'delivered'       => $sum['delivered'],
                'avg_delay_days'  => $total_avg_delay,
                'items_display'   => (!$AGGREGATE_RANGE && $pageName && strtolower($pageName) !== 'all') ? $totalItemsDisplay : '—',
                'unit_costs'      => (!$AGGREGATE_RANGE && $pageName && strtolower($pageName) !== 'all') ? $totalUnitCostsArr : [],
                'gross_sales'     => $sum['gross_sales'],
                'shipping_fee'    => $total_shipping_fee,
                'cogs'            => $sum['cogs'],
                'net_profit'      => $total_net_profit,
                'net_profit_pct'  => $total_net_profit_pct,
                'returned'        => $sum['returned'],
                'for_return'      => $sum['for_return'],
                'in_transit'      => $sum['in_transit'],
                'cpp'             => $total_cpp,
                'proceed_cpp'     => $total_proceed_cpp,
                'rts_pct'         => $total_rts_pct,
                'in_transit_pct'  => $total_in_transit_pct,
                'tcpr'            => $total_tcpr,
                'hold'            => ($sum['proceed'] - $sum['shipped']),
                'is_total'        => true,
            ];
        }

        return response()->json([
            'ads_daily'      => $rows,
            'actual_rts_pct' => $actualRtsPct,
        ]);
    }
}
