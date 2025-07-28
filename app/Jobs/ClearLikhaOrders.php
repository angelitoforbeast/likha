<?php

namespace App\Jobs;

use App\Models\LikhaOrderSetting;
use App\Models\MacroOutput;
use Google_Client;
use Google_Service_Sheets;
use Google_Service_Sheets_ClearValuesRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ClearLikhaOrders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        try {
            // 1. Truncate local table
            MacroOutput::truncate();
            \Log::info('🗑️ Truncated macro_output table.');

            // 2. Setup Google Client
            $client = new Google_Client();
            $client->setAuthConfig(storage_path('app/credentials.json'));
            $client->addScope(Google_Service_Sheets::SPREADSHEETS);
            $service = new Google_Service_Sheets($client);

            // 3. Loop through each sheet setting and clear Column I
            $settings = LikhaOrderSetting::all();
            foreach ($settings as $setting) {
                if (!$setting->sheet_id || !$setting->range) continue;

                $sheetId = $setting->sheet_id;
                $sheetName = explode('!', $setting->range)[0];
                $clearRange = "{$sheetName}!I2:I";

                $clearRequest = new Google_Service_Sheets_ClearValuesRequest();
                $service->spreadsheets_values->clear($sheetId, $clearRange, $clearRequest);

                \Log::info("🧹 Cleared Column I in sheet [$sheetId] range [$clearRange]");
            }

        } catch (\Exception $e) {
            \Log::error('❌ ClearLikhaOrders failed: ' . $e->getMessage());
            \Log::error($e->getTraceAsString());
        }
    }
}
