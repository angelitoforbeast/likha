<?php

namespace App\Jobs;

use App\Models\MacroOutput;
use App\Models\LikhaOrderSetting;
use App\Models\LikhaImportRun;
use App\Models\LikhaImportRunSheet;
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

    public int $runId;

    /** 1 oras — kayang tapusin ang malalaking sheet (chunked, may sleeps). */
    public $timeout = 3600;

    /** Isang attempt lang — chunk-level retry na ang humahawak ng transient. */
    public $tries = 1;

    /** Bilang ng rows kada fetch/write (bounded → iwas large-op backendError). */
    private const CHUNK = 500;

    public function __construct(int $runId)
    {
        $this->runId = $runId;
    }

    public function handle()
    {
        $run = LikhaImportRun::find($this->runId);
        if (!$run) return;

        $client = new Google_Client();
        $client->setAuthConfig(storage_path('app/credentials.json'));
        $client->addScope(Google_Service_Sheets::SPREADSHEETS);
        $service = new Google_Service_Sheets($client);

        $settings = LikhaOrderSetting::orderBy('id')->get();

        try {
            foreach ($settings as $setting) {
                $runSheet = LikhaImportRunSheet::where('run_id', $run->id)
                    ->where('setting_id', $setting->id)
                    ->first();

                if (!$runSheet) continue;

                if (!$setting->sheet_id || !$setting->range) {
                    $runSheet->update([
                        'status' => 'failed',
                        'message' => 'Missing sheet_id or range',
                        'finished_at' => now(),
                    ]);
                    $run->increment('total_failed');
                    continue;
                }

                $runSheet->update([
                    'status' => 'fetching',
                    'message' => null,
                    'started_at' => $runSheet->started_at ?? now(),
                ]);

                $sheetId   = $setting->sheet_id;
                $range     = $setting->range;
                $sheetName = explode('!', $range)[0] ?? '';

                // ── Resume start row mula sa J1 ──────────────────────────────
                // J1 (sheet formula) = last row na may "DONE" sa col I (last imported).
                //   fresh (walang DONE) → J1 = 1.  So unang un-imported row = J1 + 1.
                // Kung blank / #N/A / di-numeric → row 2 (= existing default start).
                // READ lang ang J1; ang sheet formula mismo ang nag-uupdate nito.
                $startRow = 2;
                try {
                    $j1Ref  = ($sheetName !== '' ? $sheetName . '!' : '') . 'J1';
                    $j1Resp = $this->gWithRetry(fn() => $service->spreadsheets_values->get($sheetId, $j1Ref));
                    $j1Raw  = $j1Resp->getValues()[0][0] ?? null;
                    if (is_numeric($j1Raw) && (int) $j1Raw >= 1) {
                        $startRow = (int) $j1Raw + 1;
                    }
                } catch (\Throwable $e) {
                    $startRow = 2; // J1 read failed → safe default
                }

                // Columns mula sa range (start dapat A; preserve end col, default I).
                $cols     = $this->rangeColumns($range);
                $startCol = $cols['startCol'];
                $endCol   = $cols['endCol'];

                $runSheet->update(['status' => 'processing']);

                // ── CHUNKED processing — tig-CHUNK rows ──────────────────────
                // fetch (bounded) → import → isulat ang DONE PER CHUNK (incremental
                // progress) → advance. Bounded bawat Google call kaya iwas sa
                // large-op backendError; at kung pumalya ang isang chunk, naka-save
                // na ang nauna (walang vicious cycle). + retry/backoff per call.
                $cursor    = $startRow;
                $processed = 0;
                $inserted  = 0;
                $updated   = 0;
                $skipped   = 0;
                $anyRows   = false;

                while (true) {
                    $endRowChunk = $cursor + self::CHUNK - 1;
                    $chunkRange  = ($sheetName !== '' ? $sheetName . '!' : '')
                                 . "{$startCol}{$cursor}:{$endCol}{$endRowChunk}";

                    $values = $this->gWithRetry(
                        fn() => $service->spreadsheets_values->get($sheetId, $chunkRange)->getValues()
                    ) ?? [];

                    if (empty($values)) break;   // walang laman → tapos na
                    $anyRows = true;

                    $updates = [];
                    foreach ($values as $i => $row) {
                        $actualRow = $cursor + $i;

                        $doneFlag = strtolower(preg_replace('/\s+/', '', $row[8] ?? ''));
                        if ($doneFlag === 'done') { $skipped++; continue; }

                        $processed++;
                        $outcome = $this->importRow($row);          // 'inserted' | 'updated'
                        if ($outcome === 'inserted')    $inserted++;
                        elseif ($outcome === 'updated') $updated++;

                        // Mark DONE sa col I gamit ang TOTOONG sheet row.
                        $updates[] = [
                            'range'  => "{$sheetName}!I{$actualRow}",
                            'values' => [['DONE']],
                        ];
                    }

                    // Isulat ang DONE ng chunk na 'to AGAD (≤CHUNK — bounded write).
                    if (!empty($updates)) {
                        $this->gWithRetry(function () use ($service, $sheetId, $updates) {
                            $batchBody = new Google_Service_Sheets_BatchUpdateValuesRequest([
                                'valueInputOption' => 'RAW',
                                'data' => array_map(fn($d) => new Google_Service_Sheets_ValueRange($d), $updates),
                            ]);
                            return $service->spreadsheets_values->batchUpdate($sheetId, $batchBody);
                        });
                    }

                    // Save progress per chunk (UI alive + persisted).
                    $runSheet->update([
                        'status'          => 'processing',
                        'processed_count' => $processed,
                        'inserted_count'  => $inserted,
                        'updated_count'   => $updated,
                        'skipped_count'   => $skipped,
                    ]);

                    $rowsFetched = count($values);
                    $cursor += $rowsFetched;
                    if ($rowsFetched < self::CHUNK) break;   // umabot na sa dulo ng data
                }

                // Final update for this sheet
                $runSheet->update([
                    'status'          => 'done',
                    'message'         => $anyRows ? null : 'No rows fetched',
                    'processed_count' => $processed,
                    'inserted_count'  => $inserted,
                    'updated_count'   => $updated,
                    'skipped_count'   => $skipped,
                    'finished_at'     => now(),
                ]);

                // Add to run totals
                $run->increment('total_processed', $processed);
                $run->increment('total_inserted', $inserted);
                $run->increment('total_updated', $updated);
                $run->increment('total_skipped', $skipped);
            }

            $run->update([
                'status' => 'done',
                'finished_at' => now(),
            ]);

        } catch (\Throwable $e) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Retry helper para sa Google API calls — exponential backoff (1s,2s,4s) sa
     * 5xx / backendError / rate-limit / timeout (transient). Iba pang error →
     * agad i-throw (huwag i-retry ang non-retryable tulad ng 400/403/404).
     */
    private function gWithRetry(callable $fn, int $tries = 4)
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                $code = (int) $e->getCode();
                $msg  = strtolower($e->getMessage());
                $retryable = ($code >= 500 && $code < 600)
                    || str_contains($msg, 'backenderror')
                    || str_contains($msg, 'internal error')
                    || str_contains($msg, 'rate limit')
                    || str_contains($msg, 'ratelimit')
                    || str_contains($msg, 'try again')
                    || str_contains($msg, 'timeout')
                    || str_contains($msg, 'deadline');
                if ($attempt >= $tries || !$retryable) {
                    throw $e;
                }
                sleep(min(8, 1 << ($attempt - 1))); // 1, 2, 4, 8s
            }
        }
    }

    /**
     * Kunin ang start + end column letters mula sa A1 range.
     *   "Sheet1!A2:I"  → ['startCol'=>'A','endCol'=>'I']
     *   "Sheet1!A:K"   → ['startCol'=>'A','endCol'=>'K']
     *   "A2:I1000"     → ['startCol'=>'A','endCol'=>'I']
     * Default: startCol='A', endCol='I' (col I = DONE flag).
     */
    private function rangeColumns(string $range): array
    {
        $a1    = str_contains($range, '!') ? explode('!', $range, 2)[1] : $range;
        $parts = explode(':', $a1, 2);

        $startCol = strtoupper(preg_replace('/[^A-Za-z]/', '', $parts[0] ?? '') ?? '');
        $endCol   = isset($parts[1]) ? strtoupper(preg_replace('/[^A-Za-z]/', '', $parts[1]) ?? '') : '';

        if ($startCol === '') $startCol = 'A';
        if ($endCol === '')   $endCol = 'I';

        return ['startCol' => $startCol, 'endCol' => $endCol];
    }

    /**
     * Import ng IISANG row → match by TIMESTAMP+PAGE+fb_name (update) o create
     * (insert). Returns 'inserted' o 'updated'. (Verbatim ang dating per-row logic.)
     */
    private function importRow(array $row): string
    {
        // TIMESTAMP normalize to "H:i d-m-Y"
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

        // PAGE (col B) fallback from col F
        $page = $row[1] ?? null;
        if (empty($page) && !empty($row[5])) {
            if (preg_match('/PAGE:\s*(.*?)\s*(?:\r?\n|$)/i', $row[5], $m)) {
                $page = trim($m[1]);
            }
        }

        $fbName = $row[2] ?? '';

        // Extract ITEM_NAME + COD from column F
        $colF = (string)($row[5] ?? '');
        $extractedItemName = null;
        $extractedCOD = null;

        if ($colF !== '') {
            $itemText = null;
            if (preg_match('/ITEM:\s*(.+?)(?:\r?\n|$)/i', $colF, $mItem)) {
                $itemText = trim(preg_replace('/\s+/', ' ', $mItem[1]));
            }

            $qty = null;
            if (preg_match('/QUANTITY:\s*([^\r\n]+)/i', $colF, $mQty)) {
                $rawQty = trim($mQty[1]);
                if (preg_match('/\d+(?:\.\d+)?/', $rawQty, $mNum)) {
                    $qty = $mNum[0];
                } else {
                    $qty = $rawQty !== '' ? $rawQty : null;
                }
            }

            if ($itemText) {
                $extractedItemName = $qty ? ($qty . ' x ' . $itemText) : $itemText;
            }

            if (preg_match('/PRICE:\s*₱\s*([0-9][\d,]*(?:\.\d{1,2})?)/iu', $colF, $mCod)) {
                $extractedCOD = str_replace(',', '', $mCod[1]);
            }
        }

        // Match existing by TIMESTAMP + PAGE + fb_name
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

            if ($existing->{'PHONE NUMBER'} === null || $existing->{'PHONE NUMBER'} === '') {
                $updateData['PHONE NUMBER'] = preg_match('/09\d{9}/', $row[3] ?? '', $m) ? $m[0] : null;
            }

            if (($existing->{'ITEM_NAME'} ?? null) === null || trim((string)$existing->{'ITEM_NAME'}) === '') {
                if (!empty($extractedItemName)) {
                    $updateData['ITEM_NAME'] = $extractedItemName;
                }
            }

            $codCurrent = $existing->{'COD'} ?? null;
            if ($codCurrent === null || $codCurrent === '') {
                if (!empty($extractedCOD)) {
                    $updateData['COD'] = $extractedCOD;
                }
            }

            $existing->update($updateData);
            return 'updated';
        }

        MacroOutput::create([
            'TIMESTAMP'          => $timestamp,
            'PAGE'               => $page,
            'FULL NAME'          => $row[2] ?? null,
            'fb_name'            => $row[2] ?? null,
            'PHONE NUMBER'       => preg_match('/09\d{9}/', $row[3] ?? '', $m) ? $m[0] : null,
            'all_user_input'     => 'FB NAME: ' . ($row[2] ?? '') . "\n" . ($row[4] ?? ''),
            'shop_details'       => $row[5] ?? null,
            'extracted_details'  => $row[6] ?? null,
            'ITEM_NAME'          => $extractedItemName ?: null,
            'COD'                => $extractedCOD ?: null,
        ]);
        return 'inserted';
    }
}
