<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AdsManagerReport;
use App\Models\MacroOutput;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CPPController extends Controller
{
    public function index(Request $request)
    {
        // Use query params if both provided; otherwise default to last 7 days
        $qStart = $request->query('start');
        $qEnd   = $request->query('end');

        if ($qStart && $qEnd) {
            $start = $qStart;
            $end   = $qEnd;
        } else {
            $end   = now()->toDateString();
            $start = now()->subDays(6)->toDateString();
        }

        // Swap if reversed
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        [$matrix, $dateRange] = $this->buildData($start, $end);

        return view('ads_manager.cpp', [
            'matrix'   => $matrix,
            'allDates' => $dateRange, // full chosen range
            'start'    => $start,
            'end'      => $end,
        ]);
    }

    /**
     * Build matrix + full date list for the given range.
     * - Filters in DB
     * - Handles MySQL/Postgres timestamp parsing
     * - UNION of keys from Ads and Orders
     */
    private function buildData(string $start, string $end): array
    {
        $normalize = fn ($s) => strtolower(preg_replace('/\s+/', '', (string) $s));

        // 1) ADS (filtered)
        $adsRows = AdsManagerReport::query()
            ->whereBetween('day', [$start, $end])
            ->select(['day', 'page_name', 'amount_spent_php', 'messaging_conversations_started', 'impressions'])
            ->get();

        $adsByKey = $adsRows->groupBy(function ($row) use ($normalize) {
            $date = $row->day instanceof Carbon ? $row->day->format('Y-m-d') : (string) $row->day;
            return $date . '__' . $normalize($row->page_name);
        });

        // 2) ORDERS (filtered; engine-specific)
        $driver = DB::connection()->getDriverName(); // mysql | pgsql | etc.

        if ($driver === 'mysql') {
            $orderRows = MacroOutput::query()
                ->selectRaw("DATE(STR_TO_DATE(`TIMESTAMP`, '%H:%i %d-%m-%Y')) AS ts_date, `PAGE`, `ITEM_NAME`, `COD`, `STATUS`")
                ->whereRaw("DATE(STR_TO_DATE(`TIMESTAMP`, '%H:%i %d-%m-%Y')) BETWEEN ? AND ?", [$start, $end])
                ->get();
        } elseif ($driver === 'pgsql') {
            $orderRows = MacroOutput::query()
                ->selectRaw("to_timestamp(\"TIMESTAMP\", 'HH24:MI DD-MM-YYYY')::date AS ts_date, \"PAGE\", \"ITEM_NAME\", \"COD\", \"STATUS\"")
                ->whereRaw("to_timestamp(\"TIMESTAMP\", 'HH24:MI DD-MM-YYYY')::date BETWEEN ? AND ?", [$start, $end])
                ->get();
        } else {
            // Fallback: parse in PHP then filter
            $orderRows = MacroOutput::query()
                ->select(['TIMESTAMP', 'PAGE', 'ITEM_NAME', 'COD', 'STATUS'])
                ->get()
                ->transform(function ($row) {
                    try {
                        $row->ts_date = Carbon::createFromFormat('H:i d-m-Y', (string) $row->TIMESTAMP)->format('Y-m-d');
                    } catch (\Throwable $e) { $row->ts_date = null; }
                    return $row;
                })
                ->filter(fn ($r) => $r->ts_date && $r->ts_date >= $start && $r->ts_date <= $end)
                ->values();
        }

        $ordersByKey = $orderRows->groupBy(function ($row) use ($normalize) {
            if (!$row->ts_date) return null;
            return $row->ts_date . '__' . $normalize($row->PAGE);
        });

        // 3) UNION of keys (so days with only orders or only ads still show)
        $allKeys = collect($adsByKey->keys())
            ->merge($ordersByKey->keys())
            ->filter()
            ->unique()
            ->values();

        // 4) Merge + compute per (date,page)
        $summary = [];
        foreach ($allKeys as $key) {
            [$date, $normPage] = explode('__', $key);
            $ads    = $adsByKey->get($key, collect());
            $orders = $ordersByKey->get($key, collect());

            $pageName = optional($ads->first())->page_name
                ?? optional($orders->first())->PAGE
                ?? '[Unknown Page]';

            $spent       = (float) $ads->sum('amount_spent_php');
            $messages    = (int)   $ads->sum('messaging_conversations_started');
            $impressions = (int)   $ads->sum('impressions');

            // Your definitions
            $cpm = $messages    > 0 ? round($spent / $messages, 2) : null;               // "cost per message"
            $cpi = $impressions > 0 ? round(($spent * 1000) / $impressions, 2) : null;   // per 1k impressions
            $ordersCount = $orders->count();
            $cpp         = $ordersCount > 0 ? round($spent / $ordersCount, 2) : null;

            $cannotProceed = $orders->filter(fn($o) => strtoupper(trim((string)($o->STATUS ?? ''))) === 'CANNOT PROCEED')->count();
            $proceedCount  = $orders->filter(fn($o) => strtoupper(trim((string)($o->STATUS ?? ''))) === 'PROCEED')->count();

            $summary[] = [
                'date'           => $date,
                'page'           => $pageName,
                'amount_spent'   => $spent,
                'orders'         => $ordersCount,
                'cpp'            => $cpp,
                'cpm'            => $cpm,
                'cpi'            => $cpi,
                'item_names'     => $orders->pluck('ITEM_NAME')->filter()->unique()->values()->all(),
                'cods'           => $orders->pluck('COD')->filter()->unique()->values()->all(),
                'cannot_proceed' => $cannotProceed,
                'proceed'        => $proceedCount,
            ];
        }

        // 5) Full date list (start→end)
        $dateRange = [];
        for ($d = Carbon::parse($start); $d->lte(Carbon::parse($end)); $d->addDay()) {
            $dateRange[] = $d->format('Y-m-d');
        }

        // 6) Build matrix[page][date]
        $matrix = [];
        foreach ($summary as $row) {
            $page = $row['page'];
            $date = $row['date'];
            $matrix[$page] ??= [];
            $matrix[$page][$date] = [
                'cpp'        => $row['cpp'],
                'orders'     => $row['orders'],
                'cpm'        => $row['cpm'],
                'cpi'        => $row['cpi'],
                'spent'      => $row['amount_spent'],
                'item_names' => $row['item_names'],
                'cods'       => $row['cods'],
                'tcpr_fail'  => $row['cannot_proceed'],
                'proceed'    => $row['proceed'],
            ];
        }

        ksort($matrix);

        return [$matrix, $dateRange];
    }
}
