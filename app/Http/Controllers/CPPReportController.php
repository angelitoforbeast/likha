<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AdsManager;
use App\Models\LikhaOrder;

class CPPReportController extends Controller
{
    public function index()
    {
        // Normalize page name
        $normalize = fn($str) => strtolower(str_replace(' ', '', $str));

        // Group Ads Manager data by date + normalized page
        $adsData = AdsManager::all()->groupBy(function ($item) use ($normalize) {
            return $item->reporting_starts . '__' . $normalize($item->page);
        });

        // Group Likha Orders by date + normalized page
        $orderData = LikhaOrder::all()->groupBy(function ($item) use ($normalize) {
            return $item->date . '__' . $normalize($item->page_name);
        });

        $summary = [];

        foreach ($adsData as $key => $adsGroup) {
    [$date, $normalizedPage] = explode('__', $key);

    $amountSpent = $adsGroup->sum('amount_spent');
    $cpm = round($adsGroup->avg('cpm'), 2);

    // Calculate denominator for weighted CPI: sum(spent / cpi)
    $cpiDenominator = $adsGroup->reduce(function ($carry, $row) {
        return $carry + ($row->cpi > 0 ? $row->amount_spent / $row->cpi : 0);
    }, 0);

    $weightedCPI = $cpiDenominator > 0 ? round($amountSpent / $cpiDenominator, 2) : null;

            $orderCount = isset($orderData[$key]) ? $orderData[$key]->count() : 0;
            $cpp = $orderCount > 0 ? round($amountSpent / $orderCount, 2) : null;

            $summary[] = [
                'date' => $date,
                'page' => $adsGroup->first()->page,
                'amount_spent' => $amountSpent,
                'orders' => $orderCount,
                'cpp' => $cpp,
                'cpm' => $cpm,
                'cpi' => $cpi,
            ];
        }

        // Sort by date ASC
        $summary = collect($summary)->sortBy('date')->values();
        $allDates = $summary->pluck('date')->unique()->sort()->values();

        // Restructure into matrix
        $matrix = [];
        foreach ($summary as $row) {
            $page = $row['page'];
            $date = $row['date'];

            if (!isset($matrix[$page])) {
                $matrix[$page] = [];
            }

            $matrix[$page][$date] = [
                'cpp' => $row['cpp'],
                'orders' => $row['orders'],
                'cpm' => $row['cpm'],
                'cpi' => $row['cpi'],  // ✅ ADD THIS LINE
                'spent' => $row['amount_spent'],
            ];
        }

$matrix = collect($matrix)->sortKeys()->toArray();
return view('cpp', compact('matrix', 'allDates'));

    }
}
