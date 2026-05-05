<?php

namespace App\Jobs;

use App\Models\ConversationTracker;
use App\Models\ConversationTrackerSetting;
use App\Models\ConversationTrackerRun;
use App\Models\ConversationTrackerRunSheet;
use Carbon\Carbon;
use Google_Client;
use Google_Service_Sheets;
use Google_Service_Sheets_ValueRange;
use Google_Service_Sheets_BatchUpdateValuesRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ImportConversationTrackerFromGoogleSheet implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $runId;

    public function __construct(int $runId)
    {
        $this->runId = $runId;
    }

    public function handle()
    {
        $run = ConversationTrackerRun::find($this->runId);
        if (!$run) return;

        $client = new Google_Client();
        $client->setAuthConfig(storage_path('app/credentials.json'));
        $client->addScope(Google_Service_Sheets::SPREADSHEETS);
        $service = new Google_Service_Sheets($client);

        $settings = ConversationTrackerSetting::orderBy('id')->get();

        try {
            foreach ($settings as $setting) {
                $runSheet = ConversationTrackerRunSheet::where('run_id', $run->id)
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
                $colRange  = $setting->range ?: 'A2:J';
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
                    // Skip if col J already says DONE.
                    $doneFlag = strtolower(preg_replace('/\s+/', '', $row[9] ?? ''));
                    if ($doneFlag === 'done') {
                        $skipped++;
                        continue;
                    }

                    $processed++;

                    $rawSubDate    = trim((string)($row[0] ?? ''));
                    $rawUploadDate = trim((string)($row[1] ?? ''));
                    $pageName      = trim((string)($row[2] ?? '')) ?: null;
                    $name          = trim((string)($row[3] ?? '')) ?: null;
                    $rawPhone      = trim((string)($row[4] ?? ''));
                    $cxDetails     = (string)($row[5] ?? '');
                    $responseTrack = (string)($row[6] ?? '');
                    $contactId     = trim((string)($row[7] ?? '')) ?: null;

                    $subDate    = self::parseSubscriptionDate($rawSubDate);
                    $uploadDate = self::parseUploadDate($rawUploadDate);
                    $phone      = self::normalizePhone($rawPhone);

                    // Build full payload from incoming row.
                    $newRow = [
                        'subscription_date'     => $subDate,
                        'subscription_date_raw' => $rawSubDate ?: null,
                        'upload_date'           => $uploadDate,
                        'upload_date_raw'       => $rawUploadDate ?: null,
                        'page_name'             => $pageName,
                        'name'                  => $name,
                        'phone_number'          => $phone,
                        'contact_id'            => $contactId,
                        'all_cx_details'        => $cxDetails ?: null,
                        'response_tracker'      => $responseTrack ?: null,
                        'imported_run_id'       => $run->id,
                    ];

                    // Upsert lookup:
                    //  1. If contact_id present → primary key (page_name, contact_id)
                    //  2. Else → fallback to (page_name, name) + phone tiebreaker
                    $matchedId = self::findMatchedId($pageName, $name, $phone, $contactId);
                    if ($matchedId) {
                        // MERGE SEMANTICS: don't overwrite existing data with empty/null
                        // for these fields (preserves phone, dates, message bodies when
                        // new upload is partial).
                        $mergeFields = [
                            'phone_number',
                            'subscription_date',
                            'subscription_date_raw',
                            'all_cx_details',
                            'response_tracker',
                            'name',
                            'contact_id',
                        ];
                        $updatePayload = $newRow;
                        foreach ($mergeFields as $f) {
                            if ($updatePayload[$f] === null || $updatePayload[$f] === '') {
                                unset($updatePayload[$f]); // skip — keep existing
                            }
                        }
                        ConversationTracker::where('id', $matchedId)->update($updatePayload);
                        $updated++;
                    } else {
                        ConversationTracker::create($newRow);
                        $inserted++;
                    }

                    // Mark DONE in column J (index 9 = J, 0-based).
                    $rowNumber = $index + 2; // +1 for 1-based, +1 for header
                    $updates[] = [
                        'range'  => "{$sheetName}!J{$rowNumber}",
                        'values' => [['DONE']],
                    ];

                    // Save progress every 25 rows.
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
                            fn($d) => new Google_Service_Sheets_ValueRange($d),
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

    /**
     * Universal date parser — handles ALL date formats in one place.
     * Public+static so the controller / tests can reuse it.
     *
     * Supports:
     *   - Unix timestamp seconds  (e.g., "1777743727" → 2026-05-02 ...)
     *   - Unix timestamp milliseconds (13+ digits)
     *   - Excel serial numbers    (e.g., "46146.87917" → 2026-05-04 21:06)
     *   - "May 2, 2026 6:35 PM"   (Month Day, Year h:mm AM/PM)
     *   - "4 May 2026 17:57"      (Day Month Year HH:mm)
     *   - "4/May/2026 18:35"      (Day/Month/Year HH:mm)
     *   - "02/May/2026 23:48"     (DD/Month/YYYY HH:mm)
     *   - "03/05/2026 20:13"      (DD/MM/YYYY — heuristic-disambiguated)
     *   - "15 January 2026 14:53"
     *   - ISO formats (Y-m-d H:i:s)
     *   - Lenient \DateTime fallback for unrecognized formats
     *
     * For DD/MM/YYYY ambiguity (both ≤ 12), heuristic prefers PAST dates
     * and CLOSER-TO-TODAY interpretation. PH context = DD/MM is preferred
     * when otherwise ambiguous.
     */
    public static function parseSubscriptionDate(?string $raw): ?string
    {
        if (!$raw) return null;
        $s = trim($raw);
        if ($s === '') return null;

        // ─── Numeric detection (Unix timestamps + Excel serials) ───
        if (is_numeric($s)) {
            $n = (float) $s;

            try {
                // Unix timestamp in milliseconds (13+ digits)
                if ($n >= 1_000_000_000_000 && $n <= 9_999_999_999_999) {
                    return Carbon::createFromTimestamp((int) ($n / 1000))
                        ->setTimezone('Asia/Manila')
                        ->format('Y-m-d H:i:s');
                }

                // Unix timestamp in seconds (10 digits, ~year 2001 onwards)
                if ($n >= 1_000_000_000 && $n <= 9_999_999_999) {
                    return Carbon::createFromTimestamp((int) $n)
                        ->setTimezone('Asia/Manila')
                        ->format('Y-m-d H:i:s');
                }

                // Excel serial number (range 25569 = 1970, 100000 ≈ 2173)
                if ($n >= 25569 && $n <= 100000) {
                    $unix = (int) round(($n - 25569) * 86400);
                    return Carbon::createFromTimestamp($unix)
                        ->setTimezone('Asia/Manila')
                        ->format('Y-m-d H:i:s');
                }
            } catch (\Throwable $e) {
                // fall through to format string attempts
            }
        }

        // ─── Unambiguous formats first ───
        $unambiguousFormats = [
            'F j, Y g:i A',  // "May 2, 2026 6:35 PM"
            'F j, Y h:i A',  // padded variant
            'F j, Y H:i',    // "May 2, 2026 18:35"
            'j F Y H:i',     // "4 May 2026 17:57"
            'j F Y G:i',     // single-digit hour
            'd F Y H:i',     // "15 January 2026 14:53"
            'j/M/Y H:i',     // "2/May/2026 18:35"
            'j/M/Y G:i',     // single-digit hour
            'd/M/Y H:i',     // "02/May/2026 23:48"
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y-m-d\TH:i:s',  // ISO-8601
        ];
        foreach ($unambiguousFormats as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $s);
            if ($dt !== false && \DateTime::getLastErrors() === false) {
                return $dt->format('Y-m-d H:i:s');
            }
        }

        // ─── Ambiguous DD/MM/YYYY vs MM/DD/YYYY (numeric slashes) ───
        // Pattern: NN/NN/YYYY [HH:mm[:ss]]
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$#', $s, $m)) {
            $a    = (int) $m[1];
            $b    = (int) $m[2];
            $year = (int) $m[3];
            $hour = isset($m[4]) ? (int) $m[4] : 0;
            $min  = isset($m[5]) ? (int) $m[5] : 0;
            $sec  = isset($m[6]) ? (int) $m[6] : 0;

            $picked = self::pickAmbiguousDayMonth($a, $b, $year);
            if ($picked !== null) {
                [$day, $mon] = $picked;
                if (checkdate($mon, $day, $year)) {
                    $time = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $mon, $day, $hour, $min, $sec);
                    return $time;
                }
            }
        }

        // ─── Lenient \DateTime fallback ───
        try {
            $dt = new \DateTime($s);
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Disambiguate "NN/NN/YYYY" — return [day, month] tuple.
     *
     * Logic:
     *   - If only one interpretation valid (e.g., "15/05" — 15 is not a month),
     *     pick that one automatically.
     *   - If both valid (both ≤ 12), apply heuristics:
     *       Rule 1: Prefer PAST date (subscriptions are past events)
     *       Rule 2: Among past, prefer CLOSER to today
     *       Fallback: Prefer DD/MM (PH context)
     *
     * Returns null if neither interpretation is valid.
     */
    private static function pickAmbiguousDayMonth(int $a, int $b, int $year): ?array
    {
        // Try as DD/MM (PH)
        $phValid = ($a >= 1 && $a <= 31) && ($b >= 1 && $b <= 12);
        // Try as MM/DD (US)
        $usValid = ($a >= 1 && $a <= 12) && ($b >= 1 && $b <= 31);

        // Filter by valid actual dates (e.g., Feb 30 invalid)
        $phReal = $phValid && checkdate($b, $a, $year);
        $usReal = $usValid && checkdate($a, $b, $year);

        if (!$phReal && !$usReal) return null;
        if ($phReal && !$usReal) return [$a, $b];      // [day, month] — PH order
        if (!$phReal && $usReal) return [$b, $a];      // PH order returned: [day, month]

        // Both real — apply heuristic
        $phTs = mktime(0, 0, 0, $b, $a, $year); // PH: month=$b, day=$a
        $usTs = mktime(0, 0, 0, $a, $b, $year); // US: month=$a, day=$b
        $today = time();

        $phPast = $phTs <= $today;
        $usPast = $usTs <= $today;

        if ($phPast && !$usPast) return [$a, $b];      // PH past, US future → PH
        if (!$phPast && $usPast) return [$b, $a];      // US past, PH future → US
        if ($phPast && $usPast) {
            // Both past — closer to today wins
            return abs($today - $phTs) <= abs($today - $usTs) ? [$a, $b] : [$b, $a];
        }

        // Both future — prefer PH (Philippine context default)
        return [$a, $b];
    }

    /**
     * Parse upload date — now delegates sa parseSubscriptionDate (universal parser).
     * Both subscription_date at upload_date columns ngayon ay handled
     * pareho — Unix timestamp, Excel serial, at all date string formats.
     */
    public static function parseUploadDate(?string $raw): ?string
    {
        return self::parseSubscriptionDate($raw);
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
     * Find an existing row to UPDATE (vs INSERT new). Lookup priority:
     *
     *  1. If contact_id is non-empty → PRIMARY: (page_name, contact_id)
     *     - 1 match → that row (UPDATE)
     *     - 0 matches → return null (INSERT)
     *     - 2+ matches → return most recent (rare; treat as duplicate)
     *
     *  2. Fallback (no contact_id) → (page_name, name) with phone tiebreaker
     *     - 1 match → that row
     *     - 2+ matches:
     *         - If phone present + 1 candidate matches phone → that one
     *         - Else if all candidates have null phone → most recent
     *         - Else → most recent (best-effort)
     *     - 0 matches → return null (INSERT)
     */
    public static function findMatchedId(
        ?string $pageName,
        ?string $name,
        ?string $phone,
        ?string $contactId = null
    ): ?int {
        if (!$pageName) return null;

        // PRIMARY: (page_name, contact_id) when contact_id is given.
        if ($contactId !== null && $contactId !== '') {
            $row = ConversationTracker::where('page_name', $pageName)
                ->where('contact_id', $contactId)
                ->orderByDesc('id')
                ->first();
            return $row ? (int) $row->id : null;
        }

        // FALLBACK: (page_name, name).
        if (!$name) return null;

        $candidates = ConversationTracker::where('page_name', $pageName)
            ->where('name', $name)
            ->select('id', 'phone_number')
            ->orderByDesc('id')
            ->get();

        if ($candidates->isEmpty()) return null;
        if ($candidates->count() === 1) return (int) $candidates->first()->id;

        // Multiple candidates with same (page, name).
        if ($phone !== null && $phone !== '') {
            // Try phone tiebreaker.
            $phoneMatches = $candidates->filter(
                fn ($c) => self::normalizePhone($c->phone_number) === $phone
            );
            if ($phoneMatches->count() >= 1) {
                return (int) $phoneMatches->first()->id; // most recent first
            }
            // No phone match → different person → INSERT
            return null;
        }

        // No phone in upload → assume same person, pick most recent.
        return (int) $candidates->first()->id;
    }
}
