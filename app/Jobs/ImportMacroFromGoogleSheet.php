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
        set_time_limit(60);

        $settings = MacroGsheetSetting::all();

        $client = new Google_Client();
        $client->setApplicationName('Laravel GSheet');
        $client->setScopes([Sheets::SPREADSHEETS]);
        $client->setAuthConfig(storage_path('app/credentials.json'));
        $client->setAccessType('offline');

        $service = new Sheets($client);

        foreach ($settings as $setting) {
            try {
                $spreadsheetId = $this->extractSpreadsheetId($setting->sheet_url);
                if (!$spreadsheetId) {
                    Log::warning("Skipping setting {$setting->id}: invalid sheet_url");
                    continue;
                }

                $range = trim((string) $setting->sheet_range);
                if ($range === '') {
                    Log::warning("Skipping setting {$setting->id}: empty sheet_range");
                    continue;
                }

                // ✅ Parse range: Sheet!A2:Q (end row optional)
                $parsed = $this->parseA1Range($range);

                $sheetName = $parsed['sheetName'];
                $startRow  = $parsed['startRow'];
                $startCol  = $parsed['startCol'];
                $endCol    = $parsed['endCol'];

                // You said mapping assumes A..P then last col is status/mark
                if ($startCol !== 'A') {
                    Log::warning("Skipping setting {$setting->id}: sheet_range must start at A. Given: {$range}");
                    continue;
                }

                $totalCols   = $this->colCount($startCol, $endCol); // e.g. A..Q = 17
                $statusIndex = $totalCols - 1;                      // last column in the returned row
                $markCol     = $endCol;                             // last column letter = mark column

                // Fetch values
                $response = $service->spreadsheets_values->get($spreadsheetId, $range);
                $values   = $response->getValues();

                if (empty($values)) {
                    continue;
                }

                // Map A..P only (first 16 columns)
                $columnMap = [
                    'TIMESTAMP', 'FULL NAME', 'PHONE NUMBER', 'ADDRESS',
                    'PROVINCE', 'CITY', 'BARANGAY', 'ITEM_NAME',
                    'COD', 'PAGE', 'all_user_input', 'SHOP DETAILS',
                    'CXD', 'AI ANALYZE', 'APP SCRIPT CHECKER', 'RESERVE COLUMN',
                ];

                $updates     = [];
                $maxImport   = 5000;
                $importCount = 0;

                for ($i = 0; $i < count($values); $i++) {
                    // Ensure row has up to endCol
                    $row = array_pad($values[$i], $totalCols, null);

                    // ✅ status = last column of range (dynamic)
                    $status = trim($row[$statusIndex] ?? '');

                    // ✅ Check kung may kahit anong laman sa A–P (first 16 columns)
                    $hasData = false;
                    for ($j = 0; $j <= 15; $j++) {
                        if (!empty(trim($row[$j] ?? ''))) {
                            $hasData = true;
                            break;
                        }
                    }

                    // ✅ Additional rule: dapat may value sa Column O (index 14)
                    $hasColumnO = !empty(trim($row[14] ?? ''));

                    // ✅ Final condition: blank status (last col), may laman A–P, at may laman Column O
                    if ($status === '' && $hasData && $hasColumnO) {
                        $data = [];

                        // Only read A..P from the sheet row
                        foreach ($columnMap as $index => $column) {
                            $data[$column] = $row[$index] ?? null;
                        }

                        // Limit phone number length
                        $data['PHONE NUMBER'] = Str::limit((string)($data['PHONE NUMBER'] ?? ''), 50);

                        // Extract fb_name from all_user_input
                        $fbName = null;
                        if (!empty($data['all_user_input'])) {
                            preg_match('/FB\s*NAME:\s*(.*?)\r?\n/i', $data['all_user_input'], $m);
                            $fbName = $m[1] ?? null;
                        }
                        $data['fb_name'] = $fbName;

                        // Extract fb_page from all_user_input if PAGE is empty
                        if (empty($data['PAGE']) && !empty($data['all_user_input'])) {
                            preg_match('/PAGE:\s*(.*?)(?:\r?\n|$)/i', $data['all_user_input'], $pm);
                            $extractedPage = $pm[1] ?? null;
                            if ($extractedPage) {
                                $data['PAGE'] = $extractedPage;
                            }
                        }

                        // ✅ Parse and normalize TIMESTAMP to H:i d-m-Y
                        $rawTimestamp = trim((string)($row[0] ?? ''));
                        $timestamp    = null;

                        if ($rawTimestamp !== '') {
                            if (preg_match('/^\d{2}:\d{2} \d{2}-\d{2}-\d{4}$/', $rawTimestamp)) {
                                $timestamp = $rawTimestamp;
                            } elseif ($parsedDt = \DateTime::createFromFormat('Y-m-d H:i:s', $rawTimestamp)) {
                                $timestamp = $parsedDt->format('H:i d-m-Y');
                            } elseif ($parsedDt = \DateTime::createFromFormat('Y-m-d H:i', $rawTimestamp)) {
                                $timestamp = $parsedDt->format('H:i d-m-Y');
                            } elseif ($parsedDt = \DateTime::createFromFormat('G:i d-m-Y', $rawTimestamp)) {
                                $timestamp = $parsedDt->format('H:i d-m-Y');
                            } elseif ($parsedDt = \DateTime::createFromFormat('H:i:s d-m-Y', $rawTimestamp)) {
                                $timestamp = $parsedDt->format('H:i d-m-Y');
                            }

                            if ($timestamp) {
                                $data['TIMESTAMP'] = $timestamp;
                            }
                        }

                        // Check if row already exists in MacroOutput (use normalized timestamp)
                        $existing = MacroOutput::where([
                            ['TIMESTAMP', '=', $timestamp],
                            ['PAGE', '=', $data['PAGE']],
                            ['fb_name', '=', $fbName],
                        ])->first();

                        if (!$existing) {
                            MacroOutput::create($data);
                        } else {
                            // Skip updating key address-related fields if they already exist in DB
                            $protectedFields = [
                                'FULL NAME',
                                'PHONE NUMBER',
                                'ADDRESS',
                                'PROVINCE',
                                'CITY',
                                'BARANGAY',
                            ];

                            foreach ($protectedFields as $field) {
                                if (!empty($existing[$field])) {
                                    unset($data[$field]);
                                }
                            }

                            $existing->update($data);
                        }

                        // ✅ Mark as IMPORTED in the LAST column of the given range
                        $updates[] = new ValueRange([
                            'range'  => "{$sheetName}!{$markCol}" . ($startRow + $i),
                            'values' => [['IMPORTED']],
                        ]);

                        $importCount++;
                        if ($importCount >= $maxImport) {
                            break;
                        }
                    }
                }

                if (!empty($updates)) {
                    $batchBody = new BatchUpdateValuesRequest([
                        'valueInputOption' => 'RAW',
                        'data'             => $updates,
                    ]);

                    $service->spreadsheets_values->batchUpdate($spreadsheetId, $batchBody);
                }

            } catch (\Throwable $e) {
                Log::error("❌ GSheet Import Failed (setting {$setting->id}): " . $e->getMessage(), [
                    'setting_id' => $setting->id,
                    'sheet_url'  => $setting->sheet_url,
                    'sheet_range'=> $setting->sheet_range,
                ]);
            }
        }
    }

    private function extractSpreadsheetId($url)
    {
        preg_match('/\/d\/([a-zA-Z0-9-_]+)/', (string)$url, $matches);
        return $matches[1] ?? null;
    }

    private function parseA1Range(string $range): array
    {
        // Supports: Sheet!A2:Q or Sheet!A2:Q9999
        $range = trim($range);

        if (!preg_match('/^([^!]+)!([A-Z]+)(\d+):([A-Z]+)(\d+)?$/i', $range, $m)) {
            throw new \InvalidArgumentException("Invalid sheet_range format: {$range}");
        }

        return [
            'sheetName' => $m[1],
            'startCol'  => strtoupper($m[2]),
            'startRow'  => (int) $m[3],
            'endCol'    => strtoupper($m[4]),
            'endRow'    => isset($m[5]) ? (int) $m[5] : null,
        ];
    }

    private function colToNumber(string $col): int
    {
        $col = strtoupper($col);
        $n = 0;

        for ($i = 0; $i < strlen($col); $i++) {
            $n = $n * 26 + (ord($col[$i]) - ord('A') + 1);
        }

        return $n;
    }

    private function colCount(string $startCol, string $endCol): int
    {
        return $this->colToNumber($endCol) - $this->colToNumber($startCol) + 1;
    }
}
