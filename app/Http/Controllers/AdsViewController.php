<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AdsManager;
use App\Models\LikhaOrder;

class AdsViewController extends Controller
{
    public function index(Request $request)
    {
        $normalize = fn($str) => strtolower(str_replace(' ', '', $str));

        $start = $request->input('start_date');
        $end = $request->input('end_date');
        $search = $request->input('search');

        // Filter AdsManager
        $adsQuery = AdsManager::query();
        if ($start) $adsQuery->where('reporting_starts', '>=', $start);
        if ($end) $adsQuery->where('reporting_starts', '<=', $end);
        $ads = $adsQuery->get();

        // Filter LikhaOrder
        $ordersQuery = LikhaOrder::query();
        if ($start) $ordersQuery->where('date', '>=', $start);
        if ($end) $ordersQuery->where('date', '<=', $end);
        $orders = $ordersQuery->get();

        // Group by normalized page
        $adsGrouped = $ads->groupBy(fn($item) => $normalize($item->page));
        $ordersGrouped = $orders->groupBy(fn($item) => $normalize($item->page_name));

        $matrix = [];

        foreach ($adsGrouped as $normPage => $adsGroup) {
            $pageName = $adsGroup->first()->page;

            // Optional search filter
            if ($search && !str_contains(strtolower($pageName), strtolower($search))) {
                continue;
            }

            $totalSpent = $adsGroup->sum('amount_spent');
            $totalImpressions = $adsGroup->sum('impressions');

            // ✅ Safe access to ordersGrouped
            $ordersCount = $ordersGrouped->has($normPage)
                ? $ordersGrouped[$normPage]->count()
                : 0;

            // Weighted metrics
            $cpp = $ordersCount > 0 ? round($totalSpent / $ordersCount, 2) : null;
            $cpm = $totalImpressions > 0 ? round(($totalSpent / $totalImpressions) * 1000, 2) : null;
            $cpi = $totalImpressions > 0 ? round($totalSpent / $totalImpressions, 2) : null;

            $matrix[] = [
                'page' => $pageName,
                'spent' => $totalSpent,
                'orders' => $ordersCount,
                'cpp' => $cpp,
                'cpm' => $cpm,
                'cpi' => $cpi,
            ];
        }

        // Sort alphabetically by page name
        $matrix = collect($matrix)->sortBy('page')->values();

        return view('ads_manager.ads', compact('matrix'));
    }
}
