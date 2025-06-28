<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AdsManager;

class FromAdsManagerController extends Controller
{
    public function store(Request $request)
    {
        $data = json_decode($request->jsonData, true);
        $batches = array_chunk($data, 1000);

        $insertCount = 0;
        $updateCount = 0;

        foreach ($batches as $batch) {
            $keys = collect($batch)->map(function ($row) {
                return $row['page'] . '__' . $row['reporting_starts'];
            });

            $existingRecords = AdsManager::whereIn(
                \DB::raw("CONCAT(page, '__', reporting_starts)"),
                $keys
            )->get()->keyBy(function ($item) {
                return $item->page . '__' . $item->reporting_starts;
            });

            $insertRows = [];

            foreach ($batch as $row) {
                $key = $row['page'] . '__' . $row['reporting_starts'];

                if (isset($existingRecords[$key])) {
                    AdsManager::where('page', $row['page'])
                        ->where('reporting_starts', $row['reporting_starts'])
                        ->update([
                            'amount_spent' => $row['amount_spent'] ?? '',
                            'cpm' => $row['cpm'] ?? '', // cost per message
                            'cpi' => $row['cpi'] ?? '', // cost per 1000 impressions
                            'updated_at' => now(),
                        ]);
                    $updateCount++;
                } else {
                    $insertRows[] = [
                        'reporting_starts' => $row['reporting_starts'] ?? '',
                        'page' => $row['page'] ?? '',
                        'amount_spent' => $row['amount_spent'] ?? '',
                        'cpm' => $row['cpm'] ?? '', // cost per message
                        'cpi' => $row['cpi'] ?? '', // cost per 1000 impressions
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    $insertCount++;
                }
            }

            if (!empty($insertRows)) {
                AdsManager::insert($insertRows);
            }
        }

        return redirect('/ads_manager/index')->with('success', "✅ Upload complete! Inserted: $insertCount | Updated: $updateCount");
    }

    public function view()
    {
        $ads = AdsManager::orderBy('reporting_starts', 'desc')->paginate(1000);
        return view('ads_manager.view', compact('ads'));
    }
    public function updateField(Request $request)
{
    $id = $request->input('id');
    $field = $request->input('field');
    $value = $request->input('value');

    $allowedFields = ['reporting_starts', 'page', 'amount_spent', 'cpm', 'cpi'];

    if (!in_array($field, $allowedFields)) {
        return response()->json(['success' => false, 'message' => 'Invalid field']);
    }

    try {
        AdsManager::where('id', $id)->update([$field => $value]);
        return response()->json(['success' => true]);
    } catch (\Exception $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()]);
    }
}

public function deleteRow(Request $request)
{
    $id = $request->input('id');

    try {
        AdsManager::where('id', $id)->delete();
        return response()->json(['success' => true]);
    } catch (\Exception $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()]);
    }
}


}
