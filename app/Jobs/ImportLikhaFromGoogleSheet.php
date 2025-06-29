<?php

namespace App\Jobs;

use App\Models\LikhaOrder;
use App\Models\LikhaOrderSetting;
use Google_Client;
use Google_Service_Sheets;
use Google_Service_Sheets_ValueRange;
use Google_Service_Sheets_BatchUpdateValuesRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
\Log::info("🚀 ImportLikhaFromGoogleSheet job started.");
class ImportLikhaFromGoogleSheet implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        $setting = LikhaOrderSetting::first();

        if (!$setting || !$setting->sheet_id || !$setting->range) {
            throw new \Exception('Missing sheet ID or range in settings.');
        }

        $sheetId = $setting->sheet_id;
        $range = $setting->range;
        $sheetName = explode('!', $range)[0];

        $client = new Google_Client();
        $client->setAuthConfig(storage_path('app/credentials.json'));
        $client->addScope(Google_Service_Sheets::SPREADSHEETS);
        $service = new Google_Service_Sheets($client);

        $response = $service->spreadsheets_values->get($sheetId, $range);
        $values = $response->getValues();

        \Log::info("📥 Fetched " . count($values) . " rows from Google Sheet.");

        if (empty($values)) return;

        $updates = [];
        $importedCount = 0;
        $skippedCount = 0;
        $firstImportedRow = null;

        foreach ($values as $index => $row) {
            $rawDone = $row[8] ?? '';
            $doneFlag = strtolower(preg_replace('/\s+/', '', $rawDone));

            \Log::info("Row {$index} raw DONE value: [" . $rawDone . "] → cleaned: [" . $doneFlag . "]");

            if ($doneFlag === 'done') {
                \Log::info("⏭️ Skipping row {$index} — marked as DONE.");
                $skippedCount++;
                continue;
            }

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
                'range' => "{$sheetName}!I{$rowNumber}",
                'values' => [['DONE']],
            ];

            if ($firstImportedRow === null) {
                $firstImportedRow = $rowNumber;
            }

            \Log::info("✅ Imported row {$rowNumber}");
            $importedCount++;
        }

        if (!empty($updates)) {
            $batchBody = new Google_Service_Sheets_BatchUpdateValuesRequest([
                'valueInputOption' => 'RAW',
                'data' => array_map(fn($data) => new Google_Service_Sheets_ValueRange($data), $updates),
            ]);

            $service->spreadsheets_values->batchUpdate($sheetId, $batchBody);
        }

        \Log::info("✅ Import complete: {$importedCount} imported, {$skippedCount} skipped.");
        if ($firstImportedRow !== null) {
            \Log::info("📌 First imported row: Row {$firstImportedRow}");
        } else {
            \Log::info("ℹ️ No new rows were imported.");
        }
    }
}