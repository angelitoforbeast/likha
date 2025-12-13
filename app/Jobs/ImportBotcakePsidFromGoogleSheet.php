<?php

namespace App\Jobs;

use App\Models\BotcakePsidSetting;
use App\Models\MacroOutput;
use Google\Client as GoogleClient;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;
use Google\Service\Sheets\BatchUpdateValuesRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ImportBotcakePsidFromGoogleSheet implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Cutoff datetime galing sa form (datetime-local string o null)
     * Example: "2025-12-08T18:55"
     */
    protected ?string $cutoffDateTime;

    public function __construct(?string $cutoffDateTime = null)
    {
        $this->cutoffDateTime = $cutoffDateTime;
    }

    public function handle(): void
    {
        $settings = BotcakePsidSetting::all();
        if ($settings->isEmpty()) {
            Log::warning('ImportBotcakePsid: no settings found.');
            return;
        }

        // Google client
        $client = new GoogleClient();
        $client->setApplicationName('Laravel Botcake PSID Import');
        // need write access
        $client->setScopes([Sheets::SPREADSHEETS]);
        $client->setAuthConfig(storage_path('app/credentials.json'));
        $client->setAccessType('offline');

        $service = new Sheets($client);

        foreach ($settings as $setting) {
            try {
                $this->processSetting($service, $setting);
            } catch (\Throwable $e) {
                Log::error(
                    'ImportBotcakePsid: error processing setting ID '
                    . $setting->id . ' - ' . $e->getMessage(),
                    ['trace' => $e->getTraceAsString()]
                );
            }
        }
    }

    protected function extractSpreadsheetId(string $url): ?string
    {
        if (preg_match('/\/d\/([a-zA-Z0-9-_]+)/', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    protected function processSetting(Sheets $service, BotcakePsidSetting $setting): void
    {
        $spreadsheetId = $this->extractSpreadsheetId($setting->sheet_url);
        if (!$spreadsheetId) {
            Log::error('ImportBotcakePsid: invalid sheet URL', ['url' => $setting->sheet_url]);
            return;
        }

        // Kunin sheet name from range, e.g. "PSID!A:J" -> "PSID"
        $range     = $setting->sheet_range;
        $sheetName = strpos($range, '!') !== false ? explode('!', $range)[0] : $range;

        /**
         * Cutoff Carbon (optional)
         */
        $cutoff = null;
        if (!empty($this->cutoffDateTime)) {
            try {
                // galing sa datetime-local → 2025-12-08T18:55
                $cutoff = Carbon::parse($this->cutoffDateTime, 'Asia/Manila');
            } catch (\Throwable $e) {
                Log::warning('ImportBotcakePsid: invalid cutoff datetime, ignoring.', [
                    'cutoff' => $this->cutoffDateTime,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        /**
         * 1) Basahin ang K1 = starting row
         */
        $startRow = 2; // default
        try {
            $k1Resp = $service->spreadsheets_values->get($spreadsheetId, $sheetName . '!K1');
            $valK1  = $k1Resp->getValues()[0][0] ?? null;
            if (!empty($valK1) && is_numeric($valK1) && (int) $valK1 >= 2) {
                $startRow = (int) $valK1;
            }
        } catch (\Throwable $e) {
            Log::warning(
                'ImportBotcakePsid: cannot read K1, using default startRow=2. ' . $e->getMessage()
            );
        }

        /**
         * 2) Basahin A:J simula startRow
         */
        $dataRange = $sheetName . '!A' . $startRow . ':J';
        $resp      = $service->spreadsheets_values->get($spreadsheetId, $dataRange);
        $rows      = $resp->getValues() ?? [];

        if (empty($rows)) {
            Log::info('ImportBotcakePsid: no rows to process (startRow=' . $startRow . ')');
            return;
        }

        $rowNumber      = $startRow;
        $updatedCount   = 0;
        $notExistingCnt = 0;

        // iipunin lahat ng updates para sa column J
        $updates = [];

        foreach ($rows as $row) {
            // kung totally walang laman yung row (lahat blank/null) → skip lang
            if (empty(array_filter($row, fn ($v) => $v !== null && $v !== ''))) {
                $rowNumber++;
                continue;
            }

            /**
             * FILTER BY DATE (Column D) – On or Before cutoff
             * Col D index = 3
             */
            if ($cutoff) {
                $dateStr  = trim($row[3] ?? ''); // DATE column
                $cellDate = null;

                if ($dateStr !== '') {
                    // support H:i:s d-m-Y, H:i d-m-Y, G:i d-m-Y
                    $formats = ['H:i:s d-m-Y', 'H:i d-m-Y', 'G:i d-m-Y'];
                    foreach ($formats as $fmt) {
                        $dt = \DateTime::createFromFormat($fmt, $dateStr);
                        if ($dt !== false) {
                            $cellDate = Carbon::instance($dt);
                            break;
                        }
                    }
                }

                // kung may cutoff at may parsed date at mas LATE kaysa cutoff → SKIP (wag pa i-import)
                if ($cellDate && $cellDate->gt($cutoff)) {
                    $rowNumber++;
                    continue;
                }
                // kung hindi ma-parse yung date, pwede rin natin i-skip
                if ($dateStr !== '' && !$cellDate) {
                    // di ma-parse, treat as skip para ma-review mo manually later
                    $rowNumber++;
                    continue;
                }
            }

            $pageName = trim($row[0] ?? ''); // Col A = PAGE NAME
            $fullName = trim($row[1] ?? ''); // Col B = FULL NAME
            $psid     = trim($row[2] ?? ''); // Col C = PSID

            // default status
            $statusText = 'not existing';

            if ($pageName !== '' && $fullName !== '' && $psid !== '') {
                // hanapin sa macro_output by PAGE + fb_name
                $query = MacroOutput::where('PAGE', $pageName)
                    ->where('fb_name', $fullName);

                if ($query->exists()) {
                    $query->update(['botcake_psid' => $psid]);
                    $statusText = 'imported';
                    $updatedCount++;
                } else {
                    $notExistingCnt++;
                }
            } else {
                // kulang data
                $notExistingCnt++;
            }

            // queue write sa J{rowNumber}
            $updates[] = new ValueRange([
                'range'  => $sheetName . '!J' . $rowNumber,
                'values' => [[$statusText]],
            ]);

            $rowNumber++;
        }

        /**
         * 3) Isang batchUpdate lang para sa lahat ng J cells
         */
        if (!empty($updates)) {
            try {
                $batchBody = new BatchUpdateValuesRequest([
                    'valueInputOption' => 'RAW',
                    'data'             => $updates,
                ]);

                $service->spreadsheets_values->batchUpdate($spreadsheetId, $batchBody);
            } catch (\Throwable $e) {
                Log::error(
                    'ImportBotcakePsid: batchUpdate failed - ' . $e->getMessage(),
                    ['trace' => $e->getTraceAsString()]
                );
            }
        }

        Log::info('ImportBotcakePsid: done for setting ID ' . $setting->id, [
            'updated'      => $updatedCount,
            'not_existing' => $notExistingCnt,
            'start_row'    => $startRow,
            'cutoff'       => $this->cutoffDateTime,
        ]);
    }
}
