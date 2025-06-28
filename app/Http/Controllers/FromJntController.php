<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\FromJnt;
use Illuminate\Pagination\LengthAwarePaginator;

use Illuminate\Support\Collection;

class FromJntController extends Controller
{
    // FROM_JNT: always insert
    public function store(Request $request)
    {
        $data = json_decode($request->jsonData, true);

        foreach ($data as $row) {
            FromJnt::create([
                'sender' => $row['Sender'] ?? '',
                'cod' => $row['COD'] ?? '',
                'status' => $row['Status'] ?? '',
                'item_name' => $row['Item Name'] ?? '',
                'submission_time' => $row['Submission Time'] ?? '',
                'receiver' => $row['Receiver'] ?? '',
                'receiver_cellphone' => $row['Receiver Cellphone'] ?? '',
                'waybill_number' => $row['Waybill Number'] ?? '',
                'signingtime' => $row['SigningTime'] ?? '',
                'remarks' => $row['Remarks'] ?? '',
            ]);
        }

        return redirect()->back()->with('success', 'Data saved to FROM_JNT.');
    }
    												
    public function index()
    {
        //$data = FromJnt::all(); // kunin lahat ng rows
        $data = FromJnt::paginate(10);
        return view('from_jnt_view', compact('data'));
    }




    // JNT_UPDATE: update if exists, else insert
public function updateOrInsert(Request $request)
{
    $data = json_decode($request->jsonData, true);
    $batches = array_chunk($data, 1000); // 1000 rows per batch

    foreach ($batches as $batch) {
        $waybills = array_column($batch, 'Waybill Number');

        // Get all existing records with waybill_number and status
        $existingRecords = FromJnt::whereIn('waybill_number', $waybills)
            ->get()
            ->keyBy('waybill_number');
					

        $insertRows = [];

        foreach ($batch as $row) {
            $waybill = $row['Waybill Number'] ?? '';
												 
										   
            $newStatus = $row['Status'] ?? '';
													   
																   
													 
																		 
            $newSigningTime = $row['SigningTime'] ?? '';
												   
									  
			  

            if (isset($existingRecords[$waybill])) {
                $existing = $existingRecords[$waybill];

                // Only update if current status is NOT Delivered or Returned
                if (!in_array(strtolower($existing->status), ['delivered', 'returned'])) {
                    FromJnt::where('waybill_number', $waybill)->update([
                        'status' => $newStatus,
                        'signingtime' => $newSigningTime,
                        'updated_at' => now(),
                    ]);
                }
            } else {
                // Insert new record
                $insertRows[] = [
                    'waybill_number' => $waybill,
                    'sender' => $row['Sender'] ?? '',
                    'cod' => $row['COD'] ?? '',
                    'status' => $newStatus,
                    'item_name' => $row['Item Name'] ?? '',
                    'submission_time' => $row['Submission Time'] ?? '',
                    'receiver' => $row['Receiver'] ?? '',
                    'receiver_cellphone' => $row['Receiver Cellphone'] ?? '',
                    'signingtime' => $newSigningTime,
                    'remarks' => $row['Remarks'] ?? '',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

								  
        if (!empty($insertRows)) {
            FromJnt::insert($insertRows);
        }
    }

    return redirect()->back()->with('success', 'Database updated via JNT_UPDATE.');
}

public function rtsView()
{
    $empty = collect(); // collection to paginate
    $paginated = new LengthAwarePaginator(
        $empty->forPage(1, 10),
        0,
        10,
        1,
        ['path' => request()->url(), 'query' => request()->query()]
    );

    return view('jnt_rts', ['results' => $paginated]);
}




public function rtsFiltered(Request $request)
{
    $from = $request->input('from');
    $to = $request->input('to');

    $rawData = FromJnt::whereBetween('submission_time', [$from, $to])->get();

    $grouped = $rawData->groupBy(function ($row) {
        return $row->sender . '|' . $row->item_name . '|' . $row->cod;
    });

    $results = collect();

    foreach ($grouped as $key => $rows) {
        $statuses = $rows->groupBy('status')->map->count()->toArray();
        $total = $rows->count();
        $rts = ($statuses['Returned'] ?? 0) + ($statuses['For Return'] ?? 0);
        $delivered = $statuses['Delivered'] ?? 0;
        $problematic = $statuses['Problematic Processing'] ?? 0;
        $detained = $statuses['Detained'] ?? 0;

        $results->push([
            'date_range' => $rows->min('submission_time') . ' to ' . $rows->max('submission_time'),
            'sender' => explode('|', $key)[0],
            'item' => explode('|', $key)[1],
            'cod' => explode('|', $key)[2],
            'quantity' => $total,
            'statuses' => $statuses,
            'rts_percent' => round(($rts / $total) * 100, 2),
            'delivered_percent' => round(($delivered / $total) * 100, 2),
            'transit_percent' => round(100 - (($rts + $delivered) / $total * 100), 2),
            'current_rts' => ($rts + $delivered) > 0 ? round(($rts / ($rts + $delivered)) * 100, 2) : 'N/A',
            'max_rts' => ($rts + $problematic + $detained + $delivered) > 0
                ? round((($rts + $problematic + $detained) / ($rts + $problematic + $detained + $delivered)) * 100, 2)
                : 'N/A',
        ]);
    }

    return view('jnt_rts', [
        'results' => $results,
        'from' => $from,
        'to' => $to,
    ]);
}





}
