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

    $rtsGroup = $request->input('rts_group', 'sender_item'); // sender_item | sender | item

    $day      = Carbon::parse($date, 'Asia/Manila');
    $dayStart = $day->copy()->startOfDay();
    $dayEnd   = $day->copy()->endOfDay();

    // 60-day window based on submission_time (including selected date)
    $windowStart = $day->copy()->subDays(60)->startOfDay();
    $windowEnd   = $dayEnd;

    // ---------- Helpers ----------
    $norm = function ($s) {
        $s = mb_strtolower((string)$s);
        return preg_replace('/\s+/u', ' ', trim($s));
    };

    $isReturnedStatus = function (string $s) use ($norm): bool {
        $s = $norm($s);
        // ✅ Returned is NOT For Return
        return str_contains($s, 'returned');
    };

    $isForReturnStatus = function (string $s) use ($norm): bool {
        $s = $norm($s);
        // ✅ For Return bucket = "For Return" or "RTS" only
        if (str_contains($s, 'for return')) return true;
        if (preg_match('/\brts\b/i', $s)) return true;
        return false;
    };

    $isDeliveringStatus = function (string $s) use ($norm): bool {
        $s = $norm($s);
        return str_contains($s, 'delivering')
            || (str_contains($s, 'deliver') && !str_contains($s, 'delivered'));
    };

    $makeGroupKey = function ($sender, $item) use ($rtsGroup) {
        $sender = trim((string)$sender);
        $item   = trim((string)$item);

        if ($rtsGroup === 'sender') return $sender;
        if ($rtsGroup === 'item')   return $item;

        // sender_item
        return $sender . ' | ' . $item;
    };

    // ==============================
    // LEFT TABLE: Status Summary per batch_at
    // ==============================
    $batches = [];

    $ensureBatch = function ($batchAt) use (&$batches) {
        if (!isset($batches[$batchAt])) {
            $batches[$batchAt] = [
                'batch_at'        => $batchAt,
                'delivering_set'  => [],
                'in_transit_set'  => [],
                'for_return_set'  => [],
                'delivering'      => 0,
                'in_transit'      => 0,
                'delivered'       => 0,
                'for_return'      => 0,
            ];
        }
    };

    DB::table('from_jnts')
        ->whereNotNull('status_logs')
        ->whereBetween('submission_time', [$windowStart->toDateTimeString(), $windowEnd->toDateTimeString()])
        ->select('id', 'waybill_number', 'status', 'status_logs', 'rts_reason')
        ->orderBy('id')
        ->chunk(1000, function ($rows) use (
            &$batches,
            $date,
            $ensureBatch,
            $isForReturnStatus,
            $isDeliveringStatus,
            $isReturnedStatus
        ) {
            foreach ($rows as $row) {
                $waybill = trim((string)($row->waybill_number ?? ''));
                if ($waybill === '') continue;

                $logsRaw = $row->status_logs;
                if ($logsRaw === null || $logsRaw === '') continue;

                $logs = json_decode($logsRaw, true);
                if (!is_array($logs) || empty($logs)) continue;

                // only logs on selected date
                $dayLogs = [];
                foreach ($logs as $entry) {
                    $batchAt = $entry['batch_at'] ?? null;
                    if (!$batchAt) continue;
                    if (substr((string)$batchAt, 0, 10) !== $date) continue;
                    $dayLogs[] = $entry;
                }
                if (empty($dayLogs)) continue;

                usort($dayLogs, fn($a,$b) => strcmp((string)($a['batch_at'] ?? ''), (string)($b['batch_at'] ?? '')));

                $currentStatus = (string)($row->status ?? '');
                $hasRtsReason  = !is_null($row->rts_reason) && trim((string)$row->rts_reason) !== '';

                $wasDeliveringToday = false;
                $forReturnCounted   = false;

                foreach ($dayLogs as $entry) {
                    $batchAt = $entry['batch_at'] ?? null;
                    if (!$batchAt) continue;

                    $ensureBatch($batchAt);

                    $to   = strtolower(trim((string)($entry['to'] ?? '')));
                    $from = strtolower(trim((string)($entry['from'] ?? '')));

                    // Delivering (exclude if may RTS reason)
                    if (str_contains($to, 'delivering') && !$hasRtsReason) {
                        $batches[$batchAt]['delivering_set'][$waybill] = true;
                        $wasDeliveringToday = true;
                    }

                    // In Transit
                    if (str_contains($to, 'transit')) {
                        $batches[$batchAt]['in_transit_set'][$waybill] = true;
                    }

                    // ✅ For Return (STRICT) — and MUST NOT be Returned currently
                    if (!$forReturnCounted && $isForReturnStatus($to)) {
                        // ✅ important: exclude current status Returned
                        if ($isReturnedStatus($currentStatus)) {
                            $forReturnCounted = true; // prevent later double-hit
                            continue;
                        }

                        // optional strictness: only count if current status still For Return / RTS
                        if (!$isForReturnStatus($currentStatus)) {
                            $forReturnCounted = true;
                            continue;
                        }

                        if ($wasDeliveringToday || $isDeliveringStatus($from)) {
                            $batches[$batchAt]['for_return_set'][$waybill] = true;
                            $forReturnCounted = true;
                        }
                    }
                }
            }
        });

    foreach ($batches as $k => $b) {
        $batches[$k]['delivering'] = count($b['delivering_set'] ?? []);
        $batches[$k]['in_transit'] = count($b['in_transit_set'] ?? []);
        $batches[$k]['for_return'] = count($b['for_return_set'] ?? []);
        unset($batches[$k]['delivering_set'], $batches[$k]['in_transit_set'], $batches[$k]['for_return_set']);
    }

    // Delivered per batch range
    ksort($batches);
    if (!empty($batches)) {
        $keys = array_keys($batches);
        $times = array_map(fn($k) => Carbon::parse($k, 'Asia/Manila'), $keys);

        $n = count($keys);
        for ($i=0; $i<$n; $i++) {
            $batchKey   = $keys[$i];
            $rangeStart = ($i === 0) ? $dayStart : $times[$i-1];

            if ($i === $n-1) {
                $rangeEnd = $dayEnd;
                $qEndOp = '<=';
            } else {
                $rangeEnd = $times[$i];
                $qEndOp = '<';
            }

            $q = DB::table('from_jnts')
                ->whereNotNull('signingtime')
                ->whereBetween('submission_time', [$windowStart->toDateTimeString(), $windowEnd->toDateTimeString()])
                ->where('signingtime', '>=', $rangeStart->toDateTimeString())
                ->where('signingtime', $qEndOp, $rangeEnd->toDateTimeString())
                ->whereRaw("LOWER(status) LIKE '%delivered%'");

            $batches[$batchKey]['delivered'] = (int)$q->count();
            $batches[$batchKey]['range_start'] = $rangeStart->toDateTimeString();
            $batches[$batchKey]['range_end']   = $rangeEnd->toDateTimeString();
        }
    }

    // Totals
    $totals = ['delivering'=>0,'in_transit'=>0,'delivered'=>0,'for_return'=>0];
    foreach ($batches as $b) {
        $totals['delivering'] += (int)$b['delivering'];
        $totals['in_transit'] += (int)$b['in_transit'];
        $totals['delivered']  += (int)$b['delivered'];
        $totals['for_return'] += (int)$b['for_return'];
    }

    // ==============================
    // RIGHT TABLE: RTS Summary (grouped within day)
    // ==============================
    $rtsAgg = []; // key => ['label','sender','item_name','delivered','for_return','volume','rts_rate']

    // Delivered rows for day
    $deliveredRows = DB::table('from_jnts')
        ->whereNotNull('signingtime')
        ->whereBetween('signingtime', [$dayStart->toDateTimeString(), $dayEnd->toDateTimeString()])
        ->whereBetween('submission_time', [$windowStart->toDateTimeString(), $windowEnd->toDateTimeString()])
        ->whereRaw("LOWER(status) LIKE '%delivered%'")
        ->select('sender','item_name')
        ->get();

    foreach ($deliveredRows as $r) {
        $key = $makeGroupKey($r->sender, $r->item_name);
        if (!isset($rtsAgg[$key])) {
            $rtsAgg[$key] = [
                'label'     => $key,
                'sender'    => trim((string)$r->sender),
                'item_name' => trim((string)$r->item_name),
                'delivered' => 0,
                'for_return'=> 0,
            ];
        }
        $rtsAgg[$key]['delivered']++;
    }

    // For Return rows (STRICT): must have For Return/RTS transition today, and current status still For Return/RTS, and NOT Returned
    DB::table('from_jnts')
        ->whereNotNull('status_logs')
        ->whereBetween('submission_time', [$windowStart->toDateTimeString(), $windowEnd->toDateTimeString()])
        ->where(function($q){
            $q->where('status_logs','like','%For Return%')
              ->orWhere('status_logs','like','%for return%')
              ->orWhere('status_logs','like','%RTS%')
              ->orWhere('status_logs','like','%rts%');
        })
        ->select('waybill_number','status','status_logs','rts_reason','sender','item_name')
        ->orderBy('id')
        ->chunk(1000, function($rows) use (
            &$rtsAgg,
            $date,
            $makeGroupKey,
            $isForReturnStatus,
            $isDeliveringStatus,
            $isReturnedStatus
        ){
            foreach ($rows as $row) {
                $waybill = trim((string)($row->waybill_number ?? ''));
                if ($waybill === '') continue;

                $currentStatus = (string)($row->status ?? '');
                if ($isReturnedStatus($currentStatus)) continue;          // ✅ exclude Returned
                if (!$isForReturnStatus($currentStatus)) continue;        // ✅ must still be For Return/RTS

                $decoded = json_decode((string)($row->status_logs ?? ''), true);
                if (!is_array($decoded) || empty($decoded)) continue;

                $dayLogs = [];
                foreach ($decoded as $e) {
                    $ba = $e['batch_at'] ?? null;
                    if (!$ba) continue;
                    if (substr((string)$ba, 0, 10) !== $date) continue;
                    $dayLogs[] = $e;
                }
                if (empty($dayLogs)) continue;

                usort($dayLogs, fn($a,$b) => strcmp((string)($a['batch_at'] ?? ''), (string)($b['batch_at'] ?? '')));

                $hasRtsReason = !is_null($row->rts_reason) && trim((string)$row->rts_reason) !== '';

                $wasDeliveringToday = false;
                $hit = false;

                foreach ($dayLogs as $e) {
                    $to   = strtolower(trim((string)($e['to'] ?? '')));
                    $from = strtolower(trim((string)($e['from'] ?? '')));

                    if (str_contains($to, 'delivering') && !$hasRtsReason) {
                        $wasDeliveringToday = true;
                    }

                    if ($isForReturnStatus($to) && ($wasDeliveringToday || $isDeliveringStatus($from))) {
                        $hit = true;
                        break;
                    }
                }

                if (!$hit) continue;

                $key = $makeGroupKey($row->sender, $row->item_name);
                if (!isset($rtsAgg[$key])) {
                    $rtsAgg[$key] = [
                        'label'     => $key,
                        'sender'    => trim((string)$row->sender),
                        'item_name' => trim((string)$row->item_name),
                        'delivered' => 0,
                        'for_return'=> 0,
                    ];
                }
                $rtsAgg[$key]['for_return']++;
            }
        });

    // finalize rows
    $rtsRows = [];
    $rtsTotals = ['delivered'=>0,'for_return'=>0,'rts_rate'=>0];

    foreach ($rtsAgg as $key => $v) {
        $del = (int)$v['delivered'];
        $fr  = (int)$v['for_return'];
        $vol = $del + $fr;
        $rate = $vol > 0 ? ($fr / $vol) * 100 : 0;

        $rtsRows[] = [
            'label'     => $v['label'],
            'sender'    => $v['sender'],
            'item_name' => $v['item_name'],
            'delivered' => $del,
            'for_return'=> $fr,
            'volume'    => $vol,
            'rts_rate'  => $rate,
        ];

        $rtsTotals['delivered']  += $del;
        $rtsTotals['for_return'] += $fr;
    }

    $totalVol = $rtsTotals['delivered'] + $rtsTotals['for_return'];
    $rtsTotals['rts_rate'] = $totalVol > 0 ? ($rtsTotals['for_return'] / $totalVol) * 100 : 0;

    // sort by volume desc
    usort($rtsRows, function($a,$b){
        return ($b['volume'] <=> $a['volume']);
    });

    return view('jnt_status_summary', [
        'date'      => $date,
        'batches'   => $batches,
        'totals'    => $totals,
        'rtsGroup'  => $rtsGroup,
        'rtsRows'   => $rtsRows,
        'rtsTotals' => $rtsTotals,
    ]);
}


public function statusSummaryRtsDetails(Request $request)
{
    $date     = $request->input('date');
    $rtsGroup = $request->input('rts_group', 'sender_item'); // sender_item | sender | item
    $metric   = $request->input('metric'); // delivered | for_return

    if (!$date || !$metric) {
        return response("Missing params (date/metric).", 422);
    }

    if (!in_array($rtsGroup, ['sender_item','sender','item'], true)) {
        return response("Invalid rts_group.", 422);
    }
    if (!in_array($metric, ['delivered','for_return'], true)) {
        return response("Invalid metric.", 422);
    }

    $sender   = trim((string)$request->input('sender', ''));
    $itemName = trim((string)$request->input('item_name', ''));
    $grp      = trim((string)$request->input('grp', ''));

    if ($rtsGroup === 'sender_item' && ($sender === '' || $itemName === '')) {
        return response("Missing sender/item_name for sender_item.", 422);
    }
    if ($rtsGroup !== 'sender_item' && $grp === '') {
        return response("Missing grp for sender/item.", 422);
    }

    $day      = Carbon::parse($date, 'Asia/Manila');
    $dayStart = $day->copy()->startOfDay();
    $dayEnd   = $day->copy()->endOfDay();

    // window for scanning status_logs (same as main page)
    $windowStart = $day->copy()->subDays(60)->startOfDay();
    $windowEnd   = $dayEnd;

    // ✅ STRICT For Return detector (NOT "Returned")
    $isForReturnStatus = function (string $s): bool {
        $s = strtolower(trim($s));
        if (str_contains($s, 'for return')) return true;
        if (preg_match('/\brts\b/i', $s)) return true;
        return false;
    };

    $isDeliveringStatus = function (string $s): bool {
        $s = strtolower(trim($s));
        return str_contains($s, 'delivering')
            || (str_contains($s, 'deliver') && !str_contains($s, 'delivered'));
    };

    // group matcher
    $matchGroup = function ($row) use ($rtsGroup, $sender, $itemName, $grp) {
        $rowSender = trim((string)($row->sender ?? ''));
        $rowItem   = trim((string)($row->item_name ?? ''));

        if ($rtsGroup === 'sender_item') {
            return mb_strtolower($rowSender) === mb_strtolower($sender)
                && mb_strtolower($rowItem)   === mb_strtolower($itemName);
        }

        if ($rtsGroup === 'sender') {
            return mb_strtolower($rowSender) === mb_strtolower($grp);
        }

        // item
        return mb_strtolower($rowItem) === mb_strtolower($grp);
    };

    // =========================
    // ✅ DELIVERED DETAILS
    // =========================
    if ($metric === 'delivered') {
        $rows = DB::table('from_jnts')
            ->whereNotNull('signingtime')
            ->whereBetween('signingtime', [$dayStart->toDateTimeString(), $dayEnd->toDateTimeString()])
            ->whereBetween('submission_time', [$windowStart->toDateTimeString(), $windowEnd->toDateTimeString()])
            ->whereRaw("LOWER(status) LIKE '%delivered%'")
            ->select('waybill_number','status','province','submission_time','signingtime','status_logs','sender','item_name')
            ->orderBy('signingtime')
            ->get();

        $items = [];
        foreach ($rows as $r) {
            if (!$matchGroup($r)) continue;

            $logs = [];
            if (!empty($r->status_logs)) {
                $d = json_decode($r->status_logs, true);
                if (is_array($d)) $logs = $d; // FULL logs (all dates)
            }

            $items[] = [
                'waybill'         => (string)$r->waybill_number,
                'status'          => (string)$r->status,
                'province'        => (string)($r->province ?? ''),
                'submission_time' => (string)($r->submission_time ?? ''),
                'signingtime'     => (string)($r->signingtime ?? ''),
                'logs'            => $logs,
            ];
        }

        // ✅ IMPORTANT: use SAME popup blade as status summary (working)
        return view('jnt_status_summary_details', [
            'title'   => "DELIVERED • " . ($rtsGroup === 'sender_item' ? "{$sender} | {$itemName}" : $grp),
            'date'    => $date,
            'batchAt' => null,
            'metric'  => 'delivered',
            'items'   => $items,
        ]);
    }

    // =========================
    // ✅ FOR_RETURN DETAILS (STRICT, NOT Returned)
    // =========================
    $itemsByWaybill = []; // unique

    DB::table('from_jnts')
        ->whereNotNull('status_logs')
        ->whereBetween('submission_time', [$windowStart->toDateTimeString(), $windowEnd->toDateTimeString()])
        ->where(function($q){
            $q->where('status_logs','like','%For Return%')
              ->orWhere('status_logs','like','%for return%')
              ->orWhere('status_logs','like','%RTS%')
              ->orWhere('status_logs','like','%rts%');
        })
        ->select('waybill_number','status','province','submission_time','signingtime','status_logs','rts_reason','sender','item_name')
        ->orderBy('id')
        ->chunk(1000, function($rows) use (
            &$itemsByWaybill,
            $matchGroup,
            $date,
            $isForReturnStatus,
            $isDeliveringStatus
        ) {
            foreach ($rows as $row) {
                $waybill = trim((string)($row->waybill_number ?? ''));
                if ($waybill === '' || isset($itemsByWaybill[$waybill])) continue;
                if (!$matchGroup($row)) continue;

                $decoded = json_decode((string)($row->status_logs ?? ''), true);
                if (!is_array($decoded) || empty($decoded)) continue;

                // logs on selected date only (for deciding if For Return today)
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

                $wasDeliveringToday = false;
                foreach ($dayLogs as $e) {
                    $to   = strtolower(trim((string)($e['to'] ?? '')));
                    $from = strtolower(trim((string)($e['from'] ?? '')));

                    // Delivering (exclude if may RTS reason)
                    if (str_contains($to, 'delivering') && !$hasRts) {
                        $wasDeliveringToday = true;
                    }

                    // ✅ STRICT: For Return / RTS only (NOT Returned)
                    if ($isForReturnStatus($to) && ($wasDeliveringToday || $isDeliveringStatus($from))) {
                        $itemsByWaybill[$waybill] = [
                            'waybill'         => $waybill,
                            'status'          => (string)($row->status ?? ''),
                            'province'        => (string)($row->province ?? ''),
                            'submission_time' => (string)($row->submission_time ?? ''),
                            'signingtime'     => (string)($row->signingtime ?? ''),
                            'logs'            => $decoded, // FULL logs
                        ];
                        break;
                    }
                }
            }
        });

    // ✅ IMPORTANT: use SAME popup blade as status summary (working)
    return view('jnt_status_summary_details', [
        'title'   => "FOR_RETURN • " . ($rtsGroup === 'sender_item' ? "{$sender} | {$itemName}" : $grp),
        'date'    => $date,
        'batchAt' => null,
        'metric'  => 'for_return',
        'items'   => array_values($itemsByWaybill),
    ]);
}



public function statusSummaryDetails(Request $request)
{
    $date    = $request->input('date');
    $batchAt = $request->input('batch_at');
    $metric  = $request->input('metric'); // delivering | in_transit | for_return | delivered

    if (!$date || !$batchAt || !$metric) return response("Missing params.", 422);

    $allowed = ['delivering', 'in_transit', 'for_return', 'delivered'];
    if (!in_array($metric, $allowed, true)) return response("Invalid metric.", 422);

    $day      = Carbon::parse($date, 'Asia/Manila');
    $dayStart = $day->copy()->startOfDay();
    $dayEnd   = $day->copy()->endOfDay();

    $windowStart = $day->copy()->subDays(60)->startOfDay();
    $windowEnd   = $dayEnd;

    $norm = function ($s) {
        $s = mb_strtolower((string)$s);
        return preg_replace('/\s+/u', ' ', trim($s));
    };

    $isReturnedStatus = function (string $s) use ($norm): bool {
        return str_contains($norm($s), 'returned');
    };

    $isForReturnStatus = function (string $s) use ($norm): bool {
        $s = $norm($s);
        if (str_contains($s, 'for return')) return true;
        if (preg_match('/\brts\b/i', $s)) return true;
        return false;
    };

    $isDeliveringStatus = function (string $s) use ($norm): bool {
        $s = $norm($s);
        return str_contains($s, 'delivering')
            || (str_contains($s, 'deliver') && !str_contains($s, 'delivered'));
    };

    // ✅ DELIVERED: via signingtime range (range passed from UI)
    if ($metric === 'delivered') {
        $rangeStart = $request->input('range_start');
        $rangeEnd   = $request->input('range_end');
        if (!$rangeStart || !$rangeEnd) return response("Missing range_start/range_end for delivered.", 422);

        $rows = DB::table('from_jnts')
            ->whereNotNull('signingtime')
            ->whereBetween('submission_time', [$windowStart->toDateTimeString(), $windowEnd->toDateTimeString()])
            ->whereRaw("LOWER(status) LIKE '%delivered%'")
            ->where('signingtime', '>=', $rangeStart)
            ->where('signingtime', '<=', $rangeEnd)
            ->select('waybill_number', 'status', 'province', 'signingtime', 'submission_time', 'status_logs')
            ->orderBy('signingtime')
            ->get();

        $items = [];
        foreach ($rows as $r) {
            $logs = [];
            if (!empty($r->status_logs)) {
                $decoded = json_decode($r->status_logs, true);
                if (is_array($decoded)) $logs = $decoded;
            }

            $items[] = [
                'waybill'         => (string)$r->waybill_number,
                'status'          => (string)$r->status,
                'province'        => (string)($r->province ?? ''),
                'submission_time' => (string)($r->submission_time ?? ''),
                'signingtime'     => (string)($r->signingtime ?? ''),
                'logs'            => $logs,
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

    // ✅ delivering / in_transit / for_return: parse status_logs, match exact batchAt
    $candidates = DB::table('from_jnts')
        ->whereNotNull('status_logs')
        ->whereBetween('submission_time', [$windowStart->toDateTimeString(), $windowEnd->toDateTimeString()])
        ->where('status_logs', 'like', '%' . $batchAt . '%')
        ->select('waybill_number', 'status_logs', 'rts_reason', 'status', 'province', 'signingtime', 'submission_time')
        ->get();

    $items = [];
    foreach ($candidates as $row) {
        $waybill = trim((string)($row->waybill_number ?? ''));
        if ($waybill === '') continue;

        $decoded = json_decode((string)($row->status_logs ?? ''), true);
        if (!is_array($decoded) || empty($decoded)) continue;

        $dayLogs = [];
        foreach ($decoded as $e) {
            $ba = $e['batch_at'] ?? null;
            if (!$ba) continue;
            if (substr((string)$ba, 0, 10) !== $date) continue;
            $dayLogs[] = $e;
        }
        if (empty($dayLogs)) continue;

        usort($dayLogs, fn($a,$b) => strcmp((string)($a['batch_at'] ?? ''), (string)($b['batch_at'] ?? '')));

        $hasRtsReason = !is_null($row->rts_reason) && trim((string)$row->rts_reason) !== '';
        $currentStatus = (string)($row->status ?? '');

        $matched = false;
        $wasDeliveringToday = false;

        foreach ($dayLogs as $e) {
            $ba   = (string)($e['batch_at'] ?? '');
            $to   = strtolower(trim((string)($e['to'] ?? '')));
            $from = strtolower(trim((string)($e['from'] ?? '')));

            if (str_contains($to, 'delivering') && !$hasRtsReason) {
                $wasDeliveringToday = true;
            }

            if ($ba !== $batchAt) continue;

            if ($metric === 'delivering') {
                if (str_contains($to, 'delivering') && !$hasRtsReason) { $matched = true; break; }
            } elseif ($metric === 'in_transit') {
                if (str_contains($to, 'transit')) { $matched = true; break; }
            } elseif ($metric === 'for_return') {
                // ✅ STRICT + exclude Returned current status + must still be For Return/RTS
                if ($isForReturnStatus($to) && ($wasDeliveringToday || $isDeliveringStatus($from))) {
                    if (!$isReturnedStatus($currentStatus) && $isForReturnStatus($currentStatus)) {
                        $matched = true;
                        break;
                    }
                }
            }
        }

        if (!$matched) continue;

        $items[] = [
            'waybill'         => $waybill,
            'status'          => $currentStatus,
            'province'        => (string)($row->province ?? ''),
            'submission_time' => (string)($row->submission_time ?? ''),
            'signingtime'     => (string)($row->signingtime ?? ''),
            'logs'            => $decoded, // FULL logs
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
    $q        = trim((string)$request->input('q', ''));

    // --- BASE QUERY: submission_time date filter lang ---
    $baseDateQuery = FromJnt::query();

    if ($dateFrom) {
        $baseDateQuery->whereDate('submission_time', '>=', $dateFrom);
    }

    if ($dateTo) {
        $baseDateQuery->whereDate('submission_time', '<=', $dateTo);
    }

    // --- COLUMN FILTERS (popup) ---
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

    // ✅ SEARCH (applies AFTER date filter + column filters)
    // Search scope is automatically limited by dateFrom/dateTo because dataQuery started from baseDateQuery.
    if ($q !== '') {
        // optional: escape % and _ so user input is literal
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
        $like = '%' . $escaped . '%';

        $dataQuery->where(function ($qq) use ($like, $q) {
            $qq->whereRaw("waybill_number LIKE ? ESCAPE '\\\\'", [$like])
               ->orWhereRaw("receiver LIKE ? ESCAPE '\\\\'", [$like])
               ->orWhereRaw("receiver_cellphone LIKE ? ESCAPE '\\\\'", [$like])
               ->orWhereRaw("sender LIKE ? ESCAPE '\\\\'", [$like])
               ->orWhereRaw("item_name LIKE ? ESCAPE '\\\\'", [$like])
               ->orWhereRaw("status LIKE ? ESCAPE '\\\\'", [$like])
               ->orWhereRaw("remarks LIKE ? ESCAPE '\\\\'", [$like])
               ->orWhereRaw("province LIKE ? ESCAPE '\\\\'", [$like])
               ->orWhereRaw("city LIKE ? ESCAPE '\\\\'", [$like])
               ->orWhereRaw("barangay LIKE ? ESCAPE '\\\\'", [$like])
               ->orWhereRaw("rts_reason LIKE ? ESCAPE '\\\\'", [$like])
               ->orWhereRaw("CAST(cod AS CHAR) LIKE ? ESCAPE '\\\\'", [$like])
               ->orWhereRaw("CAST(total_shipping_cost AS CHAR) LIKE ? ESCAPE '\\\\'", [$like]);
        });
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
    // IMPORTANT: base lang sa date range (HINDI kasama search/column filters)
    $filterBase = clone $baseDateQuery;
    $filterOptions = [];

    $filterOptions['submission_time'] = (clone $filterBase)
        ->selectRaw('DATE(submission_time) as value')
        ->distinct()
        ->orderBy('value')
        ->pluck('value')
        ->map(fn ($v) => $v ? Carbon::parse($v)->format('Y-m-d') : '')
        ->values()
        ->toArray();

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
