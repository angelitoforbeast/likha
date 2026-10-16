<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use App\Models\FeeSetting;

class SummaryOverallController extends Controller
{
    /** Mga column name na nahanap na, kada driver (para sa buong process). */
    private static array $columnsByDriver = [];

    /**
     * Ang mga column name na ginagamit ng page (candidates gaya ng dati), naka-cache nang isang araw
     * para hindi na mag-Schema::hasColumn kada request.
     */
    private function columns(): array
    {
        $driver = DB::getDriverName();

        return self::$columnsByDriver[$driver] ??= Cache::remember("summary_overall.columns.$driver", 86400, function () use ($driver) {
            $listing = function (string $table) use ($driver): array {
                if ($driver === 'pgsql') {
                    $rows = DB::select(
                        "SELECT column_name FROM information_schema.columns
                         WHERE table_schema = current_schema() AND table_name = ?",
                        [$table]
                    );
                    return array_map(fn($r) => $r->column_name, $rows);
                }
                return Schema::getColumnListing($table);
            };
            $tables = [];
            foreach (['macro_output', 'from_jnts', 'cogs'] as $t) $tables[$t] = $listing($t);

            // pgsql: eksaktong pangalan; iba: case-insensitive gaya ng Schema::hasColumn
            $pick = function (string $table, array $candidates) use ($tables, $driver) {
                $have = $driver === 'pgsql' ? $tables[$table] : array_map('strtolower', $tables[$table]);
                foreach ($candidates as $c) {
                    if (in_array($driver === 'pgsql' ? $c : strtolower($c), $have, true)) return $c;
                }
                return null;
            };

            // Collation ng from_jnts.status: _ci = hindi na kailangan ang UPPER na LIKE
            $statusCi = false;
            if ($driver === 'mysql') {
                $row = DB::selectOne(
                    "SELECT COLLATION_NAME AS c FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = 'from_jnts' AND column_name = 'status'"
                );
                $statusCi = $row && str_ends_with(strtolower((string)$row->c), '_ci');
            }

            return [
                'mo_page'        => $pick('macro_output', ['PAGE','page','page_name','Page','Page_Name']),
                'mo_status'      => $pick('macro_output', ['STATUS','status','Status']) ?? 'status',
                'mo_waybill'     => $pick('macro_output', ['waybill','Waybill','WAYBILL']) ?? 'waybill',
                'mo_item'        => $pick('macro_output', ['ITEM_NAME','item_name','Product','product_name','ITEM','item']),
                'mo_cod'         => $pick('macro_output', ['COD','cod','Cod']) ?? 'COD',
                'mo_has_ts_date' => $pick('macro_output', ['ts_date']) !== null,
                'mo_ts_cols'     => array_values(array_filter(['TIMESTAMP','timestamp'], fn($c) => $pick('macro_output', [$c]) !== null)),
                'j_submit'       => $pick('from_jnts', ['submission_time','submitted_at','submission_datetime','submissiondate','submission']) ?? 'submission_time',
                'j_status_ci'    => $statusCi,
                'cogs_item'      => $pick('cogs', ['item_name','ITEM_NAME','product','Product','Product_Name']) ?? 'item_name',
                'cogs_date'      => $pick('cogs', ['effective_date','date','valid_from','cogs_date']) ?? 'effective_date',
                'cogs_unit'      => $pick('cogs', ['unit_cost','cost','unitprice','unit_price','price']) ?? 'unit_cost',
            ];
        });
    }

    private function getNormalizedRole(): string
    {
        $raw = Auth::user()?->employeeProfile?->role ?? '';
        return preg_replace('/\s+/u', ' ', trim((string) $raw));
    }

    /** Profit ng owner ang page na ito: CEO lang. */
    private function checkAccess(): void
    {
        if (preg_match('/^ceo$/iu', $this->getNormalizedRole()) !== 1) abort(403);
    }

    public function index()
    {
        $this->checkAccess();

        // DISTINCT sa raw page_name (gamit ang index), tapos trim + isang label kada page sa PHP
        $pages = [];
        $raw = DB::table('ads_manager_reports')
            ->whereNotNull('page_name')
            ->distinct()
            ->orderBy('page_name')
            ->pluck('page_name');
        foreach ($raw as $p) {
            $p = trim((string)$p);
            $pages[mb_strtolower($p)] ??= $p;
        }
        $pages = array_values($pages);
        usort($pages, fn($a, $b) => strcasecmp($a, $b));

        // role detection
        $userRoleRaw    = Auth::user()?->employeeProfile?->role ?? '';
        $roleNorm       = preg_replace('/\s+/u', ' ', trim((string)$userRoleRaw));
        $isMarketingOIC = preg_match('/^marketing\s*[-–—]\s*oic$/iu', $roleNorm) === 1;
        $isCEO          = preg_match('/^ceo$/iu', $roleNorm) === 1;

        return view('summary.overall', compact('pages', 'isCEO', 'isMarketingOIC'));
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

        // Walang petsa: huling 30 araw (PH), gaya ng default ng page
        if (!$start && !$end) {
            $end   = now('Asia/Manila')->format('Y-m-d');
            $start = now('Asia/Manila')->subDays(29)->format('Y-m-d');
        }

        $driver = DB::getDriverName(); // 'mysql' | 'pgsql'
        $trimFn = $driver === 'pgsql' ? 'BTRIM' : 'TRIM';

        // === FEE SETTINGS (from database with effective date) — no fallback ===
        $host = strtolower((string) $request->getHost());
        $refDate = $end ?? $start ?? $today;
        $COD_FEE_RATE         = FeeSetting::getRate('cod_fee_rate',           $host, $refDate);
        $COD_FEE_VAT_RATE     = FeeSetting::getRate('cod_fee_vat_rate',       $host, $refDate);
        $SHIPPING_PER_SHIPPED = FeeSetting::getRate('shipping_fee_per_order', $host, $refDate);
        if ($COD_FEE_RATE === null || $COD_FEE_VAT_RATE === null || $SHIPPING_PER_SHIPPED === null) {
            $missing = array_filter([
                $COD_FEE_RATE         === null ? 'cod_fee_rate'           : null,
                $COD_FEE_VAT_RATE     === null ? 'cod_fee_vat_rate'       : null,
                $SHIPPING_PER_SHIPPED === null ? 'shipping_fee_per_order' : null,
            ]);
            abort(422, "Missing fee_settings for {$refDate} (host: {$host}): " . implode(', ', $missing) . ". Configure at /jnt/fee-settings.");
        }
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

        // money sanitizer
        $castMoney = function (string $expr) use ($driver) {
            return $driver === 'pgsql'
                ? "COALESCE(NULLIF(REGEXP_REPLACE(COALESCE(($expr)::text, ''), '[^0-9\\.\\-]', '', 'g'), '')::numeric, 0)"
                : "CAST(REPLACE(REPLACE(REPLACE(COALESCE($expr,''), '₱',''), ',', ''), ' ', '') AS DECIMAL(18,2))";
        };

        // Mga column name: naka-cache (hindi na Schema::hasColumn kada request)
        $cols = $this->columns();

        // resolve macro_output columns
        $pageColName = $cols['mo_page'];
        if (!$pageColName) throw new \RuntimeException('macro_output: page column not found');
        $moPageSql = 'mo.' . $quote($pageColName);

        $pageLabelExpr = "$trimFn(COALESCE($moPageSql,''))";
        $pageKeyExpr   = "LOWER($pageLabelExpr)";

        $statusExpr    = 'mo.' . $quote($cols['mo_status']);
        $statusNorm    = "LOWER(REPLACE(REPLACE($trimFn($statusExpr),' ',''),'_',''))";

        $moWaybillSql = 'mo.' . $quote($cols['mo_waybill']);

        $itemColName = $cols['mo_item'];
        if (!$itemColName) throw new \RuntimeException('macro_output: item column not found');
        $moItemExpr = 'mo.' . $quote($itemColName);
        $itemLabel  = "$trimFn(COALESCE($moItemExpr,''))";
        $itemNorm   = "LOWER(REPLACE(REPLACE(REPLACE($itemLabel,' ',''),'-',''),'_',''))";

        // COD from macro_output
        $moCodExpr = 'mo.' . $quote($cols['mo_cod']);

        // ts_date = pre-computed indexed column sa macro_output
        if ($cols['mo_has_ts_date']) {
            $dateExpr = "mo.ts_date";
        } else {
            // Fallback to old computed date expression
            $tsCols = $cols['mo_ts_cols'];

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

        // Ad spend cast
        $castSpend = $castMoney('amount_spent_php');

        // from_jnts columns
        $jSubmitColName = $cols['j_submit'];

        // cogs columns
        $cogsItemColName = $cols['cogs_item'];
        $cogsDateColName = $cols['cogs_date'];
        $cogsUnitColName = $cols['cogs_unit'];

        // aggregate mode: All Pages?
        $AGGREGATE_RANGE = ($pageName === 'all');
        $pageNameNorm = $AGGREGATE_RANGE ? null : mb_strtolower(trim((string)$pageName));

        // ======================
        // ADS (ads_manager_reports) — walang function sa `day` para magamit ang index
        // ======================
        $adsPageLabelExpr = "$trimFn(COALESCE(page_name,''))";
        $adsPageKeyExpr   = "LOWER($adsPageLabelExpr)";

        $adsBase = DB::table('ads_manager_reports');

        if ($start && $end) {
            $adsBase->whereBetween('day', [$start, $end]);
        } elseif ($start) {
            $adsBase->where('day', '>=', $start);
        } elseif ($end) {
            $adsBase->where('day', '<=', $end);
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

        // =====================================================
        // _mo_norm: ang macro_output rows sa range, na-normalize nang ISANG beses
        // (PAGE, STATUS, ITEM, waybill; raw COD). Lahat ng sumunod na query dito na nagbabasa.
        // Raw ang COD dito: ang CAST sa CREATE ... SELECT ay error sa strict mode kapag may maduming COD.
        // =====================================================
        $moWhere    = [];
        $moBindings = [];
        if ($start && $end) {
            $moWhere[] = "$dateExpr BETWEEN ? AND ?";
            $moBindings = [$start, $end];
        } elseif ($start) {
            $moWhere[] = "$dateExpr >= ?";
            $moBindings = [$start];
        } elseif ($end) {
            $moWhere[] = "$dateExpr <= ?";
            $moBindings = [$end];
        }
        if (!$AGGREGATE_RANGE && $pageNameNorm !== null && $pageNameNorm !== '') {
            $moWhere[] = "$pageKeyExpr = ?";
            $moBindings[] = $pageNameNorm;
        }
        $moWhereSql = $moWhere ? 'WHERE ' . implode(' AND ', $moWhere) : '';

        $tmpDrop = $driver === 'mysql' ? 'DROP TEMPORARY TABLE IF EXISTS' : 'DROP TABLE IF EXISTS';
        DB::statement("$tmpDrop _mo_norm");
        DB::statement("$tmpDrop _jnt_agg");

        DB::statement("
            CREATE TEMPORARY TABLE _mo_norm AS
            SELECT
                $dateExpr      AS day_key,
                $pageKeyExpr   AS page_key,
                $pageLabelExpr AS page_label,
                $statusNorm    AS status_n,
                $moWaybillSql  AS wb,
                $itemNorm      AS item_key,
                $itemLabel     AS item_label,
                $moCodExpr     AS cod_raw
            FROM macro_output mo
            $moWhereSql
        ", $moBindings);

        // =====================================================
        // _jnt_agg: per-waybill J&T status, ginawa nang isang beses para sa mga waybill sa _mo_norm.
        // Case-insensitive ang LIKE kapag _ci ang collation ng status (o sqlite), kaya isang pattern na lang.
        // =====================================================
        $like = function (string $pattern) use ($cols, $driver) {
            $variants = ($driver === 'sqlite' || $cols['j_status_ci'])
                ? [$pattern]
                : [$pattern, strtoupper($pattern)];
            return '(' . implode(' OR ', array_map(fn($p) => "j.status LIKE '$p%'", $variants)) . ')';
        };
        $isDel = $like('Delivered');
        $isRet = $like('Returned');
        $isFr  = $like('For Return');
        $int   = $driver === 'pgsql' ? '::int' : '';

        if ($driver === 'pgsql') {
            $jaMinTsExpr = "MIN(COALESCE(
                TO_TIMESTAMP(NULLIF(j.\"$jSubmitColName\",'') , 'YYYY-MM-DD HH24:MI:SS'),
                TO_TIMESTAMP(NULLIF(j.\"$jSubmitColName\",'') , 'YYYY/MM/DD HH24:MI:SS'),
                TO_TIMESTAMP(NULLIF(j.\"$jSubmitColName\",'') , 'YYYY-MM-DD'),
                j.\"created_at\"
            ))";
        } else {
            // submission_time ay DATETIME na; wala nang STR_TO_DATE
            $jaMinTsExpr = "MIN(COALESCE(j." . $quote($jSubmitColName) . ", j.created_at))";
        }

        // MySQL: primary key sa mismong CREATE (walang ALTER)
        $jaKey = $driver === 'mysql' ? '(PRIMARY KEY (wb))' : '';
        DB::statement("
            CREATE TEMPORARY TABLE _jnt_agg $jaKey AS
            SELECT
                j.waybill_number AS wb,
                MAX(CASE WHEN $isDel THEN 1 ELSE 0 END)$int AS is_delivered,
                MAX(CASE WHEN $isRet THEN 1 ELSE 0 END)$int AS is_returned,
                MAX(CASE WHEN $isFr  THEN 1 ELSE 0 END)$int AS is_for_return,
                MAX(CASE
                    WHEN $isDel THEN 0
                    WHEN $isRet THEN 0
                    WHEN $isFr  THEN 0
                    ELSE 1
                END)$int AS is_in_transit,
                $jaMinTsExpr AS min_submit_ts,
                COALESCE(MAX(CAST(j.total_shipping_cost AS DECIMAL(18,2))), 0) AS actual_shipping_cost
            FROM from_jnts j
            WHERE j.waybill_number IN (
                SELECT wb FROM _mo_norm WHERE wb IS NOT NULL AND wb != ''
            )
            GROUP BY j.waybill_number
        ");
        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE _jnt_agg ADD PRIMARY KEY (wb)");
        }

        $codClean = $castMoney('n.cod_raw');

        // =====================================================
        // OPTIMIZATION #3: Pre-load cogs into a PHP lookup array
        // (only 116 rows — much faster than correlated subqueries)
        //
        // Role-aware: CEO viewer's profit calcs use cogs_ceo (separate table)
        // while non-CEO viewers use cogs (Marketing's shared table). We just
        // swap which table populates $cogsLookup — downstream profit math is
        // untouched.
        // =====================================================
        $isCEO = (function () {
            $raw  = \Illuminate\Support\Facades\Auth::user()?->employeeProfile?->role ?? '';
            $norm = preg_replace('/\s+/u', ' ', trim((string) $raw));
            return preg_match('/^ceo$/iu', $norm) === 1;
        })();

        $cogsTable = ($isCEO && \Illuminate\Support\Facades\Schema::hasTable('cogs_ceo'))
            ? 'cogs_ceo'
            : 'cogs';

        $cogsAll = DB::table($cogsTable)
            ->selectRaw("
                LOWER(REPLACE(REPLACE(REPLACE($trimFn(COALESCE(" . $quote($cogsItemColName) . ",'')),' ',''),'-',''),'_','')) AS item_key,
                DATE(" . $quote($cogsDateColName) . ") AS eff_date,
                " . $castMoney($quote($cogsUnitColName)) . " AS unit_cost
            ")
            ->orderByRaw("LOWER(REPLACE(REPLACE(REPLACE($trimFn(COALESCE(" . $quote($cogsItemColName) . ",'')),' ',''),'-',''),'_','')) ASC, DATE(" . $quote($cogsDateColName) . ") DESC")
            ->get();

        // Build lookup: item_key => [ [date => ..., cost => ...], ... ] sorted by date DESC
        $cogsLookup = [];
        foreach ($cogsAll as $row) {
            $cogsLookup[(string)$row->item_key][] = [
                'date' => (string)$row->eff_date,
                'cost' => (float)$row->unit_cost,
            ];
        }

        // Helper: find unit cost for an item on or before a given date
        $findUnitCost = function(string $itemKey, string $maxDate) use ($cogsLookup): float {
            if (!isset($cogsLookup[$itemKey])) return 0.0;
            foreach ($cogsLookup[$itemKey] as $entry) {
                if ($entry['date'] <= $maxDate) return $entry['cost'];
            }
            return 0.0;
        };

        // Helper: find ALL distinct unit costs for an item within a date range (for range display)
        $findAllUnitCosts = function(string $itemKey, ?string $startDate = null, ?string $endDate = null) use ($cogsLookup, &$findUnitCost): array {
            if (!isset($cogsLookup[$itemKey])) return [];

            if ($startDate && $endDate) {
                // Get costs that were effective within the date range
                // For each date in range, the effective cost is the latest cost on or before that date
                // But we simplify: get all distinct costs from entries within the range,
                // plus the carry-forward cost at the start of the range
                $costsInRange = [];

                // Add carry-forward cost at start date (the cost that was effective at range start)
                $carryForward = $findUnitCost($itemKey, $endDate);
                if ($carryForward > 0) {
                    $costsInRange[] = $carryForward;
                }

                // Add any costs from entries within the date range
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

            // Fallback: all costs (no date filter)
            $costs = array_unique(array_map(fn($e) => $e['cost'], $cogsLookup[$itemKey]));
            sort($costs);
            return array_values($costs);
        };

        // =====================================================
        // Mga query sa _mo_norm (n) at _jnt_agg (ja): conditional aggregation,
        // tatlong pass imbes na pito na hiwalay na join.
        // =====================================================
        $proceedCase = "n.status_n = 'proceed'";
        $keyOf = fn($r) => $AGGREGATE_RANGE ? (string)$r->page_key : ((string)$r->day_key . '|' . (string)$r->page_key);
        $selKey = $AGGREGATE_RANGE ? 'n.page_key' : 'n.day_key, n.page_key';

        // 1) ORDERS / PROCEED / CANNOT / ODZ + PROCEED COD SUM (lahat ng rows)
        $orderAgg = DB::select("
            SELECT $selKey,
                MIN(n.page_label) AS page_label,
                COUNT(*) AS orders_total,
                SUM(CASE WHEN $proceedCase THEN 1 ELSE 0 END) AS proceed_total,
                SUM(CASE WHEN n.status_n = 'cannotproceed' THEN 1 ELSE 0 END) AS cannot_total,
                SUM(CASE WHEN n.status_n = 'odz' THEN 1 ELSE 0 END) AS odz_total,
                SUM(CASE WHEN $proceedCase THEN $codClean END) AS proceed_cod_sum
            FROM _mo_norm n
            GROUP BY $selKey
        ");

        $ordersMap = $proceedMap = $cannotMap = $odzMap = [];
        $proceedCodSumMap = [];
        foreach ($orderAgg as $r) {
            $k = $keyOf($r);
            $ordersMap[$k]  = (int)($r->orders_total ?? 0);
            $proceedMap[$k] = (int)($r->proceed_total ?? 0);
            $cannotMap[$k]  = (int)($r->cannot_total ?? 0);
            $odzMap[$k]     = (int)($r->odz_total ?? 0);
            if (!empty($r->page_label)) $labelMap[$k] = (string)$r->page_label;
            if ((int)($r->proceed_total ?? 0) > 0) {
                $proceedCodSumMap[$k] = (float)($r->proceed_cod_sum ?? 0);
            }
        }

        // 2) SHIPPED na waybills: per (key, waybill) muna, tapos per key.
        //    Status counts, actual shipping, gross (delivered), all COD, delay — isang join.
        if ($driver === 'mysql') {
            $delayDays = "DATEDIFF(DATE(ja.min_submit_ts), n.day_key)";
        } elseif ($driver === 'sqlite') {
            $delayDays = "CAST(julianday(DATE(ja.min_submit_ts)) - julianday(n.day_key) AS INTEGER)";
        } else {
            $delayDays = "(DATE(ja.min_submit_ts) - n.day_key)";
        }
        $shippedAgg = function (string $keyCols) use ($codClean, $delayDays) {
            $outerKey = str_replace('n.', 'w.', $keyCols);
            return DB::select("
                SELECT $outerKey,
                    MIN(w.page_label)            AS page_label,
                    COUNT(*)                     AS shipped_total,
                    SUM(w.is_delivered)          AS delivered_total,
                    SUM(w.is_returned)           AS returned_total,
                    SUM(w.is_for_return)         AS for_return_total,
                    SUM(w.is_in_transit)         AS in_transit_total,
                    SUM(w.ship_sum)              AS total_actual_shipping,
                    SUM(CASE WHEN w.is_delivered = 1 THEN w.cod_mo ELSE 0 END) AS gross_sales,
                    SUM(w.cod_mo)                AS all_cod,
                    AVG(w.delay_days)            AS avg_delay_days
                FROM (
                    SELECT $keyCols, n.wb,
                        MIN(n.page_label)            AS page_label,
                        MAX(ja.is_delivered)         AS is_delivered,
                        MAX(ja.is_returned)          AS is_returned,
                        MAX(ja.is_for_return)        AS is_for_return,
                        MAX(ja.is_in_transit)        AS is_in_transit,
                        SUM(ja.actual_shipping_cost) AS ship_sum,
                        MAX($codClean)               AS cod_mo,
                        MIN($delayDays)              AS delay_days
                    FROM _mo_norm n
                    JOIN _jnt_agg ja ON ja.wb = n.wb
                    WHERE n.wb IS NOT NULL AND n.wb != ''
                    GROUP BY $keyCols, n.wb
                ) w
                GROUP BY $outerKey
            ");
        };

        $shippedMap = $deliveredMap = $returnedMap = $forReturnMap = $inTransitMap = [];
        $actualShippingMap = $grossMap = $allCodMap = $avgDelayMap = [];
        $shippedLabelMap = [];
        foreach ($shippedAgg($selKey) as $r) {
            $k = $keyOf($r);
            $shippedMap[$k]        = (int)($r->shipped_total   ?? 0);
            $deliveredMap[$k]      = (int)($r->delivered_total ?? 0);
            $returnedMap[$k]       = (int)($r->returned_total  ?? 0);
            $forReturnMap[$k]      = (int)($r->for_return_total?? 0);
            $inTransitMap[$k]      = (int)($r->in_transit_total?? 0);
            $actualShippingMap[$k] = (float)($r->total_actual_shipping ?? 0);
            $grossMap[$k]          = (float)($r->gross_sales ?? 0);
            $allCodMap[$k]         = (float)($r->all_cod ?? 0);
            $avgDelayMap[$k]       = (float)($r->avg_delay_days ?? 0);
            if (!empty($r->page_label)) $shippedLabelMap[$k] = (string)$r->page_label;
        }

        // 3) ITEMS: proceed items (qty, unit cost, COD range) + delivered items (COGS) — isang pass
        $itemsRows = DB::select("
            SELECT $selKey, n.item_key,
                MIN(CASE WHEN $proceedCase THEN n.page_label END) AS page_label,
                MIN(CASE WHEN $proceedCase THEN n.item_label END) AS item_label,
                SUM(CASE WHEN $proceedCase THEN 1 ELSE 0 END)     AS qty,
                MAX(CASE WHEN $proceedCase THEN n.day_key END)    AS last_order_date,
                MIN(CASE WHEN $proceedCase THEN $codClean END)    AS min_cod,
                MAX(CASE WHEN $proceedCase THEN $codClean END)    AS max_cod,
                COUNT(DISTINCT CASE WHEN ja.is_delivered = 1 THEN n.wb END) AS delivered_qty,
                MAX(CASE WHEN ja.is_delivered = 1 THEN n.day_key END)       AS delivered_last_date
            FROM _mo_norm n
            LEFT JOIN _jnt_agg ja ON ja.wb = n.wb
            GROUP BY $selKey, n.item_key
        ");

        $itemsProceedRows = [];
        $cogsMap = [];
        foreach ($itemsRows as $r) {
            if ((int)($r->qty ?? 0) > 0) $itemsProceedRows[] = $r;
            if ((int)($r->delivered_qty ?? 0) > 0) {
                $k = $keyOf($r);
                $unitCost = $findUnitCost((string)$r->item_key, (string)$r->delivered_last_date);
                $cogsMap[$k] = ($cogsMap[$k] ?? 0.0) + ((int)$r->delivered_qty * $unitCost);
            }
        }

        $itemsListMap = [];
        $itemsCostRows = []; // keep for later use in target CPP calc
        $proceedUnitCostSumMap = [];
        foreach ($itemsProceedRows as $r) {
            $key = $keyOf($r);
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

            // Store for target CPP calculation
            $itemsCostRows[] = (object)[
                'item_key' => (string)$r->item_key,
                'qty' => (int)($r->qty ?? 0),
                'unit_cost_disp' => $unitCost,
            ];
            $proceedUnitCostSumMap[$key] = ($proceedUnitCostSumMap[$key] ?? 0.0) + ((int)$r->qty * $unitCost);
            if (!empty($r->page_label)) $labelMap[$key] = (string)$r->page_label;
        }

        // Page label: gaya ng dati, ang label ng shipped rows ang huling panalo
        foreach ($shippedLabelMap as $k => $lbl) $labelMap[$k] = $lbl;

        // PER-DATE PER-PAGE shipped/status counts (para sa Estimated RTS ng All Pages view)
        $perDatePageShipMap = [];
        if ($AGGREGATE_RANGE) {
            foreach ($shippedAgg('n.day_key, n.page_key') as $r) {
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

        // Mode ng proceed COD (para sa Target CPP, single page lang)
        $codModeRow = null;
        if (!$AGGREGATE_RANGE) {
            $codModeRow = DB::selectOne("
                SELECT $codClean AS cod_clean, COUNT(*) AS cnt
                FROM _mo_norm n
                WHERE $proceedCase
                GROUP BY $codClean
                ORDER BY COUNT(*) DESC
                LIMIT 1
            ");
        }

        // Clean up temp tables
        DB::statement("$tmpDrop _jnt_agg");
        DB::statement("$tmpDrop _mo_norm");

        // ======================
        // Merge (+ derived metrics) — same logic as before
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

                    // Build cost display string for this item
                    $allCosts = $it['all_costs'] ?? [(float)$it['unit_cost']];
                    if (count($allCosts) <= 1) {
                        $costStr = '₱' . number_format($allCosts[0] ?? 0, 2);
                    } else {
                        $costStr = '₱' . number_format(min($allCosts), 2) . ' – ₱' . number_format(max($allCosts), 2);
                    }
                    // Build COD display string for this item
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

        // ===== Effective RTS% used in projections
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

        // ===== Projected Net Profit per row
        if ($AGGREGATE_RANGE) {
            // ALL PAGES VIEW: compute per-page Estimated RTS using per-date In Transit < 3% filter
            // Same logic as single page view: only settled dates contribute to Estimated RTS
            foreach ($rows as &$r) {
                if (!empty($r['is_total'])) { $r['projected_net_profit'] = null; $r['projected_net_profit_pct'] = null; continue; }

                // Compute Estimated RTS from per-date data for this page
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
                    // Fallback: no settled dates, use overall raw RTS for this page
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
            // SINGLE PAGE VIEW: use the global effective RTS
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

        // ===== TOTAL ROW =====
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
                // For total actual RTS: aggregate settled pages
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

        // ===== Gaano na ka-final ang bawat row (dagdag na field lang; walang binagong formula)
        // Realized = actual Net Profit; Expected = Projected Net Profit (sa TOTAL: suma ng mga row);
        // Pending = Expected − Realized; Settled % = (delivered + returned + for return) ÷ proceed;
        // final = walang hold at walang in transit.
        foreach ($rows as &$r) {
            $realized = (float)($r['net_profit'] ?? 0.0);
            $expected = !empty($r['is_total']) ? ($sumProjNP ?? 0.0) : (float)($r['projected_net_profit'] ?? 0.0);
            $proc     = (int)($r['proceed'] ?? 0);
            $doneCnt  = (int)($r['delivered'] ?? 0) + (int)($r['returned'] ?? 0) + (int)($r['for_return'] ?? 0);

            $r['realized_net_profit'] = $realized;
            $r['expected_net_profit'] = $expected;
            $r['pending_net_profit']  = $expected - $realized;
            $r['settled_pct']         = $proc > 0 ? max(0.0, min(100.0, $doneCnt / $proc * 100.0)) : null;
            $r['is_final']            = (int)($r['hold'] ?? 0) <= 0 && (int)($r['in_transit'] ?? 0) === 0;
        }
        unset($r);

        // ===== TOP SUMMARY
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

        // ===== Target CPP & Breakeven CPP
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
}
