<?php

namespace App\Jobs;

use Carbon\Carbon;
use App\Models\UploadLog;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

// OpenSpout (v3 or v4 supported)
use OpenSpout\Reader\Common\Creator\ReaderEntityFactory;
use OpenSpout\Reader\CSV\Reader as CsvReaderV4;
use OpenSpout\Reader\XLSX\Reader as XlsxReaderV4;

class ProcessAdsManagerReportsUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries   = 1;

    private ?int $uploadLogId = null;

    private int $processed = 0;
    private int $skipped   = 0;
    private int $inserted  = 0;
    private int $updated   = 0;

    private const CHUNK_SIZE = 2000;

    public function __construct(
        public string $storedPath,  // storage-relative path (disk=local)
        public ?int   $userId = null,
        ?int          $uploadLogId = null
    ) {
        $this->uploadLogId = $uploadLogId;
    }

    public function handle(): void
    {
        @set_time_limit(0);

        $log = $this->uploadLogId ? UploadLog::find($this->uploadLogId) : null;

        try {
            if ($log) $log->update(['status' => 'processing', 'started_at' => now()]);

            $fullPath = Storage::disk('local')->path($this->storedPath);
            if (!file_exists($fullPath)) {
                Log::error("[AdsMgr Import] File not found: {$fullPath}");
                if ($log) $log->update(['status' => 'failed', 'finished_at' => now()]);
                return;
            }

            $ext    = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $reader = $this->createReader($ext);
            if (!$reader) {
                Log::error("[AdsMgr Import] No compatible reader for .$ext (Install OpenSpout v3/v4).");
                if ($log) $log->update(['status' => 'failed', 'finished_at' => now()]);
                return;
            }

            $reader->open($fullPath);

            $headerIndex = null;                     // normalized header => column index
            $mapNorm     = $this->mappedHeaders();   // normalized Excel header => DB column
            $buffer      = [];

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = $row->toArray();

                    if ($headerIndex === null) {
                        $headerIndex = $this->buildHeaderIndex($cells);
                        continue;
                    }

                    $normRow = $this->normalizeRow($cells, $headerIndex, $mapNorm, $ext);

                    // REQUIRED: page_name not null; KEY: day + ad_set_id (current behavior)
                    if (empty($normRow['page_name']) || empty($normRow['ad_set_id'])) {
                        $this->skipped++;
                        continue;
                    }

                    // derive day from reporting_starts (date-only) if missing
                    if (empty($normRow['day']) && !empty($normRow['reporting_starts'])) {
                        $normRow['day'] = substr($normRow['reporting_starts'], 0, 10);
                    }
                    if (empty($normRow['day'])) {
                        $this->skipped++;
                        continue;
                    }

                    $buffer[] = $normRow;
                    $this->processed++;

                    if (count($buffer) >= self::CHUNK_SIZE) {
                        $this->upsertChunk($buffer);
                        $buffer = [];
                        $this->touchProgress($log);
                    }
                }
            }

            if (!empty($buffer)) {
                $this->upsertChunk($buffer);
                $this->touchProgress($log);
            }

            $reader->close();

            if ($log) {
                $log->update([
                    'status'         => 'done',
                    'processed_rows' => $this->processed,
                    'inserted'       => $this->inserted,
                    'updated'        => $this->updated,
                    'skipped'        => $this->skipped,
                    'finished_at'    => now(),
                ]);
            }

            Log::info("[AdsMgr Import] Done. processed={$this->processed}, inserted={$this->inserted}, updated={$this->updated}, skipped={$this->skipped}, file={$this->storedPath}, user={$this->userId}");
        } catch (\Throwable $e) {
            if ($log) {
                $log->update([
                    'status'         => 'failed',
                    'processed_rows' => $this->processed,
                    'inserted'       => $this->inserted,
                    'updated'        => $this->updated,
                    'skipped'        => $this->skipped,
                    'finished_at'    => now(),
                ]);
            }
            throw $e;
        }
    }

    private function touchProgress(?UploadLog $log): void
    {
        if (!$log) return;
        $log->update([
            'processed_rows' => $this->processed,
            'inserted'       => $this->inserted,
            'updated'        => $this->updated,
            'skipped'        => $this->skipped,
        ]);
    }

    private function createReader(string $ext)
    {
        // Prefer OpenSpout v3 factory
        if (class_exists(ReaderEntityFactory::class)) {
            if ($ext === 'xlsx') {
                return ReaderEntityFactory::createXLSXReader();
            } elseif (in_array($ext, ['csv','txt'])) {
                $r = ReaderEntityFactory::createCSVReader();
                $r->setFieldDelimiter(',');
                $r->setFieldEnclosure('"');
                $r->setEndOfLineCharacter("\n");
                $r->setEncoding('UTF-8');
                return $r;
            }
            return null;
        }

        // v4 fallback
        if ($ext === 'xlsx' && class_exists(XlsxReaderV4::class)) {
            return new XlsxReaderV4();
        } elseif (in_array($ext, ['csv','txt']) && class_exists(CsvReaderV4::class)) {
            $r = new CsvReaderV4();
            $r->setFieldDelimiter(',');
            $r->setFieldEnclosure('"');
            $r->setEndOfLineCharacter("\n");
            $r->setEncoding('UTF-8');
            return $r;
        }

        return null;
    }

    /** Excel header -> DB column (direct) */
    private function mappedHeaders(): array
    {
        $map = [
            'day'                             => 'day',
            'page name'                       => 'page_name',
            'campaign name'                   => 'campaign_name',
            'ad set name'                     => 'ad_set_name',
            'ad id'                           => 'ad_id',
            'delivery status'                 => 'campaign_delivery',
            'amount spent (php)'              => 'amount_spent_php',
            'purchases'                       => 'purchases',
            'attribution setting'             => 'attribution_setting',
            'result type'                     => 'result_type',
            'results'                         => 'results',
            'reach'                           => 'reach',
            'impressions'                     => 'impressions',
            'cost per result'                 => 'cost_per_result',
            'ad set delivery'                 => 'ad_set_delivery',
            'messaging conversations started' => 'messaging_conversations_started',
            'campaign id'                     => 'campaign_id',
            'ad set id'                       => 'ad_set_id',
            'ad set budget'                   => 'ad_set_budget',
            'ad set budget type'              => 'ad_set_budget_type',
            'reporting starts'                => 'reporting_starts',
            'reporting ends'                  => 'reporting_ends',

            // Creatives
            'body (ad settings)'              => 'body_ad_settings',
            'headline'                        => 'headline',

            // OPTIONAL: Messenger templates
            'welcome message'                 => 'welcome_message',
            'quick reply 1'                   => 'quick_reply_1',
            'quick reply 2'                   => 'quick_reply_2',
            'quick reply 3'                   => 'quick_reply_3',
        ];
        $norm = fn($s)=> strtolower(trim(preg_replace('/\s+/', ' ', (string)$s)));
        $out = [];
        foreach ($map as $excelHeader => $dbCol) {
            $out[$norm($excelHeader)] = $dbCol;
        }
        return $out;
    }

    /** Build normalized header index: headerLabel(norm) => columnIndex */
    private function buildHeaderIndex(array $headers): array
    {
        $norm = fn($s)=> strtolower(trim(preg_replace('/\s+/', ' ', (string)$s)));
        $idx  = [];
        foreach ($headers as $i => $label) {
            $idx[$norm($label)] = $i;
        }
        return $idx;
    }

    private function normalizeRow(array $cells, array $headerIndex, array $mapNorm, string $ext): array
    {
        $getByHeader = function (string $excelHeaderNorm) use ($cells, $headerIndex) {
            if (!isset($headerIndex[$excelHeaderNorm])) return null;
            $val = $cells[$headerIndex[$excelHeaderNorm]] ?? null;
            return is_scalar($val) ? trim((string)$val) : (is_null($val) ? null : trim((string)$val));
        };

        $row = [];
        foreach ($mapNorm as $excelHeaderNorm => $dbCol) {
            $row[$dbCol] = $getByHeader($excelHeaderNorm);
        }

        // integers
        foreach (['reach','impressions','results','purchases','messaging_conversations_started'] as $intCol) {
            if (array_key_exists($intCol, $row)) $row[$intCol] = $this->toInt($row[$intCol]);
        }

        // decimals
        foreach (['amount_spent_php','cost_per_result','ad_set_budget'] as $decCol) {
            if (array_key_exists($decCol, $row)) $row[$decCol] = $this->toDecimal($row[$decCol]);
        }

        /** Apply 12% multiplier to amount_spent_php BEFORE saving */
        if (array_key_exists('amount_spent_php', $row) && $row['amount_spent_php'] !== null) {
            $row['amount_spent_php'] = round($row['amount_spent_php'] * 1.12, 2); // fits decimal(12,2)
        }

        // datetimes (full)
        foreach (['reporting_starts','reporting_ends'] as $dtCol) {
            if (array_key_exists($dtCol, $row)) $row[$dtCol] = $this->toDateTime($row[$dtCol], $ext);
        }

        // date-only (Day)
        if (array_key_exists('day', $row)) {
            $row['day'] = $this->toDate($row['day'], $ext);
        }

        // Nullify empty strings
        foreach ($row as $k => $v) {
            if ($v === '') $row[$k] = null;
        }

        return $row;
    }

    private function toInt($v): ?int
    {
        if ($v === null) return null;
        $v = preg_replace('/[^\d\-]/', '', (string)$v);
        if ($v === '' || $v === '-') return null;
        return (int) $v;
    }

    private function toDecimal($v): ?float
    {
        if ($v === null) return null;
        $v = str_replace([',','₱','PHP','php',' '], '', (string)$v);
        if ($v === '' || $v === '-') return null;
        return (float) $v;
    }

    private function toDateTime($v, string $ext): ?string
    {
        if ($v === null || $v === '') return null;

        if (in_array($ext, ['xlsx','xls']) && is_numeric($v) && class_exists(\PhpOffice\PhpSpreadsheet\Shared\Date::class)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($v)->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {}
        }

        $cands = [
            'Y-m-d H:i:s','Y-m-d H:i','d-m-Y H:i','d/m/Y H:i','m/d/Y H:i',
            'd-m-Y','Y-m-d','H:i d-m-Y','H:i d/m/Y',
        ];
        foreach ($cands as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, (string)$v, 'Asia/Manila')->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {}
        }

        try {
            return Carbon::parse((string)$v, 'Asia/Manila')->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            if (is_numeric($v)) {
                try {
                    $base = Carbon::create(1899, 12, 30, 0, 0, 0, 'Asia/Manila');
                    $days = floor((float)$v);
                    $frac = (float)$v - $days;
                    $secs = (int) round($frac * 86400);
                    return $base->copy()->addDays((int)$days)->addSeconds($secs)->format('Y-m-d H:i:s');
                } catch (\Throwable $e2) {}
            }
            return null;
        }
    }

    private function toDate($v, string $ext): ?string
    {
        if ($v === null || $v === '') return null;

        if (in_array($ext, ['xlsx','xls']) && is_numeric($v) && class_exists(\PhpOffice\PhpSpreadsheet\Shared\Date::class)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($v)->format('Y-m-d');
            } catch (\Throwable $e) {}
        }

        $cands = ['Y-m-d','m/d/Y','d/m/Y','d-m-Y','m-d-Y'];
        foreach ($cands as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, (string)$v, 'Asia/Manila')->format('Y-m-d');
            } catch (\Throwable $e) {}
        }

        try {
            return Carbon::parse((string)$v, 'Asia/Manila')->format('Y-m-d');
        } catch (\Throwable $e) {
            if (is_numeric($v)) {
                try {
                    $base = Carbon::create(1899, 12, 30, 0, 0, 0, 'Asia/Manila');
                    return $base->copy()->addDays((int)$v)->format('Y-m-d');
                } catch (\Throwable $e2) {}
            }
            return null;
        }
    }

    private function upsertChunk(array $rows): void
    {
        DB::beginTransaction();
        try {
            $now = now()->toDateTimeString();

            // 1) Upsert creatives in a PG-safe way (no exceptions on conflicts)
            $this->upsertCreativesForChunk($rows, $now);

            // 2) Upsert facts into ads_manager_reports (keep creatives fields for now)
            foreach ($rows as $r) {
                $updateData = ['updated_at' => $now];

                // Core fields
                $this->putIfExists($updateData, $r, 'page_name');
                $this->putIfExists($updateData, $r, 'campaign_name');
                $this->putIfExists($updateData, $r, 'ad_set_name');
                $this->putIfExists($updateData, $r, 'ad_id');
                $this->putIfExists($updateData, $r, 'campaign_delivery');
                $this->putIfExists($updateData, $r, 'amount_spent_php');
                $this->putIfExists($updateData, $r, 'purchases');
                $this->putIfExists($updateData, $r, 'attribution_setting');
                $this->putIfExists($updateData, $r, 'result_type');
                $this->putIfExists($updateData, $r, 'results');
                $this->putIfExists($updateData, $r, 'reach');
                $this->putIfExists($updateData, $r, 'impressions');
                $this->putIfExists($updateData, $r, 'cost_per_result');
                $this->putIfExists($updateData, $r, 'ad_set_delivery');
                $this->putIfExists($updateData, $r, 'messaging_conversations_started');
                $this->putIfExists($updateData, $r, 'campaign_id');
                $this->putIfExists($updateData, $r, 'ad_set_budget');
                $this->putIfExists($updateData, $r, 'ad_set_budget_type');
                $this->putIfExists($updateData, $r, 'reporting_starts');
                $this->putIfExists($updateData, $r, 'reporting_ends');

                // Creatives (still mirrored here for now)
                $this->putIfExists($updateData, $r, 'body_ad_settings');
                $this->putIfExists($updateData, $r, 'headline');
                $this->putIfExists($updateData, $r, 'welcome_message');
                $this->putIfExists($updateData, $r, 'quick_reply_1');
                $this->putIfExists($updateData, $r, 'quick_reply_2');
                $this->putIfExists($updateData, $r, 'quick_reply_3');

                $affected = DB::table('ads_manager_reports')
                    ->where('day', $r['day'])
                    ->where('ad_set_id', $r['ad_set_id'])
                    ->update($updateData);

                if ($affected === 0) {
                    $insertData = array_merge([
                        'day'        => $r['day'],
                        'ad_set_id'  => $r['ad_set_id'],
                        'created_at' => $now,
                    ], $updateData);

                    DB::table('ads_manager_reports')->insert($insertData);
                    $this->inserted++;
                } else {
                    $this->updated++;
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[AdsMgr Import] upsertChunk failed: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);
            throw $e;
        }
    }

    /**
     * Batch upsert creatives into ad_campaign_creatives in a Postgres-safe way:
     *  - Uses insertOrIgnore to avoid unique-violation exceptions.
     *  - When attaching ad_id to an existing campaign_id row, we first check
     *    if some row already has that ad_id, to avoid conflicts.
     */
    private function upsertCreativesForChunk(array $rows, string $now): void
    {
        $payloads = [];
        $adIds = [];
        $campaignIds = [];

        foreach ($rows as $r) {
            $adId       = !empty($r['ad_id']) ? (string)$r['ad_id'] : null;
            $campaignId = !empty($r['campaign_id']) ? (string)$r['campaign_id'] : null;

            if (!$adId && !$campaignId) continue;

            $p = [
                'ad_id'            => $adId,
                'campaign_id'      => $campaignId,
                'campaign_name'    => $r['campaign_name'] ?? null,
                'page_name'        => $r['page_name'] ?? null,
                'ad_set_delivery'  => $r['ad_set_delivery'] ?? null,
                'headline'         => $r['headline'] ?? null,
                'body_ad_settings' => $r['body_ad_settings'] ?? null,
                'welcome_message'  => $r['welcome_message'] ?? null,
                'quick_reply_1'    => $r['quick_reply_1'] ?? null,
                'quick_reply_2'    => $r['quick_reply_2'] ?? null,
                'quick_reply_3'    => $r['quick_reply_3'] ?? null,
                'created_at'       => $now,
                'updated_at'       => $now,
            ];

            if ($adId) {
                $payloads["ad:{$adId}"] = $p;
                $adIds[] = $adId;
            } elseif ($campaignId) {
                $payloads["cmp:{$campaignId}"] = $p;
            }

            if ($campaignId) $campaignIds[] = $campaignId;
        }

        if (empty($payloads)) return;

        // Existing rows (to avoid unnecessary writes)
        $existingByAdId = [];
        if (!empty($adIds)) {
            $existingByAdId = DB::table('ad_campaign_creatives')
                ->whereIn('ad_id', array_values(array_unique($adIds)))
                ->get(['id','ad_id','campaign_id'])
                ->keyBy('ad_id')
                ->all();
        }

        $existingByCampaignId = [];
        if (!empty($campaignIds)) {
            $existingByCampaignId = DB::table('ad_campaign_creatives')
                ->whereIn('campaign_id', array_values(array_unique($campaignIds)))
                ->get(['id','ad_id','campaign_id'])
                ->keyBy('campaign_id')
                ->all();
        }

        $toInsert = [];
        $toUpdateAdId = []; // [id => ad_id] fill only if current is NULL

        foreach ($payloads as $key => $p) {
            $adId       = $p['ad_id'] ?? null;
            $campaignId = $p['campaign_id'] ?? null;

            if ($adId && isset($existingByAdId[$adId])) {
                continue;
            }

            if ($campaignId && isset($existingByCampaignId[$campaignId])) {
                $row = $existingByCampaignId[$campaignId];
                if ($adId && empty($row->ad_id)) {
                    $toUpdateAdId[(int)$row->id] = $adId;
                }
                continue;
            }

            $toInsert[] = $p;
        }

        // IMPORTANT: avoid exceptions on unique conflicts in Postgres
        if (!empty($toInsert)) {
            DB::table('ad_campaign_creatives')->insertOrIgnore($toInsert);
        }

        // Fill ad_id on existing campaign rows, but only if nobody else already owns that ad_id
        foreach ($toUpdateAdId as $id => $adId) {
            $taken = DB::table('ad_campaign_creatives')->where('ad_id', $adId)->exists();
            if ($taken) continue;

            DB::table('ad_campaign_creatives')
                ->where('id', $id)
                ->whereNull('ad_id')
                ->update([
                    'ad_id'      => $adId,
                    'updated_at' => $now,
                ]);
        }
    }

    /**
     * Add $key => $source[$key] into $dest only if key exists AND value !== null.
     * This prevents overwriting existing DB values to NULL when the Excel column is absent/blank.
     */
    private function putIfExists(array &$dest, array $source, string $key): void
    {
        if (array_key_exists($key, $source) && $source[$key] !== null) {
            $dest[$key] = $source[$key];
        }
    }
}
