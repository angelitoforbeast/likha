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

    // 1) Basahin lahat ng may status_logs, pero limited sa 60 days by submission_time
    DB::table('from_jnts')
        ->whereNotNull('status_logs')
        ->whereBetween('submission_time', [
            $windowStart->toDateTimeString(),
            $windowEnd->toDateTimeString(),
        ])
        ->select('id', 'status_logs') // logs lang kailangan
        ->orderBy('id')
        ->chunk(1000, function ($rows) use (&$batches, $date) {

            foreach ($rows as $row) {
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
                    if (!$batchAt) {
                        continue;
                    }
                    $entryDate = substr($batchAt, 0, 10);
                    if ($entryDate !== $date) {
                        continue;
                    }
                    $dayLogs[] = $entry;
                }

                // Walang log na tumama sa selected date → skip buong waybill
                if (empty($dayLogs)) {
                    continue;
                }

                // Helper para siguradong may entry sa $batches kapag may data sa batch na 'yan
                $ensureBatch = function ($batchAt) use (&$batches) {
                    if (!isset($batches[$batchAt])) {
                        $batches[$batchAt] = [
                            'batch_at'   => $batchAt,
                            'delivering' => 0,
                            'in_transit' => 0,
                            'delivered'  => 0, // filled later via signingtime ranges
                            'for_return' => 0,
                        ];
                    }
                };

                // --- Per-log counting for Delivering / In Transit / For Return (per batch_at)
                foreach ($dayLogs as $entry) {
                    $batchAt = $entry['batch_at'] ?? null;
                    if (!$batchAt) {
                        continue;
                    }

                    $ensureBatch($batchAt);

                    $to   = strtolower((string)($entry['to'] ?? ''));
                    $from = strtolower((string)($entry['from'] ?? ''));

                    // ✅ Delivering (new since last) – base sa "to"
                    if (str_contains($to, 'delivering')) {
                        $batches[$batchAt]['delivering']++;
                    }

                    // ✅ In Transit (new since last) – base sa "to"
                    if (str_contains($to, 'transit')) {
                        $batches[$batchAt]['in_transit']++;
                    }

                    // ✅ For Return – from may "deliver", to may "return" or "rts"
                    if (
                        (str_contains($to, 'return') || str_contains($to, 'rts')) &&
                        str_contains($from, 'deliver')
                    ) {
                        $batches[$batchAt]['for_return']++;
                    }
                }
                // NOTE: Wala pang Delivered logic dito — gagawin sa step 2 gamit signingtime
            }
        });

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
                $query    = DB::table('from_jnts')
                    ->whereNotNull('signingtime')
                    // limit pa rin sa 60 days window by submission_time
                    ->whereBetween('submission_time', [
                        $windowStart->toDateTimeString(),
                        $windowEnd->toDateTimeString(),
                    ])
                    ->where('signingtime', '>=', $rangeStart->toDateTimeString())
                    ->where('signingtime', '<=', $rangeEnd->toDateTimeString());
            } else {
                $rangeEnd = $batchTimes[$i];
                $query    = DB::table('from_jnts')
                    ->whereNotNull('signingtime')
                    ->whereBetween('submission_time', [
                        $windowStart->toDateTimeString(),
                        $windowEnd->toDateTimeString(),
                    ])
                    ->where('signingtime', '>=', $rangeStart->toDateTimeString())
                    ->where('signingtime', '<',  $rangeEnd->toDateTimeString());
            }

            // ✅ Delivered logic:
            // - status column must contain "delivered"
            // - signingtime nasa time range ng batch na 'to (same date)
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
        $totals['delivering'] += $batch['delivering'];
        $totals['in_transit'] += $batch['in_transit'];
        $totals['delivered']  += $batch['delivered'];
        $totals['for_return'] += $batch['for_return'];
    }

    return view('jnt_status_summary', [
        'date'    => $date,
        'batches' => $batches,
        'totals'  => $totals,
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

    public function index()
    {
        $data = FromJnt::paginate(10);
        return view('from_jnt_view', compact('data'));
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
