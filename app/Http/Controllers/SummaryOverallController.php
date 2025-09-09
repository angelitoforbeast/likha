<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SummaryOverallController extends Controller
{
    public function index()
    {
        $pages = DB::table('ads_manager_reports')
            ->whereNotNull('page_name')
            ->selectRaw('TRIM(page_name) AS page_name')
            ->distinct()
            ->orderBy('page_name')
            ->pluck('page_name')
            ->toArray();

        return view('summary.overall', compact('pages'));
    }

    /**
     * Bottom table only:
     * Date | Page | Adspent | Orders | Proceed | Cannot Proceed | ODZ | Shipped | Delivered | TCPR
     * Filters: start_date, end_date, page_name
     * Rows shown only when Adspent > 0
     */
    public function data(Request $request)
    {
        $start    = $request->input('start_date');   // YYYY-MM-DD
        $end      = $request->input('end_date');     // YYYY-MM-DD
        $pageName = $request->input('page_name', 'all');

        $driver     = DB::getDriverName(); // 'mysql' or 'pgsql'
        $trimFn     = $driver === 'pgsql' ? 'BTRIM' : 'TRIM';
        $lowerTrim  = "LOWER($trimFn(%s))";

        // Spend cast per driver
        $castSpend  = $driver === 'pgsql'
            ? 'CAST(amount_spent_php AS NUMERIC)'
            : 'CAST(amount_spent_php AS DECIMAL(18,2))';

        // ----------------------
        // ADS: group by day + page_name
        // ----------------------
        $adsBase = DB::table('ads_manager_reports');

        if ($start && $end) {
            $adsBase->whereBetween('day', [$start, $end]);
        } elseif ($start) {
            $adsBase->where('day', '>=', $start);
        } elseif ($end) {
            $adsBase->where('day', '<=', $end);
        }

        if ($pageName && $pageName !== 'all') {
            $adsBase->whereRaw(sprintf("$lowerTrim = $lowerTrim", 'page_name', '?'), [$pageName]);
        }

        $adsRows = (clone $adsBase)
            ->whereNotNull('day')
            ->selectRaw("day AS day_key, $trimFn(COALESCE(page_name, '')) AS page_name, SUM($castSpend) AS adspent")
            ->groupBy('day', 'page_name')
            ->havingRaw("SUM($castSpend) > 0") // only rows with spend > 0
            ->orderBy('day', 'asc')
            ->orderBy('page_name', 'asc')
            ->get();

        $adsMap = [];
        foreach ($adsRows as $r) {
            $key = (string)$r->day_key . '|' . (string)$r->page_name;
            $adsMap[$key] = (float) ($r->adspent ?? 0);
        }

        // ----------------------
        // ORDERS + STATUS buckets (macro_output)
        // TIMESTAMP sample: "21:44 09-06-2025" → support dd-mm-yyyy at mm-dd-yyyy
        // ----------------------
        $hasTs = Schema::hasColumn('macro_output', 'TIMESTAMP');

        if ($hasTs) {
            if ($driver === 'mysql') {
                $dateExpr = "COALESCE(
                    DATE(STR_TO_DATE(mo.`TIMESTAMP`, '%H:%i %d-%m-%Y')),
                    DATE(STR_TO_DATE(mo.`TIMESTAMP`, '%H:%i %m-%d-%Y'))
                )";
            } else { // pgsql
                $dateExpr = "DATE(COALESCE(
                    TO_TIMESTAMP(mo.\"TIMESTAMP\", 'HH24:MI DD-MM-YYYY'),
                    TO_TIMESTAMP(mo.\"TIMESTAMP\", 'HH24:MI MM-DD-YYYY')
                ))";
            }
        } else {
            $dateExpr = $driver === 'mysql'
                ? "DATE(mo.`created_at`)"
                : "DATE(mo.\"created_at\")";
        }

        // Normalize status: lowercase + strip spaces/underscores
        $statusNorm = "LOWER(REPLACE(REPLACE($trimFn(mo.status),' ',''),'_',''))";

        $mo = DB::table('macro_output as mo');

        if ($start && $end) {
            $mo->whereRaw("$dateExpr BETWEEN ? AND ?", [$start, $end]);
        } elseif ($start) {
            $mo->whereRaw("$dateExpr >= ?", [$start]);
        } elseif ($end) {
            $mo->whereRaw("$dateExpr <= ?", [$end]);
        }
        if ($pageName && $pageName !== 'all') {
            $mo->whereRaw(sprintf("$lowerTrim = $lowerTrim", 'mo.page', '?'), [$pageName]);
        }

        // All Orders
        $ordersRows = (clone $mo)
            ->selectRaw("$dateExpr AS day_key, $trimFn(COALESCE(mo.page,'')) AS page_name, COUNT(*) AS orders_total")
            ->groupBy('day_key', 'page_name')
            ->get();

        $ordersMap = [];
        foreach ($ordersRows as $r) {
            $key = (string)$r->day_key . '|' . (string)$r->page_name;
            $ordersMap[$key] = (int) ($r->orders_total ?? 0);
        }

        // Proceed
        $proceedRows = (clone $mo)
            ->whereRaw("$statusNorm = 'proceed'")
            ->selectRaw("$dateExpr AS day_key, $trimFn(COALESCE(mo.page,'')) AS page_name, COUNT(*) AS proceed_total")
            ->groupBy('day_key', 'page_name')
            ->get();

        $proceedMap = [];
        foreach ($proceedRows as $r) {
            $key = (string)$r->day_key . '|' . (string)$r->page_name;
            $proceedMap[$key] = (int) ($r->proceed_total ?? 0);
        }

        // Cannot Proceed
        $cannotRows = (clone $mo)
            ->whereRaw("$statusNorm = 'cannotproceed'")
            ->selectRaw("$dateExpr AS day_key, $trimFn(COALESCE(mo.page,'')) AS page_name, COUNT(*) AS cannot_total")
            ->groupBy('day_key', 'page_name')
            ->get();

        $cannotMap = [];
        foreach ($cannotRows as $r) {
            $key = (string)$r->day_key . '|' . (string)$r->page_name;
            $cannotMap[$key] = (int) ($r->cannot_total ?? 0);
        }

        // ODZ
        $odzRows = (clone $mo)
            ->whereRaw("$statusNorm = 'odz'")
            ->selectRaw("$dateExpr AS day_key, $trimFn(COALESCE(mo.page,'')) AS page_name, COUNT(*) AS odz_total")
            ->groupBy('day_key', 'page_name')
            ->get();

        $odzMap = [];
        foreach ($odzRows as $r) {
            $key = (string)$r->day_key . '|' . (string)$r->page_name;
            $odzMap[$key] = (int) ($r->odz_total ?? 0);
        }

        // SHIPPED — count DISTINCT mo.waybill appearing in from_jnts.waybill_number
        $shippedRows = (clone $mo)
            ->whereNotNull('mo.waybill')
            ->whereRaw("$trimFn(mo.waybill) <> ''")
            ->join('from_jnts as j', function ($join) use ($trimFn) {
                $join->on(DB::raw("$trimFn(mo.waybill)"), '=', DB::raw("$trimFn(j.waybill_number)"));
            })
            ->selectRaw("$dateExpr AS day_key, $trimFn(COALESCE(mo.page,'')) AS page_name, COUNT(DISTINCT $trimFn(mo.waybill)) AS shipped_total")
            ->groupBy('day_key', 'page_name')
            ->get();

        $shippedMap = [];
        foreach ($shippedRows as $r) {
            $key = (string)$r->day_key . '|' . (string)$r->page_name;
            $shippedMap[$key] = (int) ($r->shipped_total ?? 0);
        }

        // DELIVERED — shipped but j.status LIKE 'delivered%'
        $deliveredRows = (clone $mo)
            ->whereNotNull('mo.waybill')
            ->whereRaw("$trimFn(mo.waybill) <> ''")
            ->join('from_jnts as j', function ($join) use ($trimFn) {
                $join->on(DB::raw("$trimFn(mo.waybill)"), '=', DB::raw("$trimFn(j.waybill_number)"));
            })
            ->whereRaw("LOWER($trimFn(j.status)) LIKE 'delivered%'")
            ->selectRaw("$dateExpr AS day_key, $trimFn(COALESCE(mo.page,'')) AS page_name, COUNT(DISTINCT $trimFn(mo.waybill)) AS delivered_total")
            ->groupBy('day_key', 'page_name')
            ->get();

        $deliveredMap = [];
        foreach ($deliveredRows as $r) {
            $key = (string)$r->day_key . '|' . (string)$r->page_name;
            $deliveredMap[$key] = (int) ($r->delivered_total ?? 0);
        }

        // ----------------------
        // Merge — keep only rows with Adspent > 0
        // TCPR = (1 - Proceed / Orders) * 100 (null if Orders == 0)
        // ----------------------
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

            $orders    = $ordersMap[$key]     ?? 0;
            $proc      = $proceedMap[$key]    ?? 0;
            $cannot    = $cannotMap[$key]     ?? 0;
            $odz       = $odzMap[$key]        ?? 0;
            $shipped   = $shippedMap[$key]    ?? 0;
            $delivered = $deliveredMap[$key]  ?? 0;

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

        return response()->json([
            'ads_daily' => $rows,
        ]);
    }
}
