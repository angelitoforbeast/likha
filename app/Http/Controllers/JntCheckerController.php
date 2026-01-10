<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Models\MacroOutput;
use App\Models\PageSenderMapping;

class JntCheckerController extends Controller
{
    public function index()
    {
        return view('jnt.checker', [
            'results'              => session('results'),
            'matchedCount'         => session('matchedCount'),
            'notMatchedCount'      => session('notMatchedCount'),
            'notInExcelCount'      => session('notInExcelCount'),
            'notInExcelRows'       => session('notInExcelRows'),
            'filter_date_start'    => session('filter_date_start'),
            'filter_date_end'      => session('filter_date_end'),
            'updatableCount'       => session('updatableCount'),

            // diagnostics
            'mappingMissingCount'  => session('mappingMissingCount'),
            'skippedCancelCount'   => session('skippedCancelCount'),
            'processedFilesCount'  => session('processedFilesCount'),
            'perfectMatch'         => session('perfectMatch'),
        ]);
    }

    public function upload(Request $request)
    {
        Log::info('📥 Entered upload() function');

        $request->validate([
            'excel_file' => 'required|mimes:xlsx,xls,zip',
        ]);

        $uploaded = $request->file('excel_file');
        $ext = strtolower($uploaded->getClientOriginalExtension());

        // Preload Sender -> Page mapping (NO N+1)
        $senderToPage = PageSenderMapping::select('SENDER_NAME', 'PAGE')->get()
            ->mapWithKeys(function ($m) {
                $sender = strtolower($this->normText($m->SENDER_NAME));
                $page   = $this->normText($m->PAGE);
                return [$sender => $page];
            })
            ->all();

        // =========================
        // Step A: Read Excel(s)
        // =========================
        $allResults = [];
        $skippedCancelCount = 0;
        $processedFilesCount = 0;

        if ($ext === 'zip') {
            $zipPath = $uploaded->getRealPath();
            $tmpDir = storage_path('app/tmp/jnt_checker_' . uniqid());
            if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);

            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                return back()->with('error', 'Failed to open ZIP file.');
            }

            $zip->extractTo($tmpDir);
            $zip->close();

            $excelFiles = $this->findExcelFilesRecursive($tmpDir);

            if (empty($excelFiles)) {
                $this->rrmdir($tmpDir);
                return back()->with('error', 'ZIP contains no .xlsx or .xls files.');
            }

            foreach ($excelFiles as $filePath) {
                $processedFilesCount++;
                $label = basename($filePath);

                $parsed = $this->parseExcelFileToResults($filePath, $senderToPage, $label);
                if ($parsed['error']) {
                    $this->rrmdir($tmpDir);
                    return back()->with('error', "File {$label}: " . $parsed['error']);
                }

                $skippedCancelCount += $parsed['skippedCancelCount'];
                $allResults = array_merge($allResults, $parsed['results']);
            }

            // cleanup
            $this->rrmdir($tmpDir);
        } else {
            $processedFilesCount = 1;

            $path = $uploaded->getRealPath();
            $label = $uploaded->getClientOriginalName();

            $parsed = $this->parseExcelFileToResults($path, $senderToPage, $label);
            if ($parsed['error']) {
                return back()->with('error', $parsed['error']);
            }

            $skippedCancelCount = $parsed['skippedCancelCount'];
            $allResults = $parsed['results'];
        }

        // If everything got skipped (e.g., all Cancel Order)
        if (empty($allResults)) {
            return redirect()
                ->route('jnt.checker')
                ->with([
                    'results'             => [],
                    'matchedCount'        => 0,
                    'notMatchedCount'     => 0,
                    'notInExcelCount'     => 0,
                    'notInExcelRows'      => collect([]),
                    'filter_date_start'   => $request->input('filter_date_start'),
                    'filter_date_end'     => $request->input('filter_date_end'),
                    'updatableCount'      => 0,
                    'mappingMissingCount' => 0,
                    'skippedCancelCount'  => $skippedCancelCount,
                    'processedFilesCount' => $processedFilesCount,
                    'perfectMatch'        => false,
                ])
                ->with('success', 'Uploaded successfully, but all rows were filtered out (Cancel Order).');
        }

        // =========================
        // Step B: Filter MacroOutput by date if provided
        // =========================
        $start = $request->input('filter_date_start');
        $end   = $request->input('filter_date_end');

        $query = MacroOutput::select('id', 'PAGE', 'PHONE NUMBER', 'ITEM_NAME', 'COD', 'TIMESTAMP', 'FULL NAME')
            ->where('STATUS', 'PROCEED');

        $driver = DB::getDriverName();

        if ($start && $end) {
            try {
                if ($driver === 'pgsql') {
                    $query->whereRaw(
                        "to_date(substring(\"TIMESTAMP\" from '.{10}$'), 'DD-MM-YYYY') BETWEEN ? AND ?",
                        [$start, $end]
                    );
                } else {
                    $query->whereBetween(
                        DB::raw("STR_TO_DATE(RIGHT(`TIMESTAMP`, 10), '%d-%m-%Y')"),
                        [date('Y-m-d', strtotime($start)), date('Y-m-d', strtotime($end))]
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('⚠️ Invalid date range.', ['err' => $e->getMessage()]);
            }
        } elseif ($start) {
            try {
                if ($driver === 'pgsql') {
                    $query->whereRaw(
                        "to_date(substring(\"TIMESTAMP\" from '.{10}$'), 'DD-MM-YYYY') = ?",
                        [$start]
                    );
                } else {
                    $query->where(
                        DB::raw("STR_TO_DATE(RIGHT(`TIMESTAMP`, 10), '%d-%m-%Y')"),
                        '=',
                        date('Y-m-d', strtotime($start))
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('⚠️ Invalid single date.', ['err' => $e->getMessage()]);
            }
        }

        Log::info('🗓️ Date Filter Start:', ['start' => $start]);
        Log::info('🗓️ Date Filter End:', ['end' => $end]);
        Log::info('📦 MacroOutput count after date + status filter:', ['count' => $query->count()]);

        $macroOutputRecords = $query->get();

        // =========================
        // Step C: Build Macro multiset (key -> list of ids)
        // =========================
        $macroKeyToIds = [];
        foreach ($macroOutputRecords as $mo) {
            $k = $this->makeKey(
                $mo->PAGE,
                $mo->{'PHONE NUMBER'},
                $mo->ITEM_NAME,
                $mo->COD
            );
            $macroKeyToIds[$k][] = (int) $mo->id;
        }

        // cursor per key
        $keyCursor = [];

        // =========================
        // Step D: STRICT 1-to-1 match (duplicates MUST match)
        // =========================
        $mappingMissingCount = 0;

        foreach ($allResults as &$res) {
            if (!$res['key']) {
                $mappingMissingCount++;
                $res['matched'] = false;
                $res['matched_id'] = null;
                continue;
            }

            $k = $res['key'];

            if (!isset($macroKeyToIds[$k])) {
                $res['matched'] = false;
                $res['matched_id'] = null;
                continue;
            }

            $i = $keyCursor[$k] ?? 0;

            if (!isset($macroKeyToIds[$k][$i])) {
                // duplicates exhausted
                $res['matched'] = false;
                $res['matched_id'] = null;
                continue;
            }

            $res['matched'] = true;
            $res['matched_id'] = $macroKeyToIds[$k][$i];
            $keyCursor[$k] = $i + 1;
        }
        unset($res);

        // =========================
        // Step E: Not in Excel (EXTRA in Macro) = remaining ids after allocation
        // =========================
        $extraMacroIds = [];
        foreach ($macroKeyToIds as $k => $ids) {
            $used = $keyCursor[$k] ?? 0;
            $count = count($ids);

            if ($used < $count) {
                for ($i = $used; $i < $count; $i++) {
                    $extraMacroIds[] = $ids[$i];
                }
            }
        }

        // faster membership lookup
        $extraIdSet = [];
        foreach ($extraMacroIds as $id) $extraIdSet[$id] = true;

        $notInExcelRows = $macroOutputRecords->filter(function ($row) use ($extraIdSet) {
            return isset($extraIdSet[(int)$row->id]);
        })->values();

        $notInExcelCount = $notInExcelRows->count();

        // =========================
        // Step F: Summary counts + sort
        // =========================
        $matchedCount    = collect($allResults)->where('matched', true)->count();
        $notMatchedCount = collect($allResults)->where('matched', false)->count();

        // Sort: mapping-missing first, then unmatched, then matched
        $allResults = collect($allResults)->sortBy(function ($r) {
            if (($r['page'] ?? '') === '❌ Not Found in Mapping') return 0;
            return ($r['matched'] ? 2 : 1);
        })->values()->all();

        // remove temp key
        foreach ($allResults as &$r) unset($r['key']);
        unset($r);

        // =========================
        // Step G: Updatable rows (STRICT: only assigned matched_id)
        // matched + has waybill + has mapped page
        // =========================
        $updatable = collect($allResults)
            ->filter(fn($r) =>
                !empty($r['matched']) &&
                !empty($r['matched_id']) &&
                !empty($r['waybill']) &&
                !empty($r['page']) &&
                $r['page'] !== '❌ Not Found in Mapping'
            )
            ->map(fn($r) => [
                'id' => (int)$r['matched_id'],
                'waybill' => $r['waybill'],
            ])
            ->values()
            ->all();

        session(['jnt_updatable' => $updatable]);
        $uniqueUpdatableCount = count(array_unique(array_column($updatable, 'id')));

        // Perfect match definition:
        // - all excel rows matched
        // - no extra macro rows
        // - no mapping missing
        $perfectMatch = ($notMatchedCount === 0) && ($notInExcelCount === 0) && ($mappingMissingCount === 0);

        return redirect()
            ->route('jnt.checker')
            ->with([
                'results'              => $allResults,
                'matchedCount'         => $matchedCount,
                'notMatchedCount'      => $notMatchedCount,
                'notInExcelCount'      => $notInExcelCount,
                'notInExcelRows'       => $notInExcelRows,
                'filter_date_start'    => $start,
                'filter_date_end'      => $end,
                'updatableCount'       => $uniqueUpdatableCount,

                'mappingMissingCount'  => $mappingMissingCount,
                'skippedCancelCount'   => $skippedCancelCount,
                'processedFilesCount'  => $processedFilesCount,
                'perfectMatch'         => $perfectMatch,
            ]);
    }

    public function update(Request $request)
    {
        $updatable = session('jnt_updatable', []);

        if (empty($updatable)) {
            return back()->with('error', 'No matched rows with WAYBILL found from the last upload.');
        }

        // Group MacroOutput IDs by WAYBILL para isang update per waybill
        $groups = [];
        foreach ($updatable as $u) {
            if (!isset($u['id'], $u['waybill'])) continue;
            $groups[$u['waybill']][] = (int) $u['id'];
        }

        $updated = 0;

        foreach ($groups as $waybill => $ids) {
            $ids = array_values(array_unique($ids));

            foreach (array_chunk($ids, 200) as $chunk) {
                $affected = MacroOutput::whereIn('id', $chunk)
                    ->update(['waybill' => $waybill]);

                $updated += $affected;
            }
        }

        return back()->with('success', "WAYBILL updated for {$updated} row(s).");
    }

    // ============================================================
    // Excel parsing (supports Order Status filter != "Cancel Order")
    // ============================================================
    private function parseExcelFileToResults(string $path, array $senderToPage, string $fileLabel): array
    {
        try {
            $spreadsheet = IOFactory::load($path);
            $sheet = $spreadsheet->getActiveSheet();
            $data = $sheet->toArray(null, true, true, true);
        } catch (\Throwable $e) {
            Log::error('❌ Excel load error: ' . $e->getMessage(), ['file' => $fileLabel]);
            return ['error' => 'Failed to read Excel file.', 'results' => [], 'skippedCancelCount' => 0];
        }

        $data = array_values($data);
        if (empty($data) || !isset($data[0])) {
            return ['error' => 'Excel file has no header row.', 'results' => [], 'skippedCancelCount' => 0];
        }

        // header map
        $headers = $data[0];
        $headerMap = [];
        foreach ($headers as $col => $value) {
            $normalized = strtolower(trim(preg_replace('/\s+/', ' ', (string)($value ?? ''))));
            if ($normalized !== '') $headerMap[$normalized] = $col;
        }

        // required columns
        $senderCol = $this->resolveHeader($headerMap, ['sender name', 'sender', 'shipper']);
        $receiverCol = $this->resolveHeader($headerMap, [
            'receiver cellphone', 'receiver phone', 'receiver mobile',
            'consignee phone', 'consignee cellphone', 'phone number', 'contact number',
        ]);
        $itemCol = $this->resolveHeader($headerMap, ['item name', 'item', 'product name', 'product']);
        $codCol = $this->resolveHeader($headerMap, ['cod', 'cod amt', 'cod amount', 'cod value']);

        // order status (required dahil gusto mo i-filter)
        $statusCol = $this->resolveHeader($headerMap, ['order status', 'status', 'orderstatus', 'order_status']);

        $missing = [];
        if (!$senderCol)   $missing[] = 'Sender Name';
        if (!$receiverCol) $missing[] = 'Receiver Cellphone';
        if (!$itemCol)     $missing[] = 'Item Name';
        if (!$codCol)      $missing[] = 'COD';
        if (!$statusCol)   $missing[] = 'Order Status';

        if (count($missing)) {
            return ['error' => 'Missing column(s): ' . implode(', ', $missing), 'results' => [], 'skippedCancelCount' => 0];
        }

        // optional waybill
        $waybillCol = $this->resolveHeader($headerMap, [
            'waybill', 'waybill no', 'waybill number',
            'tracking no', 'tracking number', 'tracking',
            'airwaybill', 'awb', 'awb no', 'awb number',
        ]);

        $rows = array_slice($data, 1);

        $results = [];
        $skippedCancelCount = 0;

        foreach ($rows as $row) {
            $status = strtolower($this->normText($row[$statusCol] ?? ''));
            // Filter: skip Cancel Order (kahit may trailing spaces / different casing)
            if ($status === 'cancel order') {
                $skippedCancelCount++;
                continue;
            }

            $sender   = $this->normText($row[$senderCol] ?? '');
            $receiver = $this->normPhone($row[$receiverCol] ?? '');
            $item     = $this->normText($row[$itemCol] ?? '');
            $cod      = $this->normCod($row[$codCol] ?? '0');
            $waybill  = $waybillCol ? $this->normText($row[$waybillCol] ?? '') : '';

            $mappedPage = $senderToPage[strtolower($sender)] ?? '';
            $key = $mappedPage ? $this->makeKey($mappedPage, $receiver, $item, $cod) : null;

            $results[] = [
                'source_file' => $fileLabel,
                'order_status'=> $this->normText($row[$statusCol] ?? ''),

                'sender'      => $sender,
                'page'        => $mappedPage ?: '❌ Not Found in Mapping',
                'receiver'    => $receiver,
                'item'        => $item,
                'cod'         => $cod, // "299.00"
                'waybill'     => $waybill,

                'matched'     => false,
                'matched_id'  => null,
                'key'         => $key, // temp
            ];
        }

        return ['error' => null, 'results' => $results, 'skippedCancelCount' => $skippedCancelCount];
    }

    // ============================================================
    // ZIP helpers
    // ============================================================
    private function findExcelFilesRecursive(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) continue;
            $ext = strtolower($file->getExtension());
            if (in_array($ext, ['xlsx', 'xls'], true)) {
                $out[] = $file->getPathname();
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

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isDir()) @rmdir($file->getPathname());
            else @unlink($file->getPathname());
        }

        @rmdir($dir);
    }

    // ============================================================
    // Helpers
    // ============================================================
    private function resolveHeader(array $headerMap, array $aliases): ?string
    {
        foreach ($aliases as $a) {
            $k = strtolower(trim(preg_replace('/\s+/', ' ', $a)));
            if (isset($headerMap[$k])) return $headerMap[$k];
        }
        return null;
    }

    private function normText($v): string
    {
        $v = (string) $v;
        $v = str_replace('Ã±', 'ñ', $v);
        $v = trim(preg_replace('/\s+/', ' ', $v));
        return $v;
    }

    private function normPhone($v): string
    {
        $p = preg_replace('/\D+/', '', (string) $v);

        // 63xxxxxxxxxx -> 0xxxxxxxxxx
        if (str_starts_with($p, '63') && strlen($p) === 12) $p = '0' . substr($p, 2);
        // 9xxxxxxxxx -> 09xxxxxxxxx
        if (strlen($p) === 10 && str_starts_with($p, '9')) $p = '0' . $p;

        return $p;
    }

    private function normCod($v): string
    {
        $raw = preg_replace('/[^0-9.]/', '', (string) $v);
        if ($raw === '' || $raw === '.') return '0.00';
        return number_format((float) $raw, 2, '.', '');
    }

    private function makeKey($page, $phone, $item, $cod): string
    {
        return strtolower($this->normText($page)) . '|' .
            $this->normPhone($phone) . '|' .
            strtolower($this->normText($item)) . '|' .
            $this->normCod($cod);
    }
}
