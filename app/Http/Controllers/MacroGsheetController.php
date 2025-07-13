<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MacroGsheetSetting;
use App\Models\MacroOutput;
use Google_Client;
use Google\Service\Sheets;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MacroGsheetController extends Controller
{
    public function import(Request $request)
{
    set_time_limit(60); // Allow longer execution

    $settings = MacroGsheetSetting::all();

    $client = new \Google_Client();
    $client->setApplicationName('Laravel GSheet');
    $client->setScopes([\Google\Service\Sheets::SPREADSHEETS]);
    $client->setAuthConfig(storage_path('app/credentials.json'));
    $client->setAccessType('offline');

    $service = new \Google\Service\Sheets($client);

    foreach ($settings as $setting) {
        $spreadsheetId = $this->extractSpreadsheetId($setting->sheet_url);
        $range = $setting->sheet_range;

        // Get sheet name and start row (e.g. from "DATABASE!A2")
        preg_match('/!([A-Z]+)(\d+)/', $range, $matches);
        $sheetName = explode('!', $range)[0];
        $startRow = isset($matches[2]) ? intval($matches[2]) : 2;

        try {
            $response = $service->spreadsheets_values->get($spreadsheetId, $range);
            $values = $response->getValues();

            if (empty($values)) continue;

            $columnMap = [
                'TIMESTAMP', 'FULL NAME', 'PHONE NUMBER', 'ADDRESS',
                'PROVINCE', 'CITY', 'BARANGAY', 'ITEM_NAME',
                'COD', 'PAGE', 'ALL USER INPUT', 'SHOP DETAILS',
                'CXD', 'AI ANALYZE', 'HUMAN CHECKER STATUS', 'RESERVE COLUMN',
                'STATUS'
            ];

            $rowsToImport = [];
            $updates = [];
            $maxImport = 1000;
            $importCount = 0;

            for ($i = 0; $i < count($values); $i++) {
                $row = array_pad($values[$i], 17, null); // Pad to 17 columns (Q = index 16)
                $status = trim($row[16] ?? '');

                // Check if A–P has any data
                $hasData = false;
                for ($j = 0; $j <= 15; $j++) {
                    if (!empty(trim($row[$j] ?? ''))) {
                        $hasData = true;
                        break;
                    }
                }

                if ($status === '' && $hasData) {
                    $data = [];
                    foreach ($columnMap as $index => $column) {
                        $data[$column] = $row[$index] ?? null;
                    }

                    $data['PHONE NUMBER'] = \Str::limit($data['PHONE NUMBER'], 50);
                    $rowsToImport[] = $data;

                    // Mark as IMPORTED (write to correct Q row)
                    $updates[] = new \Google\Service\Sheets\ValueRange([
                        'range' => "{$sheetName}!Q" . ($startRow + $i),
                        'values' => [['IMPORTED']],
                    ]);

                    $importCount++;
                    if ($importCount >= $maxImport) break;
                }
            }

            // Save to DB
            foreach ($rowsToImport as $data) {
                MacroOutput::create($data);
            }

            // Update Google Sheet
            if (!empty($updates)) {
                $batchBody = new \Google\Service\Sheets\BatchUpdateValuesRequest([
                    'valueInputOption' => 'RAW',
                    'data' => $updates,
                ]);
                $service->spreadsheets_values->batchUpdate($spreadsheetId, $batchBody);
            }

        } catch (\Exception $e) {
            return back()->with('error', '❌ Error: ' . $e->getMessage());
        }
    }

    return back()->with('success', "✅ Imported $importCount rows successfully.");
}



    private function extractSpreadsheetId($url)
    {
        preg_match('/\/d\/([a-zA-Z0-9-_]+)/', $url, $matches);
        return $matches[1] ?? null;
    }

    public function update(Request $request, $id)
{
    $request->validate([
        'sheet_url' => 'required|url',
        'sheet_range' => 'required|string',
    ]);

    $sheetId = $this->extractSpreadsheetId($request->sheet_url);
    if (!$sheetId) {
        return back()->with('error', 'Invalid Google Sheet URL.');
    }

    // Fetch actual GSheet name (title)
    try {
        $client = new \Google_Client();
        $client->setApplicationName('Laravel GSheet');
        $client->setScopes([\Google\Service\Sheets::SPREADSHEETS_READONLY]);
        $client->setAuthConfig(storage_path('app/credentials.json'));
        $client->setAccessType('offline');

        $service = new \Google\Service\Sheets($client);
        $sheetMetadata = $service->spreadsheets->get($sheetId);
        $actualName = $sheetMetadata->getProperties()->getTitle();

    } catch (\Exception $e) {
        return back()->with('error', 'Failed to fetch sheet name: ' . $e->getMessage());
    }

    // Update in DB
    $setting = MacroGsheetSetting::findOrFail($id);
    $setting->update([
        'gsheet_name' => $actualName,
        'sheet_url' => $request->sheet_url,
        'sheet_range' => $request->sheet_range,
    ]);

    return back()->with('success', 'Setting updated and name synced from GSheet.');
}



    public function settings()
    {
        $settings = MacroGsheetSetting::all();
        return view('macro.gsheet.settings', compact('settings'));
    }

    public function storeSetting(Request $request)
{
    $request->validate([
        'sheet_url' => 'required|url',
        'sheet_range' => 'required|string',
    ]);

    $sheetId = $this->extractSpreadsheetId($request->sheet_url);
    if (!$sheetId) {
        return back()->with('error', 'Invalid Google Sheet URL.');
    }

    // Fetch the actual sheet name from Google Sheets
    try {
        $client = new \Google_Client();
        $client->setApplicationName('Laravel GSheet');
        $client->setScopes([\Google\Service\Sheets::SPREADSHEETS_READONLY]);
        $client->setAuthConfig(storage_path('app/credentials.json'));
        $client->setAccessType('offline');

        $service = new \Google\Service\Sheets($client);
        $sheetMetadata = $service->spreadsheets->get($sheetId);
        $actualName = $sheetMetadata->getProperties()->getTitle();

    } catch (\Exception $e) {
        return back()->with('error', 'Failed to fetch sheet name: ' . $e->getMessage());
    }

    // Save to DB
    MacroGsheetSetting::create([
        'gsheet_name' => $actualName,
        'sheet_url' => $request->sheet_url,
        'sheet_range' => $request->sheet_range,
    ]);

    return back()->with('success', 'New setting saved and sheet name synced.');
}



    public function deleteSetting($id)
    {
        MacroGsheetSetting::findOrFail($id)->delete();
        return back()->with('success', 'Setting deleted.');
    }

    public function index()
{
    $records = MacroOutput::latest()->paginate(500);
    $totalCount = MacroOutput::count();

    return view('macro.gsheet.index', compact('records', 'totalCount'));
}


    public function deleteAll()
    {
        MacroOutput::truncate();
        return back()->with('success', 'All records deleted successfully.');
    }
}
