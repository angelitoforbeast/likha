<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SummaryOverallController extends Controller
{
    public function index()
    {
        $driver = DB::getDriverName(); // 'mysql' or 'pgsql'
        $trimFn = $driver === 'pgsql' ? 'BTRIM' : 'TRIM';

        $pages = DB::table('ads_manager_reports')
            ->whereNotNull('page_name')
            ->selectRaw("$trimFn(page_name) AS page_name")
            ->distinct()
            ->orderBy('page_name')
            ->pluck('page_name')
            ->toArray();

        return view('summary.overall', compact('pages'));
    }

    public function data(Request $request)
    {
        $start    = $request->input('start_date');
        $end      = $request->input('end_date');
        $pageName = $request->input('page_name', 'all');

        $driver = DB::getDriverName(); // 'mysql' or 'pgsql'
        $trimFn = $driver === 'pgsql' ? 'BTRIM' : 'TRIM';

        // Helpers
        $quote = fn($col) => $driver === 'pgsql' ? '"' . $col . '"' : '`' . $col . '`';
        $col   = function (string $table, array $candidates) {
            foreach ($candidates as $c) {
                if (Schema::hasColumn($table, $c)) return $c;
            }
            return null;
        };

        // --- Resolve macro_output columns (case/camel tolerant) ---
        $pageColName = $col('macro_output', ['page','Page','PAGE','page_name','Page_Name']);
        if (!$pageColName) throw new \RuntimeException('macro_output has no page/page_name column');
        $moPage   = 'mo.' . $quote($pageColName);
        $pageExpr = "$trimFn(COALESCE($moPage,''))";

        $wbColName = $col('macro_output', ['waybill','Waybill','WAYBILL']);
        $moWaybill = $wbColName ? 'mo.' . $quote($wbColName) : 'mo.waybill';

        $statusColName = $col('macro_output', ['status','Status','STATUS']) ?? 'status';
        $statusExpr    = 'mo.' . $quote($statusColName);
        $statusNorm    = "LOWER(REPLACE(REPLACE($trimFn($statusExpr),' ',''),'_',''))";

        // --- Build DATE expression using only existing timestamp columns ---
        $tsCandidates = array_values(array_filter([
            Schema::hasColumn('macro_output','TIMESTAMP') ? 'TIMESTAMP' : null,
            Schema::hasColumn('macro_output','timestamp') ? 'timestamp' : null,
        ]));

        if ($driver === 'mysql') {
            if (!empty($tsCandidates)) {
                $ts = 'mo.' . $quote($tsCandidates[0]); // use first found
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
            foreach ($tsCandidates as $c) {
                $colRef = 'mo.' . $quote($c);
                $pgParts[] = "TO_TIMESTAMP(NULLIF($colRef, ''), 'HH24:MI DD-MM-YYYY')";
                $pgParts[] = "TO_TIMESTAMP(NULLIF($colRef, ''), 'HH24:MI MM-DD-YYYY')";
            }
            // Always add created_at fallback
            $pgParts[] = 'mo."created_at"';
            $dateExpr  = 'DATE(COALESCE(' . implode(', ', $pgParts) . '))';
        }

        // --- Ad spend cast (sanitize ₱, commas, spaces, blanks) ---
        $castSpend = $driver === 'pgsql'
            ? "COALESCE(NULLIF(REGEXP_REPLACE(COALESCE(amount_spent_php::text, ''), '[^0-9\\.\\-]', '', 'g'), '')::numeric, 0)"
            : "CAST(REPLACE(REPLACE(REPLACE(COALESCE(amount_spent_php,''), '₱',''), ',', ''), ' ', '') AS DECIMAL(18,2))";

        // ----------------------
        // ADS: group by DATE(day) + page
        // ----------------------
        $adsBase = DB::table('ads_manager_reports');

        if ($start && $end) {
            $adsBase->whereRaw('DATE(day) BETWEEN ? AND ?', [$start, $end]);
        } elseif ($start) {
            $adsBase->whereRaw('DATE(day) >= ?', [$start]);
        } elseif ($end) {
            $adsBase->whereRaw('DATE(day) <= ?', [$end]);
        }

        if ($pageName && $pageName !== 'all') {
            if ($driver === 'pgsql') {
                $adsBase->whereRaw("$trimFn(page_name) ILIKE $trimFn(?)", [$pageName]);
            } else {
                $adsBase->whereRaw("LOWER($trimFn(page_name)) = LOWER($trimFn(?))", [$pageName]);
            }
        }

        $adsRows = (clone $adsBase)
            ->whereNotNull('day')
            ->selectRaw("DATE(day) AS day_key, $trimFn(COALESCE(page_name, '')) AS page_key, SUM($castSpend) AS adspent")
            ->groupByRaw("DATE(day), $trimFn(COALESCE(page_name,''))")
            ->havingRaw("SUM($castSpend) > 0")
            ->orderBy('day_key')->orderBy('page_key')
            ->get();

        $adsMap = [];
        foreach ($adsRows as $r) {
            $adsMap["{$r->day_key}|{$r->page_key}"] = (float)($r->adspent ?? 0);
        }

        // ----------------------
        // ORDERS + STATUS buckets (macro_output)
        // ----------------------
        $mo = DB::table('macro_output as mo');

        if ($start && $end) {
            $mo->whereRaw("$dateExpr BETWEEN ? AND ?", [$start, $end]);
        } elseif ($start) {
            $mo->whereRaw("$dateExpr >= ?", [$start]);
        } elseif ($end) {
            $mo->whereRaw("$dateExpr <= ?", [$end]);
        }

        if ($pageName && $pageName !== 'all') {
            if ($driver === 'pgsql') {
                $mo->whereRaw("$pageExpr ILIKE $trimFn(?)", [$pageName]);
            } else {
                $mo->whereRaw("LOWER($pageExpr) = LOWER($trimFn(?))", [$pageName]);
            }
        }

        // All Orders
        $ordersRows = (clone $mo)
            ->selectRaw("$dateExpr AS day_key, $pageExpr AS page_key, COUNT(*) AS orders_total")
            ->groupByRaw("$dateExpr, $pageExpr")
            ->get();

        $ordersMap = [];
        foreach ($ordersRows as $r) {
            $ordersMap["{$r->day_key}|{$r->page_key}"] = (int)($r->orders_total ?? 0);
        }

        // Proceed
        $proceedRows = (clone $mo)
            ->whereRaw("$statusNorm = 'proceed'")
            ->selectRaw("$dateExpr AS day_key, $pageExpr AS page_key, COUNT(*) AS proceed_total")
            ->groupByRaw("$dateExpr, $pageExpr")
            ->get();

        $proceedMap = [];
        foreach ($proceedRows as $r) {
            $proceedMap["{$r->day_key}|{$r->page_key}"] = (int)($r->proceed_total ?? 0);
        }

        // Cannot Proceed
        $cannotRows = (clone $mo)
            ->whereRaw("$statusNorm = 'cannotproceed'")
            ->selectRaw("$dateExpr AS day_key, $pageExpr AS page_key, COUNT(*) AS cannot_total")
            ->groupByRaw("$dateExpr, $pageExpr")
            ->get();

        $cannotMap = [];
        foreach ($cannotRows as $r) {
            $cannotMap["{$r->day_key}|{$r->page_key}"] = (int)($r->cannot_total ?? 0);
        }

        // ODZ
        $odzRows = (clone $mo)
            ->whereRaw("$statusNorm = 'odz'")
            ->selectRaw("$dateExpr AS day_key, $pageExpr AS page_key, COUNT(*) AS odz_total")
            ->groupByRaw("$dateExpr, $pageExpr")
            ->get();

        $odzMap = [];
        foreach ($odzRows as $r) {
            $odzMap["{$r->day_key}|{$r->page_key}"] = (int)($r->odz_total ?? 0);
        }

        // SHIPPED — DISTINCT waybill present in from_jnts
        $shippedRows = (clone $mo)
            ->whereRaw("$moWaybill IS NOT NULL")
            ->whereRaw("$trimFn($moWaybill) <> ''")
            ->join('from_jnts as j', function ($join) use ($trimFn, $moWaybill) {
                $join->on(DB::raw("$trimFn($moWaybill)"), '=', DB::raw("$trimFn(j.waybill_number)"));
            })
            ->selectRaw("$dateExpr AS day_key, $pageExpr AS page_key, COUNT(DISTINCT $trimFn($moWaybill)) AS shipped_total")
            ->groupByRaw("$dateExpr, $pageExpr")
            ->get();

        $shippedMap = [];
        foreach ($shippedRows as $r) {
            $shippedMap["{$r->day_key}|{$r->page_key}"] = (int)($r->shipped_total ?? 0);
        }

        // DELIVERED — shipped & j.status LIKE 'delivered%'
        $deliveredRows = (clone $mo)
            ->whereRaw("$moWaybill IS NOT NULL")
            ->whereRaw("$trimFn($moWaybill) <> ''")
            ->join('from_jnts as j', function ($join) use ($trimFn, $moWaybill) {
                $join->on(DB::raw("$trimFn($moWaybill)"), '=', DB::raw("$trimFn(j.waybill_number)"));
            })
            ->whereRaw("LOWER($trimFn(COALESCE(j.status,''))) LIKE 'delivered%'")
            ->selectRaw("$dateExpr AS day_key, $pageExpr AS page_key, COUNT(DISTINCT $trimFn($moWaybill)) AS delivered_total")
            ->groupByRaw("$dateExpr, $pageExpr")
            ->get();

        $deliveredMap = [];
        foreach ($deliveredRows as $r) {
            $deliveredMap["{$r->day_key}|{$r->page_key}"] = (int)($r->delivered_total ?? 0);
        }

        // Merge — keep only rows with Adspent > 0
        $keys = array_unique(array_merge(
            array_keys($adsMap),
            array_keys($ordersMap),
            array_keys($proceedMap),
            array_keys($cannotMap),
            array_keys($odzMap),
            array_keys($shippedMap),
            array_keys($deliveredMap),
        ));

        $rows = [];
        foreach ($keys as $key) {
            [$d, $p] = explode('|', $key, 2);
            $adspent = $adsMap[$key] ?? 0.0;
            if ($adspent <= 0) continue;

            $orders    = $ordersMap[$key]    ?? 0;
            $proc      = $proceedMap[$key]   ?? 0;
            $cannot    = $cannotMap[$key]    ?? 0;
            $odz       = $odzMap[$key]       ?? 0;
            $shipped   = $shippedMap[$key]   ?? 0;
            $delivered = $deliveredMap[$key] ?? 0;

            $tcpr = $orders > 0 ? (1 - ($proc / $orders)) * 100.0 : null;

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
                'tcpr'            => $tcpr,
            ];
        }

        usort($rows, function ($a, $b) {
            if ($a['date'] === $b['date']) {
                return strcmp($a['page'] ?? '', $b['page'] ?? '');
            }
            return strcmp($a['date'], $b['date']);
        });

        return response()->json(['ads_daily' => $rows]);
    }
}
