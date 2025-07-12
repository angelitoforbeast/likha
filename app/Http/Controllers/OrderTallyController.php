<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

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

        // Step 2: macro_output raw data, parse TIMESTAMP in PHP
        $macroRaw = DB::table('macro_output')->select('TIMESTAMP', 'PAGE')->get();

        $macroGrouped = [];

        foreach ($macroRaw as $row) {
            $parts = explode(' ', $row->TIMESTAMP);
            $rawDate = $parts[1] ?? null;

            if (!$rawDate) continue;

            try {
                $dateFormatted = Carbon::createFromFormat('d-m-Y', $rawDate)->format('Y-m-d');
            } catch (\Exception $e) {
                continue;
            }

            $page = $row->PAGE;
            $key = $dateFormatted . '|' . $page;

            if (!isset($macroGrouped[$key])) {
                $macroGrouped[$key] = [
                    'date' => $dateFormatted,
                    'page_name' => $page,
                    'macro_count' => 0,
                ];
            }

            $macroGrouped[$key]['macro_count']++;
        }

        // Convert to collection
        $macroOutput = collect(array_values($macroGrouped));

        // ✅ Apply filter if date is selected
        if ($filterDate) {
            $macroOutput = $macroOutput->filter(fn ($row) => $row['date'] === $filterDate)->values();
        }

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
            $moDate = strtolower(trim($mo['date']));
            $moPage = strtolower(trim($mo['page_name']));
            $foundMatch = false;

            $combined = $combined->map(function ($row) use ($moDate, $moPage, $mo, &$foundMatch) {
                if (
                    strtolower(trim($row['date'])) === $moDate &&
                    strtolower(trim($row['page_name'])) === $moPage
                ) {
                    $row['macro_count'] = $mo['macro_count'];
                    $foundMatch = true;
                }
                return $row;
            });

            if (!$foundMatch) {
                if ($filterDate === $mo['date']) {
                    logger("NO MATCH FOUND FOR: {$mo['date']} | {$mo['page_name']}");
                }

                $combined->push([
                    'date' => $mo['date'],
                    'page_name' => $mo['page_name'],
                    'likha_count' => 0,
                    'macro_count' => $mo['macro_count'],
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
            ->select('date')
            ->distinct()
            ->orderByDesc('date')
            ->pluck('date');

        // Step 6: total summary
        $totals = [
            'likha_orders' => DB::table('likha_orders')->count(),
            'macro_output' => DB::table('macro_output')->count(),
        ];

        // Step 7: filtered daily total
        $dailyTotal = [
            'likha_orders' => $combined->sum('likha_count'),
            'macro_output' => $combined->sum('macro_count'),
            'difference' => $combined->sum('likha_count') - $combined->sum('macro_count'),
        ];

        return view('orders.tally', compact(
            'combined',
            'totals',
            'availableDates',
            'filterDate',
            'dailyTotal'
        ));
    }
}
