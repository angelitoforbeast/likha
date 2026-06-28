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

                $sheetId = $setting->sheet_id;
                $range = $setting->range;
                $sheetName = explode('!', $range)[0] ?? '';

                // ── Resume start row mula sa J1 ──────────────────────────────
                // J1 (sheet formula) = last row na may "DONE" sa col I (last imported).
                //   fresh (walang DONE) → J1 = 1.  So unang un-imported row = J1 + 1.
                // Kung blank / #N/A / di-numeric → row 2 (= existing default start).
                // READ lang ang J1; ang sheet formula mismo ang nag-uupdate nito
                // (dahil isinusulat natin ang "DONE" sa col I sa baba).
                $startRow = 2;
                try {
                    $j1Ref  = ($sheetName !== '' ? $sheetName . '!' : '') . 'J1';
                    $j1Resp = $service->spreadsheets_values->get($sheetId, $j1Ref);
                    $j1Raw  = $j1Resp->getValues()[0][0] ?? null;
                    if (is_numeric($j1Raw) && (int) $j1Raw >= 1) {
                        $startRow = (int) $j1Raw + 1;
                    }
                } catch (\Throwable $e) {
                    $startRow = 2; // J1 read failed → safe default
                }

                // Build fetch range na nagsisimula sa $startRow (preserve sheet + columns).
                $fetchRange = $this->rangeFromStartRow($range, $startRow);

                $response = $service->spreadsheets_values->get($sheetId, $fetchRange);
                $values = $response->getValues();

                if (empty($values)) {
                    $runSheet->update([
                        'status' => 'done',
                        'message' => 'No rows fetched',
                        'finished_at' => now(),
                    ]);
                    continue;
                }

                $runSheet->update(['status' => 'processing']);

                $updates = [];

                // Local counters (save to DB in chunks)
                $processed = 0;
                $inserted = 0;
                $updated = 0;
                $skipped  = 0;

                foreach ($values as $index => $row) {
                    $doneFlag = strtolower(preg_replace('/\s+/', '', $row[8] ?? ''));
                    if ($doneFlag === 'done') {
                        $skipped++;
                        continue;
                    }

                    $processed++;

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
                        $updated++;
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
                            'ITEM_NAME'          => $extractedItemName ?: null,
                            'COD'                => $extractedCOD ?: null,
                        ]);
                        $inserted++;
                    }

                    // Mark DONE in Google Sheet col I — gamitin ang TOTOONG sheet row
                    // ($startRow + index), hindi na hardcoded +2. Tama kahit saan magsimula.
                    $rowNumber = $startRow + $index;
                    $updates[] = [
                        'range'  => "{$sheetName}!I{$rowNumber}",
                        'values' => [['DONE']],
                    ];

                    // Save progress every 25 processed rows (lightweight, UI becomes alive)
                    if (($processed % 25) === 0) {
                        $runSheet->update([
                            'processed_count' => $processed,
                            'inserted_count'  => $inserted,
                            'updated_count'   => $updated,
                            'skipped_count'   => $skipped,
                        ]);
                    }
                }

                $runSheet->update(['status' => 'writing']);

                if (!empty($updates)) {
                    $batchBody = new Google_Service_Sheets_BatchUpdateValuesRequest([
                        'valueInputOption' => 'RAW',
                        'data' => array_map(fn($data) => new Google_Service_Sheets_ValueRange($data), $updates),
                    ]);
                    $service->spreadsheets_values->batchUpdate($sheetId, $batchBody);
                }

                // Final update for this sheet
                $runSheet->update([
                    'status' => 'done',
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
     * I-rebuild ang A1 range para magsimula sa $startRow — preserve ang sheet name,
     * start column, at end (col/row) ng original range.
     *   "Sheet1!A2:I"     + 5 → "Sheet1!A5:I"
     *   "Sheet1!A2:I1000" + 5 → "Sheet1!A5:I1000"
     *   "Sheet1!A:I"      + 5 → "Sheet1!A5:I"
     */
    private function rangeFromStartRow(string $range, int $startRow): string
    {
        $sheetName = '';
        $a1 = $range;
        if (str_contains($range, '!')) {
            [$sheetName, $a1] = explode('!', $range, 2);
        }

        $parts    = explode(':', $a1, 2);
        $startCol = preg_replace('/[^A-Za-z]/', '', $parts[0] ?? 'A');
        if ($startCol === '') $startCol = 'A';
        $end      = $parts[1] ?? null;

        $newA1 = $startCol . $startRow . ($end !== null ? (':' . $end) : '');
        return ($sheetName !== '' ? $sheetName . '!' : '') . $newA1;
    }
}
