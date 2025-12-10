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

    /**
     * iisang batch timestamp per upload (string: 'Y-m-d H:i:s')
     * galing sa UploadLog->batch_at kung meron, else now()
     */
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

        $path = $log->path;
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        try {
            if ($ext === 'zip') {
                $this->processZip($log);
            } elseif (in_array($ext, ['csv', 'xlsx'])) {
                $this->processSingleFile(Storage::path($path), $ext, $log);
            } elseif ($ext === 'xls') {
                throw new \RuntimeException('XLS (legacy) is not supported for streaming. Please re-save as XLSX or CSV.');
            } else {
                throw new \RuntimeException('Unsupported file type: ' . $ext);
            }

            if (!empty($this->errors)) {
                $errorsPath = 'uploads/jnt/errors/upload_' . $log->id . '_' . date('Ymd_His') . '.csv';
                $this->writeErrorsCsv(Storage::path($errorsPath), $this->errors);
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

    private function processZip(UploadLog $log)
    {
        $zipPath = Storage::path($log->path);
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Cannot open ZIP: ' . $zipPath);
        }

        $tmpRoot = Storage::path('uploads/jnt/tmp/' . $log->id . '_' . uniqid());
        if (!is_dir($tmpRoot)) {
            @mkdir($tmpRoot, 0777, true);
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];
                $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, ['csv', 'xlsx'])) {
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

            // IMPORTANT: tanggapin ang "Order Status"
            'status'         => ['status', 'order status', 'order_status', 'orderstatus'],

            'item_name'      => ['item name', 'item', 'product', 'product name'],
            'sender'         => ['sender', 'shipper', 'from'],
            'receiver'       => ['receiver', 'consignee', 'to'],
            'receiver_cellphone' => ['receiver cellphone', 'receiver phone', 'consignee phone', 'phone', 'mobile'],
            'cod'            => ['cod', 'c.o.d', 'cod amt', 'cod amount', 'collect on delivery'],
            'submission_time'=> ['submission time', 'pu time', 'pickup time', 'created time'],
            'signingtime'    => ['signingtime', 'signing time', 'delivered time'],
            'remarks'        => ['remarks', 'remark', 'note', 'notes'],

            // NEW columns
            'province'           => ['province', 'prov'],
            'city'               => ['city', 'municipality', 'city/municipality'],
            'barangay'           => ['barangay', 'brgy', 'barangay name'],
            'total_shipping_cost'=> ['total shipping cost', 'shipping cost', 'total freight'],
            'rts_reason'         => ['rts reason', 'rts_reason', 'return reason', 'reason for rts'],
        ];

        $map = [];

        foreach ($headers as $idx => $label) {
            $h = $norm($label);
            $tokens = preg_split('/[^a-z0-9]+/u', $h, -1, PREG_SPLIT_NO_EMPTY);
            if (!$tokens) {
                $tokens = [];
            }

            foreach ($aliases as $canon => $cands) {
                if (isset($map[$canon])) {
                    continue;
                }

                foreach ($cands as $cand) {
                    $c = $norm($cand);
                    $matched = false;

                    if ($h === $c) {
                        $matched = true;
                    } elseif (mb_strpos($c, ' ') !== false) {
                        // phrase match
                        if (preg_match('/\b' . preg_quote($c, '/') . '\b/u', $h)) {
                            $matched = true;
                        }
                    } else {
                        // token match
                        if (in_array($c, $tokens, true)) {
                            $matched = true;
                        }
                    }

                    if ($matched) {
                        if ($canon === 'receiver' && $c === 'to' && $h !== 'to') {
                            $matched = false;
                        }
                        if ($canon === 'cod' && in_array('code', $tokens, true)) {
                            $matched = false;
                        }
                        if ($canon === 'receiver_cellphone' && in_array('sender', $tokens, true)) {
                            $matched = false;
                        }
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
        // required lang talaga: waybill_number, status, signingtime
        return isset($map['waybill_number'], $map['status'], $map['signingtime']);
    }

    private function normalizeRow(array $cells, array $map)
    {
        $get = function ($key) use ($cells, $map) {
            if (!isset($map[$key])) return '';
            $val = isset($cells[$map[$key]]) ? $cells[$map[$key]] : '';
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
                } catch (\Throwable $e) {
                }
            }

            $formats = [
                'Y-m-d H:i:s','Y-m-d H:i','m/d/Y H:i','d/m/Y H:i','m/d/Y','d/m/Y',
                'Y-m-d','d-m-Y H:i','d-m-Y H:i:s','d-m-Y',
                'H:i d-m-Y','H:i d/m/Y',
            ];
            foreach ($formats as $fmt) {
                try {
                    return Carbon::createFromFormat($fmt, $v, 'Asia/Manila')->format('Y-m-d H:i:s');
                } catch (\Throwable $e) {
                }
            }

            try {
                return Carbon::parse($v, 'Asia/Manila')->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                return null;
            }
        };

        $parseMoney = function ($v) {
            $v = (string) $v;
            // alisin currency symbol, comma, etc; iwan digits, dot, minus
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

            // NEW FIELDS FROM EXCEL
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
     * INSERT + UPDATE (status only) + status_logs
     */
    private function persistChunk(array $rows)
    {
        if (empty($rows)) return;

        // De-dup inside chunk by waybill (last one wins)
        $byWb = [];
        foreach ($rows as $r) {
            $byWb[$r['waybill_number']] = $r;
        }
        $rows = array_values($byWb);

        $waybills = array_column($rows, 'waybill_number');

        $existing = FromJnt::query()
            ->select(['waybill_number','status','status_logs'])
            ->whereIn('waybill_number', $waybills)
            ->get()
            ->keyBy('waybill_number');

        $toInsert   = [];
        $toUpdate   = [];
        $statusInfo = []; // wb => ['from'=>..., 'to'=>...]

        // ✅ Carbon version ng batch_at para sa logs
        $batchAtStr    = $this->batchAt ?: Carbon::now('Asia/Manila')->format('Y-m-d H:i:s');
        $batchAtCarbon = Carbon::parse($batchAtStr, 'Asia/Manila');

        foreach ($rows as $r) {
            $wb = $r['waybill_number'];

            if (isset($existing[$wb])) {
                // === UPDATE PATH ===
                $oldStatusRaw = (string) ($existing[$wb]->status ?: '');
                $cur          = strtolower($oldStatusRaw);

                // kapag delivered/returned na dati, skip (no update, no logs)
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

                // ✅ i-store lahat ng updates para mag-decide later kung maglo-log
                $statusInfo[$wb] = [
                    'from' => $oldStatusRaw,
                    'to'   => $newStatusRaw,
                ];
            } else {
                // === INSERT PATH ===

                // Initial status_logs: from = null, to = current status
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

                // bulk SQL update for status + signingtime
                foreach (array_chunk($keys, self::UPDATE_SUBCHUNK) as $chunkKeys) {
                    $statusCase = "CASE waybill_number\n";
                    $timeCase   = "CASE waybill_number\n";

                    foreach ($chunkKeys as $wb) {
                        $s = str_replace("'", "''", (string) $toUpdate[$wb]['status']);
                        $t = $toUpdate[$wb]['signingtime'];
                        $tSql  = $t ? ("'" . str_replace("'", "''", $t) . "'") : "NULL";
                        $wbSql = "'" . str_replace("'", "''", $wb) . "'";
                        $statusCase .= "WHEN {$wbSql} THEN '{$s}'\n";
                        $timeCase   .= "WHEN {$wbSql} THEN {$tSql}\n";
                    }

                    $statusCase .= "ELSE status END";
                    $timeCase   .= "ELSE signingtime END";

                    $inList = implode(',', array_map(function ($wb) {
                        return "'" . str_replace("'", "''", $wb) . "'";
                    }, $chunkKeys));

                    $sql = "
                        UPDATE " . DB::getTablePrefix() . (new FromJnt)->getTable() . "
                        SET status = {$statusCase},
                            signingtime = {$timeCase},
                            updated_at = NOW()
                        WHERE waybill_number IN ({$inList})
                          AND LOWER(status) NOT IN ('delivered','returned')
                    ";
                    DB::statement($sql);
                    $this->updated += count($chunkKeys);
                }

                // ✅ Append status_logs per row, gamit special In Transit logic
                if (!empty($statusInfo)) {
                    $wbKeys = array_keys($statusInfo);

                    $rowsForLogs = FromJnt::whereIn('waybill_number', $wbKeys)
                        ->select(['id', 'waybill_number', 'status_logs'])
                        ->get();

                    foreach ($rowsForLogs as $row) {
                        $wb = $row->waybill_number;
                        if (!isset($statusInfo[$wb])) {
                            continue;
                        }

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

                        // kung walang nadagdag, huwag na mag-save
                        if ($newLogs !== $logs) {
                            $row->status_logs = $newLogs;
                            $row->save();
                        }
                    }
                }
            }
        });
    }

    /**
     * Helper para sa status_logs sa Job:
     *
     * - Mag-aappend ng log if:
     *   1) oldStatus = null at may newStatus
     *   2) oldStatus != newStatus
     *   3) pareho silang "In Transit" pero ibang araw na si batchAt
     */
    protected function appendStatusLogForJob(
        $currentLogs,
        ?string $oldStatusRaw,
        ?string $newStatusRaw,
        Carbon $batchAt
    ): array {
        // i-normalize logs → array
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

        // 1) First time ever (from null → something)
        if ($oldStatus === null && $newStatus !== null) {
            $shouldAdd = true;

        // 2) Normal transition (nagbago status)
        } elseif ($oldStatus !== null && $newStatus !== null && $oldStatus !== $newStatus) {
            $shouldAdd = true;

        // 3) Same status, special rule for "In Transit"
        } elseif ($newStatus !== null && strcasecmp($newStatus, 'In Transit') === 0) {
            $lastInTransitLog = null;

            for ($i = count($logs) - 1; $i >= 0; $i--) {
                $log = $logs[$i] ?? null;
                if (!is_array($log)) {
                    continue;
                }

                if (isset($log['to']) && strcasecmp((string)$log['to'], 'In Transit') === 0) {
                    $lastInTransitLog = $log;
                    break;
                }
            }

            if ($lastInTransitLog) {
                try {
                    $lastDate    = Carbon::parse($lastInTransitLog['batch_at'])->toDateString();
                    $currentDate = $batchAt->toDateString();

                    // ✅ ibang araw na pero In Transit pa rin → log ulit
                    if ($lastDate !== $currentDate) {
                        $shouldAdd = true;
                    }
                } catch (\Throwable $e) {
                    // pag di ma-parse, safe side: log
                    $shouldAdd = true;
                }
            } else {
                // wala pang In Transit log dati → log
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
}
