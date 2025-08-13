<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\FromJnt;
use Carbon\Carbon;

class FromJntController extends Controller
{
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
                'signingtime'        => $row['SigningTime'] ?? '',
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
    public function updateOrInsert(Request $request)
    {
        $data = json_decode($request->jsonData, true);
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
                $newSigningTime = $row['SigningTime'] ?? '';

                if (isset($existingRecords[$waybill])) {
                    $existing = $existingRecords[$waybill];

                    if (!in_array(strtolower($existing->status), ['delivered', 'returned'])) {
                        FromJnt::where('waybill_number', $waybill)->update([
                            'status'      => $newStatus,
                            'signingtime' => $newSigningTime,
                            'updated_at'  => now(),
                        ]);
                    }
                } else {
                    $insertRows[] = [
                        'waybill_number'     => $waybill,
                        'sender'             => $row['Sender'] ?? '',
                        'cod'                => $row['COD'] ?? '',
                        'status'             => $newStatus,
                        'item_name'          => $row['Item Name'] ?? '',
                        'submission_time'    => $row['Submission Time'] ?? '',
                        'receiver'           => $row['Receiver'] ?? '',
                        'receiver_cellphone' => $row['Receiver Cellphone'] ?? '',
                        'signingtime'        => $newSigningTime,
                        'remarks'            => $row['Remarks'] ?? '',
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
}
