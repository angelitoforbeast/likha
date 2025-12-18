<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\FromJnt;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;


class FromJntController extends Controller
{
   
    public function statusSummary(Request $request)
{
    // Selected date, default = today (Asia/Manila)
    $date = $request->input('date');
    if (empty($date)) {
        $date = Carbon::now('Asia/Manila')->toDateString();
    }

    $day       = Carbon::parse($date, 'Asia/Manila');
    $dayStart  = $day->copy()->startOfDay();
    $dayEnd    = $day->copy()->endOfDay();

    // 60-day window based on submission_time (including selected date)
    $windowStart = $day->copy()->subDays(60)->startOfDay();
    $windowEnd   = $dayEnd;

    $batches = [];

    // --- Helpers ---
    $isReturnStatus = function (string $s): bool {
        $s = strtolower(trim($s));
        return str_contains($s, 'return') || str_contains($s, 'rts');
    };

    $isDeliveringStatus = function (string $s): bool {
        $s = strtolower(trim($s));
        // delivering only, avoid "delivered"
        return str_contains($s, 'delivering') || (str_contains($s, 'deliver') && !str_contains($s, 'delivered'));
    };

    // Helper para siguradong may entry sa $batches kapag may data sa batch na 'yan
    $ensureBatch = function ($batchAt) use (&$batches) {
        if (!isset($batches[$batchAt])) {
            $batches[$batchAt] = [
                'batch_at'        => $batchAt,

                // sets (unique per waybill)
                'delivering_set'  => [],
                'in_transit_set'  => [],
                'for_return_set'  => [],

                // final counts (filled after sets)
                'delivering'      => 0,
                'in_transit'      => 0,
                'delivered'       => 0, // filled later via signingtime ranges
                'for_return'      => 0,
            ];
        }
    };

    // 1) Basahin lahat ng may status_logs, pero limited sa 60 days by submission_time
    DB::table('from_jnts')
        ->whereNotNull('status_logs')
        ->whereBetween('submission_time', [
            $windowStart->toDateTimeString(),
            $windowEnd->toDateTimeString(),
        ])
        // ✅ include waybill_number + rts_reason
        ->select('id', 'waybill_number', 'status_logs', 'rts_reason')
        ->orderBy('id')
        ->chunk(1000, function ($rows) use (&$batches, $date, $ensureBatch, $isReturnStatus, $isDeliveringStatus) {

            foreach ($rows as $row) {
                $waybill = trim((string)($row->waybill_number ?? ''));
                if ($waybill === '') {
                    continue;
                }

                // --- Decode logs ---
                $logsRaw = $row->status_logs;
                if ($logsRaw === null || $logsRaw === '') {
                    continue;
                }

                $logs = json_decode($logsRaw, true);
                if (!is_array($logs) || empty($logs)) {
                    continue;
                }

                // --- Filter logs for the selected date only ---
                $dayLogs = [];
                foreach ($logs as $entry) {
                    $batchAt = $entry['batch_at'] ?? null;
                    if (!$batchAt) continue;

                    $entryDate = substr((string)$batchAt, 0, 10);
                    if ($entryDate !== $date) continue;

                    $dayLogs[] = $entry;
                }

                // Walang log na tumama sa selected date → skip buong waybill
                if (empty($dayLogs)) {
                    continue;
                }

                // Sort dayLogs by batch_at para ma-detect "deliver earlier today"
                usort($dayLogs, function ($a, $b) {
                    return strcmp((string)($a['batch_at'] ?? ''), (string)($b['batch_at'] ?? ''));
                });

                // Check kung may RTS reason na itong waybill (exclude delivering kapag may rts_reason)
                $rtsRaw  = $row->rts_reason ?? null;
                $hasRts  = !is_null($rtsRaw) && trim((string) $rtsRaw) !== '';

                $wasDeliveringToday = false;
                $forReturnCounted   = false;

                foreach ($dayLogs as $entry) {
                    $batchAt = $entry['batch_at'] ?? null;
                    if (!$batchAt) continue;

                    $ensureBatch($batchAt);

                    $to   = strtolower(trim((string)($entry['to'] ?? '')));
                    $from = strtolower(trim((string)($entry['from'] ?? '')));

                    // ✅ Delivering (new since last) – base sa "to"
                    //    ➕ dagdag condition: WALANG laman ang rts_reason
                    if (str_contains($to, 'delivering') && !$hasRts) {
                        $batches[$batchAt]['delivering_set'][$waybill] = true;
                        $wasDeliveringToday = true; // mark na nag-delivering na siya earlier today
                    }

                    // ✅ In Transit (new since last) – base sa "to"
                    if (str_contains($to, 'transit')) {
                        $batches[$batchAt]['in_transit_set'][$waybill] = true;
                    }

                    // ✅ For Return (new) – credited sa batch kung kailan siya unang nag Return/RTS TODAY
                    // Must have been Delivering today (earlier), OR transition itself came from delivering
                    if (!$forReturnCounted && ($isReturnStatus($to))) {
                        if ($wasDeliveringToday || $isDeliveringStatus($from)) {
                            $batches[$batchAt]['for_return_set'][$waybill] = true;
                            $forReturnCounted = true; // once per waybill
                        }
                    }
                }

                // NOTE: Wala pang Delivered logic dito — gagawin sa step 2 gamit signingtime
            }
        });

    // Convert sets to counts + cleanup sets
    foreach ($batches as $k => $b) {
        $batches[$k]['delivering'] = count($b['delivering_set'] ?? []);
        $batches[$k]['in_transit'] = count($b['in_transit_set'] ?? []);
        $batches[$k]['for_return'] = count($b['for_return_set'] ?? []);

        unset(
            $batches[$k]['delivering_set'],
            $batches[$k]['in_transit_set'],
            $batches[$k]['for_return_set']
        );
    }

    // 2) Kung may batches, ayusin order then fill up Delivered gamit signingtime ranges
    ksort($batches); // sort by upload datetime (key)

    if (!empty($batches)) {
        $batchKeys  = array_keys($batches);
        $batchCount = count($batchKeys);

        // Carbon versions ng batch times
        $batchTimes = [];
        foreach ($batchKeys as $k) {
            $batchTimes[] = Carbon::parse($k, 'Asia/Manila');
        }

        for ($i = 0; $i < $batchCount; $i++) {
            $batchKey = $batchKeys[$i];

            // Range start = 00:00 for first batch, else previous batch time
            $rangeStart = ($i === 0) ? $dayStart : $batchTimes[$i - 1];

            // Range end = current batch time (exclusive) for middle batches,
            // sa last batch hanggang end-of-day (inclusive).
            if ($i === $batchCount - 1) {
                $rangeEnd = $dayEnd;
                $query = DB::table('from_jnts')
                    ->whereNotNull('signingtime')
                    ->whereBetween('submission_time', [
                        $windowStart->toDateTimeString(),
                        $windowEnd->toDateTimeString(),
                    ])
                    ->where('signingtime', '>=', $rangeStart->toDateTimeString())
                    ->where('signingtime', '<=', $rangeEnd->toDateTimeString());
            } else {
                $rangeEnd = $batchTimes[$i];
                $query = DB::table('from_jnts')
                    ->whereNotNull('signingtime')
                    ->whereBetween('submission_time', [
                        $windowStart->toDateTimeString(),
                        $windowEnd->toDateTimeString(),
                    ])
                    ->where('signingtime', '>=', $rangeStart->toDateTimeString())
                    ->where('signingtime', '<',  $rangeEnd->toDateTimeString());
            }

            $deliveredCount = $query
                ->whereRaw("LOWER(status) LIKE '%delivered%'")
                ->count();

            $batches[$batchKey]['delivered'] = $deliveredCount;
        }
    }

    // 3) TOTAL row
    $totals = [
        'delivering' => 0,
        'in_transit' => 0,
        'delivered'  => 0,
        'for_return' => 0,
    ];

    foreach ($batches as $batch) {
        $totals['delivering'] += (int)$batch['delivering'];
        $totals['in_transit'] += (int)$batch['in_transit'];
        $totals['delivered']  += (int)$batch['delivered'];
        $totals['for_return'] += (int)$batch['for_return'];
    }

    return view('jnt_status_summary', [
        'date'    => $date,
        'batches' => $batches,
        'totals'  => $totals,
    ]);
}
public function statusSummaryDetails(Request $request)
{
    $date    = $request->input('date');
    $batchAt = $request->input('batch_at');
    $metric  = $request->input('metric'); // delivering | in_transit | for_return | delivered

    if (!$date || !$batchAt || !$metric) {
        return response("Missing params.", 422);
    }

    $allowed = ['delivering', 'in_transit', 'for_return', 'delivered'];
    if (!in_array($metric, $allowed, true)) {
        return response("Invalid metric.", 422);
    }

    $day       = Carbon::parse($date, 'Asia/Manila');
    $dayStart  = $day->copy()->startOfDay();
    $dayEnd    = $day->copy()->endOfDay();

    // 60-day window based on submission_time
    $windowStart = $day->copy()->subDays(60)->startOfDay();
    $windowEnd   = $dayEnd;

    $isReturnStatus = function (string $s): bool {
        $s = strtolower(trim($s));
        return str_contains($s, 'return') || str_contains($s, 'rts');
    };

    $isDeliveringStatus = function (string $s): bool {
        $s = strtolower(trim($s));
        return str_contains($s, 'delivering') || (str_contains($s, 'deliver') && !str_contains($s, 'delivered'));
    };

    // ✅ DELIVERED: list via signingtime range (range passed from UI)
    if ($metric === 'delivered') {
        $rangeStart = $request->input('range_start');
        $rangeEnd   = $request->input('range_end');

        if (!$rangeStart || !$rangeEnd) {
            return response("Missing range_start/range_end for delivered.", 422);
        }

        $rows = DB::table('from_jnts')
            ->whereNotNull('signingtime')
            ->whereBetween('submission_time', [
                $windowStart->toDateTimeString(),
                $windowEnd->toDateTimeString(),
            ])
            ->whereRaw("LOWER(status) LIKE '%delivered%'")
            ->where('signingtime', '>=', $rangeStart)
            ->where('signingtime', '<=', $rangeEnd)
            ->select('waybill_number', 'status', 'signingtime', 'submission_time', 'status_logs')
            ->orderBy('signingtime')
            ->get();

        $items = [];
        foreach ($rows as $r) {
            $logs = [];
            if (!empty($r->status_logs)) {
                $decoded = json_decode($r->status_logs, true);
                if (is_array($decoded)) {
                    $logs = $decoded; // ✅ FULL logs
                }
            }

            $items[] = [
                'waybill'          => (string)$r->waybill_number,
                'status'           => (string)$r->status,
                'submission_time'  => (string)$r->submission_time,
                'signingtime'      => (string)$r->signingtime,
                'logs'             => $logs,
            ];
        }

        return view('jnt_status_summary_details', [
            'title'   => "DELIVERED list for {$batchAt}",
            'date'    => $date,
            'batchAt' => $batchAt,
            'metric'  => $metric,
            'items'   => $items,
        ]);
    }

    // ✅ For delivering / in_transit / for_return: parse status_logs and match only those that belong to this batchAt (same date)
    $candidates = DB::table('from_jnts')
        ->whereNotNull('status_logs')
        ->whereBetween('submission_time', [
            $windowStart->toDateTimeString(),
            $windowEnd->toDateTimeString(),
        ])
        // quick narrowing: only rows whose JSON contains this batchAt string
        ->where('status_logs', 'like', '%' . $batchAt . '%')
        ->select('waybill_number', 'status_logs', 'rts_reason', 'status', 'signingtime', 'submission_time')
        ->get();

    $items = [];
    foreach ($candidates as $row) {
        $waybill = trim((string)($row->waybill_number ?? ''));
        if ($waybill === '') continue;

        $logsRaw = $row->status_logs ?? '';
        $decoded = json_decode($logsRaw, true);
        if (!is_array($decoded) || empty($decoded)) continue;

        // ✅ dayLogs = logs on selected date (used for matching logic)
        $dayLogs = [];
        foreach ($decoded as $e) {
            $ba = $e['batch_at'] ?? null;
            if (!$ba) continue;
            if (substr((string)$ba, 0, 10) !== $date) continue;
            $dayLogs[] = $e;
        }
        if (empty($dayLogs)) continue;

        usort($dayLogs, fn($a,$b) => strcmp((string)($a['batch_at'] ?? ''), (string)($b['batch_at'] ?? '')));

        $hasRts = !is_null($row->rts_reason) && trim((string)$row->rts_reason) !== '';

        $matched = false;

        $wasDeliveringToday = false;
        foreach ($dayLogs as $e) {
            $ba   = (string)($e['batch_at'] ?? '');
            $to   = strtolower(trim((string)($e['to'] ?? '')));
            $from = strtolower(trim((string)($e['from'] ?? '')));

            // track delivering earlier today
            if (str_contains($to, 'delivering') && !$hasRts) {
                $wasDeliveringToday = true;
            }

            // only evaluate the clicked batchAt
            if ($ba !== $batchAt) {
                continue;
            }

            if ($metric === 'delivering') {
                if (str_contains($to, 'delivering') && !$hasRts) {
                    $matched = true;
                    break;
                }
            } elseif ($metric === 'in_transit') {
                if (str_contains($to, 'transit')) {
                    $matched = true;
                    break;
                }
            } elseif ($metric === 'for_return') {
                if ($isReturnStatus($to) && ($wasDeliveringToday || $isDeliveringStatus($from))) {
                    $matched = true;
                    break;
                }
            }
        }

        if (!$matched) continue;

        // ✅ show FULL logs (all dates) as requested
        $fullLogs = $decoded;

        $items[] = [
            'waybill'          => $waybill,
            'status'           => (string)($row->status ?? ''),
            'submission_time'  => (string)($row->submission_time ?? ''),
            'signingtime'      => (string)($row->signingtime ?? ''),
            'logs'             => $fullLogs,
        ];
    }

    return view('jnt_status_summary_details', [
        'title'   => strtoupper($metric) . " list for {$batchAt}",
        'date'    => $date,
        'batchAt' => $batchAt,
        'metric'  => $metric,
        'items'   => $items,
    ]);
}



    // FROM_JNT: always insert
    public function store(Request $request)
    {
        $data = json_decode($request->jsonData, true);

        foreach ($data as $row) {
            FromJnt::create([
                'sender'             => $row['Sender'] ?? '',
                'cod'                => $row['COD'] ?? '',
                'status'             => $row['Status'] ?? '',
                'item_name'          => $row['Item Name'] ?? '',
                'submission_time'    => $row['Submission Time'] ?? '',
                'receiver'           => $row['Receiver'] ?? '',
                'receiver_cellphone' => $row['Receiver Cellphone'] ?? '',
                'waybill_number'     => $row['Waybill Number'] ?? '',
                'signingtime'        => $row['signingtime'] ?? '',
                'remarks'            => $row['Remarks'] ?? '',
            ]);
        }

        return redirect()->back()->with('success', 'Data saved to FROM_JNT.');
    }

    public function index(Request $request)
    {
        $dateFrom = $request->input('date_from');
        $dateTo   = $request->input('date_to');

        // --- BASE QUERY: submission_time lang ang gamit dito ---
        $baseDateQuery = FromJnt::query();

        if ($dateFrom) {
            $baseDateQuery->whereDate('submission_time', '>=', $dateFrom);
        }

        if ($dateTo) {
            $baseDateQuery->whereDate('submission_time', '<=', $dateTo);
        }

        // --- COLUMN FILTERS (galing sa popup) ---
        $currentFilters = $request->input('filters', []);
        $filterableCols = [
            'submission_time',
            'waybill_number',
            'receiver',
            'receiver_cellphone',
            'sender',
            'item_name',
            'cod',
            'remarks',
            'province',
            'city',
            'barangay',
            'total_shipping_cost',
            'rts_reason',
            'status',
            'signingtime',
            'created_at',
            'updated_at',
        ];

        $dataQuery = clone $baseDateQuery;

        foreach ($currentFilters as $col => $values) {
            if (!in_array($col, $filterableCols, true)) {
                continue;
            }

            if (!is_array($values)) {
                $values = [$values];
            }

            $values = array_values(array_filter($values, fn ($v) => $v !== '' && $v !== null));

            if (empty($values)) {
                continue;
            }

            // date/time columns
            if (in_array($col, ['submission_time', 'signingtime', 'created_at', 'updated_at'], true)) {
                $dataQuery->whereIn(\DB::raw("DATE($col)"), $values);
            } else {
                $dataQuery->whereIn($col, $values);
            }
        }

        // --- SORTING (server-side) ---
        $allowedSortCols = [
            'submission_time',
            'waybill_number',
            'receiver',
            'receiver_cellphone',
            'sender',
            'item_name',
            'cod',
            'status',
            'signingtime',
            'created_at',
            'updated_at',
        ];

        $sortCol = $request->input('sort_col');
        $sortDir = $request->input('sort_dir', 'desc');

        if (!in_array($sortCol, $allowedSortCols, true)) {
            $sortCol = 'submission_time';
            $sortDir = 'desc';
        }

        $data = (clone $dataQuery)
            ->orderBy($sortCol, $sortDir)
            ->paginate(100)
            ->withQueryString();

        // --- FILTER OPTIONS (pang-popup) ---
        // IMPORTANT: base lang siya sa date range, HINDI sa column filters
        $filterBase = clone $baseDateQuery;
        $filterOptions = [];

        // submission_time (date lang)
        $filterOptions['submission_time'] = (clone $filterBase)
            ->selectRaw('DATE(submission_time) as value')
            ->distinct()
            ->orderBy('value')
            ->pluck('value')
            ->map(fn ($v) => $v ? Carbon::parse($v)->format('Y-m-d') : '')
            ->values()
            ->toArray();

        // text / numeric columns
        $distinctCols = [
            'waybill_number',
            'receiver',
            'receiver_cellphone',
            'sender',
            'item_name',
            'cod',
            'remarks',
            'province',
            'city',
            'barangay',
            'total_shipping_cost',
            'rts_reason',
            'status',
        ];

        foreach ($distinctCols as $col) {
            $filterOptions[$col] = (clone $filterBase)
                ->select($col . ' as value')
                ->distinct()
                ->orderBy('value')
                ->pluck('value')
                ->map(function ($v) {
                    if (is_array($v) || is_object($v)) {
                        return json_encode($v, JSON_UNESCAPED_UNICODE);
                    }
                    return (string)($v ?? '');
                })
                ->values()
                ->toArray();
        }

        // time columns
        foreach (['signingtime', 'created_at', 'updated_at'] as $timeCol) {
            $filterOptions[$timeCol] = (clone $filterBase)
                ->select($timeCol . ' as value')
                ->distinct()
                ->orderBy('value')
                ->pluck('value')
                ->map(function ($v) {
                    return $v ? Carbon::parse($v)->format('Y-m-d\TH:i:s') : '';
                })
                ->values()
                ->toArray();
        }

        return view('jnt.dashboard', [
            'data'          => $data,
            'dateFrom'      => $dateFrom,
            'dateTo'        => $dateTo,
            'filterOptions' => $filterOptions,
            'currentFilters'=> $currentFilters,
            'sortCol'       => $sortCol,
            'sortDir'       => $sortDir,
        ]);
}

    // JNT_UPDATE: update if exists, else insert
    // ✅ may optional batch_at + status_logs logic
    public function updateOrInsert(Request $request)
    {
        $data = json_decode($request->jsonData, true);

        // ✅ Optional batch_at galing UI / caller
        $batchAtInput = $request->input('batch_at');
        try {
            $batchAt = $batchAtInput
                ? Carbon::parse($batchAtInput, 'Asia/Manila')
                : Carbon::now('Asia/Manila');
        } catch (\Throwable $e) {
            $batchAt = Carbon::now('Asia/Manila');
        }

        $batches = array_chunk($data, 1000);

        foreach ($batches as $batch) {
            $waybills = array_column($batch, 'Waybill Number');

            $existingRecords = FromJnt::whereIn('waybill_number', $waybills)
                ->get()
                ->keyBy('waybill_number');

            $insertRows = [];

            foreach ($batch as $row) {
                $waybill        = $row['Waybill Number'] ?? '';
                $newStatus      = $row['Status'] ?? '';
                $newsigningtime = $row['signingtime'] ?? '';

                if (!$waybill) {
                    continue;
                }

                if (isset($existingRecords[$waybill])) {
                    $existing   = $existingRecords[$waybill];
                    $oldStatus  = $existing->status;

                    // wag nang galawin pag Delivered/Returned na
                    if (!in_array(strtolower((string)$oldStatus), ['delivered', 'returned'])) {
                        $logsArray = $this->appendStatusLog(
                            $existing->status_logs,
                            $oldStatus,
                            $newStatus,
                            $batchAt
                        );

                        $existing->status      = $newStatus;
                        $existing->signingtime = $newsigningtime;
                        $existing->status_logs = json_encode($logsArray, JSON_UNESCAPED_UNICODE);
                        $existing->updated_at  = now();
                        $existing->save();
                    }
                } else {
                    // 🔰 Bagong waybill: treat as from = null → to = $newStatus
                    $logsArray = $this->appendStatusLog(
                        null,
                        null,
                        $newStatus,
                        $batchAt
                    );

                    $insertRows[] = [
                        'waybill_number'     => $waybill,
                        'sender'             => $row['Sender'] ?? '',
                        'cod'                => $row['COD'] ?? '',
                        'status'             => $newStatus,
                        'item_name'          => $row['Item Name'] ?? '',
                        'submission_time'    => $row['Submission Time'] ?? '',
                        'receiver'           => $row['Receiver'] ?? '',
                        'receiver_cellphone' => $row['Receiver Cellphone'] ?? '',
                        'signingtime'        => $newsigningtime,
                        'remarks'            => $row['Remarks'] ?? '',
                        'status_logs'        => json_encode($logsArray, JSON_UNESCAPED_UNICODE),
                        'created_at'         => now(),
                        'updated_at'         => now(),
                    ];
                }
            }

            if (!empty($insertRows)) {
                FromJnt::insert($insertRows);
            }
        }

        return redirect()->back()->with('success', 'Database updated via JNT_UPDATE.');
    }

    // --- RTS views ---

    public function rtsView()
    {
        return view('jnt_rts', [
            'results' => [],
            'from'    => null,
            'to'      => null,
        ]);
    }

    public function rtsFiltered(Request $request)
    {
        $from = $request->input('from');
        $to   = $request->input('to');

        if (!$from || !$to) {
            return view('jnt_rts', [
                'results' => [],
                'from'    => $from,
                'to'      => $to,
            ]);
        }

        // Include whole days
        $fromDt = Carbon::parse($from, 'Asia/Manila')->startOfDay();
        $toDt   = Carbon::parse($to, 'Asia/Manila')->endOfDay();

        $rawData = FromJnt::whereBetween('submission_time', [
            $fromDt->toDateTimeString(),
            $toDt->toDateTimeString()
        ])->get();

        // Group by Sender | Item | COD
        $grouped = $rawData->groupBy(function ($row) {
            $sender = trim((string)($row->sender ?? ''));
            $item   = trim((string)($row->item_name ?? ''));
            $cod    = trim((string)($row->cod ?? ''));
            return "{$sender}|{$item}|{$cod}";
        });

        $fmtDate = function ($v) {
            try { return Carbon::parse($v)->format('Y-m-d'); }
            catch (\Throwable $e) { return (string)$v; }
        };

        // Normalizer (lowercase + collapse spaces)
        $norm = function ($s) {
            $s = mb_strtolower((string)$s);
            return preg_replace('/\s+/u', ' ', trim($s));
        };

        $results = collect();

        foreach ($grouped as $key => $rows) {
            // counts per normalized status
            $statusCounts = $rows->groupBy(fn($r) => $norm($r->status ?? ''))
                                 ->map->count()
                                 ->toArray();

            $total = max(1, $rows->count());

            // Case-insensitive + partial matching
            $rts = $delivered = $problematic = $detained = 0;

            foreach ($statusCounts as $status => $count) {
                if (str_contains($status, 'return') || $status === 'rts' || str_contains($status, 'rts')) {
                    $rts += $count;
                }
                if (str_contains($status, 'deliver')) {
                    $delivered += $count;
                }
                if (str_contains($status, 'problem')) {
                    $problematic += $count;
                }
                if (str_contains($status, 'detain')) {
                    $detained += $count;
                }
            }

            // Percentages (match your sample formulas)
            $rts_percent       = round(($rts / $total) * 100, 2);
            $delivered_percent = round(($delivered / $total) * 100, 2);
            $transit_percent   = round(max(0, 100 - $rts_percent - $delivered_percent), 2);

            // Current RTS% among completed
            $current_base = $rts + $delivered;
            $current_rts  = $current_base > 0 ? round(($rts / $current_base) * 100, 2) : 'N/A';

            // Max RTS%
            $max_base = $rts + $problematic + $detained + $delivered;
            $max_rts  = $max_base > 0 ? round((($rts + $problematic + $detained) / $max_base) * 100, 2) : 'N/A';

            $minSub = $rows->min('submission_time');
            $maxSub = $rows->max('submission_time');
            $dateRange = $fmtDate($minSub) . ' to ' . $fmtDate($maxSub);

            [$sender, $item, $cod] = array_pad(explode('|', $key), 3, '');

            $results->push([
                'date_range'        => $dateRange,
                'sender'            => $sender,
                'item'              => $item,
                'cod'               => $cod,
                'quantity'          => $rows->count(),
                'rts_percent'       => $rts_percent,
                'delivered_percent' => $delivered_percent,
                'transit_percent'   => $transit_percent,
                'current_rts'       => $current_rts,
                'max_rts'           => $max_rts,
            ]);
        }

        return view('jnt_rts', [
            'results' => $results,
            'from'    => $from,
            'to'      => $to,
        ]);
    }

    /**
     * Helper para sa status_logs (controller route / JNT_UPDATE):
     *
     * - Mag-aappend ng log if:
     *   1) oldStatus = null at may newStatus
     *   2) oldStatus != newStatus
     *   3) pareho silang "In Transit" pero ibang araw na si batchAt
     */
    protected function appendStatusLog(
        $currentLogs,
        ?string $oldStatusRaw,
        ?string $newStatusRaw,
        Carbon $batchAt
    ): array {
        // i-normalize logs → array
        if (is_array($currentLogs)) {
            $logs = $currentLogs;
        } elseif (is_string($currentLogs) && $currentLogs !== '') {
            $decoded = json_decode($currentLogs, true);
            $logs = is_array($decoded) ? $decoded : [];
        } else {
            $logs = [];
        }

        $oldStatus = $oldStatusRaw !== null && trim($oldStatusRaw) !== '' ? trim($oldStatusRaw) : null;
        $newStatus = $newStatusRaw !== null && trim($newStatusRaw) !== '' ? trim($newStatusRaw) : null;

        $shouldAdd = false;

        // 1) First time ever (from null → something)
        if ($oldStatus === null && $newStatus !== null) {
            $shouldAdd = true;

        // 2) Normal transition (nagbago status)
        } elseif ($oldStatus !== null && $newStatus !== null && $oldStatus !== $newStatus) {
            $shouldAdd = true;

        // 3) Same status, special rule for "In Transit"
        } elseif ($newStatus !== null && strcasecmp($newStatus, 'In Transit') === 0) {
            $lastInTransitLog = null;

            for ($i = count($logs) - 1; $i >= 0; $i--) {
                $log = $logs[$i] ?? null;
                if (!is_array($log)) {
                    continue;
                }

                if (isset($log['to']) && strcasecmp((string)$log['to'], 'In Transit') === 0) {
                    $lastInTransitLog = $log;
                    break;
                }
            }

            if ($lastInTransitLog) {
                try {
                    $lastDate    = Carbon::parse($lastInTransitLog['batch_at'])->toDateString();
                    $currentDate = $batchAt->toDateString();

                    // ✅ ibang araw na pero In Transit pa rin → log ulit
                    if ($lastDate !== $currentDate) {
                        $shouldAdd = true;
                    }
                } catch (\Throwable $e) {
                    // pag di ma-parse, safe side: log
                    $shouldAdd = true;
                }
            } else {
                // wala pang In Transit log dati → log
                $shouldAdd = true;
            }
        }

        if ($shouldAdd && $newStatus !== null) {
            $logs[] = [
                'batch_at'      => $batchAt->format('Y-m-d H:i:s'),
                'upload_log_id' => null,  // dito wala tayong upload_log_id (controller path)
                'from'          => $oldStatus,
                'to'            => $newStatus,
            ];
        }

        return $logs;
    }
}
