<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\FromJnt;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;



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
        return str_contains($norm($s), 'returned'); // Returned is NOT For Return
    };

    $isForReturnStatus = function (string $s) use ($norm): bool {
    $s = $norm($s);

    // ✅ iwas false-positive: "Returned is NOT For Return"
    if (str_contains($s, 'not for return')) return false;
    if (str_contains($s, 'returned')) return false;

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

        return $sender . ' | ' . $item; // sender_item
    };

    // ✅ FIX: Delivering scenario 1 vs 2
    // Count Delivering ONLY if there is NO "For Return/RTS" log BEFORE that batch_at (any date).
    $hasForReturnBefore = function (array $allLogs, string $batchAt) use ($isForReturnStatus): bool {
        foreach ($allLogs as $e) {
            if (!is_array($e)) continue;
            $ba = (string)($e['batch_at'] ?? '');
            if ($ba === '') continue;

            // lexicographic compare OK if format is Y-m-d H:i:s consistently
            if ($ba < $batchAt) {
                $to = (string)($e['to'] ?? '');
                if ($to !== '' && $isForReturnStatus($to)) {
                    return true;
                }
            }
        }
        return false;
    };

    // Province normalization + Region grouping
    $normalizeProvince = function ($raw) {
        if (!$raw) return '';
        $s = strtoupper(trim((string)$raw));
        $s = str_replace('.', '', $s);
        $s = preg_replace('/[\s_]+/', '-', $s);

        $map = [
            'NCR'                      => 'METRO-MANILA',
            'NATIONAL-CAPITAL-REGION'  => 'METRO-MANILA',
        ];

        return $map[$s] ?? $s;
    };

    $luzon = array_flip([
        "ABRA","ALBAY","APAYAO","AURORA","BATAAN","BATANES","BATANGAS","BENGUET",
        "BULACAN","CAGAYAN","CAMARINES-NORTE","CAMARINES-SUR","CATANDUANES","CAVITE",
        "IFUGAO","ILOCOS-NORTE","ILOCOS-SUR","ISABELA","KALINGA","LA-UNION","LAGUNA",
        "MARINDUQUE","MASBATE","METRO-MANILA","MOUNTAIN-PROVINCE","NUEVA-ECIJA",
        "NUEVA-VIZCAYA","OCCIDENTAL-MINDORO","ORIENTAL-MINDORO","PALAWAN","PAMPANGA",
        "PANGASINAN","QUEZON","QUIRINO","RIZAL","ROMBLON","SORSOGON","TARLAC","ZAMBALES"
    ]);

    $visayas = array_flip([
        "AKLAN","ANTIQUE","BILIRAN","BOHOL","CAPIZ","CEBU","EASTERN-SAMAR","GUIMARAS",
        "ILOILO","LEYTE","NEGROS-OCCIDENTAL","NEGROS-ORIENTAL","NORTHERN-SAMAR",
        "SIQUIJOR","SOUTHERN-LEYTE","WESTERN-SAMAR"
    ]);

    $mindanao = array_flip([
        "AGUSAN-DEL-NORTE","AGUSAN-DEL-SUR","BASILAN","BUKIDNON","CAMIGUIN","COTABATO",
        "DAVAO-DE-ORO","DAVAO-DEL-NORTE","DAVAO-DEL-SUR","DAVAO-OCCIDENTAL","DAVAO-ORIENTAL",
        "DINAGAT-ISLANDS","LANAO-DEL-NORTE","LANAO-DEL-SUR","MAGUINDANAO","MISAMIS-OCCIDENTAL",
        "MISAMIS-ORIENTAL","SARANGANI","SOUTH-COTABATO","SULTAN-KUDARAT","SULU",
        "SURIGAO-DEL-NORTE","SURIGAO-DEL-SUR","TAWI-TAWI","ZAMBOANGA-DEL-NORTE",
        "ZAMBOANGA-DEL-SUR","ZAMBOANGA-SIBUGAY"
    ]);

    $regionOf = function ($prov) use ($luzon, $visayas, $mindanao) {
        $p = strtoupper(trim((string)$prov));
        if ($p === '') return null;
        if (isset($luzon[$p])) return 'LUZON';
        if (isset($visayas[$p])) return 'VISAYAS';
        if (isset($mindanao[$p])) return 'MINDANAO';
        return null;
    };

    // ==============================
    // LEFT TABLE: Status Summary per batch_at
    // ==============================
    $batches = [];

    $inTransitTodayWaybills = [];
    $fallbackProvinceByWb   = [];

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
        ->whereBetween('submission_time', [$windowStart, $windowEnd])
        ->where('status_logs', 'like', "%{$date}%")
        ->select('id', 'waybill_number', 'status', 'status_logs', 'province')
        ->chunkById(1000, function ($rows) use (
            &$batches,
            $date,
            $ensureBatch,
            $isForReturnStatus,
            $isDeliveringStatus,
            $isReturnedStatus,
            $hasForReturnBefore,
            &$inTransitTodayWaybills,
            &$fallbackProvinceByWb,
            $normalizeProvince
        ) {
            foreach ($rows as $row) {
                $waybill = trim((string)($row->waybill_number ?? ''));
                if ($waybill === '') continue;

                if (!isset($fallbackProvinceByWb[$waybill])) {
                    $fallbackProvinceByWb[$waybill] = $normalizeProvince($row->province ?? '');
                }

                $logs = json_decode((string)$row->status_logs, true);
                if (!is_array($logs) || empty($logs)) continue;

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
                $wasDeliveringToday = false;
                $forReturnCounted   = false;

                foreach ($dayLogs as $entry) {
                    $batchAt = (string)($entry['batch_at'] ?? '');
                    if ($batchAt === '') continue;

                    $ensureBatch($batchAt);

                    $toRaw   = (string)($entry['to'] ?? '');
                    $fromRaw = (string)($entry['from'] ?? '');

                    $toLc = strtolower(trim($toRaw));

                    // ✅ Delivering scenario fix
                    $deliverOk = (str_contains($toLc, 'delivering') && !$hasForReturnBefore($logs, $batchAt));
                    if ($deliverOk) {
                        $batches[$batchAt]['delivering_set'][$waybill] = true;
                        $wasDeliveringToday = true;
                    }

                    if (str_contains($toLc, 'transit')) {
                        $batches[$batchAt]['in_transit_set'][$waybill] = true;
                        $inTransitTodayWaybills[$waybill] = true;
                    }

                    // ✅ For Return OCCURRED today (counts even if later became Returned)
// Count only if this is the FIRST time it became For Return/RTS (no earlier For Return log before this batch_at)
if (!$forReturnCounted && $isForReturnStatus($toRaw)) {
    if (!$hasForReturnBefore($logs, $batchAt)) {
        $batches[$batchAt]['for_return_set'][$waybill] = true;
    }
    $forReturnCounted = true; // stop after first For Return log of the day
}

                }
            }
        }, 'id');

    foreach ($batches as $k => $b) {
        $batches[$k]['delivering'] = count($b['delivering_set'] ?? []);
        $batches[$k]['in_transit'] = count($b['in_transit_set'] ?? []);
        $batches[$k]['for_return'] = count($b['for_return_set'] ?? []);
        unset($batches[$k]['delivering_set'], $batches[$k]['in_transit_set'], $batches[$k]['for_return_set']);
    }

    // ==============================
    // Delivered per batch range (ONE QUERY + bucket)
    // ==============================
    ksort($batches);

    if (!empty($batches)) {
        $keys  = array_keys($batches);
        $times = array_map(fn($k) => Carbon::parse($k, 'Asia/Manila'), $keys);
        $n     = count($keys);

        for ($i=0; $i<$n; $i++) {
            $rangeStart = ($i === 0) ? $dayStart : $times[$i-1];
            $rangeEnd   = ($i === $n-1) ? $dayEnd   : $times[$i];

            $batches[$keys[$i]]['delivered']   = 0;
            $batches[$keys[$i]]['range_start'] = $rangeStart->toDateTimeString();
            $batches[$keys[$i]]['range_end']   = $rangeEnd->toDateTimeString();
        }

        $deliveredTimes = DB::table('from_jnts')
            ->whereNotNull('signingtime')
            ->whereBetween('signingtime', [$dayStart, $dayEnd])
            ->whereBetween('submission_time', [$windowStart, $windowEnd])
            ->whereRaw("LOWER(status) LIKE '%delivered%'")
            ->orderBy('signingtime')
            ->pluck('signingtime');

        $idx = 0;
        foreach ($deliveredTimes as $st) {
            $t = Carbon::parse($st, 'Asia/Manila');
            while ($idx < $n - 1 && $t >= $times[$idx]) $idx++;
            $batches[$keys[$idx]]['delivered']++;
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
    // In Transit breakdown
    // ==============================
    $inTransitBreakdown = ['luzon'=>0,'visayas'=>0,'mindanao'=>0,'total'=>0];

    $wbList = array_keys($inTransitTodayWaybills);
    $inTransitBreakdown['total'] = count($wbList);

    $provinceByWb = [];
    $macroTable = 'macro_output';

    $macroWaybillCol = null;
    $macroProvinceCol = null;

    if (Schema::hasTable($macroTable)) {
        // ✅ case-insensitive column lookup (safer)
        $cols = Schema::getColumnListing($macroTable);
        $colMap = [];
        foreach ($cols as $col) $colMap[strtolower($col)] = $col;

        $candidatesWaybill = ['jnt_mailno', 'jt_mailno', 'mailno', 'waybill_number', 'tracking_no'];
        foreach ($candidatesWaybill as $c) {
            $k = strtolower($c);
            if (isset($colMap[$k])) { $macroWaybillCol = $colMap[$k]; break; }
        }

        $candidatesProvince = ['province', 'PROVINCE', 'prov'];
        foreach ($candidatesProvince as $c) {
            $k = strtolower($c);
            if (isset($colMap[$k])) { $macroProvinceCol = $colMap[$k]; break; }
        }

        if ($macroWaybillCol && $macroProvinceCol && !empty($wbList)) {
            foreach (array_chunk($wbList, 1000) as $chunk) {
                $rows = DB::table($macroTable)
                    ->whereIn($macroWaybillCol, $chunk)
                    ->select($macroWaybillCol, $macroProvinceCol)
                    ->get();

                foreach ($rows as $r) {
                    $wb = trim((string)($r->{$macroWaybillCol} ?? ''));
                    if ($wb === '') continue;

                    $prov = $normalizeProvince($r->{$macroProvinceCol} ?? '');
                    if ($prov === '') continue;

                    if (!isset($provinceByWb[$wb]) || $provinceByWb[$wb] === '') {
                        $provinceByWb[$wb] = $prov;
                    }
                }
            }
        }
    }

    foreach ($wbList as $wb) {
        if (!isset($provinceByWb[$wb]) || $provinceByWb[$wb] === '') {
            $provinceByWb[$wb] = $fallbackProvinceByWb[$wb] ?? '';
        }
    }

    foreach ($wbList as $wb) {
        $prov = $provinceByWb[$wb] ?? '';
        $grp = $regionOf($prov);
        if ($grp === 'LUZON') $inTransitBreakdown['luzon']++;
        elseif ($grp === 'VISAYAS') $inTransitBreakdown['visayas']++;
        elseif ($grp === 'MINDANAO') $inTransitBreakdown['mindanao']++;
    }

    // ==============================
    // RIGHT TABLE: RTS Summary (grouped within day)
    // ==============================
    $rtsAgg = [];

    $deliveredRows = DB::table('from_jnts')
        ->whereNotNull('signingtime')
        ->whereBetween('signingtime', [$dayStart, $dayEnd])
        ->whereBetween('submission_time', [$windowStart, $windowEnd])
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

    DB::table('from_jnts')
        ->whereNotNull('status_logs')
        ->whereBetween('submission_time', [$windowStart, $windowEnd])
        ->where('status_logs', 'like', "%{$date}%")
        ->where(function($q){
            $q->where('status_logs','like','%For Return%')
              ->orWhere('status_logs','like','%for return%')
              ->orWhere('status_logs','like','%RTS%')
              ->orWhere('status_logs','like','%rts%');
        })
        ->select('id','waybill_number','status','status_logs','sender','item_name')
        ->chunkById(1000, function($rows) use (
            &$rtsAgg,
            $date,
            $makeGroupKey,
            $isForReturnStatus,
            $isDeliveringStatus,
            $isReturnedStatus,
            $hasForReturnBefore
        ){
            foreach ($rows as $row) {
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

                $wasDeliveringToday = false;
                $hit = false;

                foreach ($dayLogs as $e) {
                    $ba      = (string)($e['batch_at'] ?? '');
                    $toRaw   = (string)($e['to'] ?? '');
                    $fromRaw = (string)($e['from'] ?? '');

                    $toLc = strtolower(trim($toRaw));
                    if ($ba !== '' && str_contains($toLc, 'delivering') && !$hasForReturnBefore($decoded, $ba)) {
                        $wasDeliveringToday = true;
                    }

                    // ✅ HIT if For Return happened on selected date AND it's the first ever For Return
if ($isForReturnStatus($toRaw) && !$hasForReturnBefore($decoded, $ba)) {
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
        }, 'id');

    $rtsRows = [];
    $rtsTotals = ['delivered'=>0,'for_return'=>0,'rts_rate'=>0];

    foreach ($rtsAgg as $v) {
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

    usort($rtsRows, fn($a,$b) => ($b['volume'] <=> $a['volume']));

    return view('jnt_status_summary', [
        'date'              => $date,
        'batches'           => $batches,
        'totals'            => $totals,
        'rtsGroup'          => $rtsGroup,
        'rtsRows'           => $rtsRows,
        'rtsTotals'         => $rtsTotals,
        'inTransitBreakdown'=> $inTransitBreakdown,
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

    // same window as main page
    $windowStart = $day->copy()->subDays(60)->startOfDay();
    $windowEnd   = $dayEnd;

    // -------- helpers --------
    $norm = function ($s) {
        $s = mb_strtolower((string)$s);
        return preg_replace('/\s+/u', ' ', trim($s));
    };

    // ✅ STRICT: For Return / RTS only (exclude "Returned" and "Not for return")
    $isForReturnStatus = function (string $s) use ($norm): bool {
        $s = $norm($s);

        if (str_contains($s, 'not for return')) return false;
        if (str_contains($s, 'returned')) return false;

        if (str_contains($s, 'for return')) return true;
        if (preg_match('/\brts\b/i', $s)) return true;

        return false;
    };

    // ✅ same as main: Delivering counts excluded when may For Return BEFORE this batch_at (any date)
    $hasForReturnBefore = function (array $allLogs, string $batchAt) use ($isForReturnStatus): bool {
        // robust compare using Carbon if possible
        try {
            $target = Carbon::parse($batchAt, 'Asia/Manila');
        } catch (\Throwable $e) {
            $target = null;
        }

        foreach ($allLogs as $e) {
            if (!is_array($e)) continue;

            $baStr = (string)($e['batch_at'] ?? '');
            if ($baStr === '') continue;

            $isBefore = false;

            if ($target) {
                try {
                    $ba = Carbon::parse($baStr, 'Asia/Manila');
                    $isBefore = $ba->lt($target);
                } catch (\Throwable $e) {
                    // fallback: string compare
                    $isBefore = ($baStr < $batchAt);
                }
            } else {
                $isBefore = ($baStr < $batchAt);
            }

            if ($isBefore) {
                $to = (string)($e['to'] ?? '');
                if ($to !== '' && $isForReturnStatus($to)) {
                    return true;
                }
            }
        }

        return false;
    };

    // group matcher (case-insensitive)
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
                if (is_array($d)) $logs = $d; // FULL logs
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
            'title'   => "DELIVERED • " . ($rtsGroup === 'sender_item' ? "{$sender} | {$itemName}" : $grp),
            'date'    => $date,
            'batchAt' => null,
            'metric'  => 'delivered',
            'items'   => $items,
        ]);
    }

    // =========================
    // ✅ FOR_RETURN DETAILS
    // Match main summary:
    // - For Return happened on selected date
    // - and it's the FIRST EVER For Return/RTS (no earlier For Return log before that batch_at)
    // =========================
    $itemsByWaybill = [];

    DB::table('from_jnts')
        ->whereNotNull('status_logs')
        ->whereBetween('submission_time', [$windowStart->toDateTimeString(), $windowEnd->toDateTimeString()])
        ->where('status_logs', 'like', "%{$date}%")
        ->where(function($q){
            $q->where('status_logs','like','%For Return%')
              ->orWhere('status_logs','like','%for return%')
              ->orWhere('status_logs','like','%RTS%')
              ->orWhere('status_logs','like','%rts%');
        })
        ->select('id','waybill_number','status','province','submission_time','signingtime','status_logs','sender','item_name')
        ->orderBy('id')
        ->chunkById(1000, function($rows) use (
            &$itemsByWaybill,
            $matchGroup,
            $date,
            $isForReturnStatus,
            $hasForReturnBefore
        ) {
            foreach ($rows as $row) {
                $waybill = trim((string)($row->waybill_number ?? ''));
                if ($waybill === '' || isset($itemsByWaybill[$waybill])) continue;
                if (!$matchGroup($row)) continue;

                $decoded = json_decode((string)($row->status_logs ?? ''), true);
                if (!is_array($decoded) || empty($decoded)) continue;

                // ✅ find ANY log on selected date that is:
                // For Return/RTS AND first-ever For Return (no earlier)
                $hit = false;

                foreach ($decoded as $e) {
                    $ba = (string)($e['batch_at'] ?? '');
                    if ($ba === '' || substr($ba, 0, 10) !== $date) continue;

                    $toRaw = (string)($e['to'] ?? '');
                    if ($toRaw === '') continue;

                    if ($isForReturnStatus($toRaw) && !$hasForReturnBefore($decoded, $ba)) {
                        $hit = true;
                        break;
                    }
                }

                if (!$hit) continue;

                $itemsByWaybill[$waybill] = [
                    'waybill'         => $waybill,
                    'status'          => (string)($row->status ?? ''),
                    'province'        => (string)($row->province ?? ''),
                    'submission_time' => (string)($row->submission_time ?? ''),
                    'signingtime'     => (string)($row->signingtime ?? ''),
                    'logs'            => $decoded, // FULL logs
                ];
            }
        }, 'id');

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

    // ✅ iwas false-positive: "Returned is NOT For Return"
    if (str_contains($s, 'not for return')) return false;
    if (str_contains($s, 'returned')) return false;

    if (str_contains($s, 'for return')) return true;
    if (preg_match('/\brts\b/i', $s)) return true;

    return false;
};


    $isDeliveringStatus = function (string $s) use ($norm): bool {
        $s = $norm($s);
        return str_contains($s, 'delivering')
            || (str_contains($s, 'deliver') && !str_contains($s, 'delivered'));
    };

    // ✅ Delivering scenario fix helper
    $hasForReturnBefore = function (array $allLogs, string $batchAt) use ($isForReturnStatus): bool {
        foreach ($allLogs as $e) {
            if (!is_array($e)) continue;
            $ba = (string)($e['batch_at'] ?? '');
            if ($ba === '') continue;

            if ($ba < $batchAt) {
                $to = (string)($e['to'] ?? '');
                if ($to !== '' && $isForReturnStatus($to)) {
                    return true;
                }
            }
        }
        return false;
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

    // delivering / in_transit / for_return: parse status_logs, match exact batchAt
    $candidates = DB::table('from_jnts')
        ->whereNotNull('status_logs')
        ->whereBetween('submission_time', [$windowStart->toDateTimeString(), $windowEnd->toDateTimeString()])
        ->where('status_logs', 'like', '%' . $batchAt . '%')
        ->select('waybill_number', 'status_logs', 'status', 'province', 'signingtime', 'submission_time')
        ->get();

    $items = [];
    foreach ($candidates as $row) {
        $waybill = trim((string)($row->waybill_number ?? ''));
        if ($waybill === '') continue;

        $decoded = json_decode((string)($row->status_logs ?? ''), true);
        if (!is_array($decoded) || empty($decoded)) continue;

        // logs on selected date only
        $dayLogs = [];
        foreach ($decoded as $e) {
            $ba = $e['batch_at'] ?? null;
            if (!$ba) continue;
            if (substr((string)$ba, 0, 10) !== $date) continue;
            $dayLogs[] = $e;
        }
        if (empty($dayLogs)) continue;

        usort($dayLogs, fn($a,$b) => strcmp((string)($a['batch_at'] ?? ''), (string)($b['batch_at'] ?? '')));

        $currentStatus = (string)($row->status ?? '');

        // ✅ Precompute:
        // - whether we were "Delivering to customer" earlier today (scenario 1)
        // - FIRST qualifying For Return batch_at today (to match summary behavior)
        $wasDeliveringToday = false;
        $firstForReturnBa   = null;

        foreach ($dayLogs as $e) {
            $ba      = (string)($e['batch_at'] ?? '');
            $toRaw   = (string)($e['to'] ?? '');
            $fromRaw = (string)($e['from'] ?? '');
            if ($ba === '') continue;

            $toLc = strtolower(trim($toRaw));

            $deliverOk = (str_contains($toLc, 'delivering') && !$hasForReturnBefore($decoded, $ba));
            if ($deliverOk) {
                $wasDeliveringToday = true;
            }

            // FIRST For Return (same as summary: first hit only)
            // ✅ first For Return event (ever) that occurs on selected date
if ($firstForReturnBa === null && $isForReturnStatus($toRaw) && !$hasForReturnBefore($decoded, $ba)) {
    $firstForReturnBa = $ba;
}

        }

        $matched = false;

        foreach ($dayLogs as $e) {
            $ba      = (string)($e['batch_at'] ?? '');
            $toRaw   = (string)($e['to'] ?? '');
            if ($ba === '' || $ba !== $batchAt) continue;

            $toLc = strtolower(trim($toRaw));

            if ($metric === 'delivering') {
                $deliverOk = (str_contains($toLc, 'delivering') && !$hasForReturnBefore($decoded, $ba));
                if ($deliverOk) { $matched = true; break; }

            } elseif ($metric === 'in_transit') {
                if (str_contains($toLc, 'transit')) { $matched = true; break; }

            } elseif ($metric === 'for_return') {
                // ✅ MUST match the FIRST for_return batch_at today (to match summary counts)
                if ($firstForReturnBa !== null && $firstForReturnBa === $batchAt) {
                    $matched = true;
                    break;
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

    public function rtsView(Request $request)
    {
        // Default: 1st of last month → today
        $defaultFrom = Carbon::now('Asia/Manila')->subMonthNoOverflow()->startOfMonth()->toDateString();
        $defaultTo   = Carbon::now('Asia/Manila')->toDateString();

        $from = $request->input('from') ?: $defaultFrom;
        $to   = $request->input('to')   ?: $defaultTo;

        return $this->rtsFiltered(
            $request->merge(['from' => $from, 'to' => $to])
        );
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
    $toDt   = Carbon::parse($to,   'Asia/Manila')->endOfDay();

    // helper for date range display
    $fmtDate = function ($v) {
        try { return Carbon::parse($v, 'Asia/Manila')->format('Y-m-d'); }
        catch (\Throwable $e) { return (string)$v; }
    };

    /**
     * ✅ SQL aggregation:
     * - group by sender + item_name + cod
     * - compute counts per status bucket using SUM(CASE WHEN ...)
     * - compute min/max submission_time for date range string
     *
     * NOTE: This assumes submission_time is DATETIME now (after migration).
     */
    $rows = DB::table('from_jnts')
        ->whereBetween('submission_time', [$fromDt, $toDt])
        ->selectRaw("
            COALESCE(sender,'')      as sender,
            COALESCE(item_name,'')   as item_name,
            COALESCE(cod,'')         as cod,
            COUNT(*)                 as quantity,
            MIN(submission_time)     as min_sub,
            MAX(submission_time)     as max_sub,

            SUM(CASE
                WHEN LOWER(status) LIKE '%return%' OR LOWER(status) LIKE '%rts%'
                THEN 1 ELSE 0 END
            ) as rts_count,

            SUM(CASE
                WHEN LOWER(status) LIKE '%deliver%'
                THEN 1 ELSE 0 END
            ) as delivered_count,

            SUM(CASE
                WHEN LOWER(status) LIKE '%problem%'
                THEN 1 ELSE 0 END
            ) as problematic_count,

            SUM(CASE
                WHEN LOWER(status) LIKE '%detain%'
                THEN 1 ELSE 0 END
            ) as detained_count
        ")
        ->groupBy('sender', 'item_name', 'cod')
        ->get();

    $results = collect($rows)->map(function ($r) use ($fmtDate) {
        $total = max(1, (int)$r->quantity);

        $rts        = (int)$r->rts_count;
        $delivered  = (int)$r->delivered_count;
        $problematic= (int)$r->problematic_count;
        $detained   = (int)$r->detained_count;

        $rts_percent       = round(($rts / $total) * 100, 2);
        $delivered_percent = round(($delivered / $total) * 100, 2);
        $transit_percent   = round(max(0, 100 - $rts_percent - $delivered_percent), 2);

        $current_base = $rts + $delivered;
        $current_rts  = $current_base > 0 ? round(($rts / $current_base) * 100, 2) : 'N/A';

        $max_base = $rts + $problematic + $detained + $delivered;
        $max_rts  = $max_base > 0 ? round((($rts + $problematic + $detained) / $max_base) * 100, 2) : 'N/A';

        $dateRange = $fmtDate($r->min_sub) . ' to ' . $fmtDate($r->max_sub);

        return [
            'date_range'        => $dateRange,
            'sender'            => trim((string)$r->sender),
            'item'              => trim((string)$r->item_name),
            'cod'               => trim((string)$r->cod),
            'quantity'          => (int)$r->quantity,
            'rts_percent'       => $rts_percent,
            'delivered_percent' => $delivered_percent,
            'transit_percent'   => $transit_percent,
            'current_rts'       => $current_rts,
            'max_rts'           => $max_rts,
        ];
    });

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
