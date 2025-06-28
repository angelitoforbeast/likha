<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Google_Client;
use Google_Service_Sheets;
use Google_Service_Sheets_ValueRange;
use Google_Service_Sheets_BatchUpdateValuesRequest;

use App\Models\LikhaOrder;
use App\Models\LikhaOrderSetting; // or LikhaOrderSetting if you're using a separate table

class LikhaOrderImportController extends Controller
{
    public function import(Request $request)
    {
        if ($request->isMethod('get')) {
            $setting = LikhaOrderSetting::first();
            return view('likha_order.import', compact('setting'));
        }

        try {
            $setting = LikhaOrderSetting::first(); // Replace with LikhaOrderSetting::first() if separated

            if (!$setting || !$setting->sheet_id || !$setting->range) {
                return redirect('/likha_order_import')->with('status', '❌ Missing sheet ID or range in settings.');
            }

            $sheetId = $setting->sheet_id;
            $range = $setting->range;

            // Extract sheet name from range (e.g., 'Likha!A2:H' => 'Likha')
            $sheetName = explode('!', $range)[0];

            $client = new Google_Client();
            $client->setAuthConfig(storage_path('app/credentials.json'));
            $client->addScope(Google_Service_Sheets::SPREADSHEETS);
            $service = new Google_Service_Sheets($client);

            $response = $service->spreadsheets_values->get($sheetId, $range);
            $values = $response->getValues();

            if (empty($values)) {
                return redirect('/likha_order_import')->with('status', '⚠️ No data found in the specified range.');
            }

            $importedCount = 0;
            $updates = [];

            foreach ($values as $index => $row) {
                if (isset($row[8]) && strtolower(trim($row[8])) === 'done') continue;

                LikhaOrder::create([
                    'date' => isset($row[0]) ? date('Y-m-d', strtotime($row[0])) : null,
                    'page_name' => $row[1] ?? null,
                    'name' => $row[2] ?? null,
                    'phone_number' => $row[3] ?? null,
                    'all_user_input' => $row[4] ?? null,
                    'shop_details' => $row[5] ?? null,
                    'extracted_details' => $row[6] ?? null,
                    'price' => $row[7] ?? null,
                ]);

                $rowNumber = $index + 2;
                $updates[] = [
                    'range' => "{$sheetName}!I{$rowNumber}", // Column I = 9th column = DONE
                    'values' => [['DONE']],
                ];

                $importedCount++;
            }

            if (!empty($updates)) {
                $batchBody = new Google_Service_Sheets_BatchUpdateValuesRequest([
                    'valueInputOption' => 'RAW',
                    'data' => array_map(fn($data) => new Google_Service_Sheets_ValueRange($data), $updates),
                ]);

                $service->spreadsheets_values->batchUpdate($sheetId, $batchBody);
            }

            return redirect('/likha_order_import')->with('status', "✅ Successfully imported {$importedCount} row(s).");

        } catch (\Exception $e) {
            return redirect('/likha_order_import')->with('status', '❌ Error: ' . $e->getMessage());
        }
    }

public function view(Request $request)
{
    $query = \App\Models\LikhaOrder::query();

    if ($request->filled('search')) {
        $search = $request->input('search');
        $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%$search%")
              ->orWhere('phone_number', 'like', "%$search%")
              ->orWhere('page_name', 'like', "%$search%");
        });
    }

    if ($request->filled('date')) {
        $query->whereDate('date', $request->input('date'));
    }

    if ($request->filled('page_name')) {
        $query->where('page_name', $request->input('page_name'));
    }

    $orders = $query->latest()->paginate(100);
    $pages = \App\Models\LikhaOrder::select('page_name')->distinct()->pluck('page_name');

    return view('likha_order.view', compact('orders', 'pages'));
}



}
