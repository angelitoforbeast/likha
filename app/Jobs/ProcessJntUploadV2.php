<?php

namespace App\Jobs;

use App\Models\BulkUploadRun;
use App\Models\FromJnt2;
use App\Models\UploadLogV2;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\CSV\Reader as CsvReaderV4;
use OpenSpout\Reader\Common\Creator\ReaderEntityFactory;
use OpenSpout\Reader\XLSX\Reader as XlsxReaderV4;
use ZipArchive;

class ProcessJntUploadV2 implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800; // 30 minutes
    public $tries   = 3;

    private int $logId;
    private ?int $userId = null;

    // Match V1's proven config — 5000 caused deadlock on concurrent workers.
    // Smaller chunks = smaller transactions = less InnoDB lock contention.
    const CHUNK_SIZE      = 2000;
    const UPDATE_SUBCHUNK = 800;

    private array $errors = [];
    private int $processed = 0;
    private int $inserted  = 0;
    private int $updatedCt = 0;
    private int $skippedCt = 0;

    private ?string $batchAt = null;

    public function __construct(int $logId)
    {
        $this->logId = $logId;
        // Dedicated parse queue para sa parallel xlsx parsing. 5 workers
        // configured sa Supervisor program `likha-queue-jnt-v2-parse`.
        // Pure INSERT to from_jnts_2_staging — walang lock contention sa
        // from_jnts_2 dahil hindi siya hinihimas dito.
        $this->onQueue('jnt_v2_parse');
    }

    public function handle(): void
    {
        /** @var UploadLogV2 $log */
        $log = UploadLogV2::findOrFail($this->logId);
        $this->userId = $log->user_id;

        $run = $log->bulk_run_id ? BulkUploadRun::find($log->bulk_run_id) : null;

        // Safety check: skip if log o run was cancelled habang naka-queue.
        // Mabilis lang itong "no-op" pickup — para hindi sayang yung worker
        // time sa cancelled rows (kasi di natin tinatanggal sa jobs table).
        if ($log->status === 'cancelled' || ($run && $run->status === 'cancelled')) {
            $log->status      = 'cancelled';
            $log->finished_at = Carbon::now('Asia/Manila');
            $log->save();
            return;
        }

        $batchCarbon = null;
        if ($run && !empty($run->batch_at)) {
            try {
                $batchCarbon = Carbon::parse($run->batch_at, 'Asia/Manila');
            } catch (\Throwable $e) {
                $batchCarbon = null;
            }
        }
        if (!$batchCarbon) {
            $batchCarbon = Carbon::now('Asia/Manila');
        }
        $this->batchAt = $batchCarbon->format('Y-m-d H:i:s');

        $log->status     = 'processing';
        $log->started_at = Carbon::now('Asia/Manila');
        $log->save();

        $disk = $this->resolveDisk($log);
        $path = $log->path;
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        try {
            if ($ext === 'zip') {
                $this->processZip($log, $disk);
            } elseif (in_array($ext, ['csv', 'xlsx'], true)) {
                [$localPath, $cleanup] = $this->localizeFile($disk, $path, $ext);
                try {
                    $this->processSingleFile($localPath, $ext, $log);
                } finally {
                    if ($cleanup && file_exists($cleanup)) @unlink($cleanup);
                }
            } else {
                throw new \RuntimeException('Unsupported file type: ' . $ext);
            }

            if (!empty($this->errors)) {
                $errorsPath = 'uploads/jnt_v2/errors/upload_' . $log->id . '_' . date('Ymd_His') . '.csv';
                $tmpErr = $this->makeTmpPath('errors_v2_' . $log->id, 'csv');
                $this->writeErrorsCsv($tmpErr, $this->errors);

                $stream = fopen($tmpErr, 'rb');
                Storage::disk($disk)->put($errorsPath, $stream);
                if (is_resource($stream)) fclose($stream);
                @unlink($tmpErr);

                $log->errors_path = $errorsPath;
                $log->error_rows  = count($this->errors);
            }

            $log->processed_rows = $this->processed;
            $log->inserted       = $this->inserted;
            $log->updated        = $this->updatedCt;
            $log->skipped        = $this->skippedCt;
            $log->status         = 'done';
            $log->finished_at    = Carbon::now('Asia/Manila');
            $log->save();

            // Check if this was the LAST file to finish in the run.
            // If yes, dispatch the consolidation job.
            $this->maybeDispatchConsolidate($log->bulk_run_id);
        } catch (\Throwable $e) {
            $log->status        = 'failed';
            $log->error_message = mb_substr($e->getMessage(), 0, 500);
            $log->finished_at   = Carbon::now('Asia/Manila');
            $log->save();

            throw $e;
        }
    }

    /**
     * Laravel queue lifecycle hook — called when job permanently fails
     * (after all retries exhausted, or on SIGKILL/timeout).
     * Hindi nakaabot dito yung try/catch sa handle() pag SIGKILL ang nag-fire.
     * This is the safety net para hindi maiwan ang file as 'processing' forever.
     */
    public function failed(\Throwable $exception): void
    {
        try {
            $log = UploadLogV2::find($this->logId);
            if ($log && in_array($log->status, ['queued', 'processing'], true)) {
                $log->status        = 'failed';
                $log->error_message = mb_substr('Job failed: ' . $exception->getMessage(), 0, 500);
                $log->finished_at   = Carbon::now('Asia/Manila');
                $log->save();
            }

            // Even sa permanent failure, ang consolidate job ay dapat tumakbo
            // kasi baka ito yung huling file ng batch — gusto pa rin natin
            // ma-merge yung successful files na natapos.
            if ($log && $log->bulk_run_id) {
                $this->maybeDispatchConsolidate($log->bulk_run_id);
            }
        } catch (\Throwable $e) {
            \Log::error('JntV2 failed() handler error: ' . $e->getMessage());
        }
    }

    /**
     * Check kung huling file ng batch ito at dispatch consolidate job kung yes.
     * Idempotent — safe to call multiple times sa same run; consolidate job
     * itself will guard with atomic compare-and-set sa status.
     */
    private function maybeDispatchConsolidate(?int $runId): void
    {
        if (!$runId) return;

        // Count files na hindi pa terminal (still queued/processing)
        $stillRunning = UploadLogV2::where('bulk_run_id', $runId)
            ->whereIn('status', ['queued', 'processing'])
            ->where('id', '!=', $this->logId) // exclude self (just finished)
            ->count();

        if ($stillRunning > 0) {
            return; // may iba pa file na in-flight, skip dispatch
        }

        // Atomic guard: only dispatch if run is still in 'processing' state
        $run = BulkUploadRun::find($runId);
        if (!$run) return;

        if (!in_array($run->status, ['processing', 'queued'], true)) {
            // Already consolidating, done, or in some terminal state
            return;
        }

        // Dispatch consolidate job
        \App\Jobs\ConsolidateAndMergeJntV2Run::dispatch($runId);
    }

    private function processZip(UploadLogV2 $log, string $disk): void
    {
        [$zipPath, $zipCleanup] = $this->localizeFile($disk, $log->path, 'zip');

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            if ($zipCleanup && file_exists($zipCleanup)) @unlink($zipCleanup);
            throw new \RuntimeException('Cannot open ZIP: ' . $zipPath);
        }

        $tmpRoot = $this->makeTmpDir('jnt_v2_zip_' . $log->id . '_' . uniqid());

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'] ?? '';
                $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

                if (!in_array($ext, ['csv', 'xlsx'], true)) continue;

                $target = $tmpRoot . DIRECTORY_SEPARATOR . basename($name);
                $stream = $zip->getStream($name);
                if (!$stream) {
                    $this->errors[] = ['File' => $name, 'Error' => 'Cannot read stream from ZIP.'];
                    continue;
                }
                $out = fopen($target, 'wb');
                stream_copy_to_stream($stream, $out);
                fclose($stream);
                fclose($out);

                $this->processSingleFile($target, $ext, $log);
            }
        } finally {
            $zip->close();
            $this->rrmdir($tmpRoot);
            if ($zipCleanup && file_exists($zipCleanup)) @unlink($zipCleanup);
        }
    }

    private function processSingleFile(string $absPath, string $ext, UploadLogV2 $log): void
    {
        $reader = $this->makeReader($ext);
        if (!$reader) {
            throw new \RuntimeException('No compatible reader for ' . $ext);
        }

        $reader->open($absPath);

        $buffer    = [];
        $headerMap = null;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = $row->toArray();

                    if ($headerMap === null) {
                        $headerMap = $this->buildHeaderMap($cells);
                        if (!$this->hasRequiredHeaders($headerMap)) {
                            throw new \RuntimeException('Wrong file uploaded — missing required columns');
                        }
                        continue;
                    }

                    $norm = $this->normalizeRow($cells, $headerMap);

                    if ($norm['waybill_number'] === '' || $norm['status'] === '') {
                        $this->errors[] = [
                            'Waybill Number' => $norm['waybill_number'] ?? '',
                            'Status'         => $norm['status'] ?? '',
                            'Error'          => 'Missing required fields',
                        ];
                        continue;
                    }

                    $buffer[$norm['waybill_number']] = $norm;
                    $this->processed++;

                    if (count($buffer) >= self::CHUNK_SIZE) {
                        $this->persistChunk(array_values($buffer), $log);
                        $buffer = [];
                        $this->touchProgress($log);
                    }
                }
            }

            if (!empty($buffer)) {
                $this->persistChunk(array_values($buffer), $log);
                $this->touchProgress($log);
            }
        } finally {
            $reader->close();
        }
    }

    private function makeReader(string $ext)
    {
        if (class_exists(ReaderEntityFactory::class)) {
            if ($ext === 'xlsx') return ReaderEntityFactory::createXLSXReader();
            if ($ext === 'csv') {
                $r = ReaderEntityFactory::createCSVReader();
                $r->setFieldDelimiter(',');
                $r->setFieldEnclosure('"');
                $r->setEndOfLineCharacter("\n");
                $r->setEncoding('UTF-8');
                return $r;
            }
        } else {
            if ($ext === 'xlsx' && class_exists(XlsxReaderV4::class)) return new XlsxReaderV4();
            if ($ext === 'csv' && class_exists(CsvReaderV4::class)) {
                $r = new CsvReaderV4();
                $r->setFieldDelimiter(',');
                $r->setFieldEnclosure('"');
                $r->setEndOfLineCharacter("\n");
                $r->setEncoding('UTF-8');
                return $r;
            }
        }
        return null;
    }

    private function buildHeaderMap(array $headers): array
    {
        $norm = fn ($s) => trim(mb_strtolower((string) $s));

        $aliases = [
            'waybill_number' => ['waybill', 'waybill number', 'awb', 'tracking no', 'tracking number'],
            'status'         => ['status', 'order status', 'order_status', 'orderstatus'],
            'item_name'      => ['item name', 'item', 'product', 'product name'],
            'sender'         => ['sender', 'shipper', 'from'],
            'receiver'       => ['receiver', 'consignee', 'to'],
            'receiver_cellphone' => ['receiver cellphone', 'receiver phone', 'consignee phone', 'phone', 'mobile'],
            'cod'            => ['cod', 'c.o.d', 'cod amt', 'cod amount', 'collect on delivery'],
            'submission_time'=> ['submission time', 'pu time', 'pickup time', 'created time'],
            'signingtime'    => ['signingtime', 'signing time', 'delivered time'],
            'remarks'        => ['remarks', 'remark', 'note', 'notes'],
            'province'       => ['province', 'prov'],
            'city'           => ['city', 'municipality', 'city/municipality'],
            'barangay'       => ['barangay', 'brgy', 'barangay name'],
            'total_shipping_cost' => ['total shipping cost', 'shipping cost', 'total freight'],
            'rts_reason'     => ['rts reason', 'rts_reason', 'return reason', 'reason for rts'],
        ];

        $map = [];

        foreach ($headers as $idx => $label) {
            $h = $norm($label);
            $tokens = preg_split('/[^a-z0-9]+/u', $h, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            foreach ($aliases as $canon => $cands) {
                if (isset($map[$canon])) continue;

                foreach ($cands as $cand) {
                    $c = $norm($cand);
                    $matched = false;

                    if ($h === $c) {
                        $matched = true;
                    } elseif (mb_strpos($c, ' ') !== false) {
                        if (preg_match('/\b' . preg_quote($c, '/') . '\b/u', $h)) $matched = true;
                    } else {
                        if (in_array($c, $tokens, true)) $matched = true;
                    }

                    if ($matched) {
                        if ($canon === 'receiver' && $c === 'to' && $h !== 'to') $matched = false;
                        if ($canon === 'cod' && in_array('code', $tokens, true)) $matched = false;
                        if ($canon === 'receiver_cellphone' && in_array('sender', $tokens, true)) $matched = false;
                    }

                    if ($matched) {
                        $map[$canon] = $idx;
                        break;
                    }
                }
            }
        }

        return $map;
    }

    private function hasRequiredHeaders(array $map): bool
    {
        return isset($map['waybill_number'], $map['status'], $map['signingtime']);
    }

    private function normalizeRow(array $cells, array $map): array
    {
        $get = function ($key) use ($cells, $map) {
            if (!isset($map[$key])) return '';
            $val = $cells[$map[$key]] ?? '';
            $val = is_scalar($val) ? (string) $val : '';
            return trim(preg_replace('/\s+/u', ' ', $val));
        };

        $parseDate = function ($v) {
            $v = trim((string) $v);
            if ($v === '') return null;

            if (is_numeric($v)) {
                try {
                    $base = Carbon::create(1899, 12, 30, 0, 0, 0, 'Asia/Manila');
                    $dt = $base->copy()->addDays((int) $v);
                    return $dt->format('Y-m-d H:i:s');
                } catch (\Throwable $e) {}
            }

            $formats = [
                'Y-m-d H:i:s','Y-m-d H:i','m/d/Y H:i','d/m/Y H:i','m/d/Y','d/m/Y',
                'Y-m-d','d-m-Y H:i','d-m-Y H:i:s','d-m-Y',
                'H:i d-m-Y','H:i d/m/Y',
            ];
            foreach ($formats as $fmt) {
                try {
                    return Carbon::createFromFormat($fmt, $v, 'Asia/Manila')->format('Y-m-d H:i:s');
                } catch (\Throwable $e) {}
            }

            try {
                return Carbon::parse($v, 'Asia/Manila')->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                return null;
            }
        };

        $parseMoney = function ($v) {
            $v = (string) $v;
            $clean = preg_replace('/[^\d\.\-]/', '', $v);
            $clean = trim($clean);
            return $clean === '' ? null : $clean;
        };

        $now = Carbon::now('Asia/Manila')->format('Y-m-d H:i:s');

        return [
            'waybill_number'     => $get('waybill_number'),
            'status'             => $get('status'),
            'item_name'          => $get('item_name'),
            'sender'             => $get('sender'),
            'receiver'           => $get('receiver'),
            'receiver_cellphone' => $get('receiver_cellphone'),
            'cod'                => $get('cod'),
            'submission_time'    => $parseDate($get('submission_time')),
            'signingtime'        => $parseDate($get('signingtime')),
            'remarks'            => $get('remarks'),
            'province'           => $get('province'),
            'city'               => $get('city'),
            'barangay'           => $get('barangay'),
            'total_shipping_cost'=> $parseMoney($get('total_shipping_cost')),
            'rts_reason'         => $get('rts_reason'),
            'created_at'         => $now,
            'updated_at'         => $now,
        ];
    }

    /**
     * Bulk insert chunk into the staging table (from_jnts_2_staging).
     *
     * Per-file workers no longer write directly to from_jnts_2 — they just
     * dump parsed rows into staging. The ConsolidateAndMergeJntV2Run job
     * (Stage 3) is responsible for the actual merge to from_jnts_2 with
     * skip rule + status_logs maintenance.
     *
     * Yung skippedCt counter ngayon ay symbolic — true per-file skip count
     * mahihirapan natin ma-determine without the from_jnts_2 lookup. Pero
     * all rows are tracked sa staging anyway, so consolidation step yung
     * mag-detect ng terminal-state skips.
     */
    private function persistChunk(array $rows, UploadLogV2 $log): void
    {
        if (empty($rows)) return;

        // Dedupe within chunk (last-wins by waybill — matches old behavior)
        $byWb = [];
        foreach ($rows as $r) {
            $byWb[$r['waybill_number']] = $r;
        }
        $rows = array_values($byWb);

        // Build staging rows
        $now = Carbon::now('Asia/Manila')->format('Y-m-d H:i:s');
        $stagingRows = array_map(function ($r) use ($now) {
            return [
                'bulk_run_id'        => $this->bulkRunId(),
                'upload_log_id'      => $this->logId,
                'submission_time'    => $r['submission_time'],
                'waybill_number'     => $r['waybill_number'],
                'receiver'           => $r['receiver'],
                'receiver_cellphone' => $r['receiver_cellphone'],
                'sender'             => $r['sender'],
                'item_name'          => $r['item_name'],
                'cod'                => $r['cod'],
                'remarks'            => $r['remarks'],
                'status'             => $r['status'],
                'signingtime'        => $r['signingtime'],
                'province'           => $r['province'],
                'city'               => $r['city'],
                'barangay'           => $r['barangay'],
                'total_shipping_cost'=> $r['total_shipping_cost'],
                'rts_reason'         => $r['rts_reason'],
                'parsed_at'          => $now,
            ];
        }, $rows);

        // Bulk insert to staging — fast, no upsert checks, no DB locks on from_jnts_2
        DB::table('from_jnts_2_staging')->insert($stagingRows);
        $this->inserted += count($stagingRows);
    }

    /** Cached bulk_run_id lookup. */
    private ?int $cachedBulkRunId = null;
    private function bulkRunId(): ?int
    {
        if ($this->cachedBulkRunId !== null) return $this->cachedBulkRunId;
        $log = UploadLogV2::find($this->logId);
        $this->cachedBulkRunId = $log ? (int) $log->bulk_run_id : null;
        return $this->cachedBulkRunId;
    }

    private function appendStatusLog($currentLogs, ?string $oldStatusRaw, ?string $newStatusRaw, Carbon $batchAt): array
    {
        if (is_array($currentLogs)) {
            $logs = $currentLogs;
        } elseif (is_string($currentLogs) && $currentLogs !== '') {
            $decoded = json_decode($currentLogs, true);
            $logs = is_array($decoded) ? $decoded : [];
        } else {
            $logs = [];
        }

        $oldStatus = $oldStatusRaw !== null && trim($oldStatusRaw) !== '' ? trim($oldStatusRaw) : null;
        $newStatus = $newStatusRaw !== null && trim($newStatusRaw) !== '' ? trim($newStatusRaw) : null;

        $isInTransit  = fn (?string $s) => $s !== null && strcasecmp(trim($s), 'In Transit') === 0;
        $isDelivering = function (?string $s) {
            if ($s === null) return false;
            $t = strtolower(trim($s));
            if ($t === '') return false;
            return str_contains($t, 'delivering') || (str_contains($t, 'deliver') && !str_contains($t, 'delivered'));
        };

        $shouldAdd = false;

        if ($oldStatus === null && $newStatus !== null) {
            $shouldAdd = true;
        } elseif ($oldStatus !== null && $newStatus !== null && $oldStatus !== $newStatus) {
            $shouldAdd = true;
        } elseif ($newStatus !== null && $isInTransit($newStatus)) {
            $lastLog = null;
            for ($i = count($logs) - 1; $i >= 0; $i--) {
                $log = $logs[$i] ?? null;
                if (is_array($log) && isset($log['to']) && $isInTransit((string) $log['to'])) {
                    $lastLog = $log;
                    break;
                }
            }
            if ($lastLog) {
                try {
                    $lastDate    = Carbon::parse($lastLog['batch_at'])->toDateString();
                    $currentDate = $batchAt->toDateString();
                    if ($lastDate !== $currentDate) $shouldAdd = true;
                } catch (\Throwable $e) {
                    $shouldAdd = true;
                }
            } else {
                $shouldAdd = true;
            }
        } elseif ($newStatus !== null && $isDelivering($newStatus)) {
            $lastLog = null;
            for ($i = count($logs) - 1; $i >= 0; $i--) {
                $log = $logs[$i] ?? null;
                if (is_array($log) && isset($log['to']) && $isDelivering((string) $log['to'])) {
                    $lastLog = $log;
                    break;
                }
            }
            if ($lastLog) {
                try {
                    $lastDate    = Carbon::parse($lastLog['batch_at'])->toDateString();
                    $currentDate = $batchAt->toDateString();
                    if ($lastDate !== $currentDate) $shouldAdd = true;
                } catch (\Throwable $e) {
                    $shouldAdd = true;
                }
            } else {
                $shouldAdd = true;
            }
        }

        if ($shouldAdd && $newStatus !== null) {
            $logs[] = [
                'batch_at'      => $batchAt->format('Y-m-d H:i:s'),
                'upload_log_id' => $this->logId,
                'user_id'       => $this->userId,
                'from'          => $oldStatus,
                'to'            => $newStatus,
            ];
        }

        return $logs;
    }

    private function touchProgress(UploadLogV2 $log): void
    {
        $log->processed_rows = $this->processed;
        $log->inserted       = $this->inserted;
        $log->updated        = $this->updatedCt;
        $log->skipped        = $this->skippedCt;
        $log->save();
    }

    private function writeErrorsCsv(string $absPath, array $rows): void
    {
        @mkdir(dirname($absPath), 0777, true);
        $fp = fopen($absPath, 'w');
        if (!$fp) return;
        fputcsv($fp, array_keys($rows[0]));
        foreach ($rows as $r) fputcsv($fp, $r);
        fclose($fp);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = scandir($dir) ?: [];
        foreach ($files as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . DIRECTORY_SEPARATOR . $f;
            if (is_dir($p)) $this->rrmdir($p);
            else @unlink($p);
        }
        @rmdir($dir);
    }

    private function resolveDisk(UploadLogV2 $log): string
    {
        $disk = trim((string) ($log->disk ?? ''));
        if ($disk !== '') return $disk;
        $def = (string) config('filesystems.default');
        return $def !== '' ? $def : 'local';
    }

    private function localizeFile(string $disk, string $path, string $ext): array
    {
        if (in_array($disk, ['local', 'public'], true)) {
            return [Storage::disk($disk)->path($path), null];
        }

        $tmp = $this->makeTmpPath('jnt_v2_' . uniqid(), $ext);
        $in  = Storage::disk($disk)->readStream($path);
        if (!$in) throw new \RuntimeException("Cannot read file from disk={$disk}: {$path}");
        $out = fopen($tmp, 'wb');
        stream_copy_to_stream($in, $out);
        if (is_resource($out)) fclose($out);
        if (is_resource($in)) fclose($in);

        return [$tmp, $tmp];
    }

    private function makeTmpDir(string $name): string
    {
        $root = storage_path('app/tmp');
        if (!is_dir($root)) @mkdir($root, 0777, true);
        $dir = $root . DIRECTORY_SEPARATOR . $name;
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        return $dir;
    }

    private function makeTmpPath(string $name, string $ext): string
    {
        $root = storage_path('app/tmp');
        if (!is_dir($root)) @mkdir($root, 0777, true);
        $ext = ltrim((string) $ext, '.');
        return $root . DIRECTORY_SEPARATOR . $name . '.' . $ext;
    }
}
