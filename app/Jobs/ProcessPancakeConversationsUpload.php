<?php

namespace App\Jobs;

use App\Models\UploadLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

// OpenSpout (v3 or v4 supported)
use OpenSpout\Reader\Common\Creator\ReaderEntityFactory;
use OpenSpout\Reader\CSV\Reader as CsvReaderV4;
use OpenSpout\Reader\XLSX\Reader as XlsxReaderV4;

class ProcessPancakeConversationsUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries   = 1;

    private ?int $uploadLogId = null;

    private int $processed = 0;  // raw rows processed
    private int $skipped   = 0;  // raw rows skipped (missing key) + aggregates filtered (no phone)
    private int $inserted  = 0;  // new unique key inserted
    private int $updated   = 0;  // existing key appended

    private const ROW_CHUNK_SIZE = 4000;

    public function __construct(
        public string $storedPath,
        public ?int   $userId = null,
        ?int          $uploadLogId = null
    ) {
        $this->uploadLogId = $uploadLogId;
    }

    public function handle(): void
    {
        @set_time_limit(0);

        $log = $this->uploadLogId ? UploadLog::find($this->uploadLogId) : null;

        $cleanupFiles = [];
        $cleanupDirs  = [];

        try {
            if ($log) {
                $log->update([
                    'status'     => 'processing',
                    'started_at' => now(),
                ]);
            }

            $diskName = $this->resolveDiskName($log);
            $fullPath = $this->localizeToTempPath($diskName, $this->storedPath, $cleanupFiles);

            if (!file_exists($fullPath)) {
                Log::error("[Pancake Import] File not found after localize: {$fullPath}", [
                    'disk' => $diskName,
                    'path' => $this->storedPath,
                ]);
                if ($log) $log->update(['status' => 'failed', 'finished_at' => now()]);
                return;
            }

            $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

            // ZIP support
            if ($ext === 'zip') {
                $extractDir = storage_path('app/pancake_tmp/extract_' . md5($fullPath . microtime(true)));
                @mkdir($extractDir, 0777, true);

                $zip = new \ZipArchive();
                if ($zip->open($fullPath) !== true) {
                    throw new \RuntimeException("Cannot open ZIP: {$fullPath}");
                }
                $zip->extractTo($extractDir);
                $zip->close();

                $cleanupDirs[] = $extractDir;

                $files = $this->listFilesRecursive($extractDir);
                foreach ($files as $f) {
                    $e = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                    if (!in_array($e, ['csv', 'txt', 'xlsx', 'xls'], true)) continue;

                    $this->processOneFile($f, $e, $log);
                    $this->touchProgress($log);
                }
            } else {
                $this->processOneFile($fullPath, $ext, $log);
                $this->touchProgress($log);
            }

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
        } finally {
            foreach ($cleanupFiles as $p) {
                if (is_string($p) && file_exists($p)) @unlink($p);
            }
            foreach ($cleanupDirs as $d) {
                $this->deleteDirRecursive($d);
            }
        }
    }

    private function processOneFile(string $path, string $ext, ?UploadLog $log): void
    {
        $reader = $this->createReader($ext);
        if (!$reader) {
            Log::error("[Pancake Import] No compatible reader for .$ext", ['file' => $path]);
            return;
        }

        $reader->open($path);

        try {
            $agg = []; // key => ['pancake_page_id'=>.., 'full_name'=>.., 'chat'=>..]
            $rowCountInBatch = 0;

            foreach ($reader->getSheetIterator() as $sheet) {

                // ✅ IMPORTANT: reset header resolution per sheet
                $headerIndex = null;
                $msgIdx = null;
                $nameIdx = null;
                $pageIdx = null;

                foreach ($sheet->getRowIterator() as $row) {
                    $cells = $row->toArray();

                    if ($headerIndex === null) {
                        $headerIndex = $this->buildHeaderIndex($cells);

                        // ✅ contains-based matching
                        $msgIdx  = $this->findHeaderIndexContains($headerIndex, [
                            'message content', 'message', 'content',
                        ]);
                        $nameIdx = $this->findHeaderIndexContains($headerIndex, [
                            'sender name', 'customer_name', 'customer name',
                        ]);
                        $pageIdx = $this->findHeaderIndexContains($headerIndex, [
                            'page id', 'page_id',
                        ]);

                        if ($msgIdx === null || $nameIdx === null || $pageIdx === null) {
                            Log::error('[Pancake Import] Required headers not found', [
                                'file'    => $path,
                                'msgIdx'  => $msgIdx,
                                'nameIdx' => $nameIdx,
                                'pageIdx' => $pageIdx,
                                'headers' => array_keys($headerIndex),
                            ]);
                            return;
                        }
                        continue; // next row after header
                    }

                    $pageId = $cells[$pageIdx] ?? null;
                    $name   = $cells[$nameIdx] ?? null;
                    $msg    = $cells[$msgIdx]  ?? null;

                    $pageId = $pageId !== null ? trim((string) $pageId) : '';
                    $name   = $name   !== null ? trim((string) $name)   : '';
                    $msg    = $msg    !== null ? trim((string) $msg)    : '';

                    $name = $this->normalizeName($name);

                    if ($pageId === '' || $name === '') {
                        $this->skipped++;
                        continue;
                    }

                    $key = $pageId . '||' . mb_strtolower($name);

                    if (!isset($agg[$key])) {
                        $agg[$key] = [
                            'pancake_page_id' => $pageId,
                            'full_name'       => $name,
                            'chat'            => '',
                        ];
                    }

                    if ($msg !== '') {
                        $agg[$key]['chat'] = ($agg[$key]['chat'] === '')
                            ? $msg
                            : ($agg[$key]['chat'] . "\n" . $msg);
                    }

                    $this->processed++;
                    $rowCountInBatch++;

                    if ($rowCountInBatch >= self::ROW_CHUNK_SIZE) {
                        $this->flushAggregates($agg);
                        $agg = [];
                        $rowCountInBatch = 0;
                        $this->touchProgress($log);
                    }
                }
            }

            if (!empty($agg)) {
                $this->flushAggregates($agg);
            }
        } finally {
            try { $reader->close(); } catch (\Throwable $e) {}
        }
    }

    private function flushAggregates(array $agg): void
    {
        if (empty($agg)) return;

        DB::beginTransaction();
        try {
            $driver = DB::getDriverName();
            $now    = now()->toDateTimeString();

            foreach ($agg as $item) {
                $pageId = (string) ($item['pancake_page_id'] ?? '');
                $name   = (string) ($item['full_name'] ?? '');
                $chat   = (string) ($item['chat'] ?? '');

                if ($pageId === '' || $name === '') {
                    $this->skipped++;
                    continue;
                }

                // ✅ Match Retrieve Blade behavior: ONLY keep customers with phone number in compiled chat
                if ($chat === '' || !$this->hasPhilMobileNumber($chat)) {
                    $this->skipped++; // counts as filtered out
                    continue;
                }

                // 1) Update (append) if exists
                $affected = $this->appendChatIfExists($driver, $pageId, $name, $chat, $now);
                if ($affected > 0) {
                    $this->updated++;
                    continue;
                }

                // 2) Insert new
                DB::table('pancake_conversations')->insert([
                    'pancake_page_id' => $pageId,
                    'full_name'       => mb_substr($name, 0, 255),
                    'customers_chat'  => $chat,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]);

                $this->inserted++;
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[Pancake Import] flushAggregates failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    private function appendChatIfExists(string $driver, string $pageId, string $name, string $chat, string $now): int
    {
        if ($driver === 'pgsql') {
            return DB::update(
                "UPDATE pancake_conversations
                 SET customers_chat = CASE
                        WHEN customers_chat IS NULL OR customers_chat = '' THEN ?
                        ELSE customers_chat || E'\n' || ?
                     END,
                     updated_at = ?
                 WHERE pancake_page_id = ? AND full_name = ?",
                [$chat, $chat, $now, $pageId, $name]
            );
        }

        return DB::update(
            "UPDATE pancake_conversations
             SET customers_chat = CASE
                    WHEN customers_chat IS NULL OR customers_chat = '' THEN ?
                    ELSE CONCAT(customers_chat, '\n', ?)
                 END,
                 updated_at = ?
             WHERE pancake_page_id = ? AND full_name = ?",
            [$chat, $chat, $now, $pageId, $name]
        );
    }

    /**
     * ✅ Blade-aligned + robust PH mobile detection.
     *
     * Blade fast-path:
     *  - replace O/o -> 0
     *  - remove [., whitespace, -]
     *  - find #?09XXXXXXXXX
     *
     * Then robust fallback:
     *  - 09XXXXXXXXX
     *  - +639XXXXXXXXX / 639XXXXXXXXX
     *  - 9XXXXXXXXX (no 0/63)
     * Allows separators: space, dash, parentheses, dots.
     */
    private function hasPhilMobileNumber(string $text): bool
    {
        // 1) Blade-aligned fast path
        $clean = str_replace(['O', 'o'], '0', $text);
        $clean = preg_replace('/[.,\s\-]+/u', '', $clean) ?? $clean;

        // JS: /(?:#?[O0o]9[0-9]{2})\d{7}/g  => after cleaning: #?09 + 9 digits
        if (preg_match('/#?09\d{9}/', $clean)) {
            return true;
        }

        // 2) Robust fallback
        if (!preg_match('/\d/', $text)) return false;

        if (!preg_match_all('/(?<!\d)(?:\+?63|0)?\s*9[\d\s\-\(\)\.]{8,18}(?!\d)/', $text, $m)) {
            // also handle raw 10/11/12 digit contiguous
            if (!preg_match_all('/(?<!\d)\d{10,12}(?!\d)/', $text, $m2)) return false;

            foreach ($m2[0] as $chunk) {
                $digits = preg_replace('/\D+/', '', $chunk);
                if ($this->isValidPhilMobileDigits($digits)) return true;
            }
            return false;
        }

        foreach ($m[0] as $chunk) {
            $digits = preg_replace('/\D+/', '', $chunk);
            if ($this->isValidPhilMobileDigits($digits)) return true;
        }

        return false;
    }

    private function isValidPhilMobileDigits(string $digits): bool
    {
        // 11 digits starting 09
        if (strlen($digits) === 11 && str_starts_with($digits, '09')) return true;

        // 12 digits starting 639
        if (strlen($digits) === 12 && str_starts_with($digits, '639')) return true;

        // 10 digits starting 9 (missing leading 0/63)
        if (strlen($digits) === 10 && str_starts_with($digits, '9')) return true;

        return false;
    }

    private function normalizeName(string $name): string
    {
        $name = str_replace(["\r\n", "\r"], "\n", $name);
        $name = trim($name);

        // take first line only (prevents accidental multiline content being treated as name)
        if (str_contains($name, "\n")) {
            $name = trim(explode("\n", $name, 2)[0]);
        }

        // collapse spaces
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        // safety cap (DB string)
        if (mb_strlen($name) > 255) {
            $name = mb_substr($name, 0, 255);
        }

        return $name;
    }

    private function buildHeaderIndex(array $headers): array
    {
        $norm = fn($s) => strtolower(trim(preg_replace('/\s+/u', ' ', (string) $s)));
        $idx  = [];

        foreach ($headers as $i => $label) {
            $idx[$norm($label)] = (int) $i;
        }

        return $idx;
    }

    /**
     * Find header index where header CONTAINS any needle.
     */
    private function findHeaderIndexContains(array $headerIndex, array $needles): ?int
    {
        foreach ($headerIndex as $headerNorm => $i) {
            foreach ($needles as $n) {
                $n = strtolower(trim(preg_replace('/\s+/u', ' ', (string) $n)));
                if ($n !== '' && str_contains($headerNorm, $n)) {
                    return (int) $i;
                }
            }
        }
        return null;
    }

    private function resolveDiskName(?UploadLog $log): string
    {
        $d = $log && isset($log->disk) ? (string) ($log->disk ?? '') : '';
        $d = trim($d);

        return $d !== '' ? $d : (string) config('filesystems.default', 'local');
    }

    private function localizeToTempPath(string $diskName, string $relPath, array &$cleanupFiles): string
    {
        if ($diskName === 'local') {
            return Storage::disk('local')->path($relPath);
        }

        if (!Storage::disk($diskName)->exists($relPath)) {
            throw new \RuntimeException("File not found on disk={$diskName}: {$relPath}");
        }

        $tmpDir = storage_path('app/pancake_tmp');
        @mkdir($tmpDir, 0777, true);

        $ext = pathinfo($relPath, PATHINFO_EXTENSION);
        $tmp = $tmpDir . '/src_' . md5($relPath . microtime(true)) . ($ext ? '.' . $ext : '');

        $in = Storage::disk($diskName)->readStream($relPath);
        if (!$in) throw new \RuntimeException("Cannot read stream from disk={$diskName}: {$relPath}");

        $out = fopen($tmp, 'wb');
        if (!$out) {
            if (is_resource($in)) fclose($in);
            throw new \RuntimeException("Cannot write temp file: {$tmp}");
        }

        stream_copy_to_stream($in, $out);

        if (is_resource($in)) fclose($in);
        fclose($out);

        $cleanupFiles[] = $tmp;
        return $tmp;
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
            if ($ext === 'xlsx' || $ext === 'xls') {
                return ReaderEntityFactory::createXLSXReader();
            }

            if (in_array($ext, ['csv', 'txt'], true)) {
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
        if (($ext === 'xlsx' || $ext === 'xls') && class_exists(XlsxReaderV4::class)) {
            return new XlsxReaderV4();
        }

        if (in_array($ext, ['csv', 'txt'], true) && class_exists(CsvReaderV4::class)) {
            $r = new CsvReaderV4();
            $r->setFieldDelimiter(',');
            $r->setFieldEnclosure('"');
            $r->setEndOfLineCharacter("\n");
            $r->setEncoding('UTF-8');
            return $r;
        }

        return null;
    }

    private function listFilesRecursive(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

        foreach ($it as $f) {
            if ($f->isFile()) $out[] = $f->getPathname();
        }

        return $out;
    }

    private function deleteDirRecursive(string $dir): void
    {
        if (!is_dir($dir)) return;

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $f) {
            if ($f->isDir()) @rmdir($f->getPathname());
            else @unlink($f->getPathname());
        }

        @rmdir($dir);
    }
}
