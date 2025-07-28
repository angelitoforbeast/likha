<?php

namespace App\Jobs;

use App\Models\MacroGsheetSetting;
use App\Models\MacroOutput;
use Google_Client;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;
use Google\Service\Sheets\BatchUpdateValuesRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ImportMacroFromGoogleSheet implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
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
                'COD', 'PAGE', 'all_user_input', 'SHOP DETAILS',
                'CXD', 'AI ANALYZE', 'APP SCRIPT CHECKER', 'RESERVE COLUMN',
            ];

            $updates = [];
            $maxImport = 5000;
            $importCount = 0;

            for ($i = 0; $i < count($values); $i++) {
                $row = array_pad($values[$i], 17, null);
                $status = trim($row[16] ?? '');

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

                    $data['PHONE NUMBER'] = Str::limit($data['PHONE NUMBER'], 50);

                    // Extract fb_name from all_user_input
                    $fbName = null;
                    if (!empty($data['all_user_input'])) {
                        preg_match('/FB\s*NAME:\s*(.*?)\r?\n/i', $data['all_user_input'], $matches);
                        $fbName = $matches[1] ?? null;
                    }
                    $data['fb_name'] = $fbName;
                    // Extract fb_page from all_user_input if PAGE is empty
if (empty($data['PAGE']) && !empty($data['all_user_input'])) {
    preg_match('/PAGE:\s*(.*?)(?:\r?\n|$)/i', $data['all_user_input'], $pageMatches);
    $extractedPage = $pageMatches[1] ?? null;
    if ($extractedPage) {
        $data['PAGE'] = $extractedPage;
    }
}


                    // ✅ Parse and normalize TIMESTAMP to Y-m-d H:i:s
    $rawTimestamp = trim($row[0] ?? '');
$timestamp = null;

if (!empty($rawTimestamp)) {
    // Format 1: Already correct → use as is
    if (preg_match('/^\d{2}:\d{2} \d{2}-\d{2}-\d{4}$/', $rawTimestamp)) {
        $timestamp = $rawTimestamp;
    }
    // Format 2: From Y-m-d H:i or Y-m-d H:i:s → convert to H:i d-m-Y
    elseif ($parsed = \DateTime::createFromFormat('Y-m-d H:i:s', $rawTimestamp)) {
        $timestamp = $parsed->format('H:i d-m-Y');
    } elseif ($parsed = \DateTime::createFromFormat('Y-m-d H:i', $rawTimestamp)) {
        $timestamp = $parsed->format('H:i d-m-Y');
    }
    // Format 3: From H:i d-m-Y (single-digit hour) → pad hour
    elseif ($parsed = \DateTime::createFromFormat('G:i d-m-Y', $rawTimestamp)) {
        $timestamp = $parsed->format('H:i d-m-Y');
    }

    elseif ($parsed = \DateTime::createFromFormat('H:i:s d-m-Y', $rawTimestamp)) {
    $timestamp = $parsed->format('H:i d-m-Y');
}

if ($timestamp) {
    $data['TIMESTAMP'] = $timestamp;
}

}



$existing = MacroOutput::where([
    ['TIMESTAMP', '=', $timestamp],
    ['PAGE', '=', $data['PAGE']],
    ['fb_name', '=', $fbName],
])->first();


                    if (!$existing) {
    MacroOutput::create($data);
} else {
    // Skip updating key address-related fields if they already exist in DB
    $protectedFields = ['FULL NAME', 'PHONE NUMBER', 'ADDRESS', 'PROVINCE', 'CITY', 'BARANGAY'];

    foreach ($protectedFields as $field) {
        if (!empty($existing[$field])) {
            unset($data[$field]); // Don't overwrite non-empty existing fields
        }
    }

    $existing->update($data);
}




                    $updates[] = new \Google\Service\Sheets\ValueRange([
                        'range' => "{$sheetName}!Q" . ($startRow + $i),
                        'values' => [['IMPORTED']],
                    ]);

                    $importCount++;
                    if ($importCount >= $maxImport) break;
                }
            }

            if (!empty($updates)) {
                $batchBody = new \Google\Service\Sheets\BatchUpdateValuesRequest([
                    'valueInputOption' => 'RAW',
                    'data' => $updates,
                ]);
                $service->spreadsheets_values->batchUpdate($spreadsheetId, $batchBody);
            }

        } catch (\Exception $e) {
            \Log::error('❌ GSheet Import Failed: ' . $e->getMessage());
        }
    }
}


    private function extractSpreadsheetId($url)
    {
        preg_match('/\/d\/([a-zA-Z0-9-_]+)/', $url, $matches);
        return $matches[1] ?? null;
    }
}
