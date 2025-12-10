<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\FromJnt;
use Carbon\Carbon;

class FromJntController extends Controller
{
    public function statusSummary(Request $request)
{
    // Piliin date, default = today (Asia/Manila)
    $date = $request->input('date');
    if (empty($date)) {
        $date = Carbon::now('Asia/Manila')->toDateString();
    }

    // ✅ Kukunin lahat ng may status_logs
    //    kasama na ang current status para sa Delivered logic
    $rows = FromJnt::whereNotNull('status_logs')
        ->get(['id', 'status_logs', 'signingtime', 'status']);

    $batches = [];

    foreach ($rows as $row) {
        // normalize logs → array
        $logs = $row->status_logs;
        if (!is_array($logs)) {
            $decoded = json_decode($logs, true);
            $logs = is_array($decoded) ? $decoded : [];
        }

        // lowercase ng current status (for Delivered logic)
        $currentStatus = strtolower((string)($row->status ?? ''));

        foreach ($logs as $entry) {
            $batchAt = $entry['batch_at'] ?? null;
            if (!$batchAt) {
                continue;
            }

            // ✅ filter lang logs para sa piniling date (gamit batch_at)
            $entryDate = substr($batchAt, 0, 10);
            if ($entryDate !== $date) {
                continue;
            }

            // 👉 Ibig sabihin: may “paki” yung waybill na ‘to sa date na pinili,
            // kaya kasama siya sa computation for that batch.
            $key = $batchAt; // per upload / batch (exact datetime)

            if (!isset($batches[$key])) {
                $batches[$key] = [
                    'batch_at'   => $batchAt,
                    'delivering' => 0,
                    'in_transit' => 0,
                    'delivered'  => 0,
                    'for_return' => 0,
                ];
            }

            $to   = strtolower((string)($entry['to'] ?? ''));
            $from = strtolower((string)($entry['from'] ?? ''));

            // 1) Delivering (new since last) – gamit status_logs "to"
            if (str_contains($to, 'delivering')) {
                $batches[$key]['delivering']++;
            }

            // 2) In Transit (new since last) – base sa status (to)
            if (str_contains($to, 'transit')) {
                $batches[$key]['in_transit']++;
            }

            // 3) Delivered – ✅ base lang sa current status + signingtime date
            //    (pero counted lang kung may log sa date na 'to, dahil nandito tayo sa loop na yun)
            if (!empty($row->signingtime) && str_contains($currentStatus, 'deliver')) {
                try {
                    $signDate = Carbon::parse($row->signingtime)->toDateString();
                    if ($signDate === $date) {
                        $batches[$key]['delivered']++;
                    }
                } catch (\Throwable $e) {
                    // ignore parse error
                }
            }

            // 4) For Return (new, must have been Delivering before)
            if (
                (str_contains($to, 'return') || str_contains($to, 'rts')) &&
                str_contains($from, 'deliver')
            ) {
                $batches[$key]['for_return']++;
            }
        }
    }

    // sort rows by upload datetime
    ksort($batches);

    // compute TOTAL row
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
