<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AdsManagerReport;
use App\Models\MacroOutput;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CPPController extends Controller
{
    /**
     * Allow CEO, Marketing - OIC, and Marketing only. Other roles → 404.
     * Used by snapshot + timeline endpoints (sensitive financial data).
     */
    private function checkAccess(): void
    {
        $raw  = Auth::user()?->employeeProfile?->role ?? '';
        $norm = preg_replace('/\s+/u', ' ', trim((string) $raw));
        $isCEO       = preg_match('/^ceo$/iu', $norm) === 1;
        $isMOIC      = preg_match('/^marketing\s*[-–—]\s*oic$/iu', $norm) === 1;
        $isMarketing = preg_match('/^marketing$/iu', $norm) === 1;
        if (!($isCEO || $isMOIC || $isMarketing)) abort(404);
    }

    /**
     * Map a PH-local datetime to a snapshot bucket label.
     *   06:00 – 12:29  → '10AM'
     *   12:30 – 17:29  → '3PM'
     *   17:30 – 05:59  → '7PM'      (anything else falls here, incl. late-night)
     *   (future cron-only) → '11:59PM'  — schema supports it for forward-compat
     */
    private function bucketFor(Carbon $when): string
    {
        $h = (int) $when->format('H');
        $m = (int) $when->format('i');
        $mins = $h * 60 + $m;

        if ($mins >= 6 * 60 && $mins < 12 * 60 + 30)  return '10AM';
        if ($mins >= 12 * 60 + 30 && $mins < 17 * 60 + 30) return '3PM';
        return '7PM';
    }

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

    // ─────────────────────────────────────────────────────────────────────
    //  SNAPSHOT FEATURE — save the /cpp matrix for "today" sa cpp_snapshots
    //  table whenever the user clicks the Copy Table button.
    //
    //  Bucket auto-derived from PH-local current time (see bucketFor()).
    //  Re-clicks sa same bucket overwrite cleanly via the unique key
    //  (snapshot_date, snapshot_bucket, page_name). This matches the team's
    //  "re-copy when something updates" workflow.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * POST /ads_manager/cpp/snapshot
     *
     * Re-queries /cpp data for today (PH timezone) and persists every page's
     * row into cpp_snapshots, tagged with the current bucket.
     */
    public function saveSnapshot(Request $request)
    {
        $this->checkAccess();

        $nowPh = Carbon::now('Asia/Manila');
        $today = $nowPh->toDateString();
        $bucket = $this->bucketFor($nowPh);

        // Reuse the existing matrix builder — single-day range covers "today".
        [$matrix, $_dateRange] = $this->buildData($today, $today);

        $userId    = Auth::id();
        $userEmail = Auth::user()?->email;

        $normalize = fn ($s) => strtolower(preg_replace('/\s+/', '', (string) $s));

        $saved = 0;
        foreach ($matrix as $pageName => $byDate) {
            $cell = $byDate[$today] ?? null;
            if (!$cell) continue;

            $spent       = (float) ($cell['spent']   ?? 0);
            $orders      = (int)   ($cell['orders']  ?? 0);
            $proceed     = (int)   ($cell['proceed'] ?? 0);
            $cancelled   = (int)   ($cell['tcpr_fail'] ?? 0);
            $items       = (array) ($cell['item_names'] ?? []);

            // tcpr_pct = cannot_proceed ÷ orders × 100 — matches /cpp's TCPR column.
            $tcprPct = $orders > 0 ? round($cancelled / $orders * 100, 2) : null;

            // Upsert by (date, bucket, page). On re-Copy sa same bucket,
            // UPDATE happens — created_at stays as first-save timestamp,
            // updated_at + snapshot_at refresh to latest save.
            $whereKeys = [
                'snapshot_date'   => $today,
                'snapshot_bucket' => $bucket,
                'page_name'       => $pageName,
            ];
            $values = [
                'snapshot_at'         => $nowPh->toDateTimeString(),
                'page_key'            => $normalize($pageName),
                'item_names'          => implode(', ', $items),
                'amount_spent'        => $spent,
                'orders'              => $orders,
                'proceed_orders'      => $proceed,
                'cpp'                 => $cell['cpp'] ?? null,
                'cpi'                 => $cell['cpi'] ?? null,
                'cpm'                 => $cell['cpm'] ?? null,
                'tcpr_pct'            => $tcprPct,
                'saved_by_user_id'    => $userId,
                'saved_by_user_email' => $userEmail,
                'updated_at'          => now(),
            ];
            $existing = DB::table('cpp_snapshots')->where($whereKeys)->first();
            if ($existing) {
                DB::table('cpp_snapshots')->where($whereKeys)->update($values);
            } else {
                DB::table('cpp_snapshots')->insert(array_merge(
                    $whereKeys,
                    $values,
                    ['created_at' => now()]
                ));
            }
            $saved++;
        }

        return response()->json([
            'ok'              => true,
            'snapshot_date'   => $today,
            'snapshot_bucket' => $bucket,
            'snapshot_at'     => $nowPh->toDateTimeString(),
            'pages_saved'     => $saved,
        ]);
    }

    /**
     * GET /ads_manager/cpp/timeline — render the grid view (buckets × dates).
     */
    public function timeline(Request $request)
    {
        $this->checkAccess();

        // Default: last 7 days ending today (PH).
        $end   = $request->query('end')   ?: Carbon::now('Asia/Manila')->toDateString();
        $start = $request->query('start') ?: Carbon::parse($end)->subDays(6)->toDateString();
        if ($start > $end) [$start, $end] = [$end, $start];

        return view('ads_manager.cpp_timeline', [
            'start' => $start,
            'end'   => $end,
        ]);
    }

    /**
     * GET /ads_manager/cpp/timeline/data?start=...&end=...
     *
     * Returns the grid payload — rows by bucket, cols by date. Aggregates across
     * all pages para sa per-cell total (Adspent / Orders / CPP). Detail per page
     * is loaded separately via timelineDetail().
     */
    public function timelineData(Request $request)
    {
        $this->checkAccess();

        $start = $request->query('start') ?: Carbon::now('Asia/Manila')->subDays(6)->toDateString();
        $end   = $request->query('end')   ?: Carbon::now('Asia/Manila')->toDateString();
        if ($start > $end) [$start, $end] = [$end, $start];

        // ── 1) Existing saved snapshots (have adspent + orders + cpp) ──────
        $rows = DB::table('cpp_snapshots')
            ->whereBetween('snapshot_date', [$start, $end])
            ->selectRaw('
                snapshot_date,
                snapshot_bucket,
                COALESCE(SUM(amount_spent), 0)   AS total_spent,
                COALESCE(SUM(orders),       0)   AS total_orders,
                MAX(snapshot_at)                  AS latest_at
            ')
            ->groupBy('snapshot_date', 'snapshot_bucket')
            ->get();

        // ── 2) Cumulative orders from macro_output per date and bucket
        //      cutoff. Used to FILL IN cells na walang saved snapshot
        //      (orders-only — adspent/cpp stays blank for those).
        //      Bucket cutoff times (PH):
        //        10AM    → orders with TIMESTAMP < 10:00
        //        3PM     → orders with TIMESTAMP < 15:00
        //        7PM     → orders with TIMESTAMP < 19:00
        //        11:59PM → all orders for that date (no upper cap)
        //
        //      macro_output.TIMESTAMP is a STRING in 'H:i d-m-Y' format —
        //      need engine-specific parsing.
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            $tsDate = "DATE(STR_TO_DATE(`TIMESTAMP`, '%H:%i %d-%m-%Y'))";
            $tsMins = "(HOUR(STR_TO_DATE(`TIMESTAMP`, '%H:%i %d-%m-%Y')) * 60 + MINUTE(STR_TO_DATE(`TIMESTAMP`, '%H:%i %d-%m-%Y')))";

            $orderRows = DB::table('macro_output')
                ->selectRaw("
                    $tsDate AS ts_date,
                    SUM(CASE WHEN $tsMins < " . (10 * 60) . " THEN 1 ELSE 0 END) AS o_10am,
                    SUM(CASE WHEN $tsMins < " . (15 * 60) . " THEN 1 ELSE 0 END) AS o_3pm,
                    SUM(CASE WHEN $tsMins < " . (19 * 60) . " THEN 1 ELSE 0 END) AS o_7pm,
                    COUNT(*) AS o_eod
                ")
                ->whereRaw("$tsDate BETWEEN ? AND ?", [$start, $end])
                ->groupByRaw("$tsDate")
                ->get();
        } elseif ($driver === 'pgsql') {
            $tsExpr = "to_timestamp(\"TIMESTAMP\", 'HH24:MI DD-MM-YYYY')";
            $tsDate = "$tsExpr::date";
            $tsMins = "(EXTRACT(HOUR FROM $tsExpr) * 60 + EXTRACT(MINUTE FROM $tsExpr))";

            $orderRows = DB::table('macro_output')
                ->selectRaw("
                    $tsDate AS ts_date,
                    SUM(CASE WHEN $tsMins < " . (10 * 60) . " THEN 1 ELSE 0 END) AS o_10am,
                    SUM(CASE WHEN $tsMins < " . (15 * 60) . " THEN 1 ELSE 0 END) AS o_3pm,
                    SUM(CASE WHEN $tsMins < " . (19 * 60) . " THEN 1 ELSE 0 END) AS o_7pm,
                    COUNT(*) AS o_eod
                ")
                ->whereRaw("$tsDate BETWEEN ? AND ?", [$start, $end])
                ->groupByRaw("$tsDate")
                ->get();
        } else {
            $orderRows = collect();
        }

        // Map: ordersByDate[date][bucket] = cumulative count by bucket cutoff
        $ordersByDate = [];
        foreach ($orderRows as $r) {
            $d = (string) $r->ts_date;
            $ordersByDate[$d] = [
                '10AM'    => (int) $r->o_10am,
                '3PM'     => (int) $r->o_3pm,
                '7PM'     => (int) $r->o_7pm,
                '11:59PM' => (int) $r->o_eod,
            ];
        }

        // ── 3) Build date list (descending) + cells matrix ────────────────
        $dates = [];
        for ($d = Carbon::parse($end); $d->gte(Carbon::parse($start)); $d->subDay()) {
            $dates[] = $d->format('Y-m-d');
        }

        $buckets = ['10AM', '3PM', '7PM', '11:59PM'];
        $cells = [];
        foreach ($buckets as $b) $cells[$b] = [];

        // Pass 1 — populate from saved snapshots (preferred source).
        $snapshotSet = [];
        foreach ($rows as $r) {
            $b = (string) $r->snapshot_bucket;
            $d = (string) $r->snapshot_date;
            $snapshotSet["$d|$b"] = true;
            $spent  = (float) $r->total_spent;
            $orders = (int)   $r->total_orders;
            $cpp    = $orders > 0 ? round($spent / $orders, 2) : null;
            $cells[$b][$d] = [
                'spent'    => $spent,
                'orders'   => $orders,
                'cpp'      => $cpp,
                'saved_at' => $r->latest_at,
                'inferred' => false, // explicit save
            ];
        }

        // Pass 2 — fill in cells na walang snapshot pero may macro_output
        // orders. Adspent + CPP stays null (cannot reconstruct).
        foreach ($dates as $d) {
            foreach ($buckets as $b) {
                if (isset($snapshotSet["$d|$b"])) continue;
                $ord = $ordersByDate[$d][$b] ?? 0;
                if ($ord > 0) {
                    $cells[$b][$d] = [
                        'spent'    => null,
                        'orders'   => $ord,
                        'cpp'      => null,
                        'saved_at' => null,
                        'inferred' => true, // orders inferred from macro_output
                    ];
                }
            }
        }

        return response()->json([
            'start'   => $start,
            'end'     => $end,
            'dates'   => $dates,
            'buckets' => $buckets,
            'cells'   => $cells,
        ]);
    }

    /**
     * GET /ads_manager/cpp/timeline/detail?date=...&bucket=...
     *
     * Returns the per-page snapshot rows for a specific cell (date + bucket).
     * Powers the click-cell modal.
     */
    public function timelineDetail(Request $request)
    {
        $this->checkAccess();

        $date   = $request->query('date');
        $bucket = $request->query('bucket');

        if (!$date || !$bucket) {
            return response()->json(['ok' => false, 'message' => 'Missing date or bucket'], 422);
        }

        $rows = DB::table('cpp_snapshots')
            ->where('snapshot_date',   $date)
            ->where('snapshot_bucket', $bucket)
            ->orderBy('page_name')
            ->get([
                'page_name', 'item_names',
                'amount_spent', 'orders', 'proceed_orders',
                'cpp', 'cpi', 'cpm', 'tcpr_pct',
                'snapshot_at', 'saved_by_user_email',
            ]);

        // Totals
        $totSpent  = (float) $rows->sum('amount_spent');
        $totOrders = (int)   $rows->sum('orders');
        $totProc   = (int)   $rows->sum('proceed_orders');
        $totCpp    = $totOrders > 0 ? round($totSpent / $totOrders, 2) : null;

        return response()->json([
            'ok'      => true,
            'date'    => $date,
            'bucket'  => $bucket,
            'rows'    => $rows,
            'totals'  => [
                'amount_spent'   => $totSpent,
                'orders'         => $totOrders,
                'proceed_orders' => $totProc,
                'cpp'            => $totCpp,
            ],
            'saved_by' => $rows->pluck('saved_by_user_email')->filter()->unique()->values(),
            'saved_at' => optional($rows->first())->snapshot_at,
        ]);
    }
}
