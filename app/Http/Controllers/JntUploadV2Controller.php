<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessJntUploadV2;
use App\Models\BulkUploadRun;
use App\Models\UploadLogV2;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Reader\CSV\Reader as CsvReaderV4;
use OpenSpout\Reader\Common\Creator\ReaderEntityFactory;
use OpenSpout\Reader\XLSX\Reader as XlsxReaderV4;
use ZipArchive;

class JntUploadV2Controller extends Controller
{
    /** Headers required for a file to pass precheck. */
    const REQUIRED_HEADERS = ['waybill_number', 'status', 'signingtime'];

    /** Header aliases — same set as v1 for compatibility. */
    const HEADER_ALIASES = [
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

    public function index()
    {
        $recentRuns = BulkUploadRun::where('type', 'jnt_v2')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        return view('jnt_upload_v2.index', [
            'recentRuns' => $recentRuns,
        ]);
    }

    /**
     * STEP 1 — receive files, save to disk, create the bulk run + per-file
     * upload_logs_v2 rows, run header validation, return precheck report.
     */
    public function precheck(Request $request)
    {
        try {
            $request->validate([
                'files'    => 'required|array|min:1',
                'files.*'  => 'file|mimes:zip,csv,xlsx|max:1048576', // 1GB per file
                'batch_at' => 'nullable|string',
                'run_id'   => 'nullable|integer|exists:bulk_upload_runs,id',
            ]);

            $batchAt = $this->parseBatchAt($request->input('batch_at'));

            $userId = Auth::id();
            $disk   = config('filesystems.default') ?: 'local';
            $folder = 'uploads/jnt_v2/' . now()->format('Y-m-d');

            // Reuse existing run kapag may run_id; otherwise create new.
            $runId = $request->input('run_id');
            if ($runId) {
                $run = BulkUploadRun::findOrFail((int) $runId);
                if ($batchAt && empty($run->batch_at)) {
                    $run->batch_at = $batchAt;
                    $run->save();
                }
            } else {
                $run = BulkUploadRun::create([
                    'type'        => 'jnt_v2',
                    'user_id'     => $userId,
                    'status'      => 'precheck',
                    'total_files' => 0,
                    'batch_at'    => $batchAt,
                ]);
            }

            $files = $request->file('files');
            $report = [];

            foreach ($files as $file) {
                if (!$file || !$file->isValid()) continue;

                $original = $file->getClientOriginalName();
                $ext      = strtolower($file->getClientOriginalExtension());
                $basename = Str::slug(pathinfo($original, PATHINFO_FILENAME));
                $stored   = $basename . '__' . now()->format('His') . '_' . Str::random(6) . '.' . $ext;
                $path     = $file->storeAs($folder, $stored, $disk);

                $log = UploadLogV2::create([
                    'bulk_run_id'   => $run->id,
                    'user_id'       => $userId,
                    'type'          => 'jnt_v2',
                    'disk'          => $disk,
                    'path'          => $path,
                    'original_name' => $original,
                    'mime_type'     => $file->getMimeType(),
                    'size'          => $file->getSize(),
                    'status'        => 'queued',
                ]);

                // Run precheck on this file
                $check = $this->precheckFile($disk, $path, $ext, $original);

                $log->precheck_report = $check;
                $log->status = $check['ok'] ? 'precheck_ok' : 'precheck_failed';
                $log->save();

                $report[] = [
                    'log_id'           => $log->id,
                    'original_name'    => $original,
                    'size'             => $file->getSize(),
                    'ok'               => $check['ok'],
                    'issues'           => $check['issues'] ?? [],
                    'inner_files'      => $check['inner_files'] ?? null,
                    'detected_headers' => $check['detected_headers'] ?? [],
                ];
            }

            // Recount total files in this run (handles incremental adds)
            $run->total_files = UploadLogV2::where('bulk_run_id', $run->id)->count();
            $run->save();

            return response()->json([
                'run_id'      => $run->id,
                'total_files' => $run->total_files,
                'files'       => $report,
            ]);
        } catch (\Throwable $e) {
            Log::error('JntUploadV2 precheck error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'error'   => true,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * STEP 2 — confirm + dispatch processing for the chosen file IDs.
     */
    public function start(Request $request)
    {
        try {
            $request->validate([
                'run_id'   => 'required|integer|exists:bulk_upload_runs,id',
                'log_ids'  => 'required|array|min:1',
                'log_ids.*'=> 'integer',
            ]);

            $run = BulkUploadRun::findOrFail($request->integer('run_id'));

            $logs = UploadLogV2::where('bulk_run_id', $run->id)
                ->whereIn('id', $request->input('log_ids'))
                ->get();

            if ($logs->isEmpty()) {
                return response()->json(['error' => true, 'message' => 'No files selected'], 422);
            }

            // Mark un-selected files as skipped
            $selectedIds = $logs->pluck('id')->all();
            UploadLogV2::where('bulk_run_id', $run->id)
                ->whereNotIn('id', $selectedIds)
                ->whereIn('status', ['precheck_ok', 'precheck_failed', 'queued'])
                ->update(['status' => 'skipped']);

            // Lock invalid logs out
            $valid = $logs->filter(fn ($l) => $l->status === 'precheck_ok')->values();
            $invalid = $logs->filter(fn ($l) => $l->status !== 'precheck_ok')->values();

            foreach ($invalid as $bad) {
                $bad->status = 'skipped';
                $bad->save();
            }

            if ($valid->isEmpty()) {
                $run->status      = 'failed';
                $run->finished_at = Carbon::now('Asia/Manila');
                $run->message     = 'No valid files to process.';
                $run->save();

                return response()->json([
                    'run_id' => $run->id,
                    'status' => $run->status,
                ], 422);
            }

            $run->status      = 'processing';
            $run->started_at  = Carbon::now('Asia/Manila');
            $run->save();

            foreach ($valid as $log) {
                $log->status = 'queued';
                $log->save();
                ProcessJntUploadV2::dispatch($log->id);
            }

            return response()->json([
                'run_id'    => $run->id,
                'status'    => $run->status,
                'queued'    => $valid->count(),
                'skipped'   => $invalid->count(),
            ]);
        } catch (\Throwable $e) {
            Log::error('JntUploadV2 start error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'error'   => true,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Polling endpoint — returns aggregate run + per-file states.
     */
    public function status(Request $request, int $runId)
    {
        $run = BulkUploadRun::findOrFail($runId);

        $files = UploadLogV2::where('bulk_run_id', $run->id)
            ->orderBy('id')
            ->get(['id', 'original_name', 'status', 'total_rows', 'processed_rows',
                   'inserted', 'updated', 'skipped', 'error_rows', 'errors_path',
                   'started_at', 'finished_at', 'error_message']);

        // Recompute aggregates from current per-file state
        $totals = [
            'files_done'      => $files->where('status', 'done')->count(),
            'files_failed'    => $files->where('status', 'failed')->count(),
            'files_skipped'   => $files->where('status', 'skipped')->count(),
            'total_processed' => (int) $files->sum('processed_rows'),
            'total_inserted'  => (int) $files->sum('inserted'),
            'total_updated'   => (int) $files->sum('updated'),
            'total_skipped'   => (int) $files->sum('skipped'),
            'total_errors'    => (int) $files->sum('error_rows'),
        ];

        // If processing and all files reached terminal state, finalize
        if ($run->status === 'processing') {
            $terminal = $files->whereIn('status', ['done', 'failed', 'skipped'])->count();
            if ($terminal === $files->count() && $files->count() > 0) {
                $allDone = $files->whereIn('status', ['done', 'skipped'])->count() === $files->count();
                $run->status = $allDone
                    ? ($totals['files_failed'] > 0 ? 'partial' : 'done')
                    : ($totals['files_done'] > 0 ? 'partial' : 'failed');
                $run->finished_at = Carbon::now('Asia/Manila');
            }
        }

        $run->fill($totals);
        $run->save();

        return response()->json([
            'run' => [
                'id'              => $run->id,
                'status'          => $run->status,
                'total_files'     => $run->total_files,
                'files_done'      => $run->files_done,
                'files_failed'    => $run->files_failed,
                'files_skipped'   => $run->files_skipped,
                'total_processed' => $run->total_processed,
                'total_inserted'  => $run->total_inserted,
                'total_updated'   => $run->total_updated,
                'total_skipped'   => $run->total_skipped,
                'total_errors'    => $run->total_errors,
                'started_at'      => optional($run->started_at)->toDateTimeString(),
                'finished_at'     => optional($run->finished_at)->toDateTimeString(),
            ],
            'files' => $files->map(function ($f) {
                return [
                    'id'             => $f->id,
                    'original_name'  => $f->original_name,
                    'status'         => $f->status,
                    'total_rows'     => $f->total_rows,
                    'processed_rows' => $f->processed_rows,
                    'inserted'       => $f->inserted,
                    'updated'        => $f->updated,
                    'skipped'        => $f->skipped,
                    'error_rows'     => $f->error_rows,
                    'errors_path'    => $f->errors_path,
                    'error_message'  => $f->error_message,
                    'started_at'     => optional($f->started_at)->toDateTimeString(),
                    'finished_at'    => optional($f->finished_at)->toDateTimeString(),
                ];
            }),
        ]);
    }

    public function history(Request $request)
    {
        $userFilter   = trim((string) $request->query('user', ''));
        $statusFilter = trim((string) $request->query('status', ''));
        $fromDate     = trim((string) $request->query('from_date', ''));
        $toDate       = trim((string) $request->query('to_date', ''));

        $q = BulkUploadRun::query()
            ->where('type', 'jnt_v2')
            ->leftJoin('users', 'users.id', '=', 'bulk_upload_runs.user_id')
            ->select('bulk_upload_runs.*', 'users.name as user_name', 'users.email as user_email');

        if ($userFilter !== '') {
            $q->where(function ($w) use ($userFilter) {
                $w->where('users.name', 'like', "%{$userFilter}%")
                  ->orWhere('users.email', 'like', "%{$userFilter}%");
            });
        }
        if ($statusFilter !== '') {
            $q->where('bulk_upload_runs.status', $statusFilter);
        }
        if ($fromDate !== '') {
            $q->where('bulk_upload_runs.created_at', '>=', $fromDate . ' 00:00:00');
        }
        if ($toDate !== '') {
            $q->where('bulk_upload_runs.created_at', '<=', $toDate . ' 23:59:59');
        }

        $runs = $q->orderByDesc('bulk_upload_runs.id')
            ->paginate(25)
            ->appends($request->query());

        return view('jnt_upload_v2.history', [
            'runs'         => $runs,
            'userFilter'   => $userFilter,
            'statusFilter' => $statusFilter,
            'fromDate'     => $fromDate,
            'toDate'       => $toDate,
        ]);
    }

    public function historyDetail(Request $request, int $runId)
    {
        $run = BulkUploadRun::with('user')->findOrFail($runId);
        $files = UploadLogV2::where('bulk_run_id', $run->id)->orderBy('id')->get();

        return view('jnt_upload_v2.history_detail', [
            'run'   => $run,
            'files' => $files,
        ]);
    }

    // ========================
    // Helpers
    // ========================

    private function parseBatchAt(?string $raw): ?string
    {
        if (empty($raw)) return null;
        try {
            return Carbon::createFromFormat('Y-m-d\TH:i', $raw, 'Asia/Manila')->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            try {
                return Carbon::parse($raw, 'Asia/Manila')->format('Y-m-d H:i:s');
            } catch (\Throwable $e2) {
                return null;
            }
        }
    }

    /**
     * Lightweight pre-flight check on a single file.
     * Returns ['ok' => bool, 'issues' => [...], 'detected_headers' => [...], 'inner_files' => [...]]
     */
    private function precheckFile(string $disk, string $path, string $ext, string $original): array
    {
        $issues = [];
        $headers = [];
        $innerFiles = null;

        try {
            [$localPath, $cleanup] = $this->localizeFile($disk, $path, $ext);

            try {
                if ($ext === 'zip') {
                    $innerFiles = [];
                    $zip = new ZipArchive();
                    if ($zip->open($localPath) !== true) {
                        $issues[] = 'Cannot open ZIP archive';
                    } else {
                        $tmpRoot = $this->makeTmpDir('jnt_v2_zip_check_' . uniqid());
                        try {
                            $anyValid = false;
                            for ($i = 0; $i < $zip->numFiles; $i++) {
                                $stat = $zip->statIndex($i);
                                $name = $stat['name'] ?? '';
                                $innerExt = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                                if (!in_array($innerExt, ['csv', 'xlsx'], true)) continue;

                                $target = $tmpRoot . DIRECTORY_SEPARATOR . basename($name);
                                $stream = $zip->getStream($name);
                                if (!$stream) {
                                    $innerFiles[] = ['name' => $name, 'ok' => false, 'issues' => ['Cannot read entry']];
                                    continue;
                                }
                                $out = fopen($target, 'wb');
                                stream_copy_to_stream($stream, $out);
                                fclose($stream);
                                fclose($out);

                                $sub = $this->precheckSingle($target, $innerExt);
                                $innerFiles[] = ['name' => $name, 'ok' => $sub['ok'], 'issues' => $sub['issues'], 'detected_headers' => $sub['detected_headers']];
                                if ($sub['ok']) $anyValid = true;
                            }
                            if (!$anyValid) {
                                $issues[] = 'No valid CSV/XLSX inside ZIP';
                            }
                        } finally {
                            $zip->close();
                            $this->rrmdir($tmpRoot);
                        }
                    }
                } elseif (in_array($ext, ['csv', 'xlsx'], true)) {
                    $sub = $this->precheckSingle($localPath, $ext);
                    $issues = array_merge($issues, $sub['issues']);
                    $headers = $sub['detected_headers'];
                } else {
                    $issues[] = 'Unsupported file type: ' . $ext;
                }
            } finally {
                if ($cleanup && file_exists($cleanup)) @unlink($cleanup);
            }
        } catch (\Throwable $e) {
            $issues[] = 'Read error: ' . $e->getMessage();
        }

        $ok = empty($issues);
        // for ZIPs: ok kapag may at least one valid inner
        return [
            'ok'               => $ok,
            'issues'           => $issues,
            'detected_headers' => $headers,
            'inner_files'      => $innerFiles,
        ];
    }

    private function precheckSingle(string $absPath, string $ext): array
    {
        $issues = [];
        $headers = [];

        $reader = $this->makeReader($ext);
        if (!$reader) {
            return ['ok' => false, 'issues' => ['No reader available for ' . $ext], 'detected_headers' => []];
        }

        try {
            $reader->open($absPath);
            $foundHeader = false;

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $headers = $row->toArray();
                    $foundHeader = true;
                    break 2;
                }
            }

            if (!$foundHeader || empty($headers)) {
                $issues[] = 'File is empty (no header row)';
            } else {
                $map = $this->buildHeaderMap($headers);
                $missing = [];
                foreach (self::REQUIRED_HEADERS as $req) {
                    if (!isset($map[$req])) {
                        $missing[] = $req;
                    }
                }
                if (!empty($missing)) {
                    $issues[] = 'Missing required column(s): ' . implode(', ', $missing);
                }
            }
        } catch (\Throwable $e) {
            $issues[] = 'Cannot parse file: ' . $e->getMessage();
        } finally {
            try { $reader->close(); } catch (\Throwable $e) {}
        }

        return [
            'ok'               => empty($issues),
            'issues'           => $issues,
            'detected_headers' => array_values(array_filter(array_map(fn ($h) => is_scalar($h) ? (string) $h : '', $headers))),
        ];
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
        $map = [];

        foreach ($headers as $idx => $label) {
            $h = $norm($label);
            $tokens = preg_split('/[^a-z0-9]+/u', $h, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            foreach (self::HEADER_ALIASES as $canon => $cands) {
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
