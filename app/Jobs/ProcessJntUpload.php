<?php

namespace App\Jobs;

use App\Models\UploadLog;
use App\Models\FromJnt;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Expression;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

// OpenSpout v3 style factory (most common). If you installed v4, we’ll fallback to direct readers below.
use OpenSpout\Reader\Common\Creator\ReaderEntityFactory;
// If you are on v4, these classes exist:
use OpenSpout\Reader\CSV\Reader as CsvReaderV4;
use OpenSpout\Reader\XLSX\Reader as XlsxReaderV4;

class ProcessJntUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 minutes (adjust)
    public int $tries = 3;

    private int $uploadLogId;

    // Tunable chunk sizes
    private const CHUNK_SIZE = 2000;      // rows to accumulate before DB write
    private const UPDATE_SUBCHUNK = 800;  // how many rows per bulk UPDATE

    private array $errors = [];
    private int $processed = 0;
    private int $inserted = 0;
    private int $updated = 0;
    private int $skipped = 0;

    public function __construct(int $uploadLogId)
    {
        $this->uploadLogId = $uploadLogId;
    }

    public function handle(): void
    {
        /** @var UploadLog $log */
        $log = UploadLog::findOrFail($this->uploadLogId);

        $log->update([
            'status'     => 'processing',
            'started_at' => now(),
        ]);

        $path = $log->path; // storage-relative path (disk=local)
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

            // Save errors (if any)
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
            $log->finished_at    = now();
            $log->save();
        } catch (\Throwable $e) {
            $log->status = 'failed';
            $log->finished_at = now();
            $log->save();

            // Bubble up para ma-record sa failed_jobs
            throw $e;
        }
    }

    private function processZip(UploadLog $log): void
    {
        $zipPath = Storage::path($log->path);
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Cannot open ZIP: ' . $zipPath);
        }

        // Extract-to-temp per entry to process with streaming reader
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
                    // Skip unknown files/folders
                    continue;
                }
                $target = $tmpRoot . DIRECTORY_SEPARATOR . basename($name);
                $stream = $zip->getStream($name);
                if (!$stream) {
                    // log error and continue
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
            // Clean tmp
            $this->rrmdir($tmpRoot);
        }
    }

    private function processSingleFile(string $absPath, string $ext, UploadLog $log): void
    {
        // Choose reader: prefer OpenSpout. V3 uses ReaderEntityFactory; v4 has concrete reader classes.
        $reader = null;

        if (class_exists(ReaderEntityFactory::class)) {
            // v3 style
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
            // v4 style fallback
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

        $buffer = [];
        $headerMap = null;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                // OpenSpout v3: $row->toArray() returns array of scalar values
                // v4: $row->toArray() also available
                $cells = $row->toArray();

                if ($headerMap === null) {
                    $headerMap = $this->buildHeaderMap($cells);
                    if (!$this->hasRequiredHeaders($headerMap)) {
                        throw new \RuntimeException('Missing required headers (Waybill & Status at least).');
                    }
                    continue; // next row (data)
                }

                $norm = $this->normalizeRow($cells, $headerMap);

                // Validate minimal fields
                if ($norm['waybill_number'] === '' || $norm['status'] === '') {
                    $this->errors[] = [
                        'Waybill Number' => $norm['waybill_number'] ?? '',
                        'Status'         => $norm['status'] ?? '',
                        'Error'          => 'Missing required fields',
                    ];
                    continue;
                }

                $buffer[$norm['waybill_number']] = $norm; // dedupe within file by WB
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

    /** Map header names (case-insensitive, fuzzy) to canonical keys */
    private function buildHeaderMap(array $headers): array
    {
        $norm = fn ($s) => trim(mb_strtolower((string) $s));

        $aliases = [
            'waybill_number' => ['waybill', 'waybill number', 'awb', 'tracking no', 'tracking number'],
            'status'         => ['status'],
            'item_name'      => ['item name', 'item', 'product', 'product name'],
            'sender'         => ['sender', 'shipper', 'from'],
            'receiver'       => ['receiver', 'consignee', 'to'],
            'receiver_cellphone' => ['receiver cellphone', 'receiver phone', 'consignee phone', 'phone', 'mobile'],
            'cod'            => ['cod', 'cod amt', 'cod amount'],
            'submission_time'=> ['submission time', 'pu time', 'pickup time', 'created time'],
            'signingtime'    => ['signingtime', 'signing time', 'delivered time'],
            'remarks'        => ['remarks', 'remark', 'note', 'notes'],
        ];

        $map = [];
        foreach ($headers as $idx => $label) {
            $h = $norm($label);
            foreach ($aliases as $canon => $cands) {
                foreach ($cands as $cand) {
                    if (strpos($h, $cand) !== false || $h === $cand) {
                        if (!isset($map[$canon])) {
                            $map[$canon] = $idx;
                        }
                    }
                }
            }
        }
        return $map;
    }

    private function hasRequiredHeaders(array $map): bool
    {
        return isset($map['waybill_number'], $map['status']);
    }

    private function normalizeRow(array $cells, array $map): array
    {
        $get = function (string $key) use ($cells, $map) {
            if (!isset($map[$key])) return '';
            $val = $cells[$map[$key]] ?? '';
            $val = is_scalar($val) ? (string) $val : '';
            return trim(preg_replace('/\s+/u', ' ', $val));
        };

        $parseDate = function (string $v): ?string {
            $v = trim($v);
            if ($v === '') return null;

            // Excel numeric date?
            if (is_numeric($v)) {
                try {
                    // Excel epoch: 1899-12-30
                    $base = Carbon::create(1899, 12, 30, 0, 0, 0, 'Asia/Manila');
                    $dt = $base->copy()->addDays((int) $v);
                    return $dt->format('Y-m-d H:i:s');
                } catch (\Throwable $e) {
                    // fall through
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
                } catch (\Throwable $e) { /* try next */ }
            }

            try {
                return Carbon::parse($v, 'Asia/Manila')->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                return null;
            }
        };

        return [
            'waybill_number'    => $get('waybill_number'),
            'status'            => $get('status'),
            'item_name'         => $get('item_name'),
            'sender'            => $get('sender'),
            'receiver'          => $get('receiver'),
            'receiver_cellphone'=> $get('receiver_cellphone'),
            'cod'               => $get('cod'),
            'submission_time'   => $parseDate($get('submission_time')),
            'signingtime'       => $parseDate($get('signingtime')),
            'remarks'           => $get('remarks'),
            'created_at'        => now()->toDateTimeString(),
            'updated_at'        => now()->toDateTimeString(),
        ];
    }

    private function persistChunk(array $rows): void
    {
        if (empty($rows)) return;

        // dedupe by waybill in chunk
        $byWb = [];
        foreach ($rows as $r) {
            $wb = $r['waybill_number'];
            $byWb[$wb] = $r; // last one wins
        }
        $rows = array_values($byWb);

        $waybills = array_column($rows, 'waybill_number');

        // Fetch existing (status only)
        $existing = FromJnt::query()
            ->select(['waybill_number','status'])
            ->whereIn('waybill_number', $waybills)
            ->get()
            ->keyBy('waybill_number');

        $toInsert = [];
        $toUpdate = []; // waybill => ['status' => ..., 'signingtime' => ..., 'updated_at' => now()]

        foreach ($rows as $r) {
            $wb = $r['waybill_number'];
            if (isset($existing[$wb])) {
                $cur = strtolower((string) $existing[$wb]->status);
                if (in_array($cur, ['delivered','returned'], true)) {
                    $this->skipped++;
                    continue;
                }
                $toUpdate[$wb] = [
                    'status'      => $r['status'],
                    'signingtime' => $r['signingtime'],
                    'updated_at'  => now()->toDateTimeString(),
                ];
            } else {
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
                    'created_at'         => $r['created_at'],
                    'updated_at'         => $r['updated_at'],
                ];
            }
        }

        DB::transaction(function () use ($toInsert, $toUpdate) {
            if (!empty($toInsert)) {
                // bulk insert
                FromJnt::insert($toInsert);
                $this->inserted += count($toInsert);
            }

            if (!empty($toUpdate)) {
                $keys = array_keys($toUpdate);
                // Bulk update with CASE (status + signingtime)
                foreach (array_chunk($keys, self::UPDATE_SUBCHUNK) as $chunkKeys) {
                    $statusCase = "CASE waybill_number\n";
                    $timeCase   = "CASE waybill_number\n";
                    foreach ($chunkKeys as $wb) {
                        $s = str_replace("'", "''", (string) $toUpdate[$wb]['status']);
                        $t = $toUpdate[$wb]['signingtime'];
                        $tSql = $t ? ("'" . str_replace("'", "''", $t) . "'") : "NULL";
                        $wbSql = "'" . str_replace("'", "''", $wb) . "'";
                        $statusCase .= "WHEN {$wbSql} THEN '{$s}'\n";
                        $timeCase   .= "WHEN {$wbSql} THEN {$tSql}\n";
                    }
                    $statusCase .= "ELSE status END";
                    $timeCase   .= "ELSE signingtime END";

                    $inList = implode(',', array_map(fn($wb) => "'" . str_replace("'", "''", $wb) . "'", $chunkKeys));
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
            }
        });
    }

    private function touchProgress(UploadLog $log): void
    {
        // Minimize writes: update only key counters
        $log->processed_rows = $this->processed;
        $log->inserted       = $this->inserted;
        $log->updated        = $this->updated;
        $log->skipped        = $this->skipped;
        $log->save();
    }

    private function writeErrorsCsv(string $absPath, array $rows): void
    {
        @mkdir(dirname($absPath), 0777, true);
        $fp = fopen($absPath, 'w');
        if (!$fp) return;

        // header
        fputcsv($fp, array_keys($rows[0]));
        foreach ($rows as $r) {
            fputcsv($fp, $r);
        }
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
}
