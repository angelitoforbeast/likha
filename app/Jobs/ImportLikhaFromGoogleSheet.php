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
use App\Models\ImportStatus;

class ImportLikhaFromGoogleSheet implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        $client = new Google_Client();
        $client->setAuthConfig(storage_path('app/credentials.json'));
        $client->addScope(Google_Service_Sheets::SPREADSHEETS);
        $service = new Google_Service_Sheets($client);

        ImportStatus::updateOrCreate(['job_name' => 'LikhaImport'], ['is_complete' => false]);

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

                LikhaOrder::create([
                    'date' => isset($row[0]) ? date('Y-m-d', strtotime($row[0])) : null,
                    'page_name' => $row[1] ?? null,
                    'name' => $row[2] ?? null,
                    'phone_number' => isset($row[3]) ? substr(preg_replace('/[^\d+]/', '', $row[3]), 0, 50) : null,
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
            }

            if (!empty($updates)) {
                $batchBody = new Google_Service_Sheets_BatchUpdateValuesRequest([
                    'valueInputOption' => 'RAW',
                    'data' => array_map(fn($data) => new Google_Service_Sheets_ValueRange($data), $updates),
                ]);
                $service->spreadsheets_values->batchUpdate($sheetId, $batchBody);
            }
        }

        ImportStatus::where('job_name', 'LikhaImport')->update(['is_complete' => true]);
    }
}
