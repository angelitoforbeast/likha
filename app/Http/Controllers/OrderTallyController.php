<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderTallyController extends Controller
{
    public function index(Request $request)
{
    $filterDate = $request->input('date');

    // Step 1: likha_orders query
    $likhaQuery = DB::table('likha_orders')
        ->select('date as date', 'page_name', DB::raw('COUNT(*) as likha_count'))
->groupBy('date', 'page_name');


    if ($filterDate) {
        $likhaQuery->where('date', $filterDate);
    }

    $likhaOrders = $likhaQuery->get();

    // Step 2: macro_output query (with fixed TIMESTAMP parsing)
    $macroQuery = DB::table('macro_output')
        ->selectRaw("
            DATE_FORMAT(STR_TO_DATE(SUBSTRING_INDEX(`TIMESTAMP`, ' ', -1), '%d-%m-%Y'), '%Y-%m-%d') AS date,
            `PAGE` AS page_name,
            COUNT(*) AS macro_count
        ")
        ->groupBy('date', 'page_name');

    if ($filterDate) {
        $macroQuery->having('date', '=', $filterDate);
    }

    $macroOutput = $macroQuery->get();

    // Step 3: combine results manually
    $combined = collect();

    foreach ($likhaOrders as $lo) {
        $combined->push([
            'date' => $lo->date,
            'page_name' => $lo->page_name,
            'likha_count' => $lo->likha_count,
            'macro_count' => 0,
        ]);
    }

    foreach ($macroOutput as $mo) {
        $moDate = strtolower(trim($mo->date));
        $moPage = strtolower(trim($mo->page_name));
        $foundMatch = false;

        $combined = $combined->map(function ($row) use ($moDate, $moPage, $mo, &$foundMatch) {
            if (
                strtolower(trim($row['date'])) === $moDate &&
                strtolower(trim($row['page_name'])) === $moPage
            ) {
                $row['macro_count'] = $mo->macro_count;
                $foundMatch = true;
            }
            return $row;
        });

        if (!$foundMatch) {
            if ($filterDate === $mo->date) {
                logger("NO MATCH FOUND FOR: {$mo->date} | {$mo->page_name}");
            }

            $combined->push([
                'date' => $mo->date,
                'page_name' => $mo->page_name,
                'likha_count' => 0,
                'macro_count' => $mo->macro_count,
            ]);
        }
    }

    // Step 4: compute difference
    $combined = $combined->map(function ($row) {
        $row['difference'] = $row['likha_count'] - $row['macro_count'];
        return $row;
    })->sortByDesc('date')->values();

    // Step 5: date dropdown values
    $availableDates = DB::table('likha_orders')
        ->select('DATE')
        ->distinct()
        ->orderByDesc('DATE')
        ->pluck('DATE');

    // Step 6: total summary
    $totals = [
        'likha_orders' => DB::table('likha_orders')->count(),
        'macro_output' => DB::table('macro_output')->count(),
    ];

    // ✅ NEW: Daily total
    $dailyTotal = [
        'likha_orders' => $combined->sum('likha_count'),
        'macro_output' => $combined->sum('macro_count'),
        'difference' => $combined->sum('likha_count') - $combined->sum('macro_count'),
    ];

    return view('orders.tally', compact('combined', 'totals', 'availableDates', 'filterDate', 'dailyTotal'));
}

}
