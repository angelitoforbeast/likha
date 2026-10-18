<?php

namespace App\Jobs;

use Carbon\Carbon;
use App\Models\UploadLog;
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
    private int $rowsUpdated  = 0;
    private int $batches      = 0;

    private int $rowsRefused  = 0;

    public const UNDECIDABLE_ORDER = 'Cannot tell the date order of this file';

    /** Ang buong "Billing report:" / "Billing Report:" cell (unang nakita sa preamble). */
    private ?string $billingLine = null;

    /** 'm/d' o 'd/m': isang beses lang dine-decide bawat file. */
    private ?string $dateOrder = null;

    /** Para sa range guard: upload date at ISO range galing sa filename. */
    private ?Carbon $uploadDate = null;
    private ?Carbon $rangeStart = null;
    private ?Carbon $rangeEnd   = null;

    public function __construct(
        public string $storedPath,
        public string $originalName,
        public string $batchId,
        public string $uploadedBy = 'system',
        public ?string $diskName = null,
        public ?int $uploadLogId = null
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

            // Update upload_log status to processing
            $uploadLog = $this->uploadLogId ? UploadLog::find($this->uploadLogId) : null;
            if ($uploadLog) {
                $uploadLog->update(['status' => 'processing', 'started_at' => now()]);
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

            $this->readFileDates();

            // First pass: basahin lahat ng rows bago mag-parse, para isang date order lang bawat file.
            $pending = [];

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
                            $this->captureBillingLine($cells);
                        }
                        continue;
                    }

                    if ($this->isAllEmpty($cells)) continue;

                    $this->rowsRead++;

                    $pending[] = [$this->rowToAssoc($headerNorm, $cells), $context];
                }
            }

            $this->dateOrder = $this->decideDateOrder(array_map(fn ($p) => $p[0]['date'] ?? null, $pending));

            if ($this->dateOrder === null && $this->hasSlashDate($pending)) {
                Log::warning('[PAYMENT] ' . self::UNDECIDABLE_ORDER, ['file' => $this->originalName]);
                if ($uploadLog) {
                    $uploadLog->update([
                        'status'        => 'failed',
                        'total_rows'    => $this->rowsRead,
                        'error_message' => self::UNDECIDABLE_ORDER,
                        'finished_at'   => now(),
                    ]);
                }
                return;
            }

            $buffer = [];

            foreach ($pending as [$assoc, $context]) {
                $mapped = $this->mapRow($assoc, $context);
                if (!$mapped) continue;

                $this->rowsMapped++;
                $buffer[] = $mapped;

                if (count($buffer) >= self::CHUNK_SIZE) {
                    $this->writeBatch($buffer);
                    $buffer = [];
                }
            }

            if (!empty($buffer)) {
                $this->writeBatch($buffer);
            }

            Log::info('[PAYMENT] Done', [
                'read'     => $this->rowsRead,
                'mapped'   => $this->rowsMapped,
                'inserted' => $this->rowsInserted,
                'updated'  => $this->rowsUpdated,
                'refused'  => $this->rowsRefused,
                'order'    => $this->dateOrder,
                'batches'  => $this->batches,
                'stored'   => $this->storedPath,
                'disk'     => $disk,
                'batch'    => $this->batchId,
            ]);

            // Update upload_log with results
            if ($uploadLog) {
                $uploadLog->update([
                    'status'         => 'done',
                    'total_rows'     => $this->rowsRead,
                    'processed_rows' => $this->rowsMapped,
                    'inserted'       => $this->rowsInserted,
                    'updated'        => $this->rowsUpdated,
                    'skipped'        => max(0, $this->rowsMapped - $this->rowsInserted - $this->rowsUpdated),
                    'error_rows'     => $this->rowsRefused,
                    'error_message'  => $this->rowsRefused > 0
                        ? "{$this->rowsRefused} row(s) refused: invalid date, outside the file's date range, or after the upload date"
                        : null,
                    'finished_at'    => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('[PAYMENT] FAILED', [
                'msg'   => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update upload_log with error
            $uploadLog = $this->uploadLogId ? UploadLog::find($this->uploadLogId) : null;
            if ($uploadLog) {
                $uploadLog->update([
                    'status'         => 'failed',
                    'total_rows'     => $this->rowsRead,
                    'processed_rows' => $this->rowsMapped,
                    'inserted'       => $this->rowsInserted,
                    'skipped'        => max(0, $this->rowsMapped - $this->rowsInserted),
                    'error_rows'     => 1,
                    'finished_at'    => now(),
                ]);
            }
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

        // May laman ang date pero hindi totoong petsa, o labas sa range ng file: tanggihan, huwag hulaan.
        $hasDate = $dateStr instanceof \DateTimeInterface || trim((string) $dateStr) !== '';
        if (($hasDate && !$date) || ($date && !$this->inFileRange($date))) {
            $this->rowsRefused++;
            Log::warning('[PAYMENT] Row refused: bad or out-of-range date', ['file' => $this->originalName, 'txn' => $txn]);
            return null;
        }

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

    /** Itabi ang "Billing report:" cell; ang order ay dine-decide pagkatapos mabasa lahat ng rows. */
    private function captureBillingLine(array $cells): void
    {
        if ($this->billingLine !== null) return;

        foreach ($cells as $cellRaw) {
            $cell = trim((string) $cellRaw);
            if (preg_match('/Billing\s+report:/i', $cell)) {
                $this->billingLine = $cell;
                return;
            }
        }
    }

    /**
     * Upload date mula sa stored name ("20261009_100215__..."), at ISO range mula sa filename
     * ("2026-10-02--2026-10-10" o "2026-10-02-2026-10-10").
     */
    private function readFileDates(): void
    {
        $this->uploadDate = preg_match('/^(\d{8})_\d{6}__/', basename($this->storedPath), $m)
            ? $this->strictDate('!Ymd', $m[1])
            : null;
        $this->uploadDate ??= Carbon::today('Asia/Manila');

        foreach ([$this->originalName, basename($this->storedPath)] as $name) {
            if (preg_match('/(\d{4}-\d{2}-\d{2})-{1,2}(\d{4}-\d{2}-\d{2})/', $name, $m)) {
                $start = $this->strictDate('!Y-m-d', $m[1]);
                $end   = $this->strictDate('!Y-m-d', $m[2]);
                if ($start && $end && $start->lte($end)) {
                    $this->rangeStart = $start;
                    $this->rangeEnd   = $end;
                    return;
                }
            }
        }
    }

    /** Isang araw na palugit sa magkabilang dulo (timezone ng Meta vs Manila). */
    private function inFileRange(Carbon $date): bool
    {
        $d = $date->copy()->startOfDay();

        if ($this->uploadDate && $d->gt($this->uploadDate->copy()->addDay())) return false;
        if ($this->rangeStart && $d->lt($this->rangeStart->copy()->subDay())) return false;
        if ($this->rangeEnd && $d->gt($this->rangeEnd->copy()->addDay())) return false;

        return true;
    }

    /** [una, pangalawa, taon] ng "10/3/2026", "03-10-2026", "3.10.26"; null kung iba ang anyo. */
    private function slashParts($v): ?array
    {
        if ($v instanceof \DateTimeInterface) return null;

        return preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4}|\d{2})$/', trim((string) $v), $m)
            ? [(int) $m[1], (int) $m[2], $m[3]]
            : null;
    }

    private function hasSlashDate(array $pending): bool
    {
        foreach ($pending as [$assoc]) {
            if ($this->slashParts($assoc['date'] ?? null)) return true;
        }
        return false;
    }

    /**
     * Isang date order ('m/d' o 'd/m') para sa buong file, sa ganitong pagkakasunod:
     *  1) may numerong lampas 12 sa billing line o sa kahit anong row;
     *  2) ang billing line ay tugma sa ISO range sa filename;
     *  3) ang wording ng header: "Billing Report" = M/D, "Billing report" = D/M;
     *  4) wala: null (tatanggihan ang file kung may slash date).
     * Walang default: dati d/m ang hula kapag ambiguous, kaya nabaligtad ang mga "Billing Report" file.
     */
    private function decideDateOrder(array $rowDates): ?string
    {
        $billing = [];
        if ($this->billingLine !== null
            && preg_match('/(\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4})\s*-\s*(\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4})/', $this->billingLine, $m)) {
            $billing = [$m[1], $m[2]];
        }

        // 1) Numerong lampas 12
        $dm = false;
        $md = false;
        foreach (array_merge($billing, $rowDates) as $v) {
            $p = $this->slashParts($v);
            if (!$p) continue;
            if ($p[0] > 12) $dm = true;
            if ($p[1] > 12) $md = true;
        }
        if ($dm && $md) return null; // magkasalungat: hindi mapagkakatiwalaan ang file
        if ($dm) return 'd/m';
        if ($md) return 'm/d';

        // 2) Billing line vs ISO range sa filename
        if ($billing && $this->rangeStart && $this->rangeEnd) {
            $matches = [];
            foreach (['m/d', 'd/m'] as $order) {
                $s = $this->parseSlashDate($billing[0], $order);
                $e = $this->parseSlashDate($billing[1], $order);
                if ($s && $e && $s->isSameDay($this->rangeStart) && $e->isSameDay($this->rangeEnd)) {
                    $matches[] = $order;
                }
            }
            if (count($matches) === 1) return $matches[0];
        }

        // 3) Wording ng header (case-sensitive)
        if ($this->billingLine !== null) {
            if (preg_match('/Billing\s+Report:/', $this->billingLine)) return 'm/d';
            if (preg_match('/Billing\s+report:/', $this->billingLine)) return 'd/m';
        }

        return null;
    }

    /** Strict: "!"-format, at anumang warning (hal. 2/30 → Mar 2, 5/31 bilang d/m → 2028) ay failure. */
    private function strictDate(string $format, string $s): ?Carbon
    {
        $d   = \DateTimeImmutable::createFromFormat($format, $s, new \DateTimeZone('Asia/Manila'));
        $err = \DateTimeImmutable::getLastErrors();
        if (!$d || ($err && ($err['warning_count'] > 0 || $err['error_count'] > 0))) {
            return null;
        }
        return Carbon::instance($d);
    }

    private function parseSlashDate($v, string $order): ?Carbon
    {
        $p = $this->slashParts($v);
        if (!$p) return null;

        [$month, $day] = $order === 'm/d' ? [$p[0], $p[1]] : [$p[1], $p[0]];
        $yearFmt = strlen($p[2]) === 4 ? 'Y' : 'y';

        return $this->strictDate("!n/j/{$yearFmt}", "{$month}/{$day}/{$p[2]}");
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

        // Slash dates: ang order ng file lang, walang hulaan
        if ($this->slashParts($s)) {
            return $this->dateOrder ? $this->parseSlashDate($s, $this->dateOrder) : null;
        }

        if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $s)) {
            return $this->strictDate('!Y-m-d', $s);
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
     * Upsert: INSERT new records, UPDATE existing ones (matched by transaction_id).
     * Updates date, amount, payment_method, source_filename, import_batch_id, uploaded_by, uploaded_at, updated_at.
     */
    private function writeBatch(array $rows): void
    {
        $this->batches++;

        // Count existing records before upsert to determine inserts vs updates
        $txnIds = array_column($rows, 'transaction_id');
        $existingCount = DB::table('payment_activity_ads_manager')
            ->whereIn('transaction_id', $txnIds)
            ->count();

        DB::table('payment_activity_ads_manager')->upsert(
            $rows,
            ['transaction_id'], // unique key to match on
            ['date', 'amount', 'payment_method', 'source_filename', 'import_batch_id', 'uploaded_by', 'uploaded_at', 'updated_at'] // columns to update
        );

        $newInserts = count($rows) - $existingCount;
        $this->rowsInserted += max(0, $newInserts);
        $this->rowsUpdated  += $existingCount;
    }
}
