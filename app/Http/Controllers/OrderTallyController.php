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

        $likhaQuery = DB::table('likha_orders')
            ->select('date as date', 'page_name', DB::raw('COUNT(*) as likha_count'))
            ->groupBy('date', 'page_name');

        if ($filterDate) {
            $likhaQuery->where('date', $filterDate);
        }

        $likhaOrders = $likhaQuery->get();

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

        $macroOutput = collect(array_values($macroGrouped));

        if ($filterDate) {
            $macroOutput = $macroOutput->filter(fn ($row) => $row['date'] === $filterDate)->values();
        }

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

        $combined = $combined->map(function ($row) {
            $row['difference'] = $row['likha_count'] - $row['macro_count'];
            return $row;
        })->sortByDesc('date')->values();

        $availableDates = DB::table('likha_orders')
            ->select('date')
            ->distinct()
            ->orderByDesc('date')
            ->pluck('date');

        $totals = [
            'likha_orders' => DB::table('likha_orders')->count(),
            'macro_output' => DB::table('macro_output')->count(),
        ];

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

    public function show($date)
{
    $formattedDate = Carbon::parse($date)->format('Y-m-d');

    $likhaOrders = DB::table('likha_orders')
        ->where('date', $formattedDate)
        ->select('date', 'page_name', 'name')
        ->get();

    $rawMacro = DB::table('macro_output')
        ->select('TIMESTAMP', 'PAGE', DB::raw('`ALL USER INPUT` as ALL_USER_INPUT'))
        ->get();

    $macroFiltered = [];

    foreach ($rawMacro as $row) {
        $parts = explode(' ', $row->TIMESTAMP);
        $rawDate = $parts[1] ?? null;

        if (!$rawDate) continue;

        try {
            $convertedDate = Carbon::createFromFormat('d-m-Y', $rawDate)->format('Y-m-d');
        } catch (\Exception $e) {
            continue;
        }

        if ($convertedDate !== $formattedDate) continue;

        preg_match('/FB NAME:\s*(.+?)(\r?\n|$)/i', $row->ALL_USER_INPUT, $matches);
        $fbName = isset($matches[1]) ? trim($matches[1]) : null;

        if (!$fbName) continue;

        $macroFiltered[] = [
            'date' => $convertedDate,
            'page' => trim($row->PAGE),
            'fb_name' => $fbName,
        ];
    }

    $macroCollection = collect($macroFiltered);
    $results = collect();

    foreach ($likhaOrders as $lo) {
        $matches = $macroCollection->filter(function ($m) use ($lo) {
            return strtolower(trim($m['page'])) === strtolower(trim($lo->page_name))
                && strtolower(trim($m['fb_name'])) === strtolower(trim($lo->name));
        });

        $status = '✅ Matched';
        if ($matches->count() === 0) {
            $status = '❌ Missing';
        } elseif ($matches->count() > 1) {
            $status = '❗ Duplicated';
        }

        $results->push([
            'date' => $lo->date,
            'page_name' => $lo->page_name,
            'likha_name' => $lo->name,
            'matched_names' => $matches->pluck('fb_name')->implode(', '),
            'status' => $status,
        ]);
    }

    // ✅ Add this to provide date filter options
    $availableDates = DB::table('likha_orders')
        ->select('date')
        ->distinct()
        ->orderByDesc('date')
        ->pluck('date');

    return view('orders.mismatch', [
        'results' => $results->sortByDesc(function ($row) {
            return match ($row['status']) {
                '❌ Missing' => 3,
                '❗ Duplicated' => 2,
                '✅ Matched' => 1,
                default => 0,
            };
        })->values(),
        'date' => $formattedDate,
        'availableDates' => $availableDates,
        'summary' => [
            'total' => $results->count(),
            'matched' => $results->where('status', '✅ Matched')->count(),
            'missing' => $results->where('status', '❌ Missing')->count(),
            'duplicated' => $results->where('status', '❗ Duplicated')->count(),
        ],
    ]);
}

}
