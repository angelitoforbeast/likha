<?php

namespace App\Jobs;

use App\Models\MacroGsheetSetting;
use App\Models\MacroOutput;
use App\Models\MacroImportRun;
use App\Models\MacroImportRunItem;
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

    public int $timeout = 3600;
    public int $tries   = 1;

    public function __construct(
        public int $runId,
        public ?int $userId = null
    ) {}

    public function handle()
    {
        set_time_limit(0);

        $run = MacroImportRun::find($this->runId);
        if (!$run) {
            Log::error("MacroImportRun not found: {$this->runId}");
            return;
        }

        $run->update([
            'status'     => 'running',
            'started_at' => $run->started_at ?: now(),
            'message'    => null,
        ]);

        $settings = MacroGsheetSetting::all()->keyBy('id');

        $client = new Google_Client();
        $client->setApplicationName('Laravel GSheet');
        $client->setScopes([Sheets::SPREADSHEETS]);
        $client->setAuthConfig(storage_path('app/credentials.json'));
        $client->setAccessType('offline');

        $service = new Sheets($client);

        $items = MacroImportRunItem::where('run_id', $run->id)->orderBy('id')->get();

        $runProcessedSettings = 0;
        $runProcessed = 0;
        $runInserted  = 0;
        $runUpdated   = 0;
        $runSkipped   = 0;

        foreach ($items as $item) {
            $setting = $item->setting_id ? ($settings[$item->setting_id] ?? null) : null;

            if (!$setting) {
                $item->update([
                    'status'  => 'skipped',
                    'message' => 'Setting not found (deleted?).',
                    'finished_at' => now(),
                ]);

                $runProcessedSettings++;
                $runSkipped++;
                $this->updateRunTotals($run, $runProcessedSettings, $runProcessed, $runInserted, $runUpdated, $runSkipped);
                continue;
            }

            $item->update([
                'status'     => 'running',
                'started_at' => now(),
                'message'    => null,
                'processed'  => 0,
                'inserted'   => 0,
                'updated'    => 0,
                'skipped'    => 0,
            ]);

            $processed = 0;
            $inserted  = 0;
            $updated   = 0;
            $skipped   = 0;

            try {
                $spreadsheetId = $this->extractSpreadsheetId($setting->sheet_url);
                if (!$spreadsheetId) {
                    throw new \RuntimeException("Invalid sheet_url");
                }

                $range = trim((string) $setting->sheet_range);
                if ($range === '') {
                    throw new \RuntimeException("Empty sheet_range");
                }

                $parsed = $this->parseA1Range($range);

                $sheetName = $parsed['sheetName'];
                $startRow  = $parsed['startRow'];
                $startCol  = $parsed['startCol'];
                $endCol    = $parsed['endCol'];

                if ($startCol !== 'A') {
                    throw new \RuntimeException("sheet_range must start at A. Given: {$range}");
                }

                $totalCols   = $this->colCount($startCol, $endCol);
                $statusIndex = $totalCols - 1;
                $markCol     = $endCol;

                $response = $service->spreadsheets_values->get($spreadsheetId, $range);
                $values   = $response->getValues();

                if (empty($values)) {
                    $item->update([
                        'status' => 'done',
                        'message' => 'No rows found in range.',
                        'finished_at' => now(),
                    ]);
                    $runProcessedSettings++;
                    $this->updateRunTotals($run, $runProcessedSettings, $runProcessed, $runInserted, $runUpdated, $runSkipped);
                    continue;
                }

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
                    $row = array_pad($values[$i], $totalCols, null);

                    $status = trim((string)($row[$statusIndex] ?? ''));

                    // hasData A–P (first 16 cols)
                    $hasData = false;
                    for ($j = 0; $j <= 15; $j++) {
                        if (!empty(trim((string)($row[$j] ?? '')))) {
                            $hasData = true;
                            break;
                        }
                    }

                    // Column O index 14 must have value
                    $hasColumnO = !empty(trim((string)($row[14] ?? '')));

                    if ($status === '' && $hasData && $hasColumnO) {
                        $data = [];

                        foreach ($columnMap as $index => $column) {
                            $data[$column] = $row[$index] ?? null;
                        }

                        $data['PHONE NUMBER'] = Str::limit((string)($data['PHONE NUMBER'] ?? ''), 50);

                        $fbName = null;
                        if (!empty($data['all_user_input'])) {
                            preg_match('/FB\s*NAME:\s*(.*?)\r?\n/i', (string)$data['all_user_input'], $m);
                            $fbName = $m[1] ?? null;
                        }
                        $data['fb_name'] = $fbName;

                        if (empty($data['PAGE']) && !empty($data['all_user_input'])) {
                            preg_match('/PAGE:\s*(.*?)(?:\r?\n|$)/i', (string)$data['all_user_input'], $pm);
                            $extractedPage = $pm[1] ?? null;
                            if ($extractedPage) {
                                $data['PAGE'] = $extractedPage;
                            }
                        }

                        // normalize timestamp
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

                        $existing = MacroOutput::where([
                            ['TIMESTAMP', '=', $timestamp],
                            ['PAGE', '=', $data['PAGE']],
                            ['fb_name', '=', $fbName],
                        ])->first();

                        if (!$existing) {
                            MacroOutput::create($data);
                            $inserted++;
                        } else {
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
                            $updated++;
                        }

                        $processed++;

                        $updates[] = new ValueRange([
                            'range'  => "{$sheetName}!{$markCol}" . ($startRow + $i),
                            'values' => [['IMPORTED']],
                        ]);

                        $importCount++;
                        if ($importCount >= $maxImport) {
                            break;
                        }
                    } else {
                        // skipped row (either already imported or no data)
                        // We don't count this as skipped per-row to avoid huge counts.
                    }
                }

                if (!empty($updates)) {
                    $batchBody = new BatchUpdateValuesRequest([
                        'valueInputOption' => 'RAW',
                        'data'             => $updates,
                    ]);

                    $service->spreadsheets_values->batchUpdate($spreadsheetId, $batchBody);
                }

                $item->update([
                    'status'     => 'done',
                    'processed'  => $processed,
                    'inserted'   => $inserted,
                    'updated'    => $updated,
                    'skipped'    => $skipped,
                    'message'    => $processed > 0 ? 'Imported successfully.' : 'No eligible rows (blank status + valid data).',
                    'finished_at'=> now(),
                ]);

                $runProcessedSettings++;
                $runProcessed += $processed;
                $runInserted  += $inserted;
                $runUpdated   += $updated;
                $runSkipped   += $skipped;

                $this->updateRunTotals($run, $runProcessedSettings, $runProcessed, $runInserted, $runUpdated, $runSkipped);

            } catch (\Throwable $e) {
                Log::error("❌ Macro GSheet Import Failed (setting {$setting->id}): " . $e->getMessage(), [
                    'setting_id' => $setting->id,
                    'sheet_url'  => $setting->sheet_url,
                    'sheet_range'=> $setting->sheet_range,
                ]);

                $item->update([
                    'status'      => 'failed',
                    'message'     => $e->getMessage(),
                    'finished_at' => now(),
                ]);

                $runProcessedSettings++;
                $this->updateRunTotals($run, $runProcessedSettings, $runProcessed, $runInserted, $runUpdated, $runSkipped);
            }
        }

        // finalize run
        $finalStatus = 'done';

        // if any failed
        $failedCount = MacroImportRunItem::where('run_id', $run->id)->where('status', 'failed')->count();
        if ($failedCount > 0) $finalStatus = 'failed';

        $run->update([
            'status'      => $finalStatus,
            'finished_at' => now(),
            'message'     => $finalStatus === 'failed'
                ? "May {$failedCount} sheet(s) na failed."
                : 'Import completed.',
        ]);
    }

    private function updateRunTotals(MacroImportRun $run, int $processedSettings, int $processed, int $inserted, int $updated, int $skipped): void
    {
        $run->update([
            'processed_settings' => $processedSettings,
            'total_processed'    => $processed,
            'total_inserted'     => $inserted,
            'total_updated'      => $updated,
            'total_skipped'      => $skipped,
        ]);
    }

    private function extractSpreadsheetId($url)
    {
        preg_match('/\/d\/([a-zA-Z0-9-_]+)/', (string)$url, $matches);
        return $matches[1] ?? null;
    }

    private function parseA1Range(string $range): array
    {
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
