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

    private const BATCH_SIZE = 1000;

    public int $timeout = 1200; // 20 minutes

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

        $client = new GoogleClient();
        $client->setApplicationName('Laravel Botcake PSID Import');
        $client->setScopes([Sheets::SPREADSHEETS]); // read/write
        $client->setAuthConfig(storage_path('app/credentials.json'));
        $client->setAccessType('offline');

        $service = new Sheets($client);

        foreach ($settings as $setting) {
            try {
                $this->processSettingInBatches($service, $setting);
            } catch (\Throwable $e) {
                Log::error(
                    'ImportBotcakePsid: error processing setting ID ' . $setting->id . ' - ' . $e->getMessage(),
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

    /** normalize spaces + trim */
    protected function norm(?string $s): string
    {
        $s = (string)($s ?? '');
        $s = preg_replace('/\s+/', ' ', trim($s));
        return $s;
    }

    protected function lower(string $s): string
    {
        return mb_strtolower($s, 'UTF-8');
    }

    protected function processSettingInBatches(Sheets $service, BotcakePsidSetting $setting): void
    {
        $spreadsheetId = $this->extractSpreadsheetId($setting->sheet_url);
        if (!$spreadsheetId) {
            Log::error('ImportBotcakePsid: invalid sheet URL', ['url' => $setting->sheet_url]);
            return;
        }

        $range     = $setting->sheet_range;
        $sheetName = strpos($range, '!') !== false ? explode('!', $range)[0] : $range;

        // ✅ Cutoff (optional)
        $cutoff = null;
        if (!empty($this->cutoffDateTime)) {
            try {
                $cutoff = Carbon::parse($this->cutoffDateTime, 'Asia/Manila');
            } catch (\Throwable $e) {
                Log::warning('ImportBotcakePsid: invalid cutoff datetime, ignoring.', [
                    'cutoff' => $this->cutoffDateTime,
                    'error'  => $e->getMessage(),
                ]);
                $cutoff = null;
            }
        }

        /**
         * ✅ START ROW SOURCE:
         * 1) L1 = progress cursor (job writes here)
         * 2) else K1 = reference (ex: first blank count)
         * 3) else 2
         */
        $startRow = 2;
        try {
            // L1 first
            $l1Resp = $service->spreadsheets_values->get($spreadsheetId, $sheetName . '!L1');
            $valL1  = $l1Resp->getValues()[0][0] ?? null;

            if (!empty($valL1) && is_numeric($valL1) && (int)$valL1 >= 2) {
                $startRow = (int)$valL1;
            } else {
                // fallback to K1 (reference)
                $k1Resp = $service->spreadsheets_values->get($spreadsheetId, $sheetName . '!K1');
                $valK1  = $k1Resp->getValues()[0][0] ?? null;
                if (!empty($valK1) && is_numeric($valK1) && (int)$valK1 >= 2) {
                    $startRow = (int)$valK1;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('ImportBotcakePsid: cannot read L1/K1, using default startRow=2. ' . $e->getMessage());
        }

        $totalUpdated = 0;
        $totalNotExisting = 0;
        $batchNo = 0;

        while (true) {
            $batchNo++;

            $endRow = $startRow + self::BATCH_SIZE - 1;

            // Read A:J for this batch window only
            $dataRange = $sheetName . '!A' . $startRow . ':J' . $endRow;

            $resp = $service->spreadsheets_values->get($spreadsheetId, $dataRange);
            $rows = $resp->getValues() ?? [];

            if (empty($rows)) {
                Log::info('ImportBotcakePsid: no rows returned, stopping.', [
                    'setting_id' => $setting->id,
                    'start_row'  => $startRow,
                    'range'      => $dataRange,
                    'cutoff'     => $this->cutoffDateTime,
                ]);
                break;
            }

            $rowCount     = count($rows);
            $batchEndRow  = $startRow + $rowCount - 1;

            $statuses     = []; // values for J{startRow}:J{batchEndRow}

            $updatedCount = 0;
            $notExistingCnt = 0;

            foreach ($rows as $row) {
                // Ensure indexes up to J exist (A..J = 10 cols)
                $row = array_pad($row, 10, '');

                // Preserve existing J by default
                $existingStatus = trim((string)($row[9] ?? '')); // J
                $statusToWrite  = $existingStatus;

                // Skip totally blank row (do not overwrite J)
                $hasAny = false;
                foreach ($row as $v) {
                    if ($v !== null && $v !== '') { $hasAny = true; break; }
                }
                if (!$hasAny) {
                    $statuses[] = [$statusToWrite];
                    continue;
                }

                // Date filter (Column D index=3) ON/BEFORE cutoff
                if ($cutoff) {
                    $dateStr  = trim((string)($row[3] ?? '')); // D
                    $cellDate = null;

                    if ($dateStr !== '') {
                        $cellDate = $this->parseCellDateTime($dateStr);
                    }

                    // If parsed and later than cutoff -> SKIP & keep J unchanged
                    if ($cellDate && $cellDate->gt($cutoff)) {
                        $statuses[] = [$statusToWrite];
                        continue;
                    }

                    // If non-empty date but unparseable -> SKIP & keep J unchanged
                    if ($dateStr !== '' && !$cellDate) {
                        $statuses[] = [$statusToWrite];
                        continue;
                    }
                }

                // Data columns
                $pageName = $this->norm((string)($row[0] ?? '')); // A = PAGE NAME
                $fullName = $this->norm((string)($row[1] ?? '')); // B = FULL NAME
                $psid     = $this->norm((string)($row[2] ?? '')); // C = PSID

                $statusText = 'not existing';

                if ($pageName !== '' && $fullName !== '' && $psid !== '') {

                    // ✅ 1) FAST PATH (uses your existing index PAGE + fb_name)
                    $affected = MacroOutput::where('PAGE', $pageName)
                        ->where('fb_name', $fullName)
                        ->update(['botcake_psid' => $psid]);

                    // ✅ 2) FALLBACK only if not existing (case-insensitive)
                    if ($affected === 0) {
                        $affected = MacroOutput::whereRaw('LOWER("PAGE") = ?', [$this->lower($pageName)])
                            ->whereRaw('LOWER("fb_name") = ?', [$this->lower($fullName)])
                            ->update(['botcake_psid' => $psid]);
                    }

                    if ($affected > 0) {
                        $statusText = 'imported';
                        $updatedCount++;
                    } else {
                        $notExistingCnt++;
                    }
                } else {
                    $notExistingCnt++;
                }

                $statuses[] = [$statusText];
            }

            // Batch write:
            // - J range values for this batch
            // - update L1 cursor to next start row (K1 is untouched reference)
            try {
                $updates = [
                    new ValueRange([
                        'range'  => $sheetName . '!J' . $startRow . ':J' . $batchEndRow,
                        'values' => $statuses,
                    ]),
                    new ValueRange([
                        'range'  => $sheetName . '!L1',
                        'values' => [[(string)($batchEndRow + 1)]],
                    ]),
                ];

                $batchBody = new BatchUpdateValuesRequest([
                    'valueInputOption' => 'RAW',
                    'data'             => $updates,
                ]);

                $service->spreadsheets_values->batchUpdate($spreadsheetId, $batchBody);
            } catch (\Throwable $e) {
                Log::error('ImportBotcakePsid: batchUpdate failed - ' . $e->getMessage(), [
                    'setting_id' => $setting->id,
                    'start_row'  => $startRow,
                    'end_row'    => $batchEndRow,
                    'trace'      => $e->getTraceAsString(),
                ]);
                break;
            }

            $totalUpdated     += $updatedCount;
            $totalNotExisting += $notExistingCnt;

            Log::info('ImportBotcakePsid: batch done', [
                'setting_id'     => $setting->id,
                'batch_no'       => $batchNo,
                'start_row'      => $startRow,
                'end_row'        => $batchEndRow,
                'rows_in_batch'  => $rowCount,
                'updated'        => $updatedCount,
                'not_existing'   => $notExistingCnt,
                'cutoff'         => $this->cutoffDateTime,
            ]);

            // Move to next batch (cursor already written to L1)
            $startRow = $batchEndRow + 1;

            // If returned fewer than batch size, likely end
            if ($rowCount < self::BATCH_SIZE) {
                break;
            }
        }

        Log::info('ImportBotcakePsid: finished setting', [
            'setting_id'         => $setting->id,
            'total_updated'      => $totalUpdated,
            'total_not_existing' => $totalNotExisting,
            'cutoff'             => $this->cutoffDateTime,
        ]);
    }

    /**
     * Parse Column D datetime string into Carbon (Asia/Manila).
     * Supports:
     *  - H:i:s d-m-Y, H:i d-m-Y, G:i d-m-Y, G:i:s d-m-Y
     *  - Y-m-d, Y-m-d H:i, Y-m-d H:i:s, Y-m-d G:i, Y-m-d G:i:s
     *  - numeric serial like 45683 or 45683.75 (Sheets date serial)
     */
    protected function parseCellDateTime(string $dateStr): ?Carbon
    {
        $dateStr = trim($dateStr);
        if ($dateStr === '') return null;

        // numeric date serial (Sheets/Excel)
        if (is_numeric($dateStr)) {
            $n = (float)$dateStr;
            if ($n > 20000 && $n < 80000) {
                $unixSeconds = (int)round(($n - 25569) * 86400);
                return Carbon::createFromTimestamp($unixSeconds, 'Asia/Manila');
            }
        }

        $formats = [
            // d-m-Y with time
            'H:i:s d-m-Y',
            'H:i d-m-Y',
            'G:i d-m-Y',
            'G:i:s d-m-Y',

            // Y-m-d variants
            'Y-m-d',
            'Y-m-d H:i',
            'Y-m-d G:i',
            'Y-m-d H:i:s',
            'Y-m-d G:i:s',
        ];

        foreach ($formats as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $dateStr);
            if ($dt !== false) {
                return Carbon::instance($dt)->setTimezone('Asia/Manila');
            }
        }

        return null;
    }
}
