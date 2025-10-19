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

// OpenSpout (compat for v3 or v4)
use OpenSpout\Reader\Common\Creator\ReaderEntityFactory; // v3
use OpenSpout\Reader\CSV\Reader as CsvReaderV4;           // v4
use OpenSpout\Reader\XLSX\Reader as XlsxReaderV4;         // v4

class ProcessPaymentActivityUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Max runtime per attempt (seconds) */
    public int $timeout = 1800;
    /** Number of tries */
    public int $tries   = 1;

    private const CHUNK_SIZE = 2000;

    private ?int $uploadLogId = null;

    private int $rowsRead      = 0;
    private int $rowsMapped    = 0;
    private int $rowsInserted  = 0; // successful insert count (duplicates ignored)
    private int $batches       = 0;

    /**
     * @param string $storedPath  Storage-relative path (disk=local)
     * @param string $originalName
     * @param string $batchId     UUID tag per upload
     * @param string $uploadedBy
     * @param int|null $uploadLogId
     */
    public function __construct(
        public string $storedPath,
        public string $originalName,
        public string $batchId,
        public string $uploadedBy = 'system',
        ?int $uploadLogId = null
    ) {
        $this->uploadLogId = $uploadLogId;
    }

    public function handle(): void
    {
        @set_time_limit(0);

        // If you track progress via UploadLog model:
        $log = null;
        // $log = $this->uploadLogId ? \App\Models\UploadLog::find($this->uploadLogId) : null;
        // if ($log) $log->update(['status' => 'processing', 'started_at' => now()]);

        try {
            $fullPath = Storage::disk('local')->path($this->storedPath);

            if (!file_exists($fullPath)) {
                Log::error('[PAYMENT] File not found', [
                    'storedPath' => $this->storedPath,
                    'resolved'   => $fullPath,
                ]);
                if ($log) $log->update(['status' => 'failed', 'finished_at' => now()]);
                return;
            }

            $ext    = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $reader = $this->createReader($ext);
            if (!$reader) {
                Log::error('[PAYMENT] No reader for extension', ['ext' => $ext]);
                if ($log) $log->update(['status' => 'failed', 'finished_at' => now()]);
                return;
            }

            Log::info('[PAYMENT] Start', [
                'file'  => $this->originalName,
                'path'  => $fullPath,
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
                            Log::info('[PAYMENT] Header found', ['header' => $headerNorm]);
                        } else {
                            $this->scanContext($cells, $context);
                        }
                        continue;
                    }

                    // After header: skip fully blank lines
                    if ($this->isAllEmpty($cells)) continue;

                    $this->rowsRead++;

                    $assoc  = $this->rowToAssoc($headerNorm, $cells); // may include "payment method"
                    $mapped = $this->mapRow($assoc, $context);
                    if (!$mapped) continue;

                    $this->rowsMapped++;
                    $buffer[] = $mapped;

                    if (count($buffer) >= self::CHUNK_SIZE) {
                        $this->writeBatch($buffer);
                        $buffer = [];
                        $this->touchProgress($log);
                    }
                }
            }

            if (!empty($buffer)) {
                $this->writeBatch($buffer);
                $this->touchProgress($log);
            }

            $reader->close();

            if ($log) {
                $log->update([
                    'status'         => 'done',
                    'processed_rows' => $this->rowsRead,
                    'inserted'       => $this->rowsInserted,
                    'updated'        => 0,
                    'skipped'        => $this->rowsRead - $this->rowsMapped,
                    'finished_at'    => now(),
                ]);
            }

            Log::info('[PAYMENT] Done', [
                'read'      => $this->rowsRead,
                'mapped'    => $this->rowsMapped,
                'inserted'  => $this->rowsInserted,
                'batches'   => $this->batches,
                'stored'    => $this->storedPath,
                'file'      => $this->originalName,
                'batch'     => $this->batchId,
            ]);
        } catch (\Throwable $e) {
            Log::error('[PAYMENT] FAILED', [
                'msg'   => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            if ($log) {
                $log->update([
                    'status'         => 'failed',
                    'processed_rows' => $this->rowsRead,
                    'inserted'       => $this->rowsInserted,
                    'updated'        => 0,
                    'skipped'        => $this->rowsRead - $this->rowsMapped,
                    'finished_at'    => now(),
                ]);
            }
            throw $e;
        }
    }

    private function touchProgress($log): void
    {
        if (!$log) return;
        $log->update([
            'processed_rows' => $this->rowsRead,
            'inserted'       => $this->rowsInserted,
            'updated'        => 0,
            'skipped'        => $this->rowsRead - $this->rowsMapped,
        ]);
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
            return new CsvReaderV4();
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
        // payment method can be absent in some exports → optional
        return $hasDate && $hasTxn && $hasAmt;
    }

    private function normalizeHeader(array $hdr): array
    {
        return array_map(function($h){
            $h = trim((string)$h);
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
            $cell = trim((string)$cellRaw);
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

        // NEW: prefer per-row Payment Method column if present; else fall back to pre-header context
        $pmCol = $assoc['payment method'] ?? null; // header could be "Payment Method"
        $pm    = $pmCol !== null && trim((string)$pmCol) !== ''
                 ? $this->normalizePaymentMethod($pmCol)
                 : $this->normalizePaymentMethod($ctx['payment_method'] ?? null);

        $date = $this->parseDate($dateStr);
        $amt  = $this->parseAmount($amtStr);

        $txn  = trim((string)$txn);
        $acct = (string) ($ctx['ad_account'] ?? '');

        // Require date / txn / amount. ad_account can be null if not available.
        if (!$date || $txn === '' || $amt === null) {
            return null;
        }

        return [
            'date'            => $date->toDateString(),
            'transaction_id'  => $txn,
            'amount'          => $amt,
            'ad_account'      => $acct ?: null,
            'payment_method'  => $pm ?: null,
            'source_filename' => $this->originalName,
            'import_batch_id' => $this->batchId,
            'uploaded_by'     => $this->uploadedBy,
            'uploaded_at'     => now(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ];
    }

    private function parseDate($v): ?Carbon
    {
        if ($v instanceof \DateTimeInterface) return Carbon::instance($v);
        $s = trim((string)$v);
        if ($s === '') return null;
        foreach (['Y-m-d','m/d/Y','d/m/Y','n/j/Y'] as $f) {
            try { return Carbon::createFromFormat($f, $s); } catch (\Throwable $e) {}
        }
        try { return Carbon::parse($s); } catch (\Throwable $e) { return null; }
    }

    private function parseAmount($v): ?float
    {
        $s = trim((string)$v);
        if ($s === '') return null;
        $s = preg_replace('/[^\d\.\-]/', '', $s); // strip commas, currency, spaces
        if ($s === '' || !is_numeric($s)) return null;
        return round((float)$s, 2);
    }

    /** Normalize Meta-style payment method strings (NBSP, middle dots) */
    private function normalizePaymentMethod($v): ?string
    {
        if ($v === null) return null;
        $s = (string)$v;

        // Replace non-breaking spaces with regular spaces
        $s = str_replace("\xC2\xA0", ' ', $s); // NBSP
        // Collapse all horizontal whitespace to a single space
        $s = preg_replace('/[ \t]+/u', ' ', $s);
        // Trim
        $s = trim($s);

        // Safety: if somehow it's an empty string, return null
        return $s !== '' ? $s : null;
    }

    /**
     * Write a batch to DB using INSERT IGNORE semantics.
     * Requires UNIQUE index on `transaction_id`.
     * Duplicates will be silently ignored by MySQL.
     */
    private function writeBatch(array $rows): void
    {
        $this->batches++;

        try {
            $inserted = DB::table('payment_activity_ads_manager')->insertOrIgnore($rows);
            $this->rowsInserted += (int) $inserted;

            Log::info('[PAYMENT] insertOrIgnore ok', [
                'fed'      => count($rows),
                'inserted' => $inserted,
                'batches'  => $this->batches
            ]);
        } catch (\Throwable $e) {
            Log::error('[PAYMENT] insertOrIgnore failed', ['msg' => $e->getMessage()]);
            throw $e;
        }
    }
}
