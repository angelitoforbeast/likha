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

    /** 1 oras — kayang tapusin ang malalaking sheet. */
    public $timeout = 3600;

    /** Isang attempt — chunk/call-level retry na ang humahawak ng transient. */
    public $tries = 1;

    /** Rows kada read/write chunk (para sa RESUME branch + DONE writes). */
    private const CHUNK = 5000;

    /** J1 resume formula — last row na may "DONE" sa col I (auto-update). */
    private const J1_FORMULA = '=ROW(I2)+MATCH(TRUE,INDEX(ISBLANK(I2:I),0),0)-2';

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

            // Per-sheet try/catch — isang palpak na sheet ay di sisira sa iba.
            try {
                $sheetId   = $setting->sheet_id;
                $range     = $setting->range;
                $sheetName = explode('!', $range)[0] ?? '';

                $cols     = $this->rangeColumns($range);
                $startCol = $cols['startCol'];
                $endCol   = $cols['endCol'];

                $runSheet->update([
                    'status'     => 'fetching',
                    'message'    => null,
                    'started_at' => $runSheet->started_at ?? now(),
                ]);

                // ── Resume pointer mula sa J1 ────────────────────────────────
                // J1 = last row na may "DONE" sa col I.  fresh/walang DONE → 1.
                // startRow = J1 + 1 (unang un-imported). Empty/#N/A/di-numeric → 2.
                $startRow = 2;
                try {
                    $j1Ref  = ($sheetName !== '' ? $sheetName . '!' : '') . 'J1';
                    $j1Resp = $this->gWithRetry(fn() => $service->spreadsheets_values->get($sheetId, $j1Ref));
                    $j1Raw  = $j1Resp->getValues()[0][0] ?? null;
                    if (is_numeric($j1Raw) && (int) $j1Raw >= 1) {
                        $startRow = (int) $j1Raw + 1;
                    }
                } catch (\Throwable $e) {
                    $startRow = 2;
                }

                $runSheet->update(['status' => 'processing']);

                $counters = ['processed' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0];

                if ($startRow > 2) {
                    // ════ RESUME: may na-import na → chunk LANG ang natitira ════
                    // Maliit lang ang tail (bagong rows) kaya kaunting reads.
                    $this->importChunked($service, $sheetId, $sheetName, $startCol, $endCol, $startRow, $counters, $runSheet);
                } else {
                    // ════ FRESH: walang pa na-import → BUONG fetch (1 read) ════
                    // Iwas 429 (1 read kaysa daan-daang chunk). Tapos i-set ang J1
                    // formula → sa susunod, resume na (chunk) — kaunting reads na.
                    $wholeRange = ($sheetName !== '' ? $sheetName . '!' : '')
                                . "{$startCol}2:{$endCol}";

                    try {
                        $values = $this->gWithRetry(
                            fn() => $service->spreadsheets_values->get($sheetId, $wholeRange)->getValues()
                        ) ?? [];
                        if (!empty($values)) {
                            $this->processValues($values, 2, $sheetName, $service, $sheetId, $counters);
                            $this->saveProgress($runSheet, $counters);
                        }
                    } catch (\Throwable $eWhole) {
                        // Sobrang laki para sa isang read (500) → fallback: CHUNKED from row 2.
                        $this->importChunked($service, $sheetId, $sheetName, $startCol, $endCol, 2, $counters, $runSheet);
                    }

                    // Lagyan ng J1 formula (self-heal) → next run = resume/chunk.
                    $this->gWithRetry(fn() => $service->spreadsheets_values->update(
                        $sheetId,
                        ($sheetName !== '' ? $sheetName . '!' : '') . 'J1',
                        new Google_Service_Sheets_ValueRange(['values' => [[self::J1_FORMULA]]]),
                        ['valueInputOption' => 'USER_ENTERED']
                    ));
                }

                $runSheet->update([
                    'status'          => 'done',
                    'message'         => $counters['processed'] > 0 ? null : 'No new rows',
                    'processed_count' => $counters['processed'],
                    'inserted_count'  => $counters['inserted'],
                    'updated_count'   => $counters['updated'],
                    'skipped_count'   => $counters['skipped'],
                    'finished_at'     => now(),
                ]);

                $run->increment('total_processed', $counters['processed']);
                $run->increment('total_inserted',  $counters['inserted']);
                $run->increment('total_updated',   $counters['updated']);
                $run->increment('total_skipped',   $counters['skipped']);

            } catch (\Throwable $e) {
                $runSheet->update([
                    'status'      => 'failed',
                    'message'     => mb_substr($e->getMessage(), 0, 1000),
                    'finished_at' => now(),
                ]);
                $run->increment('total_failed');
            }
        }

        $run->update([
            'status' => 'done',
            'finished_at' => now(),
        ]);
    }

    /**
     * Process ng isang array ng rows simula sa $baseRow → importRow + tipunin ang
     * "DONE" marks, tapos isulat nang CHUNKED (iwas large-op + bounded writes).
     * Mina-mutate ang $counters (processed/inserted/updated/skipped).
     */
    private function processValues(array $values, int $baseRow, string $sheetName, Google_Service_Sheets $service, string $sheetId, array &$counters): void
    {
        $updates = [];
        foreach ($values as $i => $row) {
            $actualRow = $baseRow + $i;

            $doneFlag = strtolower(preg_replace('/\s+/', '', $row[8] ?? ''));
            if ($doneFlag === 'done') { $counters['skipped']++; continue; }

            $counters['processed']++;
            $outcome = $this->importRow($row);
            if ($outcome === 'inserted')    $counters['inserted']++;
            elseif ($outcome === 'updated') $counters['updated']++;

            $updates[] = [
                'range'  => "{$sheetName}!I{$actualRow}",
                'values' => [['DONE']],
            ];
        }

        // Isulat ang DONE nang tig-CHUNK (bounded writes).
        foreach (array_chunk($updates, self::CHUNK) as $batch) {
            $this->gWithRetry(function () use ($service, $sheetId, $batch) {
                $body = new Google_Service_Sheets_BatchUpdateValuesRequest([
                    'valueInputOption' => 'RAW',
                    'data' => array_map(fn($d) => new Google_Service_Sheets_ValueRange($d), $batch),
                ]);
                return $service->spreadsheets_values->batchUpdate($sheetId, $body);
            });
        }
    }

    /**
     * Chunked read+process mula sa $startRow hanggang maubos. Bounded bawat read
     * (CHUNK) + DONE write. Ginagamit ng RESUME branch at ng whole-fetch fallback.
     */
    private function importChunked(Google_Service_Sheets $service, string $sheetId, string $sheetName, string $startCol, string $endCol, int $startRow, array &$counters, LikhaImportRunSheet $runSheet): void
    {
        $cursor = $startRow;
        while (true) {
            $endRowChunk = $cursor + self::CHUNK - 1;
            $chunkRange  = ($sheetName !== '' ? $sheetName . '!' : '')
                         . "{$startCol}{$cursor}:{$endCol}{$endRowChunk}";

            $values = $this->gWithRetry(
                fn() => $service->spreadsheets_values->get($sheetId, $chunkRange)->getValues()
            ) ?? [];

            if (empty($values)) break;

            $this->processValues($values, $cursor, $sheetName, $service, $sheetId, $counters);
            $this->saveProgress($runSheet, $counters);

            $n = count($values);
            $cursor += $n;
            if ($n < self::CHUNK) break;
        }
    }

    private function saveProgress(LikhaImportRunSheet $runSheet, array $counters): void
    {
        $runSheet->update([
            'status'          => 'processing',
            'processed_count' => $counters['processed'],
            'inserted_count'  => $counters['inserted'],
            'updated_count'   => $counters['updated'],
            'skipped_count'   => $counters['skipped'],
        ]);
    }

    /**
     * Retry helper para sa Google API calls.
     *   - Rate limit / quota (429) → per-MINUTE quota → mahabang hintay (20s,40s,60s).
     *   - 5xx / backendError / timeout → exponential backoff (1s,2s,4s,8s).
     *   - Iba (400/403/404) → agad i-throw (hindi retryable).
     */
    private function gWithRetry(callable $fn, int $tries = 5)
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                $code = (int) $e->getCode();
                $msg  = strtolower($e->getMessage());

                $isRate = $code === 429
                    || str_contains($msg, 'ratelimit')
                    || str_contains($msg, 'rate limit')
                    || str_contains($msg, 'quota')
                    || str_contains($msg, 'resource_exhausted');

                $isServer = ($code >= 500 && $code < 600)
                    || str_contains($msg, 'backenderror')
                    || str_contains($msg, 'internal error')
                    || str_contains($msg, 'try again')
                    || str_contains($msg, 'timeout')
                    || str_contains($msg, 'deadline');

                if ($attempt >= $tries || !($isRate || $isServer)) {
                    throw $e;
                }

                // Rate limit = per-minute → mas mahabang hintay para mag-reset.
                sleep($isRate ? min(60, 20 * $attempt) : min(8, 1 << ($attempt - 1)));
            }
        }
    }

    /**
     * Start + end column letters mula sa A1 range. Default A / I (col I = DONE).
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
     * (insert). Returns 'inserted' o 'updated'.
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
