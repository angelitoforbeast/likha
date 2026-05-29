<?php

namespace App\Jobs;

use App\Models\CancelDetector;
use App\Models\CancelDetectorSetting;
use App\Models\CancelDetectorRun;
use App\Models\CancelDetectorRunSheet;
use Google_Client;
use Google_Service_Sheets;
use Google_Service_Sheets_ValueRange;
use Google_Service_Sheets_BatchUpdateValuesRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Async job — fetches configured Google Sheets sources, imports the 5
 * conversation columns needed for cancel detection, and marks processed
 * rows with "DONE" sa column N para hindi na ulit ma-import.
 *
 * Gsheet column layout (only these 5 stored):
 *   A: PAGE NAME       → page_name
 *   B: NAME            → name
 *   C: PHONE NUMBER    → phone_number (normalized)
 *   D: ALL CX DETAILS  (skip)
 *   E: SHOP DETAILS    → shop_details
 *   F: CXD             (skip)
 *   G: PRICE           (skip)
 *   H: STATUS          (skip)
 *   I: RESPONSE TRACKER (skip)
 *   J: CONVERSATION    → conversation
 *   K: UNIX TIMESTAMP  (skip)
 *   L: SUBSCRIBED DATE (skip)
 *   M: (reserved / unused)
 *   N: DONE marker     (written back after import)
 *
 * Upsert key: (page_name, phone_number) — same customer on the same page
 * gets merged into one row. Phone is the sole tiebreaker since this feed
 * does not carry a platform contact_id.
 *
 * AI cancel classification is NOT done here — handled separately by Phase
 * 2 background job (leaves ai_analysis NULL).
 */
class ImportCancelDetectorFromGoogleSheet implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $runId;

    public function __construct(int $runId)
    {
        $this->runId = $runId;
    }

    public function handle()
    {
        $run = CancelDetectorRun::find($this->runId);
        if (!$run) return;

        $client = new Google_Client();
        $client->setAuthConfig(storage_path('app/credentials.json'));
        $client->addScope(Google_Service_Sheets::SPREADSHEETS);
        $service = new Google_Service_Sheets($client);

        // Skip archived settings — they're kept around but no longer
        // need to be polled.
        $settings = CancelDetectorSetting::where('is_archived', false)
            ->orderBy('id')->get();

        try {
            foreach ($settings as $setting) {
                $runSheet = CancelDetectorRunSheet::where('run_id', $run->id)
                    ->where('setting_id', $setting->id)
                    ->first();
                if (!$runSheet) continue;

                if (!$setting->sheet_id || !$setting->selected_sheet_name) {
                    $runSheet->update([
                        'status'      => 'failed',
                        'message'     => 'Missing sheet_id or selected_sheet_name',
                        'finished_at' => now(),
                    ]);
                    $run->increment('total_failed');
                    continue;
                }

                $runSheet->update([
                    'status'     => 'fetching',
                    'message'    => null,
                    'started_at' => $runSheet->started_at ?? now(),
                ]);

                $sheetId   = $setting->sheet_id;
                $sheetName = $setting->selected_sheet_name;
                $colRange  = $setting->range ?: 'A2:N';
                $effectiveRange = $sheetName . '!' . $colRange;

                $response = $service->spreadsheets_values->get($sheetId, $effectiveRange);
                $values   = $response->getValues();

                if (empty($values)) {
                    $runSheet->update([
                        'status'      => 'done',
                        'message'     => 'No rows fetched',
                        'finished_at' => now(),
                    ]);
                    continue;
                }

                $runSheet->update(['status' => 'processing']);

                $updates   = [];
                $processed = 0;
                $inserted  = 0;
                $updated   = 0;
                $skipped   = 0;

                foreach ($values as $index => $row) {
                    // Skip if col N (index 13, 0-based) already says DONE.
                    $doneFlag = strtolower(preg_replace('/\s+/', '', $row[13] ?? ''));
                    if ($doneFlag === 'done') {
                        $skipped++;
                        continue;
                    }

                    $processed++;

                    // Pull only the 5 columns we care about.
                    $pageName     = trim((string)($row[0] ?? '')) ?: null;   // A
                    $name         = trim((string)($row[1] ?? '')) ?: null;   // B
                    $rawPhone     = trim((string)($row[2] ?? ''));           // C
                    $shopDetails  = (string)($row[4] ?? '');                  // E
                    $conversation = (string)($row[9] ?? '');                  // J

                    $phone = self::normalizePhone($rawPhone);

                    $newRow = [
                        'page_name'       => $pageName,
                        'name'            => $name,
                        'phone_number'    => $phone,
                        'shop_details'    => $shopDetails ?: null,
                        'conversation'    => $conversation ?: null,
                        'imported_run_id' => $run->id,
                        // ai_analysis intentionally NOT set — Phase 2 fills it.
                    ];

                    $matchedId = self::findMatchedId($pageName, $phone, $name);
                    if ($matchedId) {
                        // MERGE: don't overwrite existing data with empty/null
                        // values (preserves prior phone/shop/conversation when
                        // an upload has partial fields). ai_analysis is never
                        // touched here — kept as Phase 2's exclusive domain.
                        $mergeFields = ['name', 'phone_number', 'shop_details', 'conversation'];
                        $updatePayload = $newRow;
                        foreach ($mergeFields as $f) {
                            if ($updatePayload[$f] === null || $updatePayload[$f] === '') {
                                unset($updatePayload[$f]);
                            }
                        }
                        // If conversation changed (new content from re-upload),
                        // clear the stale ai_analysis so Phase 2 re-classifies.
                        if (
                            isset($updatePayload['conversation']) &&
                            $updatePayload['conversation'] !== ''
                        ) {
                            $existing = CancelDetector::where('id', $matchedId)
                                ->value('conversation');
                            if ($existing !== $updatePayload['conversation']) {
                                $updatePayload['ai_analysis']    = null;
                                $updatePayload['ai_analyzed_at'] = null;
                            }
                        }
                        CancelDetector::where('id', $matchedId)->update($updatePayload);
                        $updated++;
                    } else {
                        CancelDetector::create($newRow);
                        $inserted++;
                    }

                    // Mark DONE sa column N (1-based col 14).
                    $rowNumber = $index + 2; // +1 for 1-based, +1 for header
                    $updates[] = [
                        'range'  => "{$sheetName}!N{$rowNumber}",
                        'values' => [['DONE']],
                    ];

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
                        'data' => array_map(
                            fn ($d) => new Google_Service_Sheets_ValueRange($d),
                            $updates
                        ),
                    ]);
                    $service->spreadsheets_values->batchUpdate($sheetId, $batchBody);
                }

                $runSheet->update([
                    'status'          => 'done',
                    'processed_count' => $processed,
                    'inserted_count'  => $inserted,
                    'updated_count'   => $updated,
                    'skipped_count'   => $skipped,
                    'finished_at'     => now(),
                ]);

                $run->increment('total_processed', $processed);
                $run->increment('total_inserted',  $inserted);
                $run->increment('total_updated',   $updated);
                $run->increment('total_skipped',   $skipped);
            }

            $run->update(['status' => 'done', 'finished_at' => now()]);
        } catch (\Throwable $e) {
            $run->update([
                'status'      => 'failed',
                'finished_at' => now(),
                'message'     => $e->getMessage(),
            ]);
        }
    }

    /** Normalize phone: digits only; prepend 0 if 10 digits start with 9. */
    public static function normalizePhone(?string $raw): ?string
    {
        if (!$raw) return null;
        $digits = preg_replace('/\D+/', '', $raw);
        if (!$digits) return null;
        if (strlen($digits) === 10 && $digits[0] === '9') {
            return '0' . $digits;
        }
        return $digits;
    }

    /**
     * Find existing row to UPDATE vs INSERT. Lookup priority:
     *
     *  1. (page_name, phone_number) — primary. Phone is the strongest
     *     identifier in this feed since there's no contact_id.
     *  2. Fallback: (page_name, name) — when phone is missing or no
     *     phone match found. Most recent wins kung may multiple.
     *  3. Return null kapag walang match → INSERT new row.
     */
    public static function findMatchedId(
        ?string $pageName,
        ?string $phone,
        ?string $name
    ): ?int {
        if (!$pageName) return null;

        if ($phone !== null && $phone !== '') {
            $row = CancelDetector::where('page_name', $pageName)
                ->where('phone_number', $phone)
                ->orderByDesc('id')
                ->first();
            if ($row) return (int) $row->id;
        }

        if ($name !== null && $name !== '') {
            $row = CancelDetector::where('page_name', $pageName)
                ->where('name', $name)
                ->orderByDesc('id')
                ->first();
            if ($row) return (int) $row->id;
        }

        return null;
    }
}
