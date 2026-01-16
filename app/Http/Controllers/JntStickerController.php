<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Smalot\PdfParser\Parser;

class JntStickerController extends Controller
{
    private const SESS_PDF  = 'jnt_stickers_pdf';     // ['items'=>[], 'meta'=>[]]
    private const SESS_DB   = 'jnt_stickers_db';      // ['waybills'=>[], 'meta'=>[]]
    private const SESS_UI   = 'jnt_stickers_ui_meta'; // ['selected_files_count'=>int]
    private const SESS_MODE = 'jnt_stickers_mode';    // 'idle'|'pdf'|'db'|'compare'

    public function index(Request $request)
    {
        $filterDate = (string)($request->query('filter_date') ?? '');

        $pdf = session(self::SESS_PDF, [
            'items' => [],
            'meta' => [
                'files_received' => 0,
                'pages_total' => 0,
                'pages_ok' => 0,
                'pages_missing' => 0,
                'pages_ambiguous' => 0,
            ],
        ]);

        $db = session(self::SESS_DB, [
            'waybills' => [],
            'meta' => [
                'filter_date' => $filterDate,
                'count' => 0,
            ],
        ]);

        $uiMeta = session(self::SESS_UI, [
            'selected_files_count' => 0,
        ]);

        $mode = session(self::SESS_MODE, 'idle');

        // Build rows depending on mode
        $rows = [];
        if ($mode === 'pdf') {
            $rows = $this->buildPdfRows($pdf['items'] ?? []);
        } elseif ($mode === 'db') {
            $rows = $this->buildDbRows($db['waybills'] ?? []);
        } elseif ($mode === 'compare') {
            $rows = $this->buildCompareRows($pdf['items'] ?? [], $db['waybills'] ?? []);
        }

        $summary = $this->buildSummary($filterDate, $pdf, $db, $rows, $mode, $uiMeta);

        return view('jnt.stickers', [
            'filter_date' => $filterDate,
            'table' => [
                'mode' => $mode,
                'rows' => $rows,
                'summary' => $summary,
            ],
        ]);
    }

    public function upload(Request $request)
    {
        $request->validate([
            'filter_date' => ['nullable', 'date'],
            'pdf_files'   => ['required', 'array'],
            'pdf_files.*' => ['required', 'file', 'mimes:pdf', 'max:10240'], // 10MB each
            'selected_files_count' => ['nullable', 'integer', 'min:0'],
        ]);

        $filterDate = (string)($request->input('filter_date') ?? '');
        $selectedCount = (int)($request->input('selected_files_count') ?? 0);

        // Store UI meta (for detecting max_file_uploads truncation)
        session([self::SESS_UI => ['selected_files_count' => $selectedCount]]);

        $parser = new Parser();

        $items = []; // each OK page -> ['waybill','file','page']
        $filesReceived = 0;
        $pagesTotal = 0;
        $pagesOk = 0;
        $pagesMissing = 0;
        $pagesAmbiguous = 0;

        $files = $request->file('pdf_files', []);
        if (!is_array($files)) $files = [];

        foreach ($files as $file) {
            $filesReceived++;
            $fileName = $file->getClientOriginalName();

            $realPath = $file->getRealPath();
            if (!$realPath || !is_file($realPath)) {
                continue;
            }

            try {
                $pdf = $parser->parseFile($realPath);
                $pages = $pdf->getPages();

                foreach ($pages as $idx => $page) {
                    $pagesTotal++;
                    $pageNo = $idx + 1;

                    $text = (string)$page->getText();

                    // Waybill: JT + digits (adjust if your format changes)
                    preg_match_all('/\bJT\d+\b/', $text, $m);
                    $candidates = $m[0] ?? [];
                    $candidates = array_values(array_filter(array_map('trim', $candidates)));
                    $candidates = array_values(array_unique($candidates));

                    if (count($candidates) === 0) {
                        $pagesMissing++;
                        continue;
                    }

                    if (count($candidates) > 1) {
                        $pagesAmbiguous++;
                        continue;
                    }

                    $pagesOk++;
                    $items[] = [
                        'waybill' => $candidates[0],
                        'file'    => $fileName,
                        'page'    => $pageNo,
                    ];
                }
            } catch (\Throwable $e) {
                // parse fail -> skip file
                // logger()->error("JNT upload parse failed: {$fileName} - {$e->getMessage()}");
                continue;
            }
        }

        session([
            self::SESS_PDF => [
                'items' => $items,
                'meta' => [
                    'files_received' => $filesReceived,
                    'pages_total' => $pagesTotal,
                    'pages_ok' => $pagesOk,
                    'pages_missing' => $pagesMissing,
                    'pages_ambiguous' => $pagesAmbiguous,
                ],
            ],
            self::SESS_MODE => 'pdf',
        ]);

        // PRG pattern so hindi nawawala state + date
        return redirect()->route('jnt.stickers', [
            'filter_date' => $filterDate,
        ])->with('success', 'PDF uploaded and extracted successfully.');
    }

    public function loadDb(Request $request)
    {
        $request->validate([
            'filter_date' => ['required', 'date'],
        ]);

        $filterDate = (string)$request->input('filter_date');

        $waybills = DB::table('macro_output')
            ->select('waybill')
            ->whereNotNull('waybill')
            ->whereRaw("DATE(STR_TO_DATE(`TIMESTAMP`, '%H:%i %d-%m-%Y')) = ?", [$filterDate])
            ->pluck('waybill')
            ->map(fn($v) => trim((string)$v))
            ->filter(fn($v) => $v !== '')
            ->values()
            ->all();

        session([
            self::SESS_DB => [
                'waybills' => $waybills,
                'meta' => [
                    'filter_date' => $filterDate,
                    'count' => count($waybills),
                ],
            ],
            self::SESS_MODE => 'db',
        ]);

        return redirect()->route('jnt.stickers', [
            'filter_date' => $filterDate,
        ])->with('success', 'DB waybills loaded for selected date.');
    }

    public function compare(Request $request)
    {
        $request->validate([
            'filter_date' => ['required', 'date'],
        ]);

        $filterDate = (string)$request->input('filter_date');

        $pdf = session(self::SESS_PDF, ['items' => [], 'meta' => []]);
        $db  = session(self::SESS_DB,  ['waybills' => [], 'meta' => []]);

        // Ensure DB meta date matches current filter (optional)
        // If user changed date but didn't reload DB, you'll see missing results.
        // Recommended: require user to click "Show Waybills from DB" after changing date.

        session([self::SESS_MODE => 'compare']);

        return redirect()->route('jnt.stickers', [
            'filter_date' => $filterDate,
        ]);
    }

    public function reset(Request $request)
    {
        $request->validate([
            'filter_date' => ['nullable', 'date'],
        ]);

        $filterDate = (string)($request->input('filter_date') ?? '');

        session()->forget(self::SESS_PDF);
        session()->forget(self::SESS_DB);
        session()->forget(self::SESS_UI);
        session()->put(self::SESS_MODE, 'idle');

        return redirect()->route('jnt.stickers', [
            'filter_date' => $filterDate,
        ])->with('success', 'Cleared extracted data.');
    }

    // -------------------------
    // Helpers
    // -------------------------

    private function buildPdfRows(array $items): array
    {
        // one row per page (1 page = 1 waybill)
        $rows = [];
        foreach ($items as $it) {
            $rows[] = [
                'waybill' => (string)($it['waybill'] ?? ''),
                'status' => 'PDF',
                'pdf_count' => 1,
                'db_count' => 0,
                'duplicate_count' => 0,
                'files' => (string)($it['file'] ?? '') . ' (p' . (int)($it['page'] ?? 0) . ')',
                'severity' => 1,
            ];
        }
        return $rows;
    }

    private function buildDbRows(array $waybills): array
    {
        // one row per DB waybill occurrence
        // (If you want unique DB only, change here.)
        $rows = [];
        foreach ($waybills as $wb) {
            $wb = trim((string)$wb);
            if ($wb === '') continue;
            $rows[] = [
                'waybill' => $wb,
                'status' => 'DB',
                'pdf_count' => 0,
                'db_count' => 1,
                'duplicate_count' => 0,
                'files' => '',
                'severity' => 1,
            ];
        }
        return $rows;
    }

    private function buildCompareRows(array $pdfItems, array $dbWaybills): array
    {
        // PDF map: waybill => ['count'=>int, 'files'=>[file=>cnt]]
        $pdfMap = [];
        foreach ($pdfItems as $it) {
            $wb = trim((string)($it['waybill'] ?? ''));
            $fn = trim((string)($it['file'] ?? ''));
            if ($wb === '') continue;

            if (!isset($pdfMap[$wb])) $pdfMap[$wb] = ['count' => 0, 'files' => []];
            $pdfMap[$wb]['count']++;

            if ($fn !== '') {
                $pdfMap[$wb]['files'][$fn] = ($pdfMap[$wb]['files'][$fn] ?? 0) + 1;
            }
        }

        // DB map: waybill => count
        $dbMap = [];
        foreach ($dbWaybills as $wb) {
            $wb = trim((string)$wb);
            if ($wb === '') continue;
            $dbMap[$wb] = ($dbMap[$wb] ?? 0) + 1;
        }

        $allWaybills = array_unique(array_merge(array_keys($pdfMap), array_keys($dbMap)));

        $rows = [];
        foreach ($allWaybills as $wb) {
            $pdfCount = (int)($pdfMap[$wb]['count'] ?? 0);
            $dbCount  = (int)($dbMap[$wb] ?? 0);

            $fileList = '';
            if (isset($pdfMap[$wb]['files'])) {
                $parts = [];
                foreach ($pdfMap[$wb]['files'] as $fn => $cnt) {
                    $parts[] = $cnt > 1 ? "{$fn} ×{$cnt}" : $fn;
                }
                $fileList = implode('; ', $parts);
            }

            // Status priority
            $status = 'OK';
            $severity = 0;

            if ($pdfCount > 1) { $status = 'DUPLICATE_PDF'; $severity = 3; }
            else if ($dbCount > 1) { $status = 'DUPLICATE_DB'; $severity = 2; }
            else if ($pdfCount >= 1 && $dbCount === 0) { $status = 'MISSING_IN_DB'; $severity = 2; }
            else if ($dbCount >= 1 && $pdfCount === 0) { $status = 'MISSING_IN_PDF'; $severity = 2; }
            else { $status = 'OK'; $severity = 0; }

            $rows[] = [
                'waybill' => $wb,
                'status' => $status,
                'pdf_count' => $pdfCount,
                'db_count' => $dbCount,
                'duplicate_count' => max(0, $pdfCount - 1),
                'files' => $fileList,
                'severity' => $severity,
            ];
        }

        // Sort: problems first
        usort($rows, function($a, $b) {
            if (($a['severity'] ?? 0) !== ($b['severity'] ?? 0)) return ($b['severity'] ?? 0) <=> ($a['severity'] ?? 0);
            if (($a['pdf_count'] ?? 0) !== ($b['pdf_count'] ?? 0)) return ($b['pdf_count'] ?? 0) <=> ($a['pdf_count'] ?? 0);
            if (($a['db_count'] ?? 0) !== ($b['db_count'] ?? 0)) return ($b['db_count'] ?? 0) <=> ($a['db_count'] ?? 0);
            return strcmp((string)($a['waybill'] ?? ''), (string)($b['waybill'] ?? ''));
        });

        return $rows;
    }

    private function buildSummary(string $filterDate, array $pdf, array $db, array $rows, string $mode, array $uiMeta): array
    {
        $pdfMeta = $pdf['meta'] ?? [];
        $dbMeta = $db['meta'] ?? [];

        $pdfFilesReceived = (int)($pdfMeta['files_received'] ?? 0);
        $pdfPagesTotal    = (int)($pdfMeta['pages_total'] ?? 0);
        $pdfOkPages       = (int)($pdfMeta['pages_ok'] ?? 0);
        $pdfMissingPages  = (int)($pdfMeta['pages_missing'] ?? 0);
        $pdfAmbPages      = (int)($pdfMeta['pages_ambiguous'] ?? 0);

        $dbCount = (int)($dbMeta['count'] ?? 0);
        $dbFilterDate = (string)($dbMeta['filter_date'] ?? $filterDate);

        // Compare summary counts (only meaningful in compare)
        $compareOk = 0;
        $dupPdf = 0;
        $missDb = 0;
        $missPdf = 0;
        $dupDb = 0;

        if ($mode === 'compare') {
            foreach ($rows as $r) {
                $st = (string)($r['status'] ?? '');
                if ($st === 'OK') $compareOk++;
                if ($st === 'DUPLICATE_PDF') $dupPdf++;
                if ($st === 'DUPLICATE_DB') $dupDb++;
                if ($st === 'MISSING_IN_DB') $missDb++;
                if ($st === 'MISSING_IN_PDF') $missPdf++;
            }
        }

        // File truncation detection (max_file_uploads)
        $selectedFiles = (int)($uiMeta['selected_files_count'] ?? 0);
        $filesTruncated = ($selectedFiles > 0 && $pdfFilesReceived > 0 && $pdfFilesReceived < $selectedFiles)
            ? ($selectedFiles - $pdfFilesReceived)
            : 0;

        return [
            'pdf_files_selected' => $selectedFiles,
            'pdf_files_received' => $pdfFilesReceived,
            'pdf_files_truncated' => $filesTruncated,

            'pdf_pages_total' => $pdfPagesTotal,
            'pdf_pages_ok' => $pdfOkPages,
            'pdf_missing_pages' => $pdfMissingPages,
            'pdf_ambiguous_pages' => $pdfAmbPages,

            'db_filter_date' => $dbFilterDate,
            'db_waybills' => $dbCount,

            'compare_ok' => $compareOk,
            'duplicate_pdf' => $dupPdf,
            'duplicate_db' => $dupDb,
            'missing_in_db' => $missDb,
            'missing_in_pdf' => $missPdf,
        ];
    }
}
