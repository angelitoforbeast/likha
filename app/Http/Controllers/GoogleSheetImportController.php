<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Google_Client;
use Google_Service_Sheets;
use Google_Service_Sheets_ValueRange;
use Google_Service_Sheets_BatchUpdateValuesRequest;
use Google\Service\Exception as GoogleServiceException;

use App\Models\Fromgsheet;
use App\Models\GsheetSetting;

class GoogleSheetImportController extends Controller
{
    public function import(Request $request)
    {
        if ($request->isMethod('get')) {
            $setting = GsheetSetting::first();
            return view('import_gsheet', compact('setting'));
        }

        try {
            // Get GSheet config
            $setting = GsheetSetting::first();

            if (!$setting || !$setting->sheet_id || !$setting->range) {
                return redirect('/import_gsheet')->with('status', '❌ GSheet ID or range not set in settings.');
            }

            // Setup Google Client
            $client = new \Google_Client();
            $client->setAuthConfig(storage_path('app/banded-arch-432107-c9-bcdb53dc5a49.json'));
            $client->addScope(Google_Service_Sheets::SPREADSHEETS);

            $service = new Google_Service_Sheets($client);

            $spreadsheetId = $setting->sheet_id;
            $range = $setting->range;

            $response = $service->spreadsheets_values->get($spreadsheetId, $range);
            $values = $response->getValues();

            if (empty($values)) {
                return redirect('/import_gsheet')->with('status', '⚠️ No data found.');
            }

            $importedCount = 0;
            $updatedValues = [];

            foreach ($values as $index => $row) {
                if (isset($row[25]) && strtolower(trim($row[25])) === 'done') {
                    continue;
                }

                Fromgsheet::create([
                    'column1' => $row[0] ?? null,
                    'column2' => $row[1] ?? null,
                    'column3' => $row[2] ?? null,
                    'column4' => $row[3] ?? null,
                ]);

                $importedCount++;
                $rowNumber = $index + 2;

                $updatedValues[] = [
                    'range' => "Test!Z{$rowNumber}",
                    'values' => [['DONE']],
                ];
            }

            if (!empty($updatedValues)) {
                $body = new Google_Service_Sheets_BatchUpdateValuesRequest([
                    'valueInputOption' => 'RAW',
                    'data' => array_map(fn($item) => new Google_Service_Sheets_ValueRange($item), $updatedValues),
                ]);
                $service->spreadsheets_values->batchUpdate($spreadsheetId, $body);
            }

            return redirect('/import_gsheet')->with('status', "✅ GSheet imported successfully! {$importedCount} row(s) added.");

        } catch (GoogleServiceException $e) {
            $error = json_decode($e->getMessage(), true);
            $message = $error['error']['message'] ?? 'Unknown Google API error.';
            return redirect('/import_gsheet')->with('status', '❌ Google API Error: ' . $message);
        } catch (\Exception $e) {
            return redirect('/import_gsheet')->with('status', '❌ General Error: ' . $e->getMessage());
        }
    }
}
