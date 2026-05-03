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

                    $subDate    = self::parseSubscriptionDate($rawSubDate);
                    $uploadDate = self::parseUploadDate($rawUploadDate);
                    $phone      = self::normalizePhone($rawPhone);

                    $payload = [
                        'subscription_date'     => $subDate,
                        'subscription_date_raw' => $rawSubDate ?: null,
                        'upload_date'           => $uploadDate,
                        'upload_date_raw'       => $rawUploadDate ?: null,
                        'page_name'             => $pageName,
                        'name'                  => $name,
                        'phone_number'          => $phone,
                        'all_cx_details'        => $cxDetails ?: null,
                        'response_tracker'      => $responseTrack ?: null,
                        'imported_run_id'       => $run->id,
                    ];

                    // Upsert: lookup by (page_name, name); phone is tiebreaker on multi-match.
                    $matchedId = self::findMatchedId($pageName, $name, $phone);
                    if ($matchedId) {
                        ConversationTracker::where('id', $matchedId)->update($payload);
                        $updated++;
                    } else {
                        ConversationTracker::create($payload);
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
     * Parse subscription date string (multi-format) to Y-m-d H:i:s.
     * Public+static so the controller / tests can reuse it.
     */
    public static function parseSubscriptionDate(?string $raw): ?string
    {
        if (!$raw) return null;
        $s = trim($raw);
        if ($s === '') return null;

        $formats = [
            'F j, Y g:i A',  // "May 2, 2026 6:35 PM"
            'F j, Y h:i A',  // padded variant
            'j/M/Y H:i',     // "2/May/2026 18:35"
            'j/M/Y G:i',     // single-digit hour
            'Y-m-d H:i:s',
            'Y-m-d H:i',
        ];
        foreach ($formats as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $s);
            if ($dt !== false) return $dt->format('Y-m-d H:i:s');
        }
        try {
            return (new \DateTime($s))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Parse Unix-seconds integer to PH-time DATETIME. */
    public static function parseUploadDate(?string $raw): ?string
    {
        if (!$raw) return null;
        $n = (int) trim($raw);
        if ($n <= 0) return null;
        // ms safety: if it's a 13+ digit number, assume milliseconds.
        if ($n > 9_999_999_999) {
            $n = (int) ($n / 1000);
        }
        return Carbon::createFromTimestamp($n)
            ->setTimezone('Asia/Manila')
            ->format('Y-m-d H:i:s');
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
     * Lookup by (page_name, name). Returns the matching row id, or null.
     * If multiple rows match, use phone_number as tiebreaker. If still
     * ambiguous, return null (caller will INSERT a new row).
     */
    public static function findMatchedId(?string $pageName, ?string $name, ?string $phone): ?int
    {
        if (!$pageName || !$name) return null;

        $candidates = ConversationTracker::where('page_name', $pageName)
            ->where('name', $name)
            ->select('id', 'phone_number')
            ->get();

        if ($candidates->isEmpty()) return null;
        if ($candidates->count() === 1) return (int) $candidates->first()->id;

        // Multiple candidates → phone tiebreaker.
        $phoneMatches = $candidates->filter(
            fn ($c) => self::normalizePhone($c->phone_number) === $phone && $phone !== null
        );
        if ($phoneMatches->count() === 1) return (int) $phoneMatches->first()->id;

        return null; // ambiguous — let caller INSERT
    }
}
