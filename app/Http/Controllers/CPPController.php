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

        // 'upload' = use latest ads_manager_reports upload time per (date, bucket)
        //            as the cutoff for inferred orders count.
        // 'clock'  = use strict bucket clock cutoffs (10:00 / 15:00 / 19:00).
        // 11:59PM bucket ALWAYS counts all orders (EOD) regardless of mode.
        $cutoffMode = $request->query('cutoff_mode') === 'clock' ? 'clock' : 'upload';

        // ── 1) Existing saved snapshots (have adspent + orders + cpp) ──────
        // Saved snapshots are immutable — their orders count is fixed at
        // click-time, independent of cutoff_mode (per Q4).
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

        // ── 2a) Pull EOD adspent totals per date from ads_manager_reports.
        //       Used to fill 11:59PM cells live (no snapshot needed) since
        //       end-of-day total = final adspent for that date. Strips ₱ /
        //       commas / whitespace before casting — same shape used elsewhere
        //       in this app for amount_spent_php (it's stored as text in some
        //       imports).
        $driver = DB::connection()->getDriverName(); // mysql | pgsql
        $castMoneyAds = $driver === 'pgsql'
            ? "COALESCE(NULLIF(REGEXP_REPLACE(COALESCE((amount_spent_php)::text, ''), '[^0-9\\.\\-]', '', 'g'), '')::numeric, 0)"
            : "CAST(REPLACE(REPLACE(REPLACE(COALESCE(amount_spent_php,''), '₱',''), ',', ''), ' ', '') AS DECIMAL(18,2))";

        $eodAdspentRows = DB::table('ads_manager_reports')
            ->whereBetween(DB::raw('DATE(day)'), [$start, $end])
            ->selectRaw("DATE(day) AS d, SUM($castMoneyAds) AS spent")
            ->groupByRaw('DATE(day)')
            ->get();
        // [date string => float spent]
        $eodAdspentByDate = [];
        foreach ($eodAdspentRows as $r) {
            $eodAdspentByDate[(string) $r->d] = (float) $r->spent;
        }

        // ── 2) Pull ads_manager_reports upload times within range. Used to
        //      compute per (date, bucket) upload-time cutoffs sa 'upload' mode.
        $uploadRows = DB::table('upload_logs')
            ->where('type', 'ads_manager_reports')
            ->whereBetween(DB::raw('DATE(created_at)'), [$start, $end])
            ->selectRaw("
                DATE(created_at) AS d,
                (HOUR(created_at) * 60 + MINUTE(created_at)) AS mins
            ")
            ->get();

        // uploadCutoffs[date][bucket] = latest minute-of-day of upload within
        // that bucket's window on that date.
        $uploadCutoffs = [];
        foreach ($uploadRows as $u) {
            $m = (int) $u->mins;
            // Use same bucketing as bucketFor() — anything outside 6AM-onwards
            // (i.e., 00:00–05:59) is skipped since no ads upload happens then.
            if      ($m >= 6 * 60    && $m < 12 * 60 + 30) $b = '10AM';
            elseif  ($m >= 12 * 60 + 30 && $m < 17 * 60 + 30) $b = '3PM';
            elseif  ($m >= 17 * 60 + 30 && $m < 24 * 60)      $b = '7PM';
            else continue;

            $d = (string) $u->d;
            if (!isset($uploadCutoffs[$d][$b]) || $uploadCutoffs[$d][$b] < $m) {
                $uploadCutoffs[$d][$b] = $m;
            }
        }

        // ── 3) Aggregated orders from macro_output per (date, minute_of_day).
        //      Lets us compute cumulative counts up to ANY cutoff per cell
        //      in PHP — no need for multiple per-cell SQL queries.
        // ($driver already set above for the adspent query.)

        if ($driver === 'mysql') {
            $tsDate = "DATE(STR_TO_DATE(`TIMESTAMP`, '%H:%i %d-%m-%Y'))";
            $tsMins = "(HOUR(STR_TO_DATE(`TIMESTAMP`, '%H:%i %d-%m-%Y')) * 60 + MINUTE(STR_TO_DATE(`TIMESTAMP`, '%H:%i %d-%m-%Y')))";

            $orderRows = DB::table('macro_output')
                ->selectRaw("$tsDate AS ts_date, $tsMins AS ts_min, COUNT(*) AS n")
                ->whereRaw("$tsDate BETWEEN ? AND ?", [$start, $end])
                ->groupByRaw("$tsDate, $tsMins")
                ->get();
        } elseif ($driver === 'pgsql') {
            $tsExpr = "to_timestamp(\"TIMESTAMP\", 'HH24:MI DD-MM-YYYY')";
            $tsDate = "$tsExpr::date";
            $tsMins = "(EXTRACT(HOUR FROM $tsExpr) * 60 + EXTRACT(MINUTE FROM $tsExpr))";

            $orderRows = DB::table('macro_output')
                ->selectRaw("$tsDate AS ts_date, $tsMins AS ts_min, COUNT(*) AS n")
                ->whereRaw("$tsDate BETWEEN ? AND ?", [$start, $end])
                ->groupByRaw("$tsDate, $tsMins")
                ->get();
        } else {
            $orderRows = collect();
        }

        // Index by date for fast lookup.
        $ordersByDate = []; // [date] = [ [ts_min, n], ... ]
        foreach ($orderRows as $r) {
            $ordersByDate[(string) $r->ts_date][] = [(int) $r->ts_min, (int) $r->n];
        }

        // ── 4) Per (date, bucket) cutoff resolution + cumulative count ────
        // Clock-time cutoffs (used when mode='clock', or as fallback when
        // mode='upload' but walang upload for that bucket on that date).
        $clockCutoffs = [
            '10AM'    => 10 * 60,
            '3PM'     => 15 * 60,
            '7PM'     => 19 * 60,
            '11:59PM' => 24 * 60, // all minutes = EOD
        ];

        // Compute cumulative orders + cutoff used per (date, bucket).
        // inferredByDate[date][bucket] = ['orders' => N, 'cutoff_min' => X, 'cutoff_source' => 'upload'|'clock']
        $inferredByDate = [];
        foreach ($ordersByDate as $d => $minRows) {
            $inferredByDate[$d] = [];
            foreach (array_keys($clockCutoffs) as $b) {
                // 11:59PM always uses EOD (no cap) regardless of mode.
                if ($b === '11:59PM') {
                    $cutoff = $clockCutoffs['11:59PM'];
                    $src    = 'clock';
                } elseif ($cutoffMode === 'upload') {
                    $cutoff = $uploadCutoffs[$d][$b] ?? null;
                    if ($cutoff === null) {
                        // Fallback to strict clock cutoff.
                        $cutoff = $clockCutoffs[$b];
                        $src    = 'clock_fallback';
                    } else {
                        $src    = 'upload';
                    }
                } else {
                    $cutoff = $clockCutoffs[$b];
                    $src    = 'clock';
                }

                $count = 0;
                foreach ($minRows as [$ts_min, $n]) {
                    if ($ts_min < $cutoff) $count += $n;
                }
                $inferredByDate[$d][$b] = [
                    'orders'        => $count,
                    'cutoff_min'    => $cutoff,
                    'cutoff_source' => $src,
                ];
            }
        }

        // ── 5) Build date list (descending) + cells matrix ────────────────
        $dates = [];
        for ($d = Carbon::parse($end); $d->gte(Carbon::parse($start)); $d->subDay()) {
            $dates[] = $d->format('Y-m-d');
        }

        $buckets = ['10AM', '3PM', '7PM', '11:59PM'];
        $cells = [];
        foreach ($buckets as $b) $cells[$b] = [];

        // ── 5b) HISTORICAL RATIOS — used for projecting TODAY's future buckets.
        // For each PAST date in range, compute ratios (bucket_B / bucket_A).
        // Average across all past dates gives a robust "what % growth from
        // bucket A → bucket B" pattern. Today's actual bucket A count is then
        // multiplied by ratio to estimate future bucket B.
        $today      = Carbon::now('Asia/Manila')->toDateString();
        $nowPhMin   = (int) Carbon::now('Asia/Manila')->format('H') * 60
                    + (int) Carbon::now('Asia/Manila')->format('i');
        $bucketMin  = [
            '10AM'    => 10 * 60,
            '3PM'     => 15 * 60,
            '7PM'     => 19 * 60,
            '11:59PM' => 24 * 60,
        ];
        $bucketIsFuture = [
            '10AM'    => $nowPhMin < $bucketMin['10AM'],
            '3PM'     => $nowPhMin < $bucketMin['3PM'],
            '7PM'     => $nowPhMin < $bucketMin['7PM'],
            '11:59PM' => $nowPhMin < $bucketMin['11:59PM'],
        ];

        // Clock-based cumulative counts per (date, bucket) — used as the basis
        // for ratio computation. Independent of cutoffMode (estimates always
        // use clock semantics for consistent historical comparison).
        $clockCum = [];
        foreach ($ordersByDate as $d => $minRows) {
            foreach ($clockCutoffs as $b => $cutoff) {
                $count = 0;
                foreach ($minRows as [$ts_min, $n]) {
                    if ($ts_min < $cutoff) $count += $n;
                }
                $clockCum[$d][$b] = $count;
            }
        }

        // Collect ratio samples from past dates only.
        $ratioSamples = [
            '3PM_from_10AM'    => [],
            '7PM_from_3PM'     => [],
            '7PM_from_10AM'    => [],
            '11:59PM_from_7PM' => [],
            '11:59PM_from_3PM' => [],
            '11:59PM_from_10AM'=> [],
        ];
        foreach ($clockCum as $d => $bc) {
            if ($d >= $today) continue;
            $c10 = $bc['10AM']    ?? 0;
            $c15 = $bc['3PM']     ?? 0;
            $c19 = $bc['7PM']     ?? 0;
            $cEOD = $bc['11:59PM']?? 0;
            if ($c10 > 0 && $c15 > 0)  $ratioSamples['3PM_from_10AM'][]    = $c15 / $c10;
            if ($c15 > 0 && $c19 > 0)  $ratioSamples['7PM_from_3PM'][]     = $c19 / $c15;
            if ($c10 > 0 && $c19 > 0)  $ratioSamples['7PM_from_10AM'][]    = $c19 / $c10;
            if ($c19 > 0 && $cEOD > 0) $ratioSamples['11:59PM_from_7PM'][] = $cEOD / $c19;
            if ($c15 > 0 && $cEOD > 0) $ratioSamples['11:59PM_from_3PM'][] = $cEOD / $c15;
            if ($c10 > 0 && $cEOD > 0) $ratioSamples['11:59PM_from_10AM'][]= $cEOD / $c10;
        }

        // Average ratios — need at least 3 samples to avoid noisy projections.
        $ratioAvg = [];
        foreach ($ratioSamples as $k => $vals) {
            $ratioAvg[$k] = count($vals) >= 3 ? array_sum($vals) / count($vals) : null;
        }

        // Estimate per future bucket — chain from latest available actual
        // bucket today. Returns [bucket] => ['orders' => N, 'source' => 'from 3PM × 1.45'].
        $todayCum = $clockCum[$today] ?? [];
        $todayEstimates = [];
        foreach (['3PM', '7PM', '11:59PM'] as $b) {
            if (!$bucketIsFuture[$b]) continue;
            // Prefer the LATEST past-and-has-data reference bucket.
            foreach (['7PM', '3PM', '10AM'] as $ref) {
                if ($bucketMin[$ref] >= $bucketMin[$b])         continue; // ref must be earlier
                if ($bucketMin[$ref] > $nowPhMin)               continue; // ref must be past now
                $refCount = $todayCum[$ref] ?? 0;
                if ($refCount <= 0)                              continue;
                $ratio = $ratioAvg["{$b}_from_{$ref}"] ?? null;
                if ($ratio === null)                             continue;
                $todayEstimates[$b] = [
                    'orders' => (int) round($refCount * $ratio),
                    'source' => sprintf('from %s × %.2f', $ref, $ratio),
                ];
                break;
            }
        }

        // Helper: pretty-format minutes-since-midnight to 'h:mm AM/PM' string.
        $fmtMin = function (int $mins): string {
            if ($mins >= 24 * 60) return 'EOD';
            $h = intdiv($mins, 60);
            $m = $mins % 60;
            $ampm = $h >= 12 ? 'PM' : 'AM';
            $h12  = $h % 12 ?: 12;
            return sprintf('%d:%02d %s', $h12, $m, $ampm);
        };

        // Pass 1 — populate from saved snapshots (preferred source). Orders
        // count is fixed at save-time; cutoff_mode doesn't affect saved cells.
        $snapshotSet = [];
        foreach ($rows as $r) {
            $b = (string) $r->snapshot_bucket;
            $d = (string) $r->snapshot_date;
            $snapshotSet["$d|$b"] = true;
            $spent  = (float) $r->total_spent;
            $orders = (int)   $r->total_orders;
            $cpp    = $orders > 0 ? round($spent / $orders, 2) : null;

            // For saved cells, also report the ads upload time within that
            // bucket — useful kung iba sa snapshot_at. Lets the modal show
            // "saved at X · ads data as of Y" if that's desired later.
            $adsAt = isset($uploadCutoffs[$d][$b]) ? $fmtMin($uploadCutoffs[$d][$b]) : null;

            $cells[$b][$d] = [
                'spent'        => $spent,
                'orders'       => $orders,
                'cpp'          => $cpp,
                'saved_at'     => $r->latest_at,
                'ads_at'       => $adsAt,
                'cutoff_at'    => null,  // n/a — orders is fixed at save
                'cutoff_src'   => null,
                'inferred'     => false,
            ];
        }

        // Pass 2 — fill in cells na walang snapshot.
        //
        // TODAY's FUTURE buckets get special handling:
        //   - If we can compute an estimate from earlier-today's actuals × historical ratio
        //     → emit estimate cell (is_estimate=true)
        //   - Otherwise → leave empty (don't show misleading partial inferred)
        //
        // Past dates + non-future today buckets → existing inferred behavior.
        foreach ($dates as $d) {
            foreach ($buckets as $b) {
                if (isset($snapshotSet["$d|$b"])) continue;

                $isTodayFutureBucket = ($d === $today) && $bucketIsFuture[$b];

                if ($isTodayFutureBucket) {
                    $est = $todayEstimates[$b] ?? null;
                    if ($est !== null && $est['orders'] > 0) {
                        $cells[$b][$d] = [
                            'spent'           => null,
                            'orders'          => $est['orders'],
                            'cpp'             => null,
                            'saved_at'        => null,
                            'ads_at'          => null,
                            'cutoff_at'       => null,
                            'cutoff_src'      => 'estimate',
                            'inferred'        => false,
                            'is_estimate'     => true,
                            'estimate_source' => $est['source'],
                        ];
                    }
                    // else: leave empty — no estimate basis, future bucket hidden
                    continue;
                }

                // Past date OR today's already-past bucket → normal inferred fill.
                $info = $inferredByDate[$d][$b] ?? null;
                if (!$info || $info['orders'] === 0) continue;

                // 11:59PM bucket = end-of-day → adspent is FINAL for the day.
                // No snapshot needed: compute live from ads_manager_reports.
                // Only reaches here for past-day cells (today's 11:59PM goes
                // into the $isTodayFutureBucket branch above and is skipped),
                // so "wait until the bucket time has passed" semantic matches
                // the other buckets automatically.
                $liveSpent = null;
                $liveCpp   = null;
                if ($b === '11:59PM') {
                    $spentForDate = $eodAdspentByDate[$d] ?? null;
                    if ($spentForDate !== null) {
                        $liveSpent = $spentForDate;
                        if ($info['orders'] > 0) {
                            $liveCpp = round($spentForDate / $info['orders'], 2);
                        }
                    }
                }

                $cells[$b][$d] = [
                    'spent'       => $liveSpent,
                    'orders'      => $info['orders'],
                    'cpp'         => $liveCpp,
                    'saved_at'    => null,
                    'ads_at'      => null,
                    'cutoff_at'   => $fmtMin($info['cutoff_min']),
                    'cutoff_src'  => $info['cutoff_source'], // 'upload' | 'clock' | 'clock_fallback'
                    'inferred'    => true,
                    'is_estimate' => false,
                ];
            }
        }

        return response()->json([
            'start'       => $start,
            'end'         => $end,
            'dates'       => $dates,
            'buckets'     => $buckets,
            'cutoff_mode' => $cutoffMode,
            'cells'       => $cells,
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
