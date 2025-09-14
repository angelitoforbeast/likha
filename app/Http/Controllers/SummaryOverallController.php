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
        // PH timezone (for "Today" label in top summary)
        $phTz  = new \DateTimeZone('Asia/Manila');
        $today = (new \DateTime('now', $phTz))->format('Y-m-d');

        $start    = $request->input('start_date');
        $end      = $request->input('end_date');
        $pageName = $request->input('page_name', 'all');

        $driver = DB::getDriverName(); // 'mysql' | 'pgsql'
        $trimFn = $driver === 'pgsql' ? 'BTRIM' : 'TRIM';

        // === CONSTS ===
        $SHIPPING_PER_SHIPPED             = 37.0;   // Projected Shipping Fee = 37 * #proceed
        $COD_FEE_RATE                     = 0.015;  // 1.5%
        $DEFAULT_RTS_PCT                  = 30.0;   // fallback when no RTS data

        // helpers
        $quote = fn(string $col) => $driver === 'pgsql' ? '"' . $col . '"' : '`' . $col . '`';
        $fmtMonthDay = fn(string $d) => date('M j', strtotime($d));
        $makeFilteredLabel = function($s, $e) use ($fmtMonthDay) {
            if ($s && $e) {
                if ($s === $e) return $fmtMonthDay($s);
                return $fmtMonthDay($s) . ' – ' . $fmtMonthDay($e);
            } elseif ($s)   { return $fmtMonthDay($s) . ' – …'; }
              elseif ($e)   { return '… – ' . $fmtMonthDay($e); }
            return '—';
        };

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

        // money sanitizer
        $castMoney = function (string $expr) use ($driver) {
            return $driver === 'pgsql'
                ? "COALESCE(NULLIF(REGEXP_REPLACE(COALESCE(($expr)::text, ''), '[^0-9\\.\\-]', '', 'g'), '')::numeric, 0)"
                : "CAST(REPLACE(REPLACE(REPLACE(COALESCE($expr,''), '₱',''), ',', ''), ' ', '') AS DECIMAL(18,2))";
        };

        // resolve macro_output columns
        $pageColName = $pickCol('macro_output', ['PAGE','page','page_name','Page','Page_Name']);
        if (!$pageColName) throw new \RuntimeException('macro_output: page column not found');
        $moPageSql = 'mo.' . $quote($pageColName);
        $moPageCol = 'mo.' . $pageColName;
        $pageExpr  = "$trimFn(COALESCE($moPageSql,''))";

        $statusColName = $pickCol('macro_output', ['STATUS','status','Status']) ?? 'status';
        $statusExpr    = 'mo.' . $quote($statusColName);
        $statusNorm    = "LOWER(REPLACE(REPLACE($trimFn($statusExpr),' ',''),'_',''))";

        $wbColName    = $pickCol('macro_output', ['waybill','Waybill','WAYBILL']) ?? 'waybill';
        $moWaybillSql = 'mo.' . $quote($wbColName);
        $moWaybillCol = 'mo.' . $wbColName;

        $itemColName = $pickCol('macro_output', ['ITEM_NAME','item_name','Product','product_name','ITEM','item']);
        if (!$itemColName) throw new \RuntimeException('macro_output: item column not found');
        $moItemExpr = 'mo.' . $quote($itemColName);
        $itemLabel  = "$trimFn(COALESCE($moItemExpr,''))";
        $itemNorm   = "LOWER(REPLACE(REPLACE(REPLACE($itemLabel,' ',''),'-',''),'_',''))";

        // NEW: COD from macro_output
        $moCodColName = $pickCol('macro_output', ['COD','cod','Cod']) ?? 'COD';
        $moCodExpr    = 'mo.' . $quote($moCodColName);
        $moCodClean   = $castMoney($moCodExpr);

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

        // Ad spend cast
        $castSpend = $castMoney('amount_spent_php');

        // from_jnts columns (used ONLY for shipment/delivery status + delay; NOT for COD amounts)
        $jSubmitColName = $pickCol('from_jnts', ['submission_time','submitted_at','submission_datetime','submissiondate','submission']) ?? 'submission_time';

        // cogs columns
        $cogsItemColName = $pickCol('cogs', ['item_name','ITEM_NAME','product','Product','Product_Name']) ?? 'item_name';
        $cogsItemExpr    = 'c.' . $quote($cogsItemColName);
        $cogsItemNorm    = "LOWER(REPLACE(REPLACE(REPLACE($trimFn(COALESCE($cogsItemExpr,'')),' ',''),'-',''),'_',''))";

        $cogsDateColName = $pickCol('cogs', ['effective_date','date','valid_from','cogs_date']) ?? 'effective_date';
        $cogsDateExpr    = 'c.' . $quote($cogsDateColName);

        $cogsUnitColName = $pickCol('cogs', ['unit_cost','cost','unitprice','unit_price','price']) ?? 'unit_cost';
        $cogsUnitExpr    = 'c.' . $quote($cogsUnitColName);

        // aggregate mode: All Pages?
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

        if (!$AGGREGATE_RANGE) {
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
        // ORDERS / PROCEED / CANNOT / ODZ (macro_output)
        // ======================
        $mo = DB::table('macro_output as mo');

        if ($start && $end) {
            $mo->whereRaw("$dateExpr BETWEEN ? AND ?", [$start, $end]);
        } elseif ($start) {
            $mo->whereRaw("$dateExpr >= ?", [$start]);
        } elseif ($end) {
            $mo->whereRaw("$dateExpr <= ?", [$end]);
        }

        if (!$AGGREGATE_RANGE) {
            if ($driver === 'pgsql') {
                $mo->whereRaw("$pageExpr ILIKE $trimFn(?)", [$pageName]);
            } else {
                $mo->whereRaw("LOWER($pageExpr) = LOWER($trimFn(?))", [$pageName]);
            }
        }

        $selectKey   = $AGGREGATE_RANGE ? "$pageExpr AS page_key" : "$dateExpr AS day_key, $pageExpr AS page_key";
        $groupByKey  = $AGGREGATE_RANGE ? "$pageExpr" : "$dateExpr, $pageExpr";

        $orderAgg = (clone $mo)
            ->selectRaw("$selectKey,
                COUNT(*) AS orders_total,
                SUM(CASE WHEN $statusNorm = 'proceed' THEN 1 ELSE 0 END) AS proceed_total,
                SUM(CASE WHEN $statusNorm = 'cannotproceed' THEN 1 ELSE 0 END) AS cannot_total,
                SUM(CASE WHEN $statusNorm = 'odz' THEN 1 ELSE 0 END) AS odz_total
            ")
            ->groupByRaw($groupByKey)
            ->get();

        $ordersMap = $proceedMap = $cannotMap = $odzMap = [];
        foreach ($orderAgg as $r) {
            $k = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)$r->day_key . '|' . (string)$r->page_key);
            $ordersMap[$k]  = (int)($r->orders_total ?? 0);
            $proceedMap[$k] = (int)($r->proceed_total ?? 0);
            $cannotMap[$k]  = (int)($r->cannot_total ?? 0);
            $odzMap[$k]     = (int)($r->odz_total ?? 0);
        }

        // ======================
        // from_jnts aggregate (ONLY for shipment flags & delay; NOT for COD amounts)
        // ======================
        if ($driver === 'mysql') {
            $jaMinTs = "MIN(COALESCE(
                STR_TO_DATE(j.".$quote($jSubmitColName).", '%Y-%m-%d %H:%i:%s'),
                STR_TO_DATE(j.".$quote($jSubmitColName).", '%Y/%m/%d %H:%i:%s'),
                STR_TO_DATE(j.".$quote($jSubmitColName).", '%Y-%m-%d'),
                j.`created_at`
            )) AS min_submit_ts";
        } else {
            $jaMinTs = "MIN(COALESCE(
                TO_TIMESTAMP(NULLIF(j.".$quote($jSubmitColName).",'') , 'YYYY-MM-DD HH24:MI:SS'),
                TO_TIMESTAMP(NULLIF(j.".$quote($jSubmitColName).",'') , 'YYYY/MM/DD HH24:MI:SS'),
                TO_TIMESTAMP(NULLIF(j.".$quote($jSubmitColName).",'') , 'YYYY-MM-DD'),
                j.\"created_at\"
            )) AS min_submit_ts";
        }

        $jaAgg = DB::table('from_jnts as j')
            ->selectRaw("
                j.waybill_number AS wb,
                MAX(CASE WHEN j.status LIKE 'Delivered%'  OR j.status LIKE 'DELIVERED%'  THEN 1 ELSE 0 END) AS is_delivered,
                MAX(CASE WHEN j.status LIKE 'Returned%'   OR j.status LIKE 'RETURNED%'   THEN 1 ELSE 0 END) AS is_returned,
                MAX(CASE WHEN j.status LIKE 'For Return%' OR j.status LIKE 'FOR RETURN%' THEN 1 ELSE 0 END) AS is_for_return,
                MAX(CASE WHEN j.status LIKE 'In Transit%' OR j.status LIKE 'IN TRANSIT%' THEN 1 ELSE 0 END) AS is_in_transit,
                $jaMinTs
            ")
            ->groupBy('j.waybill_number');

        // Base join (to read shipment/delivery flags)
        $joinedBase = (clone $mo)
            ->whereNotNull($moWaybillCol)
            ->where($moWaybillCol, '!=', '')
            ->joinSub($jaAgg, 'ja', function ($join) use ($moWaybillCol) {
                $join->on($moWaybillCol, '=', 'ja.wb');
            });

        // ======================
        // Shipped & status counts
        // ======================
        $shipAgg = (clone $joinedBase)
            ->selectRaw("$selectKey,
                COUNT(DISTINCT $moWaybillSql) AS shipped_total,
                COUNT(DISTINCT CASE WHEN ja.is_delivered  = 1 THEN $moWaybillSql END) AS delivered_total,
                COUNT(DISTINCT CASE WHEN ja.is_returned   = 1 THEN $moWaybillSql END) AS returned_total,
                COUNT(DISTINCT CASE WHEN ja.is_for_return = 1 THEN $moWaybillSql END) AS for_return_total,
                COUNT(DISTINCT CASE WHEN ja.is_in_transit = 1 THEN $moWaybillSql END) AS in_transit_total
            ")
            ->groupByRaw($groupByKey)
            ->get();

        $shippedMap = $deliveredMap = $returnedMap = $forReturnMap = $inTransitMap = [];
        foreach ($shipAgg as $r) {
            $k = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)$r->day_key . '|' . (string)$r->page_key);
            $shippedMap[$k]   = (int)($r->shipped_total   ?? 0);
            $deliveredMap[$k] = (int)($r->delivered_total ?? 0);
            $returnedMap[$k]  = (int)($r->returned_total  ?? 0);
            $forReturnMap[$k] = (int)($r->for_return_total?? 0);
            $inTransitMap[$k] = (int)($r->in_transit_total?? 0);
        }

        // ======================
        // Gross Sales (Delivered-only) — SUM of macro_output.COD per delivered waybill
        // ======================
        $innerDeliveredCod = (clone $joinedBase)
            ->whereRaw('ja.is_delivered = 1')
            ->selectRaw("$selectKey, $moWaybillSql AS wb, MAX($moCodClean) AS cod_mo")
            ->groupByRaw("$groupByKey, $moWaybillSql");

        if ($AGGREGATE_RANGE) {
            $grossRows = DB::query()
                ->fromSub($innerDeliveredCod, 'd')
                ->selectRaw("page_key, SUM(cod_mo) AS gross_sales")
                ->groupBy('page_key')
                ->get();
        } else {
            $grossRows = DB::query()
                ->fromSub($innerDeliveredCod, 'd')
                ->selectRaw("day_key, page_key, SUM(cod_mo) AS gross_sales")
                ->groupBy('day_key','page_key')
                ->get();
        }

        $grossMap = [];
        foreach ($grossRows as $r) {
            $k = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)$r->day_key . '|' . (string)$r->page_key);
            $grossMap[$k] = (float)($r->gross_sales ?? 0);
        }

        // ======================
        // ALL COD (all shipped) — SUM of macro_output.COD per shipped waybill
        // ======================
        $innerAllCod = (clone $joinedBase)
            ->selectRaw("$selectKey, $moWaybillSql AS wb, MAX($moCodClean) AS cod_mo")
            ->groupByRaw("$groupByKey, $moWaybillSql");

        if ($AGGREGATE_RANGE) {
            $allCodRows = DB::query()
                ->fromSub($innerAllCod, 'd')
                ->selectRaw("page_key, SUM(cod_mo) AS all_cod")
                ->groupBy('page_key')
                ->get();
        } else {
            $allCodRows = DB::query()
                ->fromSub($innerAllCod, 'd')
                ->selectRaw("day_key, page_key, SUM(cod_mo) AS all_cod")
                ->groupBy('day_key','page_key')
                ->get();
        }

        $allCodMap = [];
        foreach ($allCodRows as $r) {
            $k = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)$r->day_key . '|' . (string)$r->page_key);
            $allCodMap[$k] = (float)($r->all_cod ?? 0);
        }

        // ======================
        // ITEMS + UNIT COST LISTS for UI — PROCEED-based (no Delivered requirement)
        // ======================
        $moProceedOnly = (clone $mo)->whereRaw("$statusNorm = 'proceed'");

        if ($AGGREGATE_RANGE) {
            $itemsProceedBase = (clone $moProceedOnly)
                ->selectRaw(" $pageExpr AS page_key, $itemNorm AS item_key, MIN($itemLabel) AS item_label,
                              COUNT(*) AS qty, MAX($dateExpr) AS last_order_date")
                ->groupByRaw("$pageExpr, $itemNorm");
        } else {
            $itemsProceedBase = (clone $moProceedOnly)
                ->selectRaw(" $dateExpr AS day_key, $pageExpr AS page_key, $itemNorm AS item_key, MIN($itemLabel) AS item_label,
                              COUNT(*) AS qty, MAX($dateExpr) AS last_order_date")
                ->groupByRaw("$dateExpr, $pageExpr, $itemNorm");
        }

        // For item lists (UI display with cost per item)
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
                ->fromSub($itemsProceedBase, 'd')
                ->selectRaw("page_key, item_key, item_label, qty, $unitCostDispSub AS unit_cost_disp")
                ->get();
        } else {
            $itemsCostRows = DB::query()
                ->fromSub($itemsProceedBase, 'd')
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
        // PROCEED COD SUM per key (Σ COD from macro_output where status=proceed)
        // ======================
        if ($AGGREGATE_RANGE) {
            $procCodRows = (clone $moProceedOnly)
                ->selectRaw("$pageExpr AS page_key, SUM($moCodClean) AS proceed_cod_sum")
                ->groupByRaw("$pageExpr")
                ->get();
        } else {
            $procCodRows = (clone $moProceedOnly)
                ->selectRaw("$dateExpr AS day_key, $pageExpr AS page_key, SUM($moCodClean) AS proceed_cod_sum")
                ->groupByRaw("$dateExpr, $pageExpr")
                ->get();
        }

        $proceedCodSumMap = [];
        foreach ($procCodRows as $r) {
            $k = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)$r->day_key . '|' . (string)$r->page_key);
            $proceedCodSumMap[$k] = (float)($r->proceed_cod_sum ?? 0);
        }

        // ======================
        // PROCEED Unit Cost SUM per key (Σ qty × unit_cost for proceed)
        // ======================
        if ($AGGREGATE_RANGE) {
            $procUnitCostRows = DB::query()
                ->fromSub($itemsProceedBase, 'd')
                ->selectRaw("page_key, SUM(qty * $unitCostDispSub) AS proceed_unit_cost_sum")
                ->groupBy('page_key')->get();
        } else {
            $procUnitCostRows = DB::query()
                ->fromSub($itemsProceedBase, 'd')
                ->selectRaw("day_key, page_key, SUM(qty * $unitCostDispSub) AS proceed_unit_cost_sum")
                ->groupBy('day_key','page_key')->get();
        }

        $proceedUnitCostSumMap = [];
        foreach ($procUnitCostRows as $r) {
            $k = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)$r->day_key . '|' . (string)$r->page_key);
            $proceedUnitCostSumMap[$k] = (float)($r->proceed_unit_cost_sum ?? 0);
        }

        // ======================
        // DELAY (avg days per unique waybill) via ja.min_submit_ts
        // ======================
        if ($driver === 'mysql') {
            $jSubmitDate = "DATE(ja.min_submit_ts)";
            $delayDays   = "DATEDIFF($jSubmitDate, $dateExpr)";
        } else {
            $jSubmitDate = "DATE(ja.min_submit_ts)";
            $delayDays   = "($jSubmitDate - $dateExpr)";
        }

        $delayRaw = (clone $joinedBase)
            ->selectRaw("$selectKey, $moWaybillSql AS wb, $delayDays AS delay_days");

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
            array_keys($allCodMap),
            array_keys($itemsListMap),
            array_keys($avgDelayMap),
            array_keys($proceedCodSumMap),
            array_keys($proceedUnitCostSumMap)
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
            if ($adspent <= 0) continue; // show rows with spend only

            // Items display + unit costs (PROCEED-based) for UI
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
                $gross     = $grossMap[$key]       ?? 0.0; // delivered-only (mo.COD)
                $all_cod   = $allCodMap[$key]      ?? 0.0; // ALL shipped (mo.COD)
                $avgDelay  = $avgDelayMap[$key]    ?? null;

                $shipping_fee   = $SHIPPING_PER_SHIPPED * $shipped;
                $cpp            = $orders  > 0 ? ($adspent / $orders) * 1.0 : null;
                $proceed_cpp    = $proc    > 0 ? ($adspent / $proc)   * 1.0 : null;
                $rts_pct        = $shipped > 0 ? (($returned + $forRet) / $shipped) * 100.0 : null;
                $in_transit_pct = $shipped > 0 ? ($inTrans / $shipped) * 100.0 : null;
                $tcpr           = $orders  > 0 ? (1 - ($proc / $orders)) * 100.0 : null;

                // Actual NP (delivered-only gross)
                $net_profit     = $gross - $adspent - $shipping_fee - 0; // COGS not tracked in aggregate page mode here
                $net_profit_pct = $all_cod > 0 ? ($net_profit / $all_cod) * 100.0 : null;
                $hold           = $proc - $shipped;

                $rows[] = [
                    'date'                   => $rangeLabel,
                    'page'                   => $page !== '' ? $page : null,
                    'adspent'                => $adspent,
                    'orders'                 => $orders,
                    'proceed'                => $proc,
                    'cannot_proceed'         => $cannot,
                    'odz'                    => $odz,
                    'shipped'                => $shipped,
                    'delivered'              => $delivered,
                    'avg_delay_days'         => $avgDelay,
                    'items_display'          => $itemsDisplay,
                    'unit_costs'             => $unitCostsArr,
                    'gross_sales'            => $gross,
                    'shipping_fee'           => $shipping_fee,
                    'cogs'                   => 0.0, // not computed in "all pages" mode
                    'net_profit'             => $net_profit,
                    'net_profit_pct'         => $net_profit_pct,
                    'returned'               => $returned,
                    'for_return'             => $forRet,
                    'in_transit'             => $inTrans,
                    'cpp'                    => $cpp,
                    'proceed_cpp'            => $proceed_cpp,
                    'rts_pct'                => $rts_pct,
                    'in_transit_pct'         => $in_transit_pct,
                    'tcpr'                   => $tcpr,
                    'hold'                   => $hold,
                    'is_total'               => false,
                    'all_cod'                => $all_cod, // from mo.COD
                    'proceed_cod_sum'        => $proceedCodSumMap[$key]      ?? 0.0,
                    'proceed_unit_cost_sum'  => $proceedUnitCostSumMap[$key] ?? 0.0,
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
                $gross     = $grossMap[$key]       ?? 0.0; // delivered-only (mo.COD)
                $all_cod   = $allCodMap[$key]      ?? 0.0; // ALL shipped (mo.COD)
                $avgDelay  = $avgDelayMap[$key]    ?? null;

                $shipping_fee   = $SHIPPING_PER_SHIPPED * $shipped;
                $cpp            = $orders  > 0 ? ($adspent / $orders) * 1.0 : null;
                $proceed_cpp    = $proc    > 0 ? ($adspent / $proc)   * 1.0 : null;
                $rts_pct        = $shipped > 0 ? (($returned + $forRet) / $shipped) * 100.0 : null;
                $in_transit_pct = $shipped > 0 ? ($inTrans / $shipped) * 100.0 : null;
                $tcpr           = $orders  > 0 ? (1 - ($proc / $orders)) * 100.0 : null;

                // Actual NP (delivered-only gross)
                // NOTE: In per-page mode, you previously computed COGS (delivered-only). Keep that if you need it.
                // Here, we keep the older behavior you had: cogsMap present? Use it. Else 0.
                $cogs           = 0.0; // If you want delivered-only COGS via cogs table per item, reintroduce that calc.
                $net_profit     = $gross - $adspent - $shipping_fee - $cogs;
                $net_profit_pct = $all_cod > 0 ? ($net_profit / $all_cod) * 100.0 : null;
                $hold           = $proc - $shipped;

                $rows[] = [
                    'date'                   => $d,
                    'page'                   => $p !== '' ? $p : null,
                    'adspent'                => $adspent,
                    'orders'                 => $orders,
                    'proceed'                => $proc,
                    'cannot_proceed'         => $cannot,
                    'odz'                    => $odz,
                    'shipped'                => $shipped,
                    'delivered'              => $delivered,
                    'avg_delay_days'         => $avgDelay,
                    'items_display'          => $itemsDisplay,
                    'unit_costs'             => $unitCostsArr,
                    'gross_sales'            => $gross,
                    'shipping_fee'           => $shipping_fee,
                    'cogs'                   => $cogs,
                    'net_profit'             => $net_profit,
                    'net_profit_pct'         => $net_profit_pct,
                    'returned'               => $returned,
                    'for_return'             => $forRet,
                    'in_transit'             => $inTrans,
                    'cpp'                    => $cpp,
                    'proceed_cpp'            => $proceed_cpp,
                    'rts_pct'                => $rts_pct,
                    'in_transit_pct'         => $in_transit_pct,
                    'tcpr'                   => $tcpr,
                    'hold'                   => $hold,
                    'is_total'               => false,
                    'all_cod'                => $all_cod, // from mo.COD
                    'proceed_cod_sum'        => $proceedCodSumMap[$key]      ?? 0.0,
                    'proceed_unit_cost_sum'  => $proceedUnitCostSumMap[$key] ?? 0.0,
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

        // ===== Actual RTS (strict: only dates with <3% In-Transit)
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

        // ===== Effective RTS% used in projections (Actual → Historical → Default)
        $effectiveRtsPct = $actualRtsPct;
        if (!$AGGREGATE_RANGE && $effectiveRtsPct === null) {
            $histNum = 0; $histDen = 0;
            foreach ($rows as $r) {
                if (!empty($r['is_total'])) continue;
                $ret = (int)($r['returned']   ?? 0);
                $fr  = (int)($r['for_return'] ?? 0);
                $del = (int)($r['delivered']  ?? 0);
                $histNum += ($ret + $fr);
                $histDen += ($del + $ret + $fr);
            }
            if ($histDen > 0) {
                $effectiveRtsPct = ($histNum / $histDen) * 100.0;
            } else {
                $effectiveRtsPct = $DEFAULT_RTS_PCT;
            }
        }

        // ===== Projected Net Profit per day (ROW-LEVEL) — YOUR SPEC
        if (!$AGGREGATE_RANGE) {
            $rtsFactor = 1.0;
            if ($effectiveRtsPct !== null) {
                $rtsFactor = max(0.0, min(1.0, 1.0 - ($effectiveRtsPct / 100.0)));
            }

            foreach ($rows as &$r) {
                if (!empty($r['is_total'])) { $r['projected_net_profit'] = null; $r['projected_net_profit_pct'] = null; continue; }

                $procCodSum  = (float)($r['proceed_cod_sum']       ?? 0.0); // Σ COD (proceed; from macro_output)
                $procUCSum   = (float)($r['proceed_unit_cost_sum'] ?? 0.0); // Σ UnitCost (proceed)
                $proceedCnt  = (int)  ($r['proceed']               ?? 0);
                $adsp        = (float)($r['adspent']               ?? 0.0);

                $projGross   = $procCodSum * $rtsFactor;                    // Σ COD(proceed) × (1-RTS)
                $projCodFee  = $procCodSum * $COD_FEE_RATE;                 // 1.5% of Σ COD(proceed)
                $projCogs    = $procUCSum  * $rtsFactor;                    // Σ UnitCost(proceed) × (1-RTS)
                $projShipFee = $SHIPPING_PER_SHIPPED * $proceedCnt;         // 37 × #proceed

                $projNP = $projGross - $adsp - $projCodFee - $projCogs - $projShipFee;

                $r['projected_net_profit']     = $projNP;
                $den                           = $procCodSum;               // denom = Σ COD(proceed)
                $r['projected_net_profit_pct'] = ($den > 0) ? ($projNP / $den) * 100.0 : null;

                // store denominator for Page Summary aggregation
                $r['_proj_cod_den'] = $den;
            }
            unset($r);
        }

        // ===== TOTAL ROW =====
        if (!empty($rows)) {
            $sum = [
                'adspent' => 0.0,
                'orders' => 0, 'proceed' => 0, 'cannot_proceed' => 0, 'odz' => 0,
                'shipped' => 0, 'delivered' => 0, 'returned' => 0, 'for_return' => 0, 'in_transit' => 0,
                'gross_sales' => 0.0,
                'all_cod' => 0.0,
                'proceed_cod_sum' => 0.0,
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
                $sum['all_cod']        += (float)($r['all_cod'] ?? 0);           // mo.COD for shipped
                $sum['proceed_cod_sum']+= (float)($r['proceed_cod_sum'] ?? 0);   // mo.COD for proceed

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

            $total_net_profit     = $sum['gross_sales'] - $sum['adspent'] - $total_shipping_fee - 0.0;
            $total_net_profit_pct = $sum['all_cod'] > 0 ? ($total_net_profit / $sum['all_cod']) * 100.0 : null;
            $total_avg_delay      = $delayShipCount > 0 ? ($delayWeightedSum / $delayShipCount) : null;

            // Total Projected NP% = (Σ projected NP) / (Σ proceed COD)
            $sumProjNP  = 0.0;
            foreach ($rows as $r) {
                if (!empty($r['is_total'])) continue;
                $sumProjNP += (float)($r['projected_net_profit'] ?? 0.0);
            }
            $total_projected_net_profit_pct = ($sum['proceed_cod_sum'] > 0)
                ? ($sumProjNP / $sum['proceed_cod_sum']) * 100.0
                : null;

            $rows[] = [
                'date'            => $AGGREGATE_RANGE ? $rangeLabel : 'Total',
                'page'            => (!$AGGREGATE_RANGE && $pageName && strtolower($pageName) !== 'all') ? $pageName : 'TOTAL',
                'adspent'         => $sum['adspent'],
                'orders'          => $sum['orders'],
                'proceed'         => $sum['proceed'],
                'cannot_proceed'  => $sum['cannot_proceed'],
                'odz'             => $sum['odz'],
                'shipped'         => $sum['shipped'],
                'delivered'       => $sum['delivered'],
                'avg_delay_days'  => $total_avg_delay,
                'items_display'   => '—',
                'unit_costs'      => [],
                'gross_sales'     => $sum['gross_sales'],
                'shipping_fee'    => $total_shipping_fee,
                'cogs'            => 0.0,
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
                'projected_net_profit' => null,
                'projected_net_profit_pct'  => $total_projected_net_profit_pct,
            ];
        }

        // ===== TOP SUMMARY (server-side; page != 'all')
        $topSummary = [];
        if (!$AGGREGATE_RANGE) {
            $daily = array_values(array_filter($rows, fn($r) => empty($r['is_total'])));
            $dates = array_values(array_unique(array_map(fn($r) => (string)$r['date'], $daily)));
            sort($dates);
            if (!empty($dates)) {
                $lastDate = $dates[count($dates)-1];
                $datesBeforeLast = $dates;
                array_pop($datesBeforeLast); // exclude last day from Last 7 / Last 3

                $pickRowsOn = function(array $wanted) use ($daily) {
                    $set = array_flip($wanted);
                    return array_values(array_filter($daily, fn($r) => isset($set[$r['date']])));
                };

                $rowsFiltered = $daily;
                $rowsLast7    = $pickRowsOn(array_slice($datesBeforeLast, -7));
                $rowsLast3    = $pickRowsOn(array_slice($datesBeforeLast, -3));
                $rowsLast1    = $pickRowsOn([$lastDate]);

                $aggregate = function(array $subset) {
                    if (!count($subset)) return ['adspent'=>null,'proceed_cpp'=>null,'pn_pct'=>null];

                    $adSum   = 0.0;
                    $procSum = 0;
                    $npSum   = 0.0;
                    $denSum  = 0.0; // Σ proceed COD (from macro_output)

                    foreach ($subset as $r) {
                        $adSum   += (float)($r['adspent'] ?? 0);
                        $procSum += (int)  ($r['proceed'] ?? 0);
                        $npSum   += (float)($r['projected_net_profit'] ?? 0.0);
                        $denSum  += (float)($r['_proj_cod_den']        ?? 0.0);
                    }

                    $proceedCPP = ($procSum > 0) ? ($adSum / $procSum) : null;
                    $pn_pct     = ($denSum > 0) ? ($npSum / $denSum) * 100.0 : null;

                    return ['adspent'=>$adSum,'proceed_cpp'=>$proceedCPP,'pn_pct'=>$pn_pct];
                };

                $filteredLabel = $makeFilteredLabel($start, $end);
                $last1Label    = ($lastDate === $today) ? 'Today' : ('Last Day (' . $fmtMonthDay($lastDate) . ')');

                $topSummary = [
                    ['key'=>'filtered','rangeLabel'=>$filteredLabel, ...$aggregate($rowsFiltered)],
                    ['key'=>'last7',   'rangeLabel'=>'Last 7 Days', ...$aggregate($rowsLast7)],
                    ['key'=>'last3',   'rangeLabel'=>'Last 3 Days', ...$aggregate($rowsLast3)],
                    ['key'=>'last1',   'rangeLabel'=>$last1Label, ...$aggregate($rowsLast1)],
                ];
            }
        }

        return response()->json([
            'ads_daily'      => $rows,
            'actual_rts_pct' => $actualRtsPct,
            'top_summary'    => $topSummary,
        ]);
    }
}
