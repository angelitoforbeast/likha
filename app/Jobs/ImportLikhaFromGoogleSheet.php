<?php

namespace App\Jobs;

use App\Models\MacroOutput;
use App\Models\LikhaOrderSetting;
use App\Models\ImportStatus;
use Google_Client;
use Google_Service_Sheets;
use Google_Service_Sheets_ValueRange;
use Google_Service_Sheets_BatchUpdateValuesRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ImportLikhaFromGoogleSheet implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        try {
            $updatedCount = 0;
            $insertedCount = 0;
            $client = new Google_Client();
            $client->setAuthConfig(storage_path('app/credentials.json'));
            $client->addScope(Google_Service_Sheets::SPREADSHEETS);
            $service = new Google_Service_Sheets($client);



            $settings = LikhaOrderSetting::all();
            foreach ($settings as $setting) {
                if (!$setting->sheet_id || !$setting->range) continue;

                $sheetId = $setting->sheet_id;
                $range = $setting->range;
                $sheetName = explode('!', $range)[0];

                $response = $service->spreadsheets_values->get($sheetId, $range);
                $values = $response->getValues();
                if (empty($values)) continue;

                \Log::info("📥 [$sheetId] Fetched " . count($values) . " rows");

                $updates = [];
                foreach ($values as $index => $row) {
    $doneFlag = strtolower(preg_replace('/\s+/', '', $row[8] ?? ''));
    if ($doneFlag === 'done') continue;

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

}



    $page = $row[1] ?? null;

if (empty($page) && !empty($row[5])) {
    if (preg_match('/PAGE:\s*(.*?)\s*(?:\n|$)/i', $row[5], $matches)) {
        $page = trim($matches[1]);
    }
}


    // ✅ Extract fb_name from "FB NAME: ___" in all_user_input
    $fbName = $row[2] ?? '';

    // ✅ Match existing entry by normalized TIMESTAMP + PAGE + fb_name
        $existing = MacroOutput::where([
    ['TIMESTAMP', '=', $timestamp],
    ['PAGE', '=', $page],
    ['fb_name', '=', $fbName],
])->first();

    if ($existing) {
        $updateData = [
    'shop_details'      => $row[5] ?? null,
    'extracted_details' => $row[6] ?? null,
];

if (empty($existing->{'all_user_input'})) {
    $updateData['all_user_input'] = "FB NAME: " . ($row[2] ?? '') . "\n" . ($row[4] ?? '');
}

if (empty($existing->{'PHONE NUMBER'})) {
    $updateData['PHONE NUMBER'] = preg_match('/09\d{9}/', $row[3] ?? '', $matches) ? $matches[0] : null;
}


        $updatedCount++;
        $existing->update($updateData);
    } else {
        MacroOutput::create([
            'TIMESTAMP'          => $timestamp,
            'PAGE'               => $page,
            'FULL NAME'          => $row[2] ?? null,
            'fb_name'            => $row[2] ?? null,
            'PHONE NUMBER' => preg_match('/09\d{9}/', $row[3] ?? '', $matches) ? $matches[0] : null,
            'all_user_input'     => 'FB NAME: ' . ($row[2] ?? '') . "\n" . ($row[4] ?? ''),
            'shop_details'       => $row[5] ?? null,
            'extracted_details'  => $row[6] ?? null,
        ]);
        $insertedCount++;
    }

    // ✅ Mark as DONE in Google Sheet
    $rowNumber = $index + 2;
    $updates[] = [
        'range' => "{$sheetName}!I{$rowNumber}",
        'values' => [['DONE']],
    ];
}




                if (!empty($updates)) {
                    $batchBody = new Google_Service_Sheets_BatchUpdateValuesRequest([
                        'valueInputOption' => 'RAW',
                        'data' => array_map(fn($data) => new Google_Service_Sheets_ValueRange($data), $updates),
                    ]);
                    $service->spreadsheets_values->batchUpdate($sheetId, $batchBody);
                }
            }

            \Cache::put('likha_import_result', "✅ Import complete! Inserted: $insertedCount, Updated: $updatedCount", now()->addMinutes(10));



\Log::info("✅ ImportLikhaFromGoogleSheet Done: Inserted={$insertedCount}, Updated={$updatedCount}");



        } catch (\Exception $e) {
            \Log::error('❌ ImportLikhaFromGoogleSheet failed: ' . $e->getMessage());
            \Log::error($e->getTraceAsString());
        }
    }
}
