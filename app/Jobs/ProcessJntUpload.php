<?php

namespace App\Jobs;

use App\Models\UploadLog;
use App\Models\FromJnt;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

use OpenSpout\Reader\Common\Creator\ReaderEntityFactory;
use OpenSpout\Reader\CSV\Reader as CsvReaderV4;
use OpenSpout\Reader\XLSX\Reader as XlsxReaderV4;

class ProcessJntUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800; // 30 minutes
    public $tries   = 3;

    private $uploadLogId;

    const CHUNK_SIZE      = 2000;
    const UPDATE_SUBCHUNK = 800;

    private $errors    = [];
    private $processed = 0;
    private $inserted  = 0;
    private $updated   = 0;
    private $skipped   = 0;

    protected $batchAt = null;

    public function __construct($uploadLogId)
    {
        $this->uploadLogId = (int) $uploadLogId;
    }

    public function handle()
    {
        /** @var UploadLog $log */
        $log = UploadLog::findOrFail($this->uploadLogId);

        // ✅ Piliin batch_at: unahin yung nasa UploadLog (backdated), fallback = now()
        $batchCarbon = null;
        if (!empty($log->batch_at)) {
            try {
                $batchCarbon = Carbon::parse($log->batch_at, 'Asia/Manila');
            } catch (\Throwable $e) {
                $batchCarbon = null;
            }
        }
        if (!$batchCarbon) {
            $batchCarbon = Carbon::now('Asia/Manila');
        }
        $this->batchAt = $batchCarbon->format('Y-m-d H:i:s');

        // started_at = actual processing time (hindi batch_at)
        $log->update([
            'status'     => 'processing',
            'started_at' => Carbon::now('Asia/Manila'),
        ]);

        $disk = $this->resolveDisk($log);

        $path = $log->path;
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        try {
            if ($ext === 'zip') {
                $this->processZip($log, $disk);
            } elseif (in_array($ext, ['csv', 'xlsx'], true)) {
                // ✅ If S3, download to temp; if local, use real path
                [$localPath, $cleanup] = $this->localizeFile($disk, $path, $ext);
                try {
                    $this->processSingleFile($localPath, $ext, $log);
                } finally {
                    if ($cleanup && file_exists($cleanup)) @unlink($cleanup);
                }
            } elseif ($ext === 'xls') {
                throw new \RuntimeException('XLS (legacy) is not supported for streaming. Please re-save as XLSX or CSV.');
            } else {
                throw new \RuntimeException('Unsupported file type: ' . $ext);
            }

            if (!empty($this->errors)) {
                $errorsPath = 'uploads/jnt/errors/upload_' . $log->id . '_' . date('Ymd_His') . '.csv';

                // ✅ write errors CSV to local temp, then upload to same disk
                $tmpErr = $this->makeTmpPath('errors_' . $log->id, 'csv');
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
            $log->updated        = $this->updated;
            $log->skipped        = $this->skipped;
            $log->status         = 'done';
            $log->finished_at    = Carbon::now('Asia/Manila');
            $log->save();
        } catch (\Throwable $e) {
            $log->status      = 'failed';
            $log->finished_at = Carbon::now('Asia/Manila');
            $log->save();

            throw $e;
        }
    }

    private function processZip(UploadLog $log, string $disk)
    {
        // ✅ Ensure ZIP is local path (download if S3)
        [$zipPath, $zipCleanup] = $this->localizeFile($disk, $log->path, 'zip');

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            if ($zipCleanup && file_exists($zipCleanup)) @unlink($zipCleanup);
            throw new \RuntimeException('Cannot open ZIP: ' . $zipPath);
        }

        // ✅ local temp root (wag Storage::path kasi baka s3 default)
        $tmpRoot = $this->makeTmpDir('jnt_zip_' . $log->id . '_' . uniqid());

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'] ?? '';
                $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

                if (!in_array($ext, ['csv', 'xlsx'], true)) {
                    continue;
                }

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

    private function processSingleFile($absPath, $ext, UploadLog $log)
    {
        $reader = null;

        if (class_exists(ReaderEntityFactory::class)) {
            if ($ext === 'xlsx') {
                $reader = ReaderEntityFactory::createXLSXReader();
            } elseif ($ext === 'csv') {
                $reader = ReaderEntityFactory::createCSVReader();
                $reader->setFieldDelimiter(',');
                $reader->setFieldEnclosure('"');
                $reader->setEndOfLineCharacter("\n");
                $reader->setEncoding('UTF-8');
            }
        } else {
            if ($ext === 'xlsx' && class_exists(XlsxReaderV4::class)) {
                $reader = new XlsxReaderV4();
            } elseif ($ext === 'csv' && class_exists(CsvReaderV4::class)) {
                $reader = new CsvReaderV4();
                $reader->setFieldDelimiter(',');
                $reader->setFieldEnclosure('"');
                $reader->setEndOfLineCharacter("\n");
                $reader->setEncoding('UTF-8');
            }
        }

        if (!$reader) {
            throw new \RuntimeException('No compatible reader for ' . $ext . ' (check OpenSpout version).');
        }

        $reader->open($absPath);

        $buffer    = [];
        $headerMap = null;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells = $row->toArray();

                if ($headerMap === null) {
                    $headerMap = $this->buildHeaderMap($cells);
                    if (!$this->hasRequiredHeaders($headerMap)) {
                        throw new \RuntimeException('Wrong File Uploaded');
                    }
                    continue;
                }

                $norm = $this->normalizeRow($cells, $headerMap);

                if ($norm['waybill_number'] === '' || $norm['status'] === '') {
                    $this->errors[] = [
                        'Waybill Number' => isset($norm['waybill_number']) ? $norm['waybill_number'] : '',
                        'Status'         => isset($norm['status']) ? $norm['status'] : '',
                        'Error'          => 'Missing required fields',
                    ];
                    continue;
                }

                // Deduplicate inside this batch by waybill
                $buffer[$norm['waybill_number']] = $norm;
                $this->processed++;

                if (count($buffer) >= self::CHUNK_SIZE) {
                    $this->persistChunk(array_values($buffer));
                    $buffer = [];
                    $this->touchProgress($log);
                }
            }
        }

        if (!empty($buffer)) {
            $this->persistChunk(array_values($buffer));
            $buffer = [];
            $this->touchProgress($log);
        }

        $reader->close();
    }

    private function buildHeaderMap(array $headers)
    {
        $norm = function ($s) {
            return trim(mb_strtolower((string) $s));
        };

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

            'province'           => ['province', 'prov'],
            'city'               => ['city', 'municipality', 'city/municipality'],
            'barangay'           => ['barangay', 'brgy', 'barangay name'],
            'total_shipping_cost'=> ['total shipping cost', 'shipping cost', 'total freight'],
            'rts_reason'         => ['rts reason', 'rts_reason', 'return reason', 'reason for rts'],
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

    private function hasRequiredHeaders(array $map)
    {
        return isset($map['waybill_number'], $map['status'], $map['signingtime']);
    }

    private function normalizeRow(array $cells, array $map)
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
     * INSERT + UPDATE (status + signingtime + rts_reason) + status_logs
     * ✅ CASE SQL logic untouched
     */
    private function persistChunk(array $rows)
    {
        if (empty($rows)) return;

        $byWb = [];
        foreach ($rows as $r) {
            $byWb[$r['waybill_number']] = $r;
        }
        $rows = array_values($byWb);

        $waybills = array_column($rows, 'waybill_number');

        $existing = FromJnt::query()
            ->select(['waybill_number','status','status_logs','rts_reason'])
            ->whereIn('waybill_number', $waybills)
            ->get()
            ->keyBy('waybill_number');

        $toInsert   = [];
        $toUpdate   = [];
        $statusInfo = [];

        $batchAtStr    = $this->batchAt ?: Carbon::now('Asia/Manila')->format('Y-m-d H:i:s');
        $batchAtCarbon = Carbon::parse($batchAtStr, 'Asia/Manila');

        foreach ($rows as $r) {
            $wb = $r['waybill_number'];

            if (isset($existing[$wb])) {
                $oldStatusRaw = (string) ($existing[$wb]->status ?: '');
                $cur          = strtolower($oldStatusRaw);

                if (in_array($cur, ['delivered', 'returned'], true)) {
                    $this->skipped++;
                    continue;
                }

                $newStatusRaw = (string) $r['status'];

                $toUpdate[$wb] = [
                    'status'      => $newStatusRaw,
                    'signingtime' => $r['signingtime'],
                    'updated_at'  => Carbon::now('Asia/Manila')->format('Y-m-d H:i:s'),
                ];

                $newRts = trim((string)($r['rts_reason'] ?? ''));
                if ($newRts !== '') {
                    $toUpdate[$wb]['rts_reason'] = $newRts;
                }

                $statusInfo[$wb] = [
                    'from' => $oldStatusRaw,
                    'to'   => $newStatusRaw,
                ];
            } else {
                $initialLogs = [
                    [
                        'batch_at'      => $batchAtCarbon->format('Y-m-d H:i:s'),
                        'upload_log_id' => $this->uploadLogId,
                        'from'          => null,
                        'to'            => (string) $r['status'],
                    ],
                ];

                $toInsert[] = [
                    'waybill_number'     => $r['waybill_number'],
                    'sender'             => $r['sender'],
                    'cod'                => $r['cod'],
                    'status'             => $r['status'],
                    'item_name'          => $r['item_name'],
                    'submission_time'    => $r['submission_time'],
                    'receiver'           => $r['receiver'],
                    'receiver_cellphone' => $r['receiver_cellphone'],
                    'signingtime'        => $r['signingtime'],
                    'remarks'            => $r['remarks'],
                    'province'           => $r['province'],
                    'city'               => $r['city'],
                    'barangay'           => $r['barangay'],
                    'total_shipping_cost'=> $r['total_shipping_cost'],
                    'rts_reason'         => $r['rts_reason'],
                    'status_logs'        => json_encode($initialLogs),
                    'created_at'         => $r['created_at'],
                    'updated_at'         => $r['updated_at'],
                ];
            }
        }

        DB::transaction(function () use ($toInsert, $toUpdate, $statusInfo, $batchAtCarbon) {
            if (!empty($toInsert)) {
                FromJnt::insert($toInsert);
                $this->inserted += count($toInsert);
            }

            if (!empty($toUpdate)) {
                $keys = array_keys($toUpdate);

                foreach (array_chunk($keys, self::UPDATE_SUBCHUNK) as $chunkKeys) {
                    $statusCase = "CASE waybill_number\n";
                    $timeCase   = "CASE waybill_number\n";

                    $rtsCase = "CASE waybill_number\n";
                    $hasRts  = false;

                    foreach ($chunkKeys as $wb) {
                        $s = str_replace("'", "''", (string) $toUpdate[$wb]['status']);
                        $t = $toUpdate[$wb]['signingtime'];
                        $tSql  = $t ? ("'" . str_replace("'", "''", $t) . "'") : "NULL";
                        $wbSql = "'" . str_replace("'", "''", $wb) . "'";

                        $statusCase .= "WHEN {$wbSql} THEN '{$s}'\n";
                        $timeCase   .= "WHEN {$wbSql} THEN {$tSql}\n";

                        if (isset($toUpdate[$wb]['rts_reason'])) {
                            $hasRts = true;
                            $rr = str_replace("'", "''", (string) $toUpdate[$wb]['rts_reason']);
                            $rtsCase .= "WHEN {$wbSql} THEN '{$rr}'\n";
                        }
                    }

                    $statusCase .= "ELSE status END";
                    $timeCase   .= "ELSE signingtime END";
                    $rtsCase    .= "ELSE rts_reason END";

                    $inList = implode(',', array_map(function ($wb) {
                        return "'" . str_replace("'", "''", $wb) . "'";
                    }, $chunkKeys));

                    $setParts = [
                        "status = {$statusCase}",
                        "signingtime = {$timeCase}",
                        "updated_at = NOW()",
                    ];

                    if ($hasRts) {
                        $setParts[] = "rts_reason = {$rtsCase}";
                    }

                    $sql = "
                        UPDATE " . DB::getTablePrefix() . (new FromJnt)->getTable() . "
                        SET " . implode(",\n                            ", $setParts) . "
                        WHERE waybill_number IN ({$inList})
                          AND LOWER(status) NOT IN ('delivered','returned')
                    ";

                    DB::statement($sql);
                    $this->updated += count($chunkKeys);
                }

                if (!empty($statusInfo)) {
                    $wbKeys = array_keys($statusInfo);

                    $rowsForLogs = FromJnt::whereIn('waybill_number', $wbKeys)
                        ->select(['id', 'waybill_number', 'status_logs'])
                        ->get();

                    foreach ($rowsForLogs as $row) {
                        $wb = $row->waybill_number;
                        if (!isset($statusInfo[$wb])) continue;

                        $oldStatus = $statusInfo[$wb]['from'];
                        $newStatus = $statusInfo[$wb]['to'];

                        $logs = $row->status_logs ?: [];
                        if (!is_array($logs)) {
                            $decoded = json_decode($logs, true);
                            $logs = is_array($decoded) ? $decoded : [];
                        }

                        $newLogs = $this->appendStatusLogForJob(
                            $logs,
                            $oldStatus,
                            $newStatus,
                            $batchAtCarbon
                        );

                        if ($newLogs !== $logs) {
                            $row->status_logs = $newLogs;
                            $row->save();
                        }
                    }
                }
            }
        });
    }

    protected function appendStatusLogForJob($currentLogs, ?string $oldStatusRaw, ?string $newStatusRaw, Carbon $batchAt): array
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

        $shouldAdd = false;

        if ($oldStatus === null && $newStatus !== null) {
            $shouldAdd = true;
        } elseif ($oldStatus !== null && $newStatus !== null && $oldStatus !== $newStatus) {
            $shouldAdd = true;
        } elseif ($newStatus !== null && strcasecmp($newStatus, 'In Transit') === 0) {
            $lastInTransitLog = null;

            for ($i = count($logs) - 1; $i >= 0; $i--) {
                $log = $logs[$i] ?? null;
                if (!is_array($log)) continue;

                if (isset($log['to']) && strcasecmp((string)$log['to'], 'In Transit') === 0) {
                    $lastInTransitLog = $log;
                    break;
                }
            }

            if ($lastInTransitLog) {
                try {
                    $lastDate    = Carbon::parse($lastInTransitLog['batch_at'])->toDateString();
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
                'upload_log_id' => $this->uploadLogId,
                'from'          => $oldStatus,
                'to'            => $newStatus,
            ];
        }

        return $logs;
    }

    private function touchProgress(UploadLog $log)
    {
        $log->processed_rows = $this->processed;
        $log->inserted       = $this->inserted;
        $log->updated        = $this->updated;
        $log->skipped        = $this->skipped;
        $log->save();
    }

    private function writeErrorsCsv($absPath, array $rows)
    {
        @mkdir(dirname($absPath), 0777, true);
        $fp = fopen($absPath, 'w');
        if (!$fp) return;

        fputcsv($fp, array_keys($rows[0]));
        foreach ($rows as $r) {
            fputcsv($fp, $r);
        }
        fclose($fp);
    }

    private function rrmdir($dir)
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

    // ==========================
    // ✅ NEW helper methods (storage-safe for local+s3)
    // ==========================

    private function resolveDisk(UploadLog $log): string
    {
        $disk = (string)($log->disk ?? '');
        $disk = trim($disk);
        if ($disk !== '') return $disk;

        $def = (string)config('filesystems.default');
        return $def !== '' ? $def : 'local';
    }

    /**
     * Returns: [localPath, cleanupPath|null]
     * - local disk => cleanup null
     * - s3 disk => downloads to temp, cleanup = temp file
     */
    private function localizeFile(string $disk, string $path, string $ext): array
    {
        // local/public => real local file path
        if (in_array($disk, ['local', 'public'], true)) {
            return [Storage::disk($disk)->path($path), null];
        }

        // s3/others => download to temp file
        $tmp = $this->makeTmpPath('jnt_' . uniqid(), $ext);

        $in = Storage::disk($disk)->readStream($path);
        if (!$in) {
            throw new \RuntimeException("Cannot read file from disk={$disk}: {$path}");
        }

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

        $ext = ltrim((string)$ext, '.');
        return $root . DIRECTORY_SEPARATOR . $name . '.' . $ext;
    }
}
