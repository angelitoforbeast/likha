<?php

namespace App\Jobs;

use App\Models\JntChatblastGsheetSetting;
use Carbon\Carbon;
use Google\Client as GoogleClient;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExportJntStatusToGsheet implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200; // 20 mins

    public function __construct(
        public string $status = 'All',
        public string $dateRangeRaw = ''
    ) {}

    public function handle(): void
    {
        $setting = JntChatblastGsheetSetting::latest('id')->first();
        if (!$setting) {
            Log::warning('ExportJntStatusToGsheet: no settings found.');
            return;
        }

        $spreadsheetId = $this->extractSpreadsheetId($setting->sheet_url);
        if (!$spreadsheetId) {
            Log::error('ExportJntStatusToGsheet: invalid sheet URL in settings.');
            return;
        }

        // ===== filters (same logic as your controller) =====
        $allowed = ['All','Delivered','For Return','Returned','In Transit','Delivering','In Transit + Delivering'];
        $status = in_array($this->status, $allowed, true) ? $this->status : 'All';

        $defaultStart = Carbon::now('Asia/Manila')->startOfMonth()->subMonth()->startOfDay();
        $defaultEnd   = Carbon::now('Asia/Manila')->endOfMonth()->endOfDay();

        [$startAt, $endAt] = $this->parseDateRange(trim($this->dateRangeRaw), $defaultStart, $defaultEnd);

        $driver = DB::getDriverName();

        $q = DB::table('from_jnts as j')
            ->select([
                'j.submission_time',
                'j.waybill_number',
                'j.receiver',
                'j.sender',
                'j.cod',
                'j.status',
                'j.rts_reason',
                'j.signingtime',
                'j.item_name',
                'mo.botcake_psid as botcake_psid',
                'psm.page as page',
            ]);

        $q->leftJoin(DB::raw("
            (SELECT waybill, MAX(botcake_psid) AS botcake_psid
             FROM macro_output
             GROUP BY waybill) mo
        "), 'mo.waybill', '=', 'j.waybill_number');

        if ($driver === 'mysql') {
            $q->leftJoin(DB::raw("
                (SELECT sender_name, MAX(page) AS page
                 FROM page_sender_mappings
                 GROUP BY sender_name) psm
            "), function ($join) {
                $join->on(DB::raw('BINARY psm.sender_name'), '=', DB::raw('BINARY j.sender'));
            });
        } else {
            $q->leftJoin(DB::raw('
                (
                  SELECT "SENDER_NAME" AS sender_name, MAX("PAGE") AS page
                  FROM page_sender_mappings
                  GROUP BY "SENDER_NAME"
                ) psm
            '), function ($join) {
                $join->on('psm.sender_name', '=', 'j.sender');
            });
        }

        $q->whereBetween('j.submission_time', [$startAt->toDateTimeString(), $endAt->toDateTimeString()]);

        // Status rules (same as yours)
        if ($status !== 'All') {
            if ($status === 'In Transit + Delivering') {
                $q->where(function ($w) {
                    $w->where('j.status', 'In Transit')
                      ->orWhere(function ($d) {
                          $d->where('j.status', 'Delivering')
                            ->where(function ($rr) {
                                $rr->whereNull('j.rts_reason')
                                   ->orWhere(DB::raw("TRIM(COALESCE(j.rts_reason,''))"), '=', '');
                            });
                      });
                });
            } elseif ($status === 'Delivering') {
                $q->where('j.status', 'Delivering')
                  ->where(function ($rr) {
                      $rr->whereNull('j.rts_reason')
                         ->orWhere(DB::raw("TRIM(COALESCE(j.rts_reason,''))"), '=', '');
                  });
            } else {
                $q->where('j.status', '=', $status);
            }
        }

        $q->orderByDesc('j.submission_time');

        // ===== Google Sheets service =====
        $client = new GoogleClient();
        $client->setApplicationName('Laravel JNT Export');
        $client->setScopes([Sheets::SPREADSHEETS]);
        $client->setAuthConfig(storage_path('app/credentials.json'));
        $client->setAccessType('offline');

        $service = new Sheets($client);

        $range = $setting->sheet_range; // ex: Export!A:K

        // ===== append in chunks using cursor (memory safe) =====
        $chunk = [];
        $chunkSize = 500;
        $written = 0;

        foreach ($q->cursor() as $r) {
            $chunk[] = [
                (string)($r->submission_time ?? ''),
                (string)($r->waybill_number ?? ''),
                (string)($r->receiver ?? ''),
                (string)($r->sender ?? ''),
                (string)($r->page ?? ''),
                (string)($r->cod ?? ''),
                (string)($r->status ?? ''),
                (string)($r->rts_reason ?? ''),
                (string)($r->signingtime ?? ''),
                (string)($r->item_name ?? ''),
                (string)($r->botcake_psid ?? ''),
            ];

            if (count($chunk) >= $chunkSize) {
                $written += $this->appendChunk($service, $spreadsheetId, $range, $chunk);
                $chunk = [];
            }
        }

        if (!empty($chunk)) {
            $written += $this->appendChunk($service, $spreadsheetId, $range, $chunk);
        }

        Log::info("ExportJntStatusToGsheet: done. rows={$written}");
    }

    private function appendChunk(Sheets $service, string $spreadsheetId, string $range, array $rows): int
    {
        $body = new ValueRange(['values' => $rows]);

        $service->spreadsheets_values->append(
            $spreadsheetId,
            $range,
            $body,
            [
                'valueInputOption' => 'USER_ENTERED',
                'insertDataOption' => 'INSERT_ROWS',
            ]
        );

        return count($rows);
    }

    private function extractSpreadsheetId(string $url): ?string
    {
        if (preg_match('/\/d\/([a-zA-Z0-9-_]+)/', $url, $m)) return $m[1];
        return null;
    }

    private function parseDateRange(string $raw, Carbon $fallbackStart, Carbon $fallbackEnd): array
    {
        if ($raw === '') return [$fallbackStart, $fallbackEnd];

        $parts = preg_split('/\s+to\s+/i', $raw);
        if (!$parts || count($parts) !== 2) return [$fallbackStart, $fallbackEnd];

        try {
            $start = Carbon::parse(trim($parts[0]), 'Asia/Manila')->startOfDay();
            $end   = Carbon::parse(trim($parts[1]), 'Asia/Manila')->endOfDay();

            if ($end->lessThan($start)) {
                [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
            }

            return [$start, $end];
        } catch (\Throwable $e) {
            return [$fallbackStart, $fallbackEnd];
        }
    }
}
