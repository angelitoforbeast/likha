<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AdsManagerReport;
use App\Models\MacroOutput;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class PancakeSubscriptionCheckerController extends Controller
{
    public function index(Request $request)
    {
        // Range inputs; default both = today
        $from = $request->input('from');
        $to   = $request->input('to');

        if (!$from || !$to) {
            $from = now()->toDateString(); // Y-m-d
            $to   = $from;
        }

        // Build list of days in 'd-m-Y' for macro_output TIMESTAMP LIKE filters
        $period   = CarbonPeriod::create(Carbon::parse($from), Carbon::parse($to));
        $datesDMY = [];
        foreach ($period as $d) {
            $datesDMY[] = $d->format('d-m-Y');
        }

        $normalize = fn($v) => strtolower(str_replace(' ', '', (string) $v));

        /**
         * 1) ADS SIDE (ads_manager_report)
         * Required fields: day (Y-m-d), page_name, purchases
         * Sum purchases per page within [from, to]
         */
        $adsRows = AdsManagerReport::query()
            ->select(['day', 'page_name', 'purchases'])
            ->whereBetween('day', [$from, $to])
            ->get();

        $adsByPage = [];
        foreach ($adsRows as $r) {
            $key = $normalize($r->page_name);
            if (!isset($adsByPage[$key])) {
                $adsByPage[$key] = ['page' => (string) $r->page_name, 'purchases' => 0];
            }
            $adsByPage[$key]['purchases'] += (int) ($r->purchases ?? 0);
        }

        /**
         * 2) ORDERS SIDE (macro_output)
         * TIMESTAMP format: "H:i d-m-Y"
         * OR-like per day sa period
         */
        $ordersRows = MacroOutput::query()
            ->select(['TIMESTAMP', 'PAGE'])
            ->when(count($datesDMY) > 0, function ($q) use ($datesDMY) {
                $q->where(function ($qq) use ($datesDMY) {
                    foreach ($datesDMY as $dmy) {
                        $qq->orWhere('TIMESTAMP', 'like', '% ' . $dmy);
                    }
                });
            })
            ->get();

        $ordersByPage = [];
        foreach ($ordersRows as $r) {
            $key = $normalize($r->PAGE);
            if (!isset($ordersByPage[$key])) {
                $ordersByPage[$key] = ['page' => (string) $r->PAGE, 'orders' => 0];
            }
            $ordersByPage[$key]['orders']++;
        }

        /**
         * 3) Merge pages + totals
         */
        $allKeys = collect(array_unique(array_merge(array_keys($adsByPage), array_keys($ordersByPage))))->values();

        $rows = [];
        $totalPurchases = 0;
        $totalOrders    = 0;

        foreach ($allKeys as $k) {
            $pageName  = $adsByPage[$k]['page'] ?? ($ordersByPage[$k]['page'] ?? $k);
            $purchases = $adsByPage[$k]['purchases'] ?? 0;
            $orders    = $ordersByPage[$k]['orders'] ?? 0;

            $rows[] = ['page' => $pageName, 'purchases' => $purchases, 'orders' => $orders];

            $totalPurchases += $purchases;
            $totalOrders    += $orders;
        }

        usort($rows, fn($a, $b) => strcasecmp($a['page'], $b['page']));

        $totals = ['purchases' => $totalPurchases, 'orders' => $totalOrders];

        return view('ads_manager.pancake-subscription-checker', [
            'from'   => $from,
            'to'     => $to,
            'rows'   => $rows,
            'totals' => $totals,
        ]);
    }
}
