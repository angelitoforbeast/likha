<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;
use App\Models\FeeSetting;

class OwnerPrivateController extends Controller
{
    private function checkAccess(): void
    {
        $roleRaw  = Auth::user()?->employeeProfile?->role ?? '';
        $roleNorm = preg_replace('/\s+/u', ' ', trim((string) $roleRaw));
        $isCEO    = preg_match('/^ceo$/iu', $roleNorm) === 1;
        if (!$isCEO) abort(404);
    }

    public function index()
    {
        $this->checkAccess();

        $driver = DB::getDriverName();
        $trimFn = $driver === 'pgsql' ? 'BTRIM' : 'TRIM';

        $pages = DB::table('ads_manager_reports')
            ->whereNotNull('page_name')
            ->selectRaw("$trimFn(page_name) AS page_name")
            ->distinct()
            ->orderBy('page_name')
            ->pluck('page_name')
            ->toArray();

        $userRoleRaw    = Auth::user()?->employeeProfile?->role ?? '';
        $roleNorm       = preg_replace('/\s+/u', ' ', trim((string)$userRoleRaw));
        $isMarketingOIC = preg_match('/^marketing\s*[-–—]\s*oic$/iu', $roleNorm) === 1;
        $isCEO          = preg_match('/^ceo$/iu', $roleNorm) === 1;

        return view('owner.private', compact('pages', 'isCEO', 'isMarketingOIC'));
    }

    public function data(Request $request)
    {
        $this->checkAccess();

        // PH timezone (for "Today" label in top summary)
        $phTz  = new \DateTimeZone('Asia/Manila');
        $today = (new \DateTime('now', $phTz))->format('Y-m-d');

        $start    = $request->input('start_date');
        $end      = $request->input('end_date');
        $pageName = $request->input('page_name', 'all');

        $driver = DB::getDriverName(); // 'mysql' | 'pgsql'
        $trimFn = $driver === 'pgsql' ? 'BTRIM' : 'TRIM';

        // === FEE SETTINGS (from database with effective date) ===
        $host = strtolower((string) $request->getHost());
        $refDate = $end ?? $start ?? $today;
        $COD_FEE_RATE    = FeeSetting::getRate('cod_fee_rate', $host, $refDate) ?? 0.015;
        $COD_FEE_VAT_RATE = FeeSetting::getRate('cod_fee_vat_rate', $host, $refDate) ?? 0.12;
        $SHIPPING_PER_SHIPPED = 37.0;
        $DEFAULT_RTS_PCT      = 30.0;

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

        $castMoney = function (string $expr) use ($driver) {
            return $driver === 'pgsql'
                ? "COALESCE(NULLIF(REGEXP_REPLACE(COALESCE(($expr)::text, ''), '[^0-9\\.\\-]', '', 'g'), '')::numeric, 0)"
                : "CAST(REPLACE(REPLACE(REPLACE(COALESCE($expr,''), '₱',''), ',', ''), ' ', '') AS DECIMAL(18,2))";
        };

        $pageColName = $pickCol('macro_output', ['PAGE','page','page_name','Page','Page_Name']);
        if (!$pageColName) throw new \RuntimeException('macro_output: page column not found');
        $moPageSql = 'mo.' . $quote($pageColName);
        $moPageCol = 'mo.' . $pageColName;

        $pageLabelExpr = "$trimFn(COALESCE($moPageSql,''))";
        $pageKeyExpr   = "LOWER($pageLabelExpr)";

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

        $moCodColName = $pickCol('macro_output', ['COD','cod','Cod']) ?? 'COD';
        $moCodExpr    = 'mo.' . $quote($moCodColName);
        $moCodClean   = $castMoney($moCodExpr);

        $hasTsDate = Schema::hasColumn('macro_output', 'ts_date');

        if ($hasTsDate) {
            $dateExpr = "mo.ts_date";
        } else {
            $tsCols = [];
            foreach (['TIMESTAMP','timestamp'] as $c) if ($pickCol('macro_output', [$c])) $tsCols[] = $c;

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
            } else {
                $pgParts = [];
                foreach ($tsCols as $c) {
                    $ref = 'mo.' . $quote($c);
                    $pgParts[] = "TO_TIMESTAMP(NULLIF($ref, ''), 'HH24:MI DD-MM-YYYY')";
                    $pgParts[] = "TO_TIMESTAMP(NULLIF($ref, ''), 'HH24:MI MM-DD-YYYY')";
                }
                $pgParts[] = 'mo."created_at"';
                $dateExpr  = 'DATE(COALESCE(' . implode(', ', $pgParts) . '))';
            }
        }

        $castSpend = $castMoney('amount_spent_php');

        $jSubmitColName = $pickCol('from_jnts', ['submission_time','submitted_at','submission_datetime','submissiondate','submission']) ?? 'submission_time';

        $cogsItemColName = $pickCol('cogs', ['item_name','ITEM_NAME','product','Product','Product_Name']) ?? 'item_name';
        $cogsItemExpr    = 'c.' . $quote($cogsItemColName);
        $cogsItemNorm    = "LOWER(REPLACE(REPLACE(REPLACE($trimFn(COALESCE($cogsItemExpr,'')),' ',''),'-',''),'_',''))";

        $cogsDateColName = $pickCol('cogs', ['effective_date','date','valid_from','cogs_date']) ?? 'effective_date';
        $cogsDateExpr    = 'c.' . $quote($cogsDateColName);

        $cogsUnitColName = $pickCol('cogs', ['unit_cost','cost','unitprice','unit_price','price']) ?? 'unit_cost';
        $cogsUnitExpr    = 'c.' . $quote($cogsUnitColName);

        $AGGREGATE_RANGE = ($pageName === 'all');
        $pageNameNorm = $AGGREGATE_RANGE ? null : mb_strtolower(trim((string)$pageName));

        $adsPageLabelExpr = "$trimFn(COALESCE(page_name,''))";
        $adsPageKeyExpr   = "LOWER($adsPageLabelExpr)";

        $adsBase = DB::table('ads_manager_reports');

        if ($start && $end) {
            $adsBase->whereRaw('DATE(day) BETWEEN ? AND ?', [$start, $end]);
        } elseif ($start) {
            $adsBase->whereRaw('DATE(day) >= ?', [$start]);
        } elseif ($end) {
            $adsBase->whereRaw('DATE(day) <= ?', [$end]);
        }

        if (!$AGGREGATE_RANGE && $pageNameNorm !== null && $pageNameNorm !== '') {
            $adsBase->whereRaw("$adsPageKeyExpr = ?", [$pageNameNorm]);
        }

        if ($AGGREGATE_RANGE) {
            $adsRows = (clone $adsBase)
                ->selectRaw("$adsPageKeyExpr AS page_key, MIN($adsPageLabelExpr) AS page_label, SUM($castSpend) AS adspent")
                ->groupByRaw("$adsPageKeyExpr")
                ->havingRaw("SUM($castSpend) > 0")
                ->orderBy('page_key')
                ->get();
        } else {
            $adsRows = (clone $adsBase)
                ->whereNotNull('day')
                ->selectRaw("DATE(day) AS day_key, $adsPageKeyExpr AS page_key, MIN($adsPageLabelExpr) AS page_label, SUM($castSpend) AS adspent")
                ->groupByRaw("DATE(day), $adsPageKeyExpr")
                ->havingRaw("SUM($castSpend) > 0")
                ->orderBy('day_key', 'asc')
                ->orderBy('page_key', 'asc')
                ->get();
        }

        $adsMap   = [];
        $labelMap = [];
        foreach ($adsRows as $r) {
            if ($AGGREGATE_RANGE) {
                $k = (string)$r->page_key;
                $adsMap[$k] = (float)($r->adspent ?? 0);
                if (!empty($r->page_label)) $labelMap[$k] = (string)$r->page_label;
            } else {
                $k = (string)$r->day_key . '|' . (string)$r->page_key;
                $adsMap[$k] = (float)($r->adspent ?? 0);
                if (!empty($r->page_label)) $labelMap[$k] = (string)$r->page_label;
            }
        }

        $mo = DB::table('macro_output as mo');

        if ($start && $end) {
            $mo->whereRaw("$dateExpr BETWEEN ? AND ?", [$start, $end]);
        } elseif ($start) {
            $mo->whereRaw("$dateExpr >= ?", [$start]);
        } elseif ($end) {
            $mo->whereRaw("$dateExpr <= ?", [$end]);
        }

        if (!$AGGREGATE_RANGE && $pageNameNorm !== null && $pageNameNorm !== '') {
            $mo->whereRaw("$pageKeyExpr = ?", [$pageNameNorm]);
        }

        if ($AGGREGATE_RANGE) {
            $selectKeyAgg  = "$pageKeyExpr AS page_key, MIN($pageLabelExpr) AS page_label";
            $groupByKey    = "$pageKeyExpr";
            $selectKeyBase = "$pageKeyExpr AS page_key, $pageLabelExpr AS page_label";
        } else {
            $selectKeyAgg  = "$dateExpr AS day_key, $pageKeyExpr AS page_key, MIN($pageLabelExpr) AS page_label";
            $groupByKey    = "$dateExpr, $pageKeyExpr";
            $selectKeyBase = "$dateExpr AS day_key, $pageKeyExpr AS page_key, $pageLabelExpr AS page_label";
        }

        $orderAgg = (clone $mo)
            ->selectRaw("$selectKeyAgg,
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
            if (!empty($r->page_label)) $labelMap[$k] = (string)$r->page_label;
        }

        $dateFilterParts = [];
        $dateFilterBindings = [];
        if ($start && $end) {
            $dateFilterParts[] = "$dateExpr BETWEEN ? AND ?";
            $dateFilterBindings = [$start, $end];
        } elseif ($start) {
            $dateFilterParts[] = "$dateExpr >= ?";
            $dateFilterBindings = [$start];
        } elseif ($end) {
            $dateFilterParts[] = "$dateExpr <= ?";
            $dateFilterBindings = [$end];
        }

        $pageFilterSql = '';
        if (!$AGGREGATE_RANGE && $pageNameNorm !== null && $pageNameNorm !== '') {
            $pageFilterSql = " AND $pageKeyExpr = ?";
            $dateFilterBindings[] = $pageNameNorm;
        }

        $dateWhere = !empty($dateFilterParts) ? 'WHERE ' . implode(' AND ', $dateFilterParts) . $pageFilterSql : ($pageFilterSql ? 'WHERE 1=1' . $pageFilterSql : '');

        if ($driver === 'mysql') {
            DB::statement("DROP TEMPORARY TABLE IF EXISTS _jnt_agg");

            $jaMinTsExpr = "MIN(COALESCE(
                STR_TO_DATE(j.`$jSubmitColName`, '%Y-%m-%d %H:%i:%s'),
                STR_TO_DATE(j.`$jSubmitColName`, '%Y/%m/%d %H:%i:%s'),
                STR_TO_DATE(j.`$jSubmitColName`, '%Y-%m-%d'),
                j.`created_at`
            ))";

            DB::statement("
                CREATE TEMPORARY TABLE _jnt_agg AS
                SELECT
                    j.waybill_number AS wb,
                    MAX(CASE WHEN j.status LIKE 'Delivered%'  OR j.status LIKE 'DELIVERED%'  THEN 1 ELSE 0 END) AS is_delivered,
                    MAX(CASE WHEN j.status LIKE 'Returned%'   OR j.status LIKE 'RETURNED%'   THEN 1 ELSE 0 END) AS is_returned,
                    MAX(CASE WHEN j.status LIKE 'For Return%' OR j.status LIKE 'FOR RETURN%' THEN 1 ELSE 0 END) AS is_for_return,
                    MAX(CASE
                        WHEN j.status LIKE 'Delivered%'  OR j.status LIKE 'DELIVERED%'  THEN 0
                        WHEN j.status LIKE 'Returned%'   OR j.status LIKE 'RETURNED%'   THEN 0
                        WHEN j.status LIKE 'For Return%' OR j.status LIKE 'FOR RETURN%' THEN 0
                        ELSE 1
                    END) AS is_in_transit,
                    $jaMinTsExpr AS min_submit_ts,
                    COALESCE(MAX(CAST(j.total_shipping_cost AS DECIMAL(18,2))), 0) AS actual_shipping_cost
                FROM from_jnts j
                WHERE j.waybill_number IN (
                    SELECT DISTINCT $moWaybillSql
                    FROM macro_output mo
                    $dateWhere
                    AND $moWaybillSql IS NOT NULL AND $moWaybillSql != ''
                )
                GROUP BY j.waybill_number
            ", $dateFilterBindings);

            DB::statement("ALTER TABLE _jnt_agg ADD PRIMARY KEY (wb)");
        } else {
            DB::statement("DROP TABLE IF EXISTS _jnt_agg");

            $jaMinTsExpr = "MIN(COALESCE(
                TO_TIMESTAMP(NULLIF(j.\"$jSubmitColName\",'') , 'YYYY-MM-DD HH24:MI:SS'),
                TO_TIMESTAMP(NULLIF(j.\"$jSubmitColName\",'') , 'YYYY/MM/DD HH24:MI:SS'),
                TO_TIMESTAMP(NULLIF(j.\"$jSubmitColName\",'') , 'YYYY-MM-DD'),
                j.\"created_at\"
            ))";

            DB::statement("
                CREATE TEMPORARY TABLE _jnt_agg AS
                SELECT
                    j.waybill_number AS wb,
                    MAX(CASE WHEN j.status LIKE 'Delivered%'  OR j.status LIKE 'DELIVERED%'  THEN 1 ELSE 0 END)::int AS is_delivered,
                    MAX(CASE WHEN j.status LIKE 'Returned%'   OR j.status LIKE 'RETURNED%'   THEN 1 ELSE 0 END)::int AS is_returned,
                    MAX(CASE WHEN j.status LIKE 'For Return%' OR j.status LIKE 'FOR RETURN%' THEN 1 ELSE 0 END)::int AS is_for_return,
                    MAX(CASE
                        WHEN j.status LIKE 'Delivered%'  OR j.status LIKE 'DELIVERED%'  THEN 0
                        WHEN j.status LIKE 'Returned%'   OR j.status LIKE 'RETURNED%'   THEN 0
                        WHEN j.status LIKE 'For Return%' OR j.status LIKE 'FOR RETURN%' THEN 0
                        ELSE 1
                    END)::int AS is_in_transit,
                    $jaMinTsExpr AS min_submit_ts,
                    COALESCE(MAX(CAST(j.total_shipping_cost AS DECIMAL(18,2))), 0) AS actual_shipping_cost
                FROM from_jnts j
                WHERE j.waybill_number IN (
                    SELECT DISTINCT $moWaybillSql
                    FROM macro_output mo
                    $dateWhere
                    AND $moWaybillSql IS NOT NULL AND $moWaybillSql != ''
                )
                GROUP BY j.waybill_number
            ", $dateFilterBindings);

            DB::statement("ALTER TABLE _jnt_agg ADD PRIMARY KEY (wb)");
        }

        $cogsAll = DB::table('cogs')
            ->selectRaw("
                LOWER(REPLACE(REPLACE(REPLACE($trimFn(COALESCE(" . $quote($cogsItemColName) . ",'')),' ',''),'-',''),'_','')) AS item_key,
                DATE(" . $quote($cogsDateColName) . ") AS eff_date,
                " . $castMoney($quote($cogsUnitColName)) . " AS unit_cost
            ")
            ->orderByRaw("LOWER(REPLACE(REPLACE(REPLACE($trimFn(COALESCE(" . $quote($cogsItemColName) . ",'')),' ',''),'-',''),'_','')) ASC, DATE(" . $quote($cogsDateColName) . ") DESC")
            ->get();

        $cogsLookup = [];
        foreach ($cogsAll as $row) {
            $cogsLookup[(string)$row->item_key][] = [
                'date' => (string)$row->eff_date,
                'cost' => (float)$row->unit_cost,
            ];
        }

        $findUnitCost = function(string $itemKey, string $maxDate) use ($cogsLookup): float {
            if (!isset($cogsLookup[$itemKey])) return 0.0;
            foreach ($cogsLookup[$itemKey] as $entry) {
                if ($entry['date'] <= $maxDate) return $entry['cost'];
            }
            return 0.0;
        };

        $findAllUnitCosts = function(string $itemKey, ?string $startDate = null, ?string $endDate = null) use ($cogsLookup, &$findUnitCost): array {
            if (!isset($cogsLookup[$itemKey])) return [];

            if ($startDate && $endDate) {
                $costsInRange = [];
                $carryForward = $findUnitCost($itemKey, $endDate);
                if ($carryForward > 0) {
                    $costsInRange[] = $carryForward;
                }
                foreach ($cogsLookup[$itemKey] as $entry) {
                    if ($entry['date'] >= $startDate && $entry['date'] <= $endDate && $entry['cost'] > 0) {
                        $costsInRange[] = $entry['cost'];
                    }
                }
                if (empty($costsInRange)) return [];
                $costs = array_unique($costsInRange);
                sort($costs);
                return array_values($costs);
            }

            $costs = array_unique(array_map(fn($e) => $e['cost'], $cogsLookup[$itemKey]));
            sort($costs);
            return array_values($costs);
        };

        $joinedBase = (clone $mo)
            ->whereNotNull($moWaybillCol)
            ->where($moWaybillCol, '!=', '')
            ->join('_jnt_agg AS ja', $moWaybillCol, '=', 'ja.wb');

        $shipAgg = (clone $joinedBase)
            ->selectRaw("$selectKeyAgg,
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
            if (!empty($r->page_label)) $labelMap[$k] = (string)$r->page_label;
        }

        $actualShipAgg = (clone $joinedBase)
            ->selectRaw("$selectKeyAgg,
                SUM(ja.actual_shipping_cost) AS total_actual_shipping")
            ->groupByRaw($groupByKey)
            ->get();

        $actualShippingMap = [];
        foreach ($actualShipAgg as $r) {
            $k = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)$r->day_key . '|' . (string)$r->page_key);
            $actualShippingMap[$k] = (float)($r->total_actual_shipping ?? 0);
        }

        $innerDeliveredCod = (clone $joinedBase)
            ->whereRaw('ja.is_delivered = 1')
            ->selectRaw("$selectKeyAgg, $moWaybillSql AS wb, MAX($moCodClean) AS cod_mo")
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

        $innerAllCod = (clone $joinedBase)
            ->selectRaw("$selectKeyAgg, $moWaybillSql AS wb, MAX($moCodClean) AS cod_mo")
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

        $moProceedOnly = (clone $mo)->whereRaw("$statusNorm = 'proceed'");

        $itemsProceedRows = (clone $moProceedOnly)
            ->selectRaw("$selectKeyAgg,
                          $itemNorm AS item_key,
                          MIN($itemLabel) AS item_label,
                          COUNT(*) AS qty,
                          MAX($dateExpr) AS last_order_date,
                          MIN($moCodClean) AS min_cod,
                          MAX($moCodClean) AS max_cod")
            ->groupByRaw("$groupByKey, $itemNorm")
            ->get();

        $itemsListMap = [];
        $itemsCostRows = [];
        foreach ($itemsProceedRows as $r) {
            $key = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)($r->day_key ?? '') . '|' . (string)$r->page_key);
            $unitCost = $findUnitCost((string)$r->item_key, (string)$r->last_order_date);
            $allCosts = $findAllUnitCosts((string)$r->item_key, $start, $end);

            $itemsListMap[$key] ??= [];
            $itemsListMap[$key][] = [
                'label'     => (string)($r->item_label ?? ''),
                'qty'       => (int)($r->qty ?? 0),
                'unit_cost' => $unitCost,
                'all_costs' => $allCosts,
                'min_cod'   => (float)($r->min_cod ?? 0),
                'max_cod'   => (float)($r->max_cod ?? 0),
            ];
            if (!empty($r->page_label)) $labelMap[$key] = (string)$r->page_label;

            $itemsCostRows[] = (object)[
                'item_key' => (string)$r->item_key,
                'qty' => (int)($r->qty ?? 0),
                'unit_cost_disp' => $unitCost,
            ];
        }

        $procCodRows = (clone $moProceedOnly)
            ->selectRaw("$selectKeyAgg, SUM($moCodClean) AS proceed_cod_sum")
            ->groupByRaw("$groupByKey")
            ->get();

        $proceedCodSumMap = [];
        foreach ($procCodRows as $r) {
            $k = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)$r->day_key . '|' . (string)$r->page_key);
            $proceedCodSumMap[$k] = (float)($r->proceed_cod_sum ?? 0);
            if (!empty($r->page_label)) $labelMap[$k] = (string)$r->page_label;
        }

        $proceedUnitCostSumMap = [];
        foreach ($itemsProceedRows as $r) {
            $key = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)($r->day_key ?? '') . '|' . (string)$r->page_key);
            $unitCost = $findUnitCost((string)$r->item_key, (string)$r->last_order_date);
            $proceedUnitCostSumMap[$key] = ($proceedUnitCostSumMap[$key] ?? 0.0) + ((int)$r->qty * $unitCost);
            if (!empty($r->page_label)) $labelMap[$key] = (string)$r->page_label;
        }

        if ($driver === 'mysql') {
            $jSubmitDate = "DATE(ja.min_submit_ts)";
            $delayDays   = "DATEDIFF($jSubmitDate, $dateExpr)";
        } else {
            $jSubmitDate = "DATE(ja.min_submit_ts)";
            $delayDays   = "($jSubmitDate - $dateExpr)";
        }

        $delayRaw = (clone $joinedBase)
            ->selectRaw("$selectKeyBase, $moWaybillSql AS wb, $delayDays AS delay_days");

        if ($AGGREGATE_RANGE) {
            $delayDistinct = DB::query()
                ->fromSub($delayRaw, 'r')
                ->selectRaw("page_key, MIN(page_label) AS page_label, wb, MIN(delay_days) AS delay_days")
                ->groupBy('page_key','wb');

            $delayAvgRows = DB::query()
                ->fromSub($delayDistinct, 'd')
                ->selectRaw("page_key, MIN(page_label) AS page_label, AVG(delay_days) AS avg_delay_days")
                ->groupBy('page_key')
                ->get();
        } else {
            $delayDistinct = DB::query()
                ->fromSub($delayRaw, 'r')
                ->selectRaw("day_key, page_key, MIN(page_label) AS page_label, wb, MIN(delay_days) AS delay_days")
                ->groupBy('day_key','page_key','wb');

            $delayAvgRows = DB::query()
                ->fromSub($delayDistinct, 'd')
                ->selectRaw("day_key, page_key, MIN(page_label) AS page_label, AVG(delay_days) AS avg_delay_days")
                ->groupBy('day_key','page_key')
                ->get();
        }

        $avgDelayMap = [];
        foreach ($delayAvgRows as $r) {
            $k = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)$r->day_key . '|' . (string)$r->page_key);
            $avgDelayMap[$k] = (float)($r->avg_delay_days ?? 0);
            if (!empty($r->page_label)) $labelMap[$k] = (string)$r->page_label;
        }

        $deliveredItemsRows = (clone $joinedBase)
            ->whereRaw('ja.is_delivered = 1')
            ->selectRaw("$selectKeyAgg, $itemNorm AS item_key, MIN($itemLabel) AS item_label,
                         COUNT(DISTINCT $moWaybillSql) AS qty, MAX($dateExpr) AS last_order_date")
            ->groupByRaw("$groupByKey, $itemNorm")
            ->get();

        $cogsMap = [];
        foreach ($deliveredItemsRows as $r) {
            $k = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)$r->day_key . '|' . (string)$r->page_key);
            $unitCost = $findUnitCost((string)$r->item_key, (string)$r->last_order_date);
            $cogsMap[$k] = ($cogsMap[$k] ?? 0.0) + ((int)$r->qty * $unitCost);
        }

        $perDatePageShipMap = [];
        if ($AGGREGATE_RANGE) {
            $perDatePageShipRows = (clone $joinedBase)
                ->selectRaw("$dateExpr AS day_key, $pageKeyExpr AS page_key,
                    COUNT(DISTINCT $moWaybillSql) AS shipped_total,
                    COUNT(DISTINCT CASE WHEN ja.is_delivered  = 1 THEN $moWaybillSql END) AS delivered_total,
                    COUNT(DISTINCT CASE WHEN ja.is_returned   = 1 THEN $moWaybillSql END) AS returned_total,
                    COUNT(DISTINCT CASE WHEN ja.is_for_return = 1 THEN $moWaybillSql END) AS for_return_total,
                    COUNT(DISTINCT CASE WHEN ja.is_in_transit = 1 THEN $moWaybillSql END) AS in_transit_total
                ")
                ->groupByRaw("$dateExpr, $pageKeyExpr")
                ->get();

            foreach ($perDatePageShipRows as $r) {
                $pk = (string)$r->page_key;
                $perDatePageShipMap[$pk][] = [
                    'shipped'    => (int)($r->shipped_total ?? 0),
                    'delivered'  => (int)($r->delivered_total ?? 0),
                    'returned'   => (int)($r->returned_total ?? 0),
                    'for_return' => (int)($r->for_return_total ?? 0),
                    'in_transit' => (int)($r->in_transit_total ?? 0),
                ];
            }
        }

        if ($driver === 'mysql') {
            DB::statement("DROP TEMPORARY TABLE IF EXISTS _jnt_agg");
        } else {
            DB::statement("DROP TABLE IF EXISTS _jnt_agg");
        }

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
            array_keys($proceedUnitCostSumMap),
            array_keys($cogsMap)
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
            if ($adspent <= 0) continue;

            $itemsDisplay = null;
            $unitCostsArr = [];
            $itemsWithCosts = [];
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

                    $allCosts = $it['all_costs'] ?? [(float)$it['unit_cost']];
                    if (count($allCosts) <= 1) {
                        $costStr = '₱' . number_format($allCosts[0] ?? 0, 2);
                    } else {
                        $costStr = '₱' . number_format(min($allCosts), 2) . ' – ₱' . number_format(max($allCosts), 2);
                    }
                    $minCod = (float)($it['min_cod'] ?? 0);
                    $maxCod = (float)($it['max_cod'] ?? 0);
                    if ($minCod == $maxCod || $maxCod <= 0) {
                        $codStr = '₱' . number_format($maxCod > 0 ? $maxCod : $minCod, 2);
                    } else {
                        $codStr = '₱' . number_format($minCod, 2) . ' – ₱' . number_format($maxCod, 2);
                    }

                    $itemsWithCosts[] = [
                        'label' => $lbl,
                        'cost'  => $costStr,
                        'cod'   => $codStr,
                    ];
                }
                $itemsDisplay = implode(' / ', $labels);
            }

            $pageLabelResolved = $labelMap[$key] ?? null;

            if ($AGGREGATE_RANGE) {
                $orders    = $ordersMap[$key]      ?? 0;
                $proc      = $proceedMap[$key]     ?? 0;
                $cannot    = $cannotMap[$key]      ?? 0;
                $odz       = $odzMap[$key]         ?? 0;
                $shipped   = $shippedMap[$key]     ?? 0;
                $delivered = $deliveredMap[$key]   ?? 0;
                $returned  = $returnedMap[$key]    ?? 0;
                $forRet    = $forReturnMap[$key]   ?? 0;
                $inTrans   = $inTransitMap[$key]   ?? 0;
                $gross     = $grossMap[$key]       ?? 0.0;
                $all_cod   = $allCodMap[$key]      ?? 0.0;
                $avgDelay  = $avgDelayMap[$key]    ?? null;
                $cogs      = $cogsMap[$key]        ?? 0.0;

                $shipping_fee   = $actualShippingMap[$key] ?? ($SHIPPING_PER_SHIPPED * $shipped);
                $cod_fee_actual = $gross * $COD_FEE_RATE;
                $cod_fee_vat    = $cod_fee_actual * $COD_FEE_VAT_RATE;

                $cpp            = $orders  > 0 ? ($adspent / $orders) * 1.0 : null;
                $proceed_cpp    = $proc    > 0 ? ($adspent / $proc)   * 1.0 : null;
                $rts_pct        = $shipped > 0 ? (($returned + $forRet) / $shipped) * 100.0 : null;
                $in_transit_pct = $shipped > 0 ? ($inTrans / $shipped) * 100.0 : null;
                $tcpr           = $orders  > 0 ? (1 - ($proc / $orders)) * 100.0 : null;

                $net_profit     = $gross - $adspent - $shipping_fee - $cod_fee_actual - $cod_fee_vat - $cogs;
                $net_profit_pct = $all_cod > 0 ? ($net_profit / $all_cod) * 100.0 : null;
                $hold           = $proc - $shipped;

                $rows[] = [
                    'date'                   => $rangeLabel,
                    'page'                   => $pageLabelResolved ?: null,
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
                    'items_with_costs'       => $itemsWithCosts,
                    'gross_sales'            => $gross,
                    'shipping_fee'           => $shipping_fee,
                    'cod_fee'                => $cod_fee_actual,
                    'cod_fee_vat'            => $cod_fee_vat,
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
                    'all_cod'                => $all_cod,
                    'proceed_cod_sum'        => $proceedCodSumMap[$key]      ?? 0.0,
                    'proceed_unit_cost_sum'  => $proceedUnitCostSumMap[$key] ?? 0.0,
                ];
            } else {
                [$d, $pKey] = explode('|', $key, 2);
                $orders    = $ordersMap[$key]      ?? 0;
                $proc      = $proceedMap[$key]     ?? 0;
                $cannot    = $cannotMap[$key]      ?? 0;
                $odz       = $odzMap[$key]         ?? 0;
                $shipped   = $shippedMap[$key]     ?? 0;
                $delivered = $deliveredMap[$key]   ?? 0;
                $returned  = $returnedMap[$key]    ?? 0;
                $forRet    = $forReturnMap[$key]   ?? 0;
                $inTrans   = $inTransitMap[$key]   ?? 0;
                $gross     = $grossMap[$key]       ?? 0.0;
                $all_cod   = $allCodMap[$key]      ?? 0.0;
                $avgDelay  = $avgDelayMap[$key]    ?? null;
                $cogs      = $cogsMap[$key]        ?? 0.0;

                $shipping_fee   = $actualShippingMap[$key] ?? ($SHIPPING_PER_SHIPPED * $shipped);
                $cod_fee_actual = $gross * $COD_FEE_RATE;
                $cod_fee_vat    = $cod_fee_actual * $COD_FEE_VAT_RATE;

                $cpp            = $orders  > 0 ? ($adspent / $orders) * 1.0 : null;
                $proceed_cpp    = $proc    > 0 ? ($adspent / $proc)   * 1.0 : null;
                $rts_pct        = $shipped > 0 ? (($returned + $forRet) / $shipped) * 100.0 : null;
                $in_transit_pct = $shipped > 0 ? ($inTrans / $shipped) * 100.0 : null;
                $tcpr           = $orders  > 0 ? (1 - ($proc / $orders)) * 100.0 : null;

                $net_profit     = $gross - $adspent - $shipping_fee - $cod_fee_actual - $cod_fee_vat - $cogs;
                $net_profit_pct = $all_cod > 0 ? ($net_profit / $all_cod) * 100.0 : null;
                $hold           = $proc - $shipped;

                $rows[] = [
                    'date'                   => $d,
                    'page'                   => $pageLabelResolved ?: null,
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
                    'items_with_costs'       => $itemsWithCosts,
                    'gross_sales'            => $gross,
                    'shipping_fee'           => $shipping_fee,
                    'cod_fee'                => $cod_fee_actual,
                    'cod_fee_vat'            => $cod_fee_vat,
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
                    'all_cod'                => $all_cod,
                    'proceed_cod_sum'        => $proceedCodSumMap[$key]      ?? 0.0,
                    'proceed_unit_cost_sum'  => $proceedUnitCostSumMap[$key] ?? 0.0,
                ];
            }
        }

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

        $actualRtsPct = null;
        if (!$AGGREGATE_RANGE) {
            $num = 0;
            $den = 0;
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

        if ($AGGREGATE_RANGE) {
            foreach ($rows as &$r) {
                if (!empty($r['is_total'])) { $r['projected_net_profit'] = null; $r['projected_net_profit_pct'] = null; continue; }

                $pageKey = mb_strtolower(trim((string)($r['page'] ?? '')));
                $perDateRows = $perDatePageShipMap[$pageKey] ?? [];

                $estRtsNum = 0;
                $estRtsDen = 0;
                foreach ($perDateRows as $pd) {
                    $pdShipped = $pd['shipped'];
                    $pdInTransitPct = $pdShipped > 0 ? ($pd['in_transit'] / $pdShipped) * 100.0 : null;
                    if ($pdInTransitPct !== null && $pdInTransitPct < 3.0) {
                        $estRtsNum += ($pd['returned'] + $pd['for_return']);
                        $estRtsDen += ($pd['delivered'] + $pd['returned'] + $pd['for_return']);
                    }
                }

                $pageRtsPct = null;
                if ($estRtsDen > 0) {
                    $pageRtsPct = ($estRtsNum / $estRtsDen) * 100.0;
                } else {
                    $fallbackNum = 0; $fallbackDen = 0;
                    foreach ($perDateRows as $pd) {
                        $fallbackNum += ($pd['returned'] + $pd['for_return']);
                        $fallbackDen += ($pd['delivered'] + $pd['returned'] + $pd['for_return']);
                    }
                    $pageRtsPct = $fallbackDen > 0 ? ($fallbackNum / $fallbackDen) * 100.0 : null;
                }

                if ($pageRtsPct === null) $pageRtsPct = $DEFAULT_RTS_PCT;
                $r['actual_rts_pct'] = $pageRtsPct;
                $rtsFactor = max(0.0, min(1.0, 1.0 - ($pageRtsPct / 100.0)));

                $procCodSum  = (float)($r['proceed_cod_sum']       ?? 0.0);
                $procUCSum   = (float)($r['proceed_unit_cost_sum'] ?? 0.0);
                $proceedCnt  = (int)  ($r['proceed']               ?? 0);
                $adsp        = (float)($r['adspent']               ?? 0.0);

                $projGross      = $procCodSum * $rtsFactor;
                $projCodFee     = $procCodSum * $COD_FEE_RATE;
                $projCodFeeVat  = $projCodFee * $COD_FEE_VAT_RATE;
                $projCogs       = $procUCSum  * $rtsFactor;
                $projShipFee    = $SHIPPING_PER_SHIPPED * $proceedCnt;

                $projNP = $projGross - $adsp - $projCodFee - $projCodFeeVat - $projCogs - $projShipFee;

                $r['projected_net_profit']     = $projNP;
                $den                           = $procCodSum;
                $r['projected_net_profit_pct'] = ($den > 0) ? ($projNP / $den) * 100.0 : null;
                $r['_proj_cod_den'] = $den;
            }
            unset($r);
        } else {
            $rtsFactor = 1.0;
            if ($effectiveRtsPct !== null) {
                $rtsFactor = max(0.0, min(1.0, 1.0 - ($effectiveRtsPct / 100.0)));
            }

            foreach ($rows as &$r) {
                if (!empty($r['is_total'])) { $r['projected_net_profit'] = null; $r['projected_net_profit_pct'] = null; $r['actual_rts_pct'] = $actualRtsPct; continue; }
                $r['actual_rts_pct'] = $actualRtsPct;

                $procCodSum  = (float)($r['proceed_cod_sum']       ?? 0.0);
                $procUCSum   = (float)($r['proceed_unit_cost_sum'] ?? 0.0);
                $proceedCnt  = (int)  ($r['proceed']               ?? 0);
                $adsp        = (float)($r['adspent']               ?? 0.0);

                $projGross      = $procCodSum * $rtsFactor;
                $projCodFee     = $procCodSum * $COD_FEE_RATE;
                $projCodFeeVat  = $projCodFee * $COD_FEE_VAT_RATE;
                $projCogs       = $procUCSum  * $rtsFactor;
                $projShipFee    = $SHIPPING_PER_SHIPPED * $proceedCnt;

                $projNP = $projGross - $adsp - $projCodFee - $projCodFeeVat - $projCogs - $projShipFee;

                $r['projected_net_profit']     = $projNP;
                $den                           = $procCodSum;
                $r['projected_net_profit_pct'] = ($den > 0) ? ($projNP / $den) * 100.0 : null;

                $r['_proj_cod_den'] = $den;
            }
            unset($r);
        }

        if (!empty($rows)) {
            $sum = [
                'adspent' => 0.0,
                'orders' => 0, 'proceed' => 0, 'cannot_proceed' => 0, 'odz' => 0,
                'shipped' => 0, 'delivered' => 0, 'returned' => 0, 'for_return' => 0, 'in_transit' => 0,
                'gross_sales' => 0.0,
                'all_cod' => 0.0,
                'proceed_cod_sum' => 0.0,
                'cogs' => 0.0,
                'shipping_fee' => 0.0,
                'cod_fee_vat' => 0.0,
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
                $sum['all_cod']        += (float)($r['all_cod'] ?? 0);
                $sum['proceed_cod_sum']+= (float)($r['proceed_cod_sum'] ?? 0);
                $sum['cogs']           += (float)($r['cogs'] ?? 0);
                $sum['shipping_fee']   += (float)($r['shipping_fee'] ?? 0);
                $sum['cod_fee_vat']    += (float)($r['cod_fee_vat'] ?? 0);

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
            $total_shipping_fee   = $sum['shipping_fee'] ?? ($SHIPPING_PER_SHIPPED * $sum['shipped']);
            $total_cod_fee        = $sum['gross_sales'] * $COD_FEE_RATE;
            $total_cod_fee_vat    = $sum['cod_fee_vat'] ?? ($total_cod_fee * $COD_FEE_VAT_RATE);

            $total_net_profit     = $sum['gross_sales'] - $sum['adspent'] - $total_shipping_fee - $total_cod_fee - $total_cod_fee_vat - $sum['cogs'];
            $total_net_profit_pct = $sum['all_cod'] > 0 ? ($total_net_profit / $sum['all_cod']) * 100.0 : null;
            $total_avg_delay      = $delayShipCount > 0 ? ($delayWeightedSum / $delayShipCount) : null;

            $sumProjNP  = 0.0;
            $totalActualRtsNum = 0;
            $totalActualRtsDen = 0;
            foreach ($rows as $r) {
                if (!empty($r['is_total'])) continue;
                $sumProjNP += (float)($r['projected_net_profit'] ?? 0.0);
                $inPct = $r['in_transit_pct'] ?? null;
                if ($inPct !== null && $inPct < 3.0) {
                    $totalActualRtsNum += (int)($r['returned'] ?? 0) + (int)($r['for_return'] ?? 0);
                    $totalActualRtsDen += (int)($r['delivered'] ?? 0) + (int)($r['returned'] ?? 0) + (int)($r['for_return'] ?? 0);
                }
            }
            $totalActualRtsPct = $totalActualRtsDen > 0 ? ($totalActualRtsNum / $totalActualRtsDen) * 100.0 : null;
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
                'cod_fee'         => $total_cod_fee,
                'cod_fee_vat'     => $total_cod_fee_vat,
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
                'projected_net_profit' => null,
                'projected_net_profit_pct'  => $total_projected_net_profit_pct,
                'actual_rts_pct'  => $totalActualRtsPct ?? ($AGGREGATE_RANGE ? null : $actualRtsPct),
            ];
        }

        $topSummary = [];
        if (!$AGGREGATE_RANGE) {
            $daily = array_values(array_filter($rows, fn($r) => empty($r['is_total'])));
            $dates = array_values(array_unique(array_map(fn($r) => (string)$r['date'], $daily)));
            sort($dates);
            if (!empty($dates)) {
                $lastDate = $dates[count($dates)-1];
                $datesBeforeLast = $dates;
                array_pop($datesBeforeLast);

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
                    $denSum  = 0.0;

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

        $targetCPP = null;
        $breakevenCPP = null;

        if (!$AGGREGATE_RANGE) {
            $unitCostMode = null;
            if (!empty($itemsCostRows)) {
                $freq = [];
                foreach ($itemsCostRows as $r) {
                    $uc  = (float)($r->unit_cost_disp ?? 0);
                    $qty = (int)($r->qty ?? 0);
                    $key = number_format($uc, 2, '.', '');
                    $freq[$key] = ($freq[$key] ?? 0) + $qty;
                }
                if (!empty($freq)) {
                    arsort($freq);
                    $unitCostMode = (float)array_key_first($freq);
                }
            }

            $codMode = null;
            $codModeRow = (clone $moProceedOnly)
                ->selectRaw("$moCodClean AS cod_clean, COUNT(*) AS cnt")
                ->groupByRaw("$moCodClean")
                ->orderByRaw("COUNT(*) DESC")
                ->limit(1)
                ->first();
            if ($codModeRow) {
                $codMode = (float)$codModeRow->cod_clean;
            }

            $rtsPctUsed = $effectiveRtsPct ?? $DEFAULT_RTS_PCT;
            $rts = max(0.0, min(1.0, $rtsPctUsed / 100.0));

            if ($unitCostMode !== null && $codMode !== null) {
                $codFeeWithVat = $COD_FEE_RATE * (1 + $COD_FEE_VAT_RATE);
                $targetCPP     = (1 - $rts) * ((1 - $codFeeWithVat) * $codMode - $unitCostMode) - 0.2  * $codMode - $SHIPPING_PER_SHIPPED;
                $breakevenCPP  = (1 - $rts) * ((1 - $codFeeWithVat) * $codMode - $unitCostMode) - 0.05 * $codMode - $SHIPPING_PER_SHIPPED;
            }
        }

        return response()->json([
            'ads_daily'       => $rows,
            'actual_rts_pct'  => $actualRtsPct,
            'top_summary'     => $topSummary,
            'target_cpp'      => $targetCPP,
            'breakeven_cpp'   => $breakevenCPP,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Item Summary (one-day view, grouped by page, dominant item used for calcs)
    // ─────────────────────────────────────────────────────────────────────────

    public function itemSummary(Request $request)
    {
        $this->checkAccess();

        $phTz = new \DateTimeZone('Asia/Manila');
        $date = $request->input('date', (new \DateTime('now', $phTz))->format('Y-m-d'));
        $host = strtolower((string) $request->getHost());

        $driver = DB::getDriverName();
        $trimFn = $driver === 'pgsql' ? 'BTRIM' : 'TRIM';
        $quote  = fn(string $col) => $driver === 'pgsql' ? '"'.$col.'"' : '`'.$col.'`';

        $pickCol = function (string $table, array $candidates) {
            foreach ($candidates as $c) {
                if (Schema::hasColumn($table, $c)) return $c;
            }
            return null;
        };

        $castMoney = fn(string $expr) => $driver === 'pgsql'
            ? "COALESCE(NULLIF(REGEXP_REPLACE(COALESCE(($expr)::text, ''), '[^0-9\\.\\-]', '', 'g'), '')::numeric, 0)"
            : "CAST(REPLACE(REPLACE(REPLACE(COALESCE($expr,''), '₱',''), ',', ''), ' ', '') AS DECIMAL(18,2))";

        // ── column detection ──────────────────────────────────────────────────
        $pageCol   = $pickCol('macro_output', ['PAGE','page','page_name','Page']) ?? 'PAGE';
        $itemCol   = $pickCol('macro_output', ['ITEM_NAME','item_name','ITEM','item']) ?? 'ITEM_NAME';
        $statusCol = $pickCol('macro_output', ['STATUS','status','Status']) ?? 'STATUS';
        $hasTsDate = Schema::hasColumn('macro_output', 'ts_date');

        $moPage   = 'mo.'.$quote($pageCol);
        $moItem   = 'mo.'.$quote($itemCol);
        $moStatus = 'mo.'.$quote($statusCol);

        $statusNorm = "LOWER(REPLACE(REPLACE($trimFn($moStatus),' ',''),'_',''))";
        $pageTrim   = "$trimFn(COALESCE($moPage,''))";
        $pageKey    = "LOWER($pageTrim)";
        $itemTrim   = "$trimFn(COALESCE($moItem,''))";

        // ── date expression ───────────────────────────────────────────────────
        if ($hasTsDate) {
            $dateExpr = 'DATE(mo.ts_date)';
        } else {
            $tsCol = $pickCol('macro_output', ['TIMESTAMP','timestamp']) ?? 'created_at';
            $tsRef = 'mo.'.$quote($tsCol);
            if ($driver === 'mysql') {
                $dateExpr = "COALESCE(DATE(STR_TO_DATE($tsRef,'%H:%i %d-%m-%Y')),DATE(STR_TO_DATE($tsRef,'%H:%i %m-%d-%Y')),DATE(mo.`created_at`))";
            } else {
                $dateExpr = "DATE(COALESCE(TO_TIMESTAMP(NULLIF($tsRef,''),'HH24:MI DD-MM-YYYY'),TO_TIMESTAMP(NULLIF($tsRef,''),'HH24:MI MM-DD-YYYY'),mo.\"created_at\"))";
            }
        }

        // ── fee rates ─────────────────────────────────────────────────────────
        $codFeeRate  = 0.05;   // COD fee = Price × 0.05 × 1.12, per delivered only
        $shippingFee = FeeSetting::getRate('shipping_per_order', $host, $date) ?? 37.0;

        // ── COD column detection ───────────────────────────────────────────────
        $codCol   = $pickCol('macro_output', ['COD','cod','Cod']) ?? 'COD';
        $moCod    = 'mo.'.$quote($codCol);
        $codClean = $castMoney($moCod);

        // ── macro_output: grouped by page + item for the date ─────────────────
        // Also pulls max/min COD per item so we can auto-detect selling price
        $moRows = DB::table('macro_output as mo')
            ->whereRaw("$dateExpr = ?", [$date])
            ->whereRaw("$pageTrim != ''")
            ->selectRaw("
                $pageTrim AS page_label,
                $pageKey  AS page_key,
                $itemTrim AS item_name,
                COUNT(*)  AS total_orders,
                SUM(CASE WHEN $statusNorm = 'proceed' THEN 1 ELSE 0 END) AS proceed_orders,
                MAX($codClean) AS max_cod,
                MIN(CASE WHEN $codClean > 0 THEN $codClean ELSE NULL END) AS min_cod
            ")
            ->groupByRaw("$pageKey, $pageTrim, $itemTrim")
            ->get();

        // ── group by page ─────────────────────────────────────────────────────
        $pageGroups = [];
        foreach ($moRows as $row) {
            $pk = (string)$row->page_key;
            if (!isset($pageGroups[$pk])) {
                $pageGroups[$pk] = [
                    'page_label'     => (string)$row->page_label,
                    'page_key'       => $pk,
                    'total_orders'   => 0,
                    'proceed_orders' => 0,
                    'items'          => [],
                ];
            }
            $pageGroups[$pk]['total_orders']   += (int)$row->total_orders;
            $pageGroups[$pk]['proceed_orders'] += (int)$row->proceed_orders;
            $pageGroups[$pk]['items'][] = [
                'item_name'      => (string)$row->item_name,
                'total_orders'   => (int)$row->total_orders,
                'proceed_orders' => (int)$row->proceed_orders,
                'max_cod'        => (float)($row->max_cod ?? 0),
                'min_cod'        => (float)($row->min_cod ?? $row->max_cod ?? 0),
            ];
        }

        // Sort items descending by total_orders (dominant = index 0)
        foreach ($pageGroups as &$pg) {
            usort($pg['items'], fn($a, $b) => $b['total_orders'] - $a['total_orders']);
        }
        unset($pg);

        // ── adspent per page for the date ─────────────────────────────────────
        $castSpend = $castMoney('amount_spent_php');
        $adsRows = DB::table('ads_manager_reports')
            ->whereRaw('DATE(day) = ?', [$date])
            ->selectRaw("LOWER($trimFn(COALESCE(page_name,''))) AS page_key, SUM($castSpend) AS adspent")
            ->groupByRaw("LOWER($trimFn(COALESCE(page_name,'')))")
            ->get();
        $adsMap = [];
        foreach ($adsRows as $r) $adsMap[(string)$r->page_key] = (float)$r->adspent;

        // ── COGS: latest entry ≤ date ─────────────────────────────────────────
        $cogsItemCol = $pickCol('cogs', ['item_name','ITEM_NAME']) ?? 'item_name';
        $cogsDateCol = $pickCol('cogs', ['date','effective_date']) ?? 'date';
        $cogsUnitCol = $pickCol('cogs', ['unit_cost','cost']) ?? 'unit_cost';

        $cogsRows = DB::table('cogs')
            ->where($cogsDateCol, '<=', $date)
            ->orderByDesc($cogsDateCol)
            ->get([$cogsItemCol, $cogsDateCol, $cogsUnitCol]);

        $cogsMap = []; // normalized_item_name → unit_cost
        foreach ($cogsRows as $r) {
            $k = strtolower(trim((string)($r->$cogsItemCol ?? '')));
            if (!isset($cogsMap[$k])) {
                $cogsMap[$k] = (float)($r->$cogsUnitCol ?? 0);
            }
        }

        // ── page_item_settings: latest per page+item ≤ date ──────────────────
        // NOTE: `price` column is repurposed to store item_value (COGS override).
        // Actual selling price comes from macro_output COD column (auto-detected).
        $settingsMap = [];
        if (Schema::hasTable('page_item_settings')) {
            $settingRows = DB::table('page_item_settings')
                ->where('effective_date', '<=', $date)
                ->orderBy('page_name')
                ->orderBy('item_name')
                ->orderByDesc('effective_date')
                ->get(['page_name', 'item_name', 'price', 'rts_pct', 'effective_date']);

            foreach ($settingRows as $s) {
                $k = strtolower(trim((string)$s->page_name)).'||'.strtolower(trim((string)$s->item_name));
                if (!isset($settingsMap[$k])) {
                    $settingsMap[$k] = [
                        'item_value'     => (float)$s->price,   // price col repurposed
                        'rts_pct'        => (float)$s->rts_pct,
                        'effective_date' => (string)$s->effective_date,
                    ];
                }
            }
        }

        // ── build response ────────────────────────────────────────────────────
        $result = [];
        foreach ($pageGroups as $pk => $pg) {
            if (empty($pg['items'])) continue;

            $dominant    = $pg['items'][0];
            $secondary   = array_slice($pg['items'], 1);
            $dominantKey = strtolower(trim($dominant['item_name']));
            $settingKey  = $pk.'||'.$dominantKey;

            $adspent       = $adsMap[$pk] ?? 0.0;
            $totalOrders   = (int)$pg['total_orders'];
            $proceedOrders = (int)$pg['proceed_orders'];

            // Price = auto from COD in macro_output (dominant item)
            $price    = (float)($dominant['max_cod'] ?? 0);
            $priceMin = (float)($dominant['min_cod'] ?? $price);
            $priceIsRange = $priceMin > 0 && abs($priceMin - $price) > 0.01;

            // Item value = page_item_settings override (priority) or cogs table fallback
            $settings  = $settingsMap[$settingKey] ?? null;
            $itemValue = $settings
                ? (float)$settings['item_value']
                : ($cogsMap[$dominantKey] ?? null);
            $rtsPct    = $settings ? (float)$settings['rts_pct'] : null;

            $cpp        = ($totalOrders > 0 && $adspent > 0) ? $adspent / $totalOrders   : null;
            $proceedCpp = ($proceedOrders > 0 && $adspent > 0) ? $adspent / $proceedOrders : null;

            // COD fee per delivered = Price × 5% × 1.12
            $codFeePerDelivered = $price > 0 ? round($price * $codFeeRate * 1.12, 4) : null;

            // Projected Profit (needs: price from COD, item_value, rts_pct)
            $projProfit = $projProfitPerOrder = null;
            if ($price > 0 && $rtsPct !== null && $itemValue !== null) {
                $rts           = $rtsPct / 100.0;
                $deliverFactor = 1.0 - $rts;
                $projProfit =
                    $proceedOrders * $price * $deliverFactor                      // revenue
                    - $proceedOrders * $shippingFee                               // shipping (all proceed)
                    - $proceedOrders * $deliverFactor * $itemValue                // COGS (delivered)
                    - $adspent                                                    // adspent
                    - $proceedOrders * $deliverFactor * ($price * $codFeeRate * 1.12); // COD fee (delivered)

                $projProfitPerOrder = $proceedOrders > 0 ? $projProfit / $proceedOrders : null;
            }

            $result[] = [
                'page_name'             => $pg['page_label'],
                'page_key'              => $pk,
                'item_name'             => $dominant['item_name'],
                'secondary_items'       => array_map(fn($i) => [
                    'item_name'    => $i['item_name'],
                    'total_orders' => $i['total_orders'],
                    'price'        => (float)($i['max_cod'] ?? 0),  // each item's actual price
                ], $secondary),
                'adspent'               => $adspent,
                'orders'                => $totalOrders,
                'cpp'                   => $cpp,
                'proceed_orders'        => $proceedOrders,
                'proceed_cpp'           => $proceedCpp,
                'projected_profit'      => $projProfit,
                'proj_profit_per_order' => $projProfitPerOrder,
                'rts_pct'               => $rtsPct,
                'price'                 => $price > 0 ? $price : null,
                'price_min'             => $priceIsRange ? $priceMin : null,
                'price_is_range'        => $priceIsRange,
                'item_value'            => $itemValue,
                'item_value_source'     => $settings ? 'manual' : ($itemValue !== null ? 'cogs' : null),
                'shipping_fee'          => $shippingFee,
                'cod_fee'               => $codFeePerDelivered,
                'settings_date'         => $settings ? $settings['effective_date'] : null,
                'has_settings'          => $settings !== null,
            ];
        }

        usort($result, fn($a, $b) => strcmp((string)$a['page_name'], (string)$b['page_name']));

        return response()->json(['rows' => $result, 'date' => $date]);
    }

    public function saveItemSetting(Request $request)
    {
        $this->checkAccess();

        $validated = $request->validate([
            'page_name'      => 'required|string|max:255',
            'item_name'      => 'required|string|max:255',
            'item_value'     => 'required|numeric|min:0',   // stored in `price` column
            'rts_pct'        => 'required|numeric|min:0|max:100',
            'effective_date' => 'required|date',
        ]);

        try {
            DB::table('page_item_settings')->insert([
                'page_name'      => trim($validated['page_name']),
                'item_name'      => trim($validated['item_name']),
                'price'          => $validated['item_value'],   // price col repurposed as item_value
                'rts_pct'        => $validated['rts_pct'],
                'effective_date' => $validated['effective_date'],
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json(['ok' => true]);
    }
}
