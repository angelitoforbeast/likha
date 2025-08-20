<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AdsManagerReport;
use App\Models\MacroOutput;
use Carbon\Carbon;

class CPPController extends Controller
{
    public function index(Request $request)
    {
        // Normalize page for joining
        $normalize = fn($str) => strtolower(str_replace(' ', '', (string) $str));

        /**
         * 1) ADS DATA (from ads_manager_report)
         * - page column: page_name
         * - compute:
         *    CPM (your definition) = total_spent / total_messages
         *    CPI (your definition) = (total_spent * 1000) / total_impressions
         */
        $adsData = AdsManagerReport::query()
            ->select([
                'day',
                'page_name',
                'amount_spent_php',
                'messaging_conversations_started',
                'impressions',
            ])
            ->get()
            ->groupBy(function ($row) use ($normalize) {
                $date = (string) $row->day; // expected Y-m-d
                return $date . '__' . $normalize($row->page_name);
            });

        /**
         * 2) ORDERS DATA (from macro_output)
         * - include STATUS for TCPR
         */
        $orderData = MacroOutput::query()
            ->select(['TIMESTAMP', 'PAGE', 'ITEM_NAME', 'COD', 'STATUS']) // <-- added STATUS
            ->get()
            ->groupBy(function ($row) use ($normalize) {
                try {
                    $date = Carbon::createFromFormat('H:i d-m-Y', (string) $row->TIMESTAMP)->format('Y-m-d');
                } catch (\Exception $e) {
                    return null; // skip malformed dates
                }
                return $date . '__' . $normalize($row->PAGE);
            });

        /**
         * 3) MERGE + COMPUTE per (date, page)
         */
        $summary = [];

        foreach ($adsData as $key => $group) {
            if (!$key) continue;

            [$date, $normalizedPage] = explode('__', $key);

            $totalSpent       = (float) $group->sum('amount_spent_php');
            $totalMessages    = (int)   $group->sum('messaging_conversations_started');
            $totalImpressions = (int)   $group->sum('impressions');

            // Your definitions:
            // CPM = amount_spent_php / messaging_conversations_started
            $cpm = $totalMessages > 0 ? round($totalSpent / $totalMessages, 2) : null;

            // CPI = (amount_spent_php * 1000) / impressions
            $cpi = $totalImpressions > 0 ? round(($totalSpent * 1000) / $totalImpressions, 2) : null;

            // Actual orders from macro_output
            $orders     = $orderData->get($key, collect());
            $orderCount = $orders->count();
            $cpp        = $orderCount > 0 ? round($totalSpent / $orderCount, 2) : null;

            // TCPR numerator: count STATUS == "CANNOT PROCEED"
            $cannotProceed = $orders->filter(function ($o) {
                $status = strtoupper(trim((string)($o->STATUS ?? '')));
                return $status === 'CANNOT PROCEED';
            })->count();
            $proceedCount = $orders->filter(fn($o) =>
    strtoupper(trim((string)($o->STATUS ?? ''))) === 'PROCEED'
)->count();


            $uniqueItems = $orders->pluck('ITEM_NAME')->filter()->unique()->values()->all();
            $uniqueCODs  = $orders->pluck('COD')->filter()->unique()->values()->all();

            $summary[] = [
                'date'             => $date,
                'page'             => $group->first()->page_name,
                'amount_spent'     => $totalSpent,
                'orders'           => $orderCount,
                'cpp'              => $cpp,
                'cpm'              => $cpm,
                'cpi'              => $cpi,
                'item_names'       => $uniqueItems,
                'cods'             => $uniqueCODs,
                'cannot_proceed'   => $cannotProceed, // <-- add numerator for TCPR
                'proceed'       => $proceedCount,   // 👈 add here
            ];
        }

        // 4) Sort + reshape for blade
        $summary  = collect($summary)->sortBy('date')->values();
        $allDates = $summary->pluck('date')->unique()->sort()->values();

        $matrix = [];
        foreach ($summary as $row) {
            $page = $row['page'];
            $date = $row['date'];

            if (!isset($matrix[$page])) {
                $matrix[$page] = [];
            }

            $matrix[$page][$date] = [
                'cpp'        => $row['cpp'],
                'orders'     => $row['orders'],
                'cpm'        => $row['cpm'],
                'cpi'        => $row['cpi'],
                'spent'      => $row['amount_spent'],
                'item_names' => $row['item_names'],
                'cods'       => $row['cods'],
                'tcpr_fail'  => $row['cannot_proceed'], // <-- per-day fail count
                'proceed'    => $row['proceed'],    // 👈 add here
            ];
        }

        $matrix = collect($matrix)->sortKeys()->toArray();

        return view('ads_manager.cpp', compact('matrix', 'allDates'));
    }
}
