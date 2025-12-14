<?php

namespace App\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

// OpenSpout (compat v3/v4)
use OpenSpout\Reader\Common\Creator\ReaderEntityFactory; // v3
use OpenSpout\Reader\CSV\Reader as CsvReaderV4;          // v4
use OpenSpout\Reader\XLSX\Reader as XlsxReaderV4;        // v4

class ProcessPaymentActivityUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries   = 1;

    private const CHUNK_SIZE = 2000;

    private int $rowsRead     = 0;
    private int $rowsMapped   = 0;
    private int $rowsInserted = 0;
    private int $batches      = 0;

    public function __construct(
        public string $storedPath,
        public string $originalName,
        public string $batchId,
        public string $uploadedBy = 'system',
        public ?string $diskName = null
    ) {}

    public function handle(): void
    {
        @set_time_limit(0);

        $reader  = null;
        $cleanup = [];

        try {
            $disk = $this->diskName ?: (string) config('filesystems.default', 'local');

            $fullPath = $this->localizeToTempPath($disk, $this->storedPath, $cleanup);

            if (!file_exists($fullPath)) {
                Log::error('[PAYMENT] File not found after localize', [
                    'disk' => $disk,
                    'path' => $this->storedPath,
                    'full' => $fullPath,
                ]);
                return;
            }

            $ext    = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $reader = $this->createReader($ext);

            if (!$reader) {
                Log::error('[PAYMENT] No reader for extension', ['ext' => $ext]);
                return;
            }

            Log::info('[PAYMENT] Start', [
                'file'  => $this->originalName,
                'disk'  => $disk,
                'path'  => $this->storedPath,
                'full'  => $fullPath,
                'batch' => $this->batchId,
                'ext'   => $ext,
            ]);

            $reader->open($fullPath);

            $buffer = [];

            foreach ($reader->getSheetIterator() as $sheet) {
                $headerNorm = null;
                $context    = ['ad_account' => null, 'payment_method' => null];

                foreach ($sheet->getRowIterator() as $row) {
                    $cells = $row->toArray();

                    // Find header; pre-header lines used to scrape "Account:" and "Payment Method:"
                    if ($headerNorm === null) {
                        if ($this->looksLikeHeader($cells)) {
                            $headerNorm = $this->normalizeHeader($cells);
                        } else {
                            $this->scanContext($cells, $context);
                        }
                        continue;
                    }

                    if ($this->isAllEmpty($cells)) continue;

                    $this->rowsRead++;

                    $assoc  = $this->rowToAssoc($headerNorm, $cells);
                    $mapped = $this->mapRow($assoc, $context);
                    if (!$mapped) continue;

                    $this->rowsMapped++;
                    $buffer[] = $mapped;

                    if (count($buffer) >= self::CHUNK_SIZE) {
                        $this->writeBatch($buffer);
                        $buffer = [];
                    }
                }
            }

            if (!empty($buffer)) {
                $this->writeBatch($buffer);
            }

            Log::info('[PAYMENT] Done', [
                'read'     => $this->rowsRead,
                'mapped'   => $this->rowsMapped,
                'inserted' => $this->rowsInserted,
                'batches'  => $this->batches,
                'stored'   => $this->storedPath,
                'disk'     => $disk,
                'batch'    => $this->batchId,
            ]);
        } catch (\Throwable $e) {
            Log::error('[PAYMENT] FAILED', [
                'msg'   => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        } finally {
            try { if ($reader) $reader->close(); } catch (\Throwable $e) {}

            foreach ($cleanup as $p) {
                if (is_string($p) && file_exists($p)) @unlink($p);
            }
        }
    }

    /**
     * ✅ If disk=local -> returns real local path
     * ✅ If disk=s3 (or any remote) -> downloads to temp, returns temp path
     */
    private function localizeToTempPath(string $disk, string $relPath, array &$cleanup): string
    {
        if ($disk === 'local') {
            return Storage::disk('local')->path($relPath);
        }

        if (!Storage::disk($disk)->exists($relPath)) {
            throw new \RuntimeException("File not found on disk={$disk}: {$relPath}");
        }

        $tmpDir = storage_path('app/payment_tmp');
        @mkdir($tmpDir, 0777, true);

        $ext = pathinfo($relPath, PATHINFO_EXTENSION);
        $tmp = $tmpDir . '/src_' . md5($relPath . microtime(true)) . ($ext ? '.' . $ext : '');

        $in = Storage::disk($disk)->readStream($relPath);
        if (!$in) {
            throw new \RuntimeException("Cannot read stream from disk={$disk}: {$relPath}");
        }

        $out = fopen($tmp, 'wb');
        if (!$out) {
            if (is_resource($in)) fclose($in);
            throw new \RuntimeException("Cannot write temp file: {$tmp}");
        }

        stream_copy_to_stream($in, $out);

        if (is_resource($in)) fclose($in);
        fclose($out);

        $cleanup[] = $tmp;
        return $tmp;
    }

    /* ------------------- OpenSpout reader (v3/v4 safe) ------------------- */

    private function createReader(string $ext)
    {
        // v3
        if (class_exists(ReaderEntityFactory::class)) {
            if ($ext === 'xlsx') {
                return ReaderEntityFactory::createXLSXReader();
            }
            if (in_array($ext, ['csv','txt'])) {
                $r = ReaderEntityFactory::createCSVReader();
                if (method_exists($r, 'setFieldDelimiter')) $r->setFieldDelimiter(',');
                if (method_exists($r, 'setFieldEnclosure'))  $r->setFieldEnclosure('"');
                if (method_exists($r, 'setEndOfLineCharacter')) $r->setEndOfLineCharacter("\n");
                if (method_exists($r, 'setEncoding')) $r->setEncoding('UTF-8');
                return $r;
            }
            return null;
        }

        // v4
        if ($ext === 'xlsx' && class_exists(XlsxReaderV4::class)) {
            return new XlsxReaderV4();
        }
        if (in_array($ext, ['csv','txt']) && class_exists(CsvReaderV4::class)) {
            $r = new CsvReaderV4();
            if (method_exists($r, 'setFieldDelimiter')) $r->setFieldDelimiter(',');
            if (method_exists($r, 'setFieldEnclosure'))  $r->setFieldEnclosure('"');
            if (method_exists($r, 'setEndOfLineCharacter')) $r->setEndOfLineCharacter("\n");
            if (method_exists($r, 'setEncoding')) $r->setEncoding('UTF-8');
            return $r;
        }

        return null;
    }

    /* ------------------- Header + preamble context ------------------- */

    private function looksLikeHeader(array $row): bool
    {
        $norm = $this->normalizeHeader($row);
        $hasDate = in_array('date', $norm, true);
        $hasTxn  = in_array('transaction id', $norm, true) || in_array('transaction_id', $norm, true);
        $hasAmt  = in_array('amount', $norm, true);
        return $hasDate && $hasTxn && $hasAmt;
    }

    private function normalizeHeader(array $hdr): array
    {
        return array_map(function ($h) {
            $h = trim((string) $h);
            $h = preg_replace('/\s+/', ' ', $h);
            return mb_strtolower($h);
        }, $hdr);
    }

    private function rowToAssoc(array $headerNorm, array $row): array
    {
        $row = array_pad($row, count($headerNorm), null);
        $assoc = [];
        foreach ($headerNorm as $i => $label) {
            $assoc[$label] = $row[$i] ?? null;
        }
        return $assoc;
    }

    /** Scan pre-header lines for:
     *  - "Account: 1742294716648193"         → ad_account
     *  - "Payment Method: Visa ···· 6226"    → payment_method
     */
    private function scanContext(array $cells, array &$context): void
    {
        foreach ($cells as $cellRaw) {
            $cell = trim((string) $cellRaw);
            if ($cell === '') continue;

            if (!$context['ad_account'] && preg_match('/^Account:\s*([0-9]+)/i', $cell, $m)) {
                $context['ad_account'] = $m[1];
            }
            if (!$context['payment_method'] && preg_match('/^Payment\s*Method:\s*(.+)$/i', $cell, $m)) {
                $context['payment_method'] = $this->normalizePaymentMethod($m[1]);
            }
        }
    }

    private function isAllEmpty(array $cells): bool
    {
        foreach ($cells as $c) if (trim((string)$c) !== '') return false;
        return true;
    }

    /* ------------------- Mapping + batch write ------------------- */

    private function mapRow(array $assoc, array $ctx): ?array
    {
        $dateStr = $assoc['date'] ?? null;
        $txn     = $assoc['transaction id'] ?? ($assoc['transaction_id'] ?? null);
        $amtStr  = $assoc['amount'] ?? null;

        // Prefer per-row Payment Method column if present; else fall back to pre-header context
        $pmCol = $assoc['payment method'] ?? null;
        $pm    = $pmCol !== null && trim((string)$pmCol) !== ''
            ? $this->normalizePaymentMethod($pmCol)
            : $this->normalizePaymentMethod($ctx['payment_method'] ?? null);

        $date = $this->parseDate($dateStr);
        $amt  = $this->parseAmount($amtStr);

        $txn  = trim((string)$txn);
        $acct = (string)($ctx['ad_account'] ?? '');

        if (!$date || $txn === '' || $amt === null) {
            return null;
        }

        $now = now();

        return [
            'date'            => $date->toDateString(),
            'transaction_id'  => $txn,
            'amount'          => $amt,
            'ad_account'      => $acct !== '' ? $acct : null,
            'payment_method'  => $pm ?: null,
            'source_filename' => $this->originalName,
            'import_batch_id' => $this->batchId,
            'uploaded_by'     => $this->uploadedBy,
            'uploaded_at'     => $now,
            'created_at'      => $now,
            'updated_at'      => $now,
        ];
    }

    private function parseDate($v): ?Carbon
    {
        if ($v instanceof \DateTimeInterface) return Carbon::instance($v);

        // Excel serial date support (xlsx sometimes gives numeric)
        if (is_numeric($v)) {
            try {
                $base = Carbon::create(1899, 12, 30, 0, 0, 0, 'Asia/Manila');
                return $base->copy()->addDays((int) floor((float)$v));
            } catch (\Throwable $e) {}
        }

        $s = trim((string)$v);
        if ($s === '') return null;

        foreach (['Y-m-d','m/d/Y','d/m/Y','n/j/Y','m/d/y','d/m/y'] as $f) {
            try { return Carbon::createFromFormat($f, $s, 'Asia/Manila'); } catch (\Throwable $e) {}
        }

        try { return Carbon::parse($s, 'Asia/Manila'); } catch (\Throwable $e) { return null; }
    }

    private function parseAmount($v): ?float
    {
        $s = trim((string)$v);
        if ($s === '') return null;

        $s = preg_replace('/[^\d\.\-]/', '', $s);
        if ($s === '' || !is_numeric($s)) return null;

        return round((float)$s, 2);
    }

    private function normalizePaymentMethod($v): ?string
    {
        if ($v === null) return null;

        $s = (string)$v;
        $s = str_replace("\xC2\xA0", ' ', $s);   // NBSP -> space
        $s = preg_replace('/[ \t]+/u', ' ', $s); // collapse spaces
        $s = trim($s);

        return $s !== '' ? $s : null;
    }

    /**
     * insertOrIgnore = duplicates ignored (MySQL: INSERT IGNORE, PG: ON CONFLICT DO NOTHING)
     */
    private function writeBatch(array $rows): void
    {
        $this->batches++;

        $inserted = DB::table('payment_activity_ads_manager')->insertOrIgnore($rows);

        // insertOrIgnore returns count in MySQL, may return 0/true depending on driver.
        // We'll still accumulate safely:
        if (is_int($inserted)) {
            $this->rowsInserted += $inserted;
        }
    }
}
