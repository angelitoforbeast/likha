<?php

namespace App\Jobs;

use App\Models\BotcakePsidSetting;
use App\Models\MacroOutput;
use App\Models\BotcakePsidImportRun;
use App\Models\BotcakePsidImportEvent;
use Carbon\Carbon;
use Google\Client as GoogleClient;
use Google\Service\Sheets;
use Google\Service\Sheets\BatchUpdateValuesRequest;
use Google\Service\Sheets\ValueRange;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportBotcakePsidFromGoogleSheet implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const BATCH_SIZE      = 500;
    private const SCAN_CHUNK_SIZE = 500;

    public int $timeout = 1200; // 20 minutes

    protected ?string $cutoffDateTime;
    protected ?int $runId;

    public function __construct(?string $cutoffDateTime = null, ?int $runId = null)
    {
        $this->cutoffDateTime = $cutoffDateTime;
        $this->runId = $runId;
    }

    public function handle(): void
    {
        $run = $this->getRun();

        try {
            if ($run) {
                $run->update([
                    'status'       => 'running',
                    'last_message' => 'Starting...',
                    'last_error'   => null,
                ]);
            }

            $settings = BotcakePsidSetting::all();
            if ($settings->isEmpty()) {
                Log::warning('ImportBotcakePsid: no settings found.');
                $this->updateRun([
                    'status' => 'done',
                    'last_message' => 'No settings found.',
                ]);
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
                    $this->updateRun([
                        'current_setting_id'  => $setting->id,
                        'current_gsheet_name' => $setting->gsheet_name ?? null,
                        'last_message'        => 'Processing setting #' . $setting->id,
                    ]);

                    $this->addEvent([
                        'type'       => 'setting_start',
                        'setting_id' => $setting->id,
                        'gsheet_name'=> $setting->gsheet_name ?? null,
                        'message'    => 'Setting started',
                    ]);

                    $this->processSettingInBatches($service, $setting);

                    $this->addEvent([
                        'type'       => 'setting_done',
                        'setting_id' => $setting->id,
                        'gsheet_name'=> $setting->gsheet_name ?? null,
                        'message'    => 'Setting finished',
                    ]);
                } catch (\Throwable $e) {
                    Log::error(
                        'ImportBotcakePsid: error processing setting ID ' . $setting->id . ' - ' . $e->getMessage(),
                        ['trace' => $e->getTraceAsString()]
                    );

                    $this->updateRun([
                        'last_error'   => 'Setting ID ' . $setting->id . ': ' . $e->getMessage(),
                        'last_message' => 'Error in one setting. Continuing...',
                    ]);

                    $this->addEvent([
                        'type'       => 'error',
                        'setting_id' => $setting->id,
                        'gsheet_name'=> $setting->gsheet_name ?? null,
                        'message'    => $e->getMessage(),
                    ]);

                    // continue to next setting
                }
            }

            $this->updateRun([
                'status'       => 'done',
                'last_message' => 'All settings processed.',
            ]);

            $this->addEvent([
                'type'    => 'run_done',
                'message' => 'Import run finished',
            ]);
        } catch (\Throwable $e) {
            Log::error('ImportBotcakePsid: fatal - ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);

            $this->updateRun([
                'status'     => 'failed',
                'last_error' => $e->getMessage(),
                'last_message' => 'Run failed.',
            ]);

            $this->addEvent([
                'type'    => 'error',
                'message' => 'Fatal: ' . $e->getMessage(),
            ]);
        }
    }

    protected function getRun(): ?BotcakePsidImportRun
    {
        if (!$this->runId) return null;
        return BotcakePsidImportRun::find($this->runId);
    }

    protected function updateRun(array $data): void
    {
        try {
            $run = $this->getRun();
            if (!$run) return;
            $run->update($data);
        } catch (\Throwable $e) {
            // never break the job due to UI updates
        }
    }

    protected function addEvent(array $data): void
    {
        try {
            if (!$this->runId) return;

            $payload = array_merge([
                'run_id' => $this->runId,
            ], $data);

            BotcakePsidImportEvent::create($payload);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    protected function extractSpreadsheetId(string $url): ?string
    {
        if (preg_match('/\/d\/([a-zA-Z0-9-_]+)/', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    protected function norm(?string $s): string
    {
        $s = (string)($s ?? '');
        return preg_replace('/\s+/', ' ', trim($s));
    }

    protected function lower(string $s): string
    {
        return mb_strtolower($s, 'UTF-8');
    }

    /**
     * Eligible row for START SEARCH:
     * - J is blank
     * - row has any content (A..I)
     * - if cutoff exists:
     *     - D blank -> allowed
     *     - D parseable and <= cutoff -> allowed
     *     - D parseable and > cutoff -> NOT eligible
     *     - D non-empty but unparseable -> NOT eligible
     */
    protected function isEligibleRow(array $row, ?Carbon $cutoff): bool
    {
        $row = array_pad($row, 10, '');

        $statusJ = trim((string)($row[9] ?? ''));
        if ($statusJ !== '') return false;

        $hasAny = false;
        for ($i = 0; $i <= 8; $i++) {
            $v = $row[$i] ?? '';
            if ($v !== null && $v !== '') { $hasAny = true; break; }
        }
        if (!$hasAny) return false;

        if ($cutoff) {
            $dateStr = trim((string)($row[3] ?? '')); // D
            if ($dateStr === '') return true;

            $cellDate = $this->parseCellDateTime($dateStr);
            if (!$cellDate) return false;
            if ($cellDate->gt($cutoff)) return false;
        }

        return true;
    }

    protected function findNextEligibleStartRow(
        Sheets $service,
        string $spreadsheetId,
        string $sheetName,
        int $fromRow,
        ?Carbon $cutoff
    ): ?int {
        $scanStart = max(2, $fromRow);

        while (true) {
            $scanEnd = $scanStart + self::SCAN_CHUNK_SIZE - 1;
            $range   = $sheetName . '!A' . $scanStart . ':J' . $scanEnd;

            $resp = $service->spreadsheets_values->get($spreadsheetId, $range);
            $rows = $resp->getValues() ?? [];

            if (empty($rows)) {
                return null;
            }

            $rowCount = count($rows);

            for ($i = 0; $i < $rowCount; $i++) {
                $row = array_pad($rows[$i] ?? [], 10, '');
                if ($this->isEligibleRow($row, $cutoff)) {
                    return $scanStart + $i;
                }
            }

            $scanStart = $scanStart + $rowCount;

            if ($rowCount < self::SCAN_CHUNK_SIZE) {
                return null;
            }
        }
    }

    protected function col(string $name): string
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            return '"' . str_replace('"', '""', $name) . '"';
        }
        return '`' . str_replace('`', '``', $name) . '`';
    }

    protected function processSettingInBatches(Sheets $service, BotcakePsidSetting $setting): void
    {
        $spreadsheetId = $this->extractSpreadsheetId($setting->sheet_url);
        if (!$spreadsheetId) {
            Log::error('ImportBotcakePsid: invalid sheet URL', ['url' => $setting->sheet_url]);
            $this->updateRun(['last_message' => 'Invalid sheet URL for setting #' . $setting->id]);
            return;
        }

        $range     = $setting->sheet_range;
        $sheetName = strpos($range, '!') !== false ? explode('!', $range)[0] : $range;

        $this->updateRun([
            'current_sheet_name' => $sheetName,
        ]);

        // Cutoff (optional)
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
         * ✅ INITIAL SEED = K1 ONLY
         */
        $seedRow = 2;
        $k1Value = null;

        try {
            $k1Resp = $service->spreadsheets_values->get($spreadsheetId, $sheetName . '!K1');
            $valK1  = $k1Resp->getValues()[0][0] ?? null;

            if (!empty($valK1) && is_numeric($valK1) && (int)$valK1 >= 2) {
                $seedRow = (int)$valK1;
                $k1Value = (int)$valK1;
            } else {
                $k1Value = $valK1 !== null ? (string)$valK1 : null;
            }
        } catch (\Throwable $e) {
            Log::warning('ImportBotcakePsid: cannot read K1, using seedRow=2. ' . $e->getMessage());
        }

        $this->updateRun([
            'k1_value'  => is_numeric($k1Value) ? (int)$k1Value : null,
            'seed_row'  => $seedRow,
            'last_message' => 'Seed row selected from K1',
        ]);

        // Find first eligible start
        $startRow = $this->findNextEligibleStartRow($service, $spreadsheetId, $sheetName, $seedRow, $cutoff);

        $this->updateRun([
            'selected_start_row' => $startRow,
        ]);

        if (!$startRow) {
            Log::info('ImportBotcakePsid: no eligible rows found from seed.', [
                'setting_id' => $setting->id,
                'seed_row'   => $seedRow,
                'cutoff'     => $this->cutoffDateTime,
            ]);

            $this->updateRun([
                'last_message' => 'No eligible rows found from K1 seed.',
            ]);

            return;
        }

        $batchNo = 0;

        while (true) {
            $batchNo++;

            $endRow = $startRow + self::BATCH_SIZE - 1;
            $dataRange = $sheetName . '!A' . $startRow . ':J' . $endRow;

            $resp = $service->spreadsheets_values->get($spreadsheetId, $dataRange);
            $rows = $resp->getValues() ?? [];

            if (empty($rows)) {
                $this->updateRun([
                    'last_message' => 'No rows returned; stopping.',
                ]);
                break;
            }

            $rowCount    = count($rows);
            $batchEndRow = $startRow + $rowCount - 1;

            $this->updateRun([
                'batch_no'        => $batchNo,
                'batch_start_row' => $startRow,
                'batch_end_row'   => $batchEndRow,
                'last_message'    => "Processing batch #{$batchNo} ({$startRow} → {$batchEndRow})",
            ]);

            $statuses = [];
            $updatedCount = 0;
            $notExistingCnt = 0;
            $skippedCnt = 0;

            for ($i = 0; $i < $rowCount; $i++) {
                $row = array_pad($rows[$i] ?? [], 10, '');

                $existingStatus = trim((string)($row[9] ?? '')); // J
                $statusToWrite  = $existingStatus;

                // totally blank row -> keep J
                $hasAny = false;
                foreach ($row as $v) {
                    if ($v !== null && $v !== '') { $hasAny = true; break; }
                }
                if (!$hasAny) {
                    $statuses[] = [$statusToWrite];
                    continue;
                }

                // ONLY BLANK J gets edited
                if ($existingStatus !== '') {
                    $statuses[] = [$statusToWrite];
                    continue;
                }

                // cutoff: if date exists but > cutoff or invalid -> skip (leave blank)
                if ($cutoff) {
                    $dateStr = trim((string)($row[3] ?? '')); // D
                    if ($dateStr !== '') {
                        $cellDate = $this->parseCellDateTime($dateStr);
                        if (!$cellDate || $cellDate->gt($cutoff)) {
                            $skippedCnt++;
                            $statuses[] = [$statusToWrite]; // keep blank
                            continue;
                        }
                    }
                }

                $pageName = $this->norm((string)($row[0] ?? ''));
                $fullName = $this->norm((string)($row[1] ?? ''));
                $psid     = $this->norm((string)($row[2] ?? ''));

                $statusText = 'not existing';

                if ($pageName !== '' && $fullName !== '' && $psid !== '') {
                    $affected = MacroOutput::where('PAGE', $pageName)
                        ->where('fb_name', $fullName)
                        ->update(['botcake_psid' => $psid]);

                    if ($affected === 0) {
                        $colPage = $this->col('PAGE');
                        $colFb   = $this->col('fb_name');

                        $affected = MacroOutput::whereRaw("LOWER($colPage) = ?", [$this->lower($pageName)])
                            ->whereRaw("LOWER($colFb) = ?", [$this->lower($fullName)])
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

            $probeRow = $batchEndRow + 1;

            // write J + L1 cursor
            try {
                $updates = [
                    new ValueRange([
                        'range'  => $sheetName . '!J' . $startRow . ':J' . $batchEndRow,
                        'values' => $statuses,
                    ]),
                    new ValueRange([
                        'range'  => $sheetName . '!L1',
                        'values' => [[(string)$probeRow]],
                    ]),
                ];

                $batchBody = new BatchUpdateValuesRequest([
                    'valueInputOption' => 'RAW',
                    'data'             => $updates,
                ]);

                $service->spreadsheets_values->batchUpdate($spreadsheetId, $batchBody);
            } catch (\Throwable $e) {
                $this->updateRun([
                    'last_error'   => $e->getMessage(),
                    'last_message' => 'batchUpdate failed',
                ]);
                throw $e;
            }

            // Update run totals (aggregate)
            $run = $this->getRun();
            if ($run) {
                $this->updateRun([
                    'next_scan_from'   => $probeRow,
                    'total_imported'   => (int)$run->total_imported + $updatedCount,
                    'total_not_existing'=> (int)$run->total_not_existing + $notExistingCnt,
                    'total_skipped'    => (int)$run->total_skipped + $skippedCnt,
                    'last_message'     => "Batch #{$batchNo} done. Next scan from {$probeRow}",
                ]);
            } else {
                $this->updateRun([
                    'next_scan_from' => $probeRow,
                ]);
            }

            $this->addEvent([
                'type'         => 'batch_done',
                'setting_id'   => $setting->id,
                'gsheet_name'  => $setting->gsheet_name ?? null,
                'sheet_name'   => $sheetName,
                'batch_no'     => $batchNo,
                'start_row'    => $startRow,
                'end_row'      => $batchEndRow,
                'rows_in_batch'=> $rowCount,
                'imported'     => $updatedCount,
                'not_existing' => $notExistingCnt,
                'skipped'      => $skippedCnt,
                'message'      => "Wrote statuses to J{$startRow}:J{$batchEndRow}; L1={$probeRow}",
            ]);

            // Next batch: find next eligible startRow from probeRow
            $nextStart = $this->findNextEligibleStartRow($service, $spreadsheetId, $sheetName, $probeRow, $cutoff);
            if (!$nextStart) {
                $this->updateRun([
                    'last_message' => 'No more eligible rows found. Stopping.',
                ]);
                break;
            }

            $startRow = $nextStart;

            // If fewer than batch size returned, likely end -> but we still continue scanning
            if ($rowCount < self::BATCH_SIZE) {
                // optional: continue scanning anyway (your rule says continue)
                // so DO NOTHING here
            }
        }
    }

    protected function parseCellDateTime(string $dateStr): ?Carbon
    {
        $dateStr = trim($dateStr);
        if ($dateStr === '') return null;

        if (is_numeric($dateStr)) {
            $n = (float)$dateStr;
            if ($n > 20000 && $n < 80000) {
                $unixSeconds = (int)round(($n - 25569) * 86400);
                return Carbon::createFromTimestamp($unixSeconds, 'Asia/Manila');
            }
        }

        $formats = [
            'H:i:s d-m-Y',
            'H:i d-m-Y',
            'G:i d-m-Y',
            'G:i:s d-m-Y',

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
