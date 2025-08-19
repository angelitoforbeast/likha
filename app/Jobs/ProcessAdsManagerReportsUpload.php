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

                    // REQUIRED: page_name not null; KEY: day + ad_set_id
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
            'Day'                              => 'day',
            'Page Name'                        => 'page_name',
            'Campaign name'                    => 'campaign_name',
            'Ad Set Name'                      => 'ad_set_name',
            'Ad ID'                            => 'ad_id',
            'Delivery status'                  => 'campaign_delivery',
            'Amount spent (PHP)'               => 'amount_spent_php',
            'Purchases'                        => 'purchases',
            'Attribution setting'              => 'attribution_setting',
            'Result type'                      => 'result_type',
            'Results'                          => 'results',
            'Reach'                            => 'reach',
            'Impressions'                      => 'impressions',
            'Cost per result'                  => 'cost_per_result',
            'Ad Set Delivery'                  => 'ad_set_delivery',
            'Messaging conversations started'  => 'messaging_conversations_started',
            'Campaign ID'                      => 'campaign_id',
            'Ad set ID'                        => 'ad_set_id',
            'Ad Set Budget'                    => 'ad_set_budget',
            'Ad Set Budget Type'               => 'ad_set_budget_type',
            'Reporting starts'                 => 'reporting_starts',
            'Reporting ends'                   => 'reporting_ends',
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
        // decimals
foreach (['amount_spent_php','cost_per_result','ad_set_budget'] as $decCol) {
    if (array_key_exists($decCol, $row)) $row[$decCol] = $this->toDecimal($row[$decCol]);
}

/** 🔧 Apply 12% multiplier to amount_spent_php BEFORE saving */
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

            foreach ($rows as $r) {
                // UPDATE first (for accurate updated count)
                $updateData = [
                    'page_name'                       => $r['page_name'] ?? null,
                    'campaign_name'                   => $r['campaign_name'] ?? null,
                    'ad_set_name'                     => $r['ad_set_name'] ?? null,
                    'ad_id'                           => $r['ad_id'] ?? null,
                    'campaign_delivery'               => $r['campaign_delivery'] ?? null,
                    'amount_spent_php'                => $r['amount_spent_php'] ?? null,
                    'purchases'                       => $r['purchases'] ?? null,
                    'attribution_setting'             => $r['attribution_setting'] ?? null,
                    'result_type'                     => $r['result_type'] ?? null,
                    'results'                         => $r['results'] ?? null,
                    'reach'                           => $r['reach'] ?? null,
                    'impressions'                     => $r['impressions'] ?? null,
                    'cost_per_result'                 => $r['cost_per_result'] ?? null,
                    'ad_set_delivery'                 => $r['ad_set_delivery'] ?? null,
                    'messaging_conversations_started' => $r['messaging_conversations_started'] ?? null,
                    'campaign_id'                     => $r['campaign_id'] ?? null,
                    'ad_set_budget'                   => $r['ad_set_budget'] ?? null,
                    'ad_set_budget_type'              => $r['ad_set_budget_type'] ?? null,
                    'reporting_starts'                => $r['reporting_starts'] ?? null,
                    'reporting_ends'                  => $r['reporting_ends'] ?? null,
                    'updated_at'                      => $now,
                ];

                $affected = DB::table('ads_manager_reports')
                    ->where('day', $r['day'])
                    ->where('ad_set_id', $r['ad_set_id'])
                    ->update($updateData);

                if ($affected === 0) {
                    // INSERT
                    DB::table('ads_manager_reports')->insert(array_merge([
                        'day'        => $r['day'],
                        'ad_set_id'  => $r['ad_set_id'],
                        'created_at' => $now,
                    ], $updateData));
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
}
