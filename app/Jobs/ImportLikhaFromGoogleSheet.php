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

                    // ✅ Parse and normalize TIMESTAMP to "H:i d-m-Y"
                    $rawTimestamp = trim($row[0] ?? '');
                    $timestamp = null;
                    if (!empty($rawTimestamp)) {
                        if (preg_match('/^\d{2}:\d{2} \d{2}-\d{2}-\d{4}$/', $rawTimestamp)) {
                            $timestamp = $rawTimestamp;
                        } elseif ($parsed = \DateTime::createFromFormat('Y-m-d H:i:s', $rawTimestamp)) {
                            $timestamp = $parsed->format('H:i d-m-Y');
                        } elseif ($parsed = \DateTime::createFromFormat('Y-m-d H:i', $rawTimestamp)) {
                            $timestamp = $parsed->format('H:i d-m-Y');
                        } elseif ($parsed = \DateTime::createFromFormat('G:i d-m-Y', $rawTimestamp)) {
                            $timestamp = $parsed->format('H:i d-m-Y');
                        } elseif ($parsed = \DateTime::createFromFormat('H:i:s d-m-Y', $rawTimestamp)) {
                            $timestamp = $parsed->format('H:i d-m-Y');
                        }
                    }

                    // ✅ PAGE (col B) with fallback from col F text ("PAGE: ...")
                    $page = $row[1] ?? null;
                    if (empty($page) && !empty($row[5])) {
                        if (preg_match('/PAGE:\s*(.*?)\s*(?:\r?\n|$)/i', $row[5], $m)) {
                            $page = trim($m[1]);
                        }
                    }

                    // ✅ fb_name / FULL NAME (col C)
                    $fbName = $row[2] ?? '';

                    // ✅ Extract from column F (index 5) for ITEM_NAME and COD
$colF = (string)($row[5] ?? '');
$extractedItemName = null;
$extractedCOD = null;

if ($colF !== '') {
    // ITEM: grab text after "ITEM:" until next line
    $itemText = null;
    if (preg_match('/ITEM:\s*(.+?)(?:\r?\n|$)/i', $colF, $mItem)) {
        $itemText = trim(preg_replace('/\s+/', ' ', $mItem[1]));
    }

    // QUANTITY: grab value after "QUANTITY:" (prefer numeric)
    $qty = null;
    if (preg_match('/QUANTITY:\s*([^\r\n]+)/i', $colF, $mQty)) {
        $rawQty = trim($mQty[1]);
        if (preg_match('/\d+(?:\.\d+)?/', $rawQty, $mNum)) {
            $qty = $mNum[0]; // e.g., "2" or "2.5"
        } else {
            // fallback to the raw (non-numeric) text if any
            $qty = $rawQty !== '' ? $rawQty : null;
        }
    }

    // Build ITEM_NAME: "<qty> x <item>"
    if ($itemText) {
        $extractedItemName = $qty ? ($qty . ' x ' . $itemText) : $itemText;
    }

    // COD: number after "PRICE: ₱"
    if (preg_match('/PRICE:\s*₱\s*([0-9][\d,]*(?:\.\d{1,2})?)/iu', $colF, $mCod)) {
        $extractedCOD = str_replace(',', '', $mCod[1]);
    }
}


                    // ✅ Try match existing row by TIMESTAMP + PAGE + fb_name
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

                        // Fill ALL_USER_INPUT if missing
                        if (empty($existing->{'all_user_input'})) {
                            $updateData['all_user_input'] = "FB NAME: " . ($row[2] ?? '') . "\n" . ($row[4] ?? '');
                        }

                        // Fill PHONE NUMBER if missing
                        if ($existing->{'PHONE NUMBER'} === null || $existing->{'PHONE NUMBER'} === '') {
                            $updateData['PHONE NUMBER'] = preg_match('/09\d{9}/', $row[3] ?? '', $m) ? $m[0] : null;
                        }

                        // ✅ Fill ITEM_NAME if missing
                        if (($existing->{'ITEM_NAME'} ?? null) === null || trim((string)$existing->{'ITEM_NAME'}) === '') {
                            if (!empty($extractedItemName)) {
                                $updateData['ITEM_NAME'] = $extractedItemName;
                            }
                        }

                        // ✅ Fill COD if missing (treat only null/empty as missing; don't overwrite 0)
                        $codCurrent = $existing->{'COD'} ?? null;
                        if ($codCurrent === null || $codCurrent === '') {
                            if (!empty($extractedCOD)) {
                                $updateData['COD'] = $extractedCOD;
                            }
                        }

                        $existing->update($updateData);
                        $updatedCount++;
                    } else {
                        MacroOutput::create([
                            'TIMESTAMP'          => $timestamp,
                            'PAGE'               => $page,
                            'FULL NAME'          => $row[2] ?? null,
                            'fb_name'            => $row[2] ?? null,
                            'PHONE NUMBER'       => preg_match('/09\d{9}/', $row[3] ?? '', $m) ? $m[0] : null,
                            'all_user_input'     => 'FB NAME: ' . ($row[2] ?? '') . "\n" . ($row[4] ?? ''),
                            'shop_details'       => $row[5] ?? null,
                            'extracted_details'  => $row[6] ?? null,
                            // ✅ Also save parsed ITEM_NAME & COD on insert
                            'ITEM_NAME'          => $extractedItemName ?: null,
                            'COD'                => $extractedCOD ?: null,
                        ]);
                        $insertedCount++;
                    }

                    // ✅ Mark as DONE in Google Sheet (col I)
                    $rowNumber = $index + 2; // header at row 1
                    $updates[] = [
                        'range'  => "{$sheetName}!I{$rowNumber}",
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
