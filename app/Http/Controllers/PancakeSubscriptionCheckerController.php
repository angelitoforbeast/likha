<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AdsManagerReport;
use App\Models\MacroOutput;
use Carbon\Carbon;

class PancakeSubscriptionCheckerController extends Controller
{
    public function index(Request $request)
    {
        // Single date filter (default: today, Y-m-d)
        $date = $request->input('date', now()->toDateString());
        $dateDMY = Carbon::parse($date)->format('d-m-Y');

        // Normalization helper for joining by page
        $normalize = fn($v) => strtolower(str_replace(' ', '', (string) $v));

        /**
         * 1) ADS SIDE (ads_manager_report)
         * Required fields: day, page_name, purchases
         */
        $adsRows = AdsManagerReport::query()
            ->select(['day', 'page_name', 'purchases'])
            ->where('day', $date) // use exact match on the 'day' column
            ->get();

        // Aggregate purchases per normalized page
        $adsByPage = [];
        foreach ($adsRows as $r) {
            $key = $normalize($r->page_name);
            if (!isset($adsByPage[$key])) {
                $adsByPage[$key] = [
                    'page'      => (string) $r->page_name,
                    'purchases' => 0,
                ];
            }
            $adsByPage[$key]['purchases'] += (int) ($r->purchases ?? 0);
        }

        /**
         * 2) ORDERS SIDE (macro_output)
         * Count orders per page for the same day
         * TIMESTAMP format is "H:i d-m-Y" → filter via LIKE on " d-m-Y"
         */
        $ordersRows = MacroOutput::query()
            ->select(['TIMESTAMP', 'PAGE'])
            ->where('TIMESTAMP', 'like', '% ' . $dateDMY)
            ->get();

        $ordersByPage = [];
        foreach ($ordersRows as $r) {
            $key = $normalize($r->PAGE);
            if (!isset($ordersByPage[$key])) {
                $ordersByPage[$key] = [
                    'page'   => (string) $r->PAGE,
                    'orders' => 0,
                ];
            }
            $ordersByPage[$key]['orders']++;
        }

        /**
         * 3) UNION + MERGE (by page)
         */
        $allKeys = collect(array_unique(array_merge(array_keys($adsByPage), array_keys($ordersByPage))))
            ->values();

        $rows = [];
        $totalPurchases = 0;
        $totalOrders    = 0;

        foreach ($allKeys as $k) {
            $pageName  = $adsByPage[$k]['page'] ?? ($ordersByPage[$k]['page'] ?? $k);
            $purchases = $adsByPage[$k]['purchases'] ?? 0;
            $orders    = $ordersByPage[$k]['orders'] ?? 0;

            $rows[] = [
                'page'      => $pageName,
                'purchases' => $purchases, // from ads_manager_report
                'orders'    => $orders,    // from macro_output
            ];

            $totalPurchases += $purchases;
            $totalOrders    += $orders;
        }

        // Sort rows alphabetically by page
        usort($rows, fn($a, $b) => strcasecmp($a['page'], $b['page']));

        $totals = [
            'purchases' => $totalPurchases,
            'orders'    => $totalOrders,
        ];

        return view('ads_manager.pancake-subscription-checker', compact('date', 'rows', 'totals'));
    }
}
