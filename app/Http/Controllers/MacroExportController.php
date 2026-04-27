<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * /macro/export — CEO-only filtered export of the `macro_output` table.
 *
 *  - GET  /macro/export                  → filter UI page
 *  - POST /macro/export/count            → JSON {count} for the active filters
 *  - POST /macro/export/download         → streamed CSV / XLSX download
 *
 * Both CSV and XLSX writers stream rows to disk/output without loading
 * the entire result set into memory, so there is no hard row cap.
 */
class MacroExportController extends Controller
{
    /**
     * All columns in macro_output — order here is the default download order.
     * Source: schema migrations under database/migrations/*macro_output*.
     */
    private const ALL_COLUMNS = [
        'id', 'ts_date', 'TIMESTAMP', 'PAGE', 'fb_name', 'botcake_psid',
        'FULL NAME', 'PHONE NUMBER', 'ADDRESS', 'PROVINCE', 'CITY', 'BARANGAY',
        'ITEM_NAME', 'COD', 'NAME', 'CXD', 'waybill',
        'STATUS', 'status_logs',
        'shop_details', 'extracted_details', 'all_user_input', 'RESERVE COLUMN',
        'AI ANALYZE', 'APP SCRIPT CHECKER',
        'HISTORICAL LOGS',
        'edited_full_name', 'edited_phone_number', 'edited_address',
        'edited_province', 'edited_city', 'edited_barangay',
        'edited_cod', 'edited_item_name',
        'validate_1', 'validate_2', 'item_checker',
        'created_at', 'updated_at',
    ];

    private function checkAccess(): void
    {
        $roleRaw  = Auth::user()?->employeeProfile?->role ?? '';
        $roleNorm = preg_replace('/\s+/u', ' ', trim((string) $roleRaw));
        $isCEO    = preg_match('/^ceo$/iu', $roleNorm) === 1;
        if (!$isCEO) abort(404);
    }

    public function index()
    {
        $this->checkAccess();

        // Lookup data for the multi-select filters. Distinct values are cached
        // for 15 minutes — they don't change often and the queries can be slow.
        $distinct = $this->loadDistinctValues();
        $addressTriples = $this->loadAddressTriples();

        return view('macro.export', [
            'allColumns'         => self::ALL_COLUMNS,
            'distinctPages'      => $distinct['pages'],
            'distinctItems'      => $distinct['items'],
            'distinctStatuses'   => $distinct['statuses'],
            'distinctProvinces'  => $distinct['provinces'],
            'distinctCities'     => $distinct['cities'],
            'distinctBarangays'  => $distinct['barangays'],
            // Each triple = [province, city, barangay]. Used by the JS to
            // cascade-filter dropdowns (pick a city → barangay options narrow
            // to only those that occur in that city, etc.). Bidirectional.
            'addressTriples'     => $addressTriples,
        ]);
    }

    /**
     * Distinct (province, city, barangay) triples — used for cascading filter
     * in the UI. Cached 15min. If the result set is huge (>50k triples), we
     * still return it; client falls back to plain distinct lists if needed.
     */
    private function loadAddressTriples(): array
    {
        return cache()->remember('macro_export.addr_triples_v1', 900, function () {
            return DB::table('macro_output')
                ->select('PROVINCE', 'CITY', 'BARANGAY')
                ->whereNotNull('PROVINCE')->where('PROVINCE', '!=', '')
                ->whereNotNull('CITY')    ->where('CITY',     '!=', '')
                ->whereNotNull('BARANGAY')->where('BARANGAY', '!=', '')
                ->distinct()
                ->orderBy('PROVINCE')->orderBy('CITY')->orderBy('BARANGAY')
                ->get()
                ->map(fn($r) => [
                    (string) $r->PROVINCE,
                    (string) $r->CITY,
                    (string) $r->BARANGAY,
                ])
                ->all();
        });
    }

    /** Build the filtered query (without selecting columns yet). */
    private function buildQuery(Request $request)
    {
        $q = DB::table('macro_output');

        // ── Date range (ts_date — indexed) ──────────────────────────────────
        $start = trim((string) $request->input('start_date', ''));
        $end   = trim((string) $request->input('end_date',   ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $q->where('ts_date', '>=', $start);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   $q->where('ts_date', '<=', $end);

        // ── PAGE / ITEM_NAME / STATUS — multi-select (exact match, IN list) ─
        $pages = (array) $request->input('pages', []);
        $pages = array_values(array_filter(array_map('trim', $pages), fn($s) => $s !== ''));
        if (!empty($pages))   $q->whereIn('PAGE', $pages);

        $items = (array) $request->input('items', []);
        $items = array_values(array_filter(array_map('trim', $items), fn($s) => $s !== ''));
        if (!empty($items))   $q->whereIn('ITEM_NAME', $items);

        $statuses = (array) $request->input('statuses', []);
        $statuses = array_values(array_filter(array_map('trim', $statuses), fn($s) => $s !== ''));
        if (!empty($statuses)) $q->whereIn('STATUS', $statuses);

        // ── Address fields — INDEPENDENT, exact-match multi-select.
        //    Per user spec: "kahit isa lang pwede" — kung Barangay lang ang
        //    may laman, walang req sa Province / City. Within a field, multiple
        //    selections are OR'd. Across fields, AND.
        //    Inputs: provinces[], cities[], barangays[] (multi-select dropdowns).
        foreach ([
            'provinces' => 'PROVINCE',
            'cities'    => 'CITY',
            'barangays' => 'BARANGAY',
        ] as $reqKey => $col) {
            $vals = (array) $request->input($reqKey, []);
            $vals = array_values(array_filter(array_map('trim', $vals), fn($s) => $s !== ''));
            if (empty($vals)) continue;
            $q->whereIn($col, $vals);
        }

        // ── Free-text search across name/phone/address/all_user_input ──────
        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $like = '%' . mb_strtolower($search) . '%';
            $q->where(function ($sub) use ($like) {
                $sub->whereRaw('LOWER(`FULL NAME`) LIKE ?',   [$like])
                    ->orWhereRaw('LOWER(`PHONE NUMBER`) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(`ADDRESS`) LIKE ?',   [$like])
                    ->orWhereRaw('LOWER(all_user_input) LIKE ?', [$like]);
            });
        }

        // ── CXD substring match ────────────────────────────────────────────
        $cxd = trim((string) $request->input('cxd', ''));
        if ($cxd !== '') {
            $q->whereRaw('LOWER(`CXD`) LIKE ?', ['%' . mb_strtolower($cxd) . '%']);
        }

        // ── Waybill: any / yes / no ────────────────────────────────────────
        $waybill = (string) $request->input('waybill', 'any');
        if ($waybill === 'yes') {
            $q->whereNotNull('waybill')->where('waybill', '!=', '');
        } elseif ($waybill === 'no') {
            $q->where(function ($sub) {
                $sub->whereNull('waybill')->orWhere('waybill', '');
            });
        }

        // ── Tri-state boolean filters (any / yes / no) ────────────────────
        $triState = function ($field, $reqKey) use ($request, $q) {
            $v = (string) $request->input($reqKey, 'any');
            if ($v === 'yes') $q->where($field, true);
            elseif ($v === 'no') $q->where(function ($sub) use ($field) {
                $sub->where($field, false)->orWhereNull($field);
            });
        };
        // Validate flags
        $triState('validate_1',   'validate_1');
        $triState('validate_2',   'validate_2');
        $triState('item_checker', 'item_checker');
        // Edit flags
        foreach ([
            'edited_full_name', 'edited_phone_number', 'edited_address',
            'edited_province', 'edited_city', 'edited_barangay',
            'edited_cod', 'edited_item_name',
        ] as $f) $triState($f, $f);

        return $q;
    }

    /** POST /macro/export/count — preview row count for current filters. */
    public function count(Request $request)
    {
        $this->checkAccess();
        $count = (int) $this->buildQuery($request)->count();
        return response()->json(['ok' => true, 'count' => $count]);
    }

    /** POST /macro/export/download — streamed CSV or XLSX. */
    public function download(Request $request)
    {
        $this->checkAccess();

        $format = strtolower((string) $request->input('format', 'csv'));
        if (!in_array($format, ['csv', 'xlsx'], true)) $format = 'csv';

        // Column selection — must be a subset of ALL_COLUMNS to prevent
        // arbitrary-column injection.
        $requested = (array) $request->input('columns', []);
        $cols = array_values(array_intersect(self::ALL_COLUMNS, $requested));
        if (empty($cols)) $cols = self::ALL_COLUMNS;

        $base = $this->buildQuery($request)
            ->orderBy('ts_date')
            ->orderBy('id');

        // Build SELECT list — wrap each in backticks so spaces work.
        $selectExpr = implode(', ', array_map(
            fn($c) => '`' . str_replace('`', '', $c) . '`',
            $cols
        ));

        $filename = 'macro_export_' . date('Ymd_His') . '.' . $format;

        if ($format === 'csv') {
            return $this->streamCsv($base, $cols, $selectExpr, $filename);
        }
        return $this->streamXlsx($base, $cols, $selectExpr, $filename);
    }

    /**
     * Stream CSV via chunked DB query + fputcsv. No memory cap — works for
     * arbitrary row counts.
     */
    private function streamCsv($base, array $cols, string $selectExpr, string $filename): StreamedResponse
    {
        return new StreamedResponse(function () use ($base, $cols, $selectExpr) {
            $out = fopen('php://output', 'w');
            // BOM so Excel opens UTF-8 correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $cols);

            $base->selectRaw($selectExpr)->chunk(1000, function ($rows) use ($out, $cols) {
                foreach ($rows as $r) {
                    $line = [];
                    foreach ($cols as $c) {
                        $v = $r->{$c} ?? null;
                        $line[] = is_bool($v) ? ($v ? '1' : '0') : (string) ($v ?? '');
                    }
                    fputcsv($out, $line);
                }
                @ob_flush(); @flush();
            });

            fclose($out);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'X-Accel-Buffering'   => 'no',
            'Cache-Control'       => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * Stream XLSX via OpenSpout — true row-by-row writer, no full in-memory
     * matrix. Handles millions of rows on modest memory.
     */
    private function streamXlsx($base, array $cols, string $selectExpr, string $filename): StreamedResponse
    {
        return new StreamedResponse(function () use ($base, $cols, $selectExpr) {
            $writer = new XlsxWriter();
            $writer->openToFile('php://output');

            $writer->addRow(Row::fromValues($cols));

            $base->selectRaw($selectExpr)->chunk(1000, function ($rows) use ($writer, $cols) {
                $batch = [];
                foreach ($rows as $r) {
                    $line = [];
                    foreach ($cols as $c) {
                        $v = $r->{$c} ?? null;
                        $line[] = is_bool($v) ? ($v ? 1 : 0) : ($v ?? '');
                    }
                    $batch[] = Row::fromValues($line);
                }
                $writer->addRows($batch);
                @ob_flush(); @flush();
            });

            $writer->close();
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'X-Accel-Buffering'   => 'no',
            'Cache-Control'       => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * Distinct values for the PAGE / ITEM_NAME / STATUS multi-selects.
     * Cached for 5 minutes since these don't change often and the queries
     * can be slow on large tables.
     */
    private function loadDistinctValues(): array
    {
        // Cached 15 min — these are heavy queries on a big table, but the
        // distinct value sets change slowly.
        return cache()->remember('macro_export.distinct_v2', 900, function () {
            $distinctOf = function (string $col) {
                return DB::table('macro_output')
                    ->whereNotNull($col)->where($col, '!=', '')
                    ->distinct()->orderBy($col)
                    ->pluck($col)->all();
            };
            return [
                'pages'     => $distinctOf('PAGE'),
                'items'     => $distinctOf('ITEM_NAME'),
                'statuses'  => $distinctOf('STATUS'),
                'provinces' => $distinctOf('PROVINCE'),
                'cities'    => $distinctOf('CITY'),
                'barangays' => $distinctOf('BARANGAY'),
            ];
        });
    }
}
