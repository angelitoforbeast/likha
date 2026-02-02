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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

use ZipArchive;

// OpenSpout (compat v3/v4)
use OpenSpout\Reader\Common\Creator\ReaderEntityFactory; // v3
use OpenSpout\Reader\CSV\Reader as CsvReaderV4;          // v4
use OpenSpout\Reader\XLSX\Reader as XlsxReaderV4;        // v4

class ProcessJntCheckerRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;

    public function __construct(public int $runId) {}

    public function handle(): void
    {
        @set_time_limit(0);

        $run = DB::table('jnt_checker_runs')->where('id', $this->runId)->first();
        if (!$run) return;

        $now = now();

        DB::table('jnt_checker_runs')->where('id', $this->runId)->update([
            'status'     => 'processing',
            'started_at' => $now,
            'error_text' => null,
            'updated_at' => $now,
        ]);

        $cleanup = [];
        $reader  = null;

        try {
            // ---- Load uploaded files list from extras ----
            $extras = DB::table('jnt_checker_run_extras')->where('run_id', $this->runId)->first();
            $uploadedFiles = $extras ? json_decode((string)($extras->uploaded_files_json ?? '[]'), true) : [];
            if (!is_array($uploadedFiles)) $uploadedFiles = [];

            // ---- DB waybills set + mapping missing list ----
            [$dbSet, $mappingMissingList] = $this->getDbWaybillsAndMissing($run);

            // ---- Build Excel waybills set from all uploaded files (zip or xlsx) ----
            $excelSet = [];             // unique waybills from excel
            $excelRows = 0;
            $skippedCancel = 0;

            $fileErrors = [];           // per-file errors
            $filesProcessed = 0;

            // For UI lists (limit to avoid huge storage)
            $notMatchedExcelList = [];  // Excel -> not in DB
            $notInExcelList = [];       // DB -> not in Excel
            $skippedCancelList = [];    // optional: store some evidence

            foreach ($uploadedFiles as $fileMeta) {
                $relPath = $fileMeta['path'] ?? null;
                $orig    = $fileMeta['original'] ?? $relPath;

                if (!$relPath || !Storage::disk('local')->exists($relPath)) {
                    $fileErrors[] = [
                        'file'  => (string)$orig,
                        'error' => 'File not found on storage (local).',
                    ];
                    continue;
                }

                $full = Storage::disk('local')->path($relPath);
                $ext  = strtolower(pathinfo($full, PATHINFO_EXTENSION));

                // ZIP => extract, scan xlsx/xls/csv inside
                if ($ext === 'zip') {
                    $tmpDir = storage_path('app/jnt_checker_tmp/run_' . $this->runId . '_' . uniqid());
                    @mkdir($tmpDir, 0777, true);

                    $zip = new ZipArchive();
                    if ($zip->open($full) !== true) {
                        $fileErrors[] = [
                            'file'  => (string)$orig,
                            'error' => 'Failed to open ZIP.',
                        ];
                        continue;
                    }
                    $zip->extractTo($tmpDir);
                    $zip->close();

                    $cleanup[] = $tmpDir;

                    $found = $this->scanForExcelFiles($tmpDir);
                    if (count($found) === 0) {
                        $fileErrors[] = [
                            'file'  => (string)$orig,
                            'error' => 'No .xlsx/.xls/.csv found inside ZIP (wrong structure?).',
                        ];
                        continue;
                    }

                    foreach ($found as $insidePath) {
                        $res = $this->readExcelIntoSet(
                            $insidePath,
                            $excelSet,
                            $excelRows,
                            $skippedCancel,
                            $skippedCancelList
                        );

                        if ($res !== true) {
                            $fileErrors[] = [
                                'file'  => basename($insidePath),
                                'error' => (string)$res,
                            ];
                            continue;
                        }

                        $filesProcessed++;
                    }

                    continue;
                }

                // XLSX / CSV
                $res = $this->readExcelIntoSet(
                    $full,
                    $excelSet,
                    $excelRows,
                    $skippedCancel,
                    $skippedCancelList
                );

                if ($res !== true) {
                    $fileErrors[] = [
                        'file'  => (string)$orig,
                        'error' => (string)$res,
                    ];
                    continue;
                }

                $filesProcessed++;
            }

            // ---- Compute matches ----
            $matched = 0;
            $notMatchedExcel = 0;
            $notInExcel = 0;

            // Matched/NotMatched (Excel vs DB)
            foreach ($excelSet as $wb => $_true) {
                if (isset($dbSet[$wb])) {
                    $matched++;
                } else {
                    $notMatchedExcel++;
                    if (count($notMatchedExcelList) < 2000) $notMatchedExcelList[] = $wb;
                }
            }

            // Not in Excel (DB vs Excel)
            foreach ($dbSet as $wb => $_true) {
                if (!isset($excelSet[$wb])) {
                    $notInExcel++;
                    if (count($notInExcelList) < 2000) $notInExcelList[] = $wb;
                }
            }

            $mappingMissingCount = count($mappingMissingList);

            // Updatable: matched rows
            $updatableCount = $matched;

            // Perfect = no issues
            $isPerfect = (
                $filesProcessed > 0 &&
                $notMatchedExcel === 0 &&
                $notInExcel === 0 &&
                $mappingMissingCount === 0
            );

            // ---- Save results ----
            $now2 = now();

            DB::table('jnt_checker_runs')->where('id', $this->runId)->update([
                'status'               => $isPerfect ? 'done' : 'done',
                'matched_count'        => $matched,
                'not_matched_count'    => $notMatchedExcel,
                'not_in_excel_count'   => $notInExcel,
                'mapping_missing_count'=> $mappingMissingCount,
                'skipped_cancel_count' => $skippedCancel,
                'files_processed_count'=> $filesProcessed,
                'updatable_count'      => $updatableCount,
                'is_perfect'           => $isPerfect ? 1 : 0,
                'finished_at'          => $now2,
                'updated_at'           => $now2,
            ]);

            // Store lists + errors in extras
            $extrasPayload = [
                'file_errors'            => $fileErrors,
                'not_matched_list'       => $notMatchedExcelList,
                'not_in_excel_list'      => $notInExcelList,
                'mapping_missing_list'   => $mappingMissingList,
                'skipped_cancel_list'    => array_slice($skippedCancelList, 0, 2000),
                'meta' => [
                    'excel_rows_read' => $excelRows,
                ],
            ];

            DB::table('jnt_checker_run_extras')->updateOrInsert(
                ['run_id' => $this->runId],
                [
                    'run_id' => $this->runId,
                    'results_json' => json_encode($extrasPayload, JSON_UNESCAPED_UNICODE),
                    'updated_at' => $now2,
                    'created_at' => $extras ? ($extras->created_at ?? $now2) : $now2,
                ]
            );

        } catch (\Throwable $e) {
            $msg = $e->getMessage();

            DB::table('jnt_checker_runs')->where('id', $this->runId)->update([
                'status'     => 'failed',
                'error_text' => $msg,
                'finished_at'=> now(),
                'updated_at' => now(),
            ]);

            Log::error('[JNT CHECKER] FAILED', [
                'run_id' => $this->runId,
                'msg'    => $msg,
                'trace'  => $e->getTraceAsString(),
            ]);

            throw $e;
        } finally {
            try { if ($reader) $reader->close(); } catch (\Throwable $e) {}

            foreach ($cleanup as $p) {
                if (is_string($p) && is_dir($p)) $this->rrmdir($p);
            }
        }
    }

    /* =========================================================
       DB waybills + mapping missing
       ========================================================= */

    private function getDbWaybillsAndMissing(object $run): array
    {
        $col = $this->detectWaybillColumn();

        $start = $run->filter_date_start ? Carbon::parse($run->filter_date_start) : null;
        $end   = $run->filter_date_end   ? Carbon::parse($run->filter_date_end)   : null;

        $q = DB::table('macro_output')->select(['id', $col]);

        // date filter (ts_date typical)
        if ($start && $end) {
            $q->whereDate('ts_date', '>=', $start->toDateString())
              ->whereDate('ts_date', '<=', $end->toDateString());
        } elseif ($start) {
            $q->whereDate('ts_date', $start->toDateString());
        }

        $rows = $q->get();

        $set = [];
        $missing = [];

        foreach ($rows as $r) {
            $v = $r->{$col} ?? null;
            $v = $this->normalizeWaybill($v);

            if ($v === '') {
                if (count($missing) < 2000) {
                    $missing[] = [
                        'id' => $r->id,
                        'reason' => 'empty waybill',
                    ];
                }
                continue;
            }

            $set[$v] = true;
        }

        return [$set, $missing];
    }

    private function detectWaybillColumn(): string
    {
        // adjust list based sa actual schema mo
        $candidates = [
            'mailno', 'waybill', 'waybill_no', 'tracking_no', 'tracking', 'billcode',
        ];

        foreach ($candidates as $c) {
            if (Schema::hasTable('macro_output') && Schema::hasColumn('macro_output', $c)) {
                return $c;
            }
        }

        // fallback: if none found, still return something to force visible error
        throw new \RuntimeException("No waybill column found in macro_output (expected: " . implode(', ', $candidates) . ")");
    }

    /* =========================================================
       ZIP scanning
       ========================================================= */

    private function scanForExcelFiles(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $ext = strtolower($f->getExtension());
            if (in_array($ext, ['xlsx','xls','csv'], true)) {
                $out[] = $f->getPathname();
            }
        }
        return $out;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $f) {
            $p = $f->getPathname();
            if ($f->isDir()) @rmdir($p); else @unlink($p);
        }
        @rmdir($dir);
    }

    /* =========================================================
       Excel reader (OpenSpout v3/v4 safe)
       ========================================================= */

    private function readExcelIntoSet(
        string $fullPath,
        array &$excelSet,
        int &$excelRows,
        int &$skippedCancel,
        array &$skippedCancelList
    ) {
        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

        if ($ext === 'xls') {
            return 'XLS is not supported by OpenSpout. Please upload .xlsx or convert to .xlsx.';
        }

        $reader = $this->createReader($ext);
        if (!$reader) {
            return "No reader for extension: {$ext}";
        }

        try {
            $reader->open($fullPath);

            foreach ($reader->getSheetIterator() as $sheet) {
                $header = null;
                $idxWaybill = null;
                $idxStatus  = null;

                foreach ($sheet->getRowIterator() as $row) {
                    $cells = $row->toArray();

                    if ($header === null) {
                        $header = $this->normalizeHeader($cells);
                        [$idxWaybill, $idxStatus] = $this->detectColumnsFromHeader($header);
                        continue;
                    }

                    if ($this->isAllEmpty($cells)) continue;

                    $excelRows++;

                    $status = $idxStatus !== null ? (string)($cells[$idxStatus] ?? '') : '';
                    if ($this->isCancelRow($status)) {
                        $skippedCancel++;
                        if (count($skippedCancelList) < 2000) {
                            $skippedCancelList[] = [
                                'file' => basename($fullPath),
                                'status' => trim((string)$status),
                            ];
                        }
                        continue;
                    }

                    if ($idxWaybill === null) continue;

                    $wb = $cells[$idxWaybill] ?? null;
                    $wb = $this->normalizeWaybill($wb);
                    if ($wb === '') continue;

                    $excelSet[$wb] = true;
                }
            }

            return true;

        } catch (\Throwable $e) {
            return 'Failed to read Excel file.';
        } finally {
            try { $reader->close(); } catch (\Throwable $e) {}
        }
    }

    private function createReader(string $ext)
    {
        // v3
        if (class_exists(ReaderEntityFactory::class)) {
            if ($ext === 'xlsx') return ReaderEntityFactory::createXLSXReader();
            if ($ext === 'csv')  return ReaderEntityFactory::createCSVReader();
            return null;
        }

        // v4
        if ($ext === 'xlsx' && class_exists(XlsxReaderV4::class)) return new XlsxReaderV4();
        if ($ext === 'csv'  && class_exists(CsvReaderV4::class))  return new CsvReaderV4();

        return null;
    }

    private function normalizeHeader(array $hdr): array
    {
        return array_map(function ($h) {
            $h = trim((string) $h);
            $h = preg_replace('/\s+/', ' ', $h);
            return mb_strtolower($h);
        }, $hdr);
    }

    private function detectColumnsFromHeader(array $header): array
    {
        $idxWaybill = null;
        $idxStatus  = null;

        foreach ($header as $i => $h) {
            $h2 = preg_replace('/[^a-z0-9 ]/i', '', $h);
            $h2 = trim($h2);

            // waybill / tracking candidates
            if ($idxWaybill === null) {
                if (
                    str_contains($h2, 'waybill') ||
                    str_contains($h2, 'tracking') ||
                    str_contains($h2, 'air waybill') ||
                    str_contains($h2, 'billcode') ||
                    str_contains($h2, 'awb')
                ) {
                    $idxWaybill = $i;
                }
            }

            // status candidates
            if ($idxStatus === null) {
                if (str_contains($h2, 'status') || str_contains($h2, 'order status')) {
                    $idxStatus = $i;
                }
            }
        }

        return [$idxWaybill, $idxStatus];
    }

    private function isAllEmpty(array $cells): bool
    {
        foreach ($cells as $c) if (trim((string)$c) !== '') return false;
        return true;
    }

    private function isCancelRow(string $status): bool
    {
        $s = mb_strtolower(trim($status));
        if ($s === '') return false;

        return str_contains($s, 'cancel') || str_contains($s, 'cancel order');
    }

    private function normalizeWaybill($v): string
    {
        $s = trim((string)$v);
        if ($s === '') return '';

        // common cleanup
        $s = str_replace(["\xC2\xA0", ' '], '', $s); // remove NBSP + spaces
        $s = trim($s);

        return $s;
    }
}
