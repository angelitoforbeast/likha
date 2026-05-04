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
 * /macro/export — CEO-only filtered export.
 *
 *  - GET  /macro/export                  → filter UI page (table dropdown)
 *  - POST /macro/export/count            → JSON {count} for the active filters
 *  - POST /macro/export/download         → streamed CSV / XLSX download
 *
 * Three sources supported (selected via `?table=` or POST body `table`):
 *   - macro_output  → original full filter set
 *   - from_jnts     → JNT-tailored filter set
 *   - from_jnts_2   → same as from_jnts plus 2 audit cols
 *
 * Both CSV and XLSX writers stream rows to disk/output without loading
 * the entire result set into memory, so there is no hard row cap.
 */
class MacroExportController extends Controller
{
    /** Per-table config. Adding a new source = drop a new entry here. */
    private const TABLE_CONFIG = [
        'macro_output' => [
            'label'       => 'Macro Output',
            'columns'     => [
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
            ],
            'default_cols' => [
                'id','ts_date','PAGE','FULL NAME','PHONE NUMBER','ADDRESS',
                'PROVINCE','CITY','BARANGAY','ITEM_NAME','COD','STATUS','waybill',
            ],
            'cols' => [
                'page' => 'PAGE', 'item' => 'ITEM_NAME', 'status' => 'STATUS',
                'province' => 'PROVINCE', 'city' => 'CITY', 'barangay' => 'BARANGAY',
                'date' => 'ts_date',
            ],
            'order_by' => ['ts_date', 'id'],
        ],

        'from_jnts' => [
            'label'       => 'JNT — from_jnts',
            'columns'     => [
                'id', 'waybill_number', 'status', 'item_name', 'sender',
                'receiver', 'receiver_cellphone', 'cod',
                'submission_time', 'signingtime', 'remarks',
                'province', 'city', 'barangay',
                'total_shipping_cost', 'rts_reason',
                'status_logs', 'created_at', 'updated_at',
            ],
            'default_cols' => [
                'waybill_number','status','signingtime','item_name','sender',
                'receiver','receiver_cellphone','cod','province','city','barangay',
            ],
            'cols' => [
                'sender' => 'sender', 'item' => 'item_name', 'status' => 'status',
                'province' => 'province', 'city' => 'city', 'barangay' => 'barangay',
                'date_signing' => 'signingtime', 'date_submission' => 'submission_time',
                'cod' => 'cod', 'rts' => 'rts_reason',
            ],
            'order_by' => ['signingtime', 'id'],
        ],

        'from_jnts_2' => [
            'label'       => 'JNT — from_jnts_2 (sandbox)',
            'columns'     => [
                'id', 'waybill_number', 'status', 'item_name', 'sender',
                'receiver', 'receiver_cellphone', 'cod',
                'submission_time', 'signingtime', 'remarks',
                'province', 'city', 'barangay',
                'total_shipping_cost', 'rts_reason',
                'status_logs',
                'last_uploaded_by_user_id', 'last_upload_log_id',
                'created_at', 'updated_at',
            ],
            'default_cols' => [
                'waybill_number','status','signingtime','item_name','sender',
                'receiver','receiver_cellphone','cod','province','city','barangay',
            ],
            'cols' => [
                'sender' => 'sender', 'item' => 'item_name', 'status' => 'status',
                'province' => 'province', 'city' => 'city', 'barangay' => 'barangay',
                'date_signing' => 'signingtime', 'date_submission' => 'submission_time',
                'cod' => 'cod', 'rts' => 'rts_reason',
            ],
            'order_by' => ['signingtime', 'id'],
        ],
    ];

    private function checkAccess(): void
    {
        $roleRaw  = Auth::user()?->employeeProfile?->role ?? '';
        $roleNorm = preg_replace('/\s+/u', ' ', trim((string) $roleRaw));
        $isCEO    = preg_match('/^ceo$/iu', $roleNorm) === 1;
        if (!$isCEO) abort(404);
    }

    /** Resolve the table key from the request. Falls back to macro_output. */
    private function resolveTable(Request $request): string
    {
        $t = (string) ($request->input('table', $request->query('table', 'macro_output')));
        return array_key_exists($t, self::TABLE_CONFIG) ? $t : 'macro_output';
    }

    public function index(Request $request)
    {
        $this->checkAccess();
        $table  = $this->resolveTable($request);
        $config = self::TABLE_CONFIG[$table];

        // Lookup data for the multi-select filters per table.
        if ($table === 'macro_output') {
            $distinct       = $this->loadMacroDistinctValues();
            $addressTriples = $this->loadMacroAddressTriples();
            $distinctSenders = [];
        } else {
            $distinct       = $this->loadJntDistinctValues($table);
            $addressTriples = $this->loadJntAddressTriples($table);
            $distinctSenders = $distinct['senders'] ?? [];
        }

        return view('macro.export', [
            'tables'             => self::TABLE_CONFIG,
            'table'              => $table,
            'tableConfig'        => $config,
            'allColumns'         => $config['columns'],
            'defaultCols'        => $config['default_cols'],

            'distinctPages'      => $distinct['pages']      ?? [],
            'distinctItems'      => $distinct['items']      ?? [],
            'distinctStatuses'   => $distinct['statuses']   ?? [],
            'distinctProvinces'  => $distinct['provinces']  ?? [],
            'distinctCities'     => $distinct['cities']     ?? [],
            'distinctBarangays'  => $distinct['barangays']  ?? [],
            'distinctSenders'    => $distinctSenders,
            'addressTriples'     => $addressTriples,
        ]);
    }

    public function count(Request $request)
    {
        $this->checkAccess();
        $table = $this->resolveTable($request);
        $count = (int) $this->buildQuery($request, $table)->count();
        return response()->json(['ok' => true, 'count' => $count, 'table' => $table]);
    }

    public function download(Request $request)
    {
        $this->checkAccess();
        $table  = $this->resolveTable($request);
        $config = self::TABLE_CONFIG[$table];

        $format = strtolower((string) $request->input('format', 'csv'));
        if (!in_array($format, ['csv', 'xlsx'], true)) $format = 'csv';

        // Column selection — must be a subset of the table's whitelist.
        $requested = (array) $request->input('columns', []);
        $cols = array_values(array_intersect($config['columns'], $requested));
        if (empty($cols)) $cols = $config['columns'];

        $base = $this->buildQuery($request, $table);
        foreach ($config['order_by'] as $orderCol) {
            $base->orderBy($orderCol);
        }

        $selectExpr = implode(', ', array_map(
            fn($c) => '`' . str_replace('`', '', $c) . '`',
            $cols
        ));

        $filename = $table . '_export_' . date('Ymd_His') . '.' . $format;

        if ($format === 'csv') {
            return $this->streamCsv($base, $cols, $selectExpr, $filename);
        }
        return $this->streamXlsx($base, $cols, $selectExpr, $filename);
    }

    // =========================================================================
    // Query building — branches per table
    // =========================================================================

    private function buildQuery(Request $request, string $table)
    {
        if ($table === 'macro_output') {
            return $this->buildMacroQuery($request);
        }
        return $this->buildJntQuery($request, $table);
    }

    /** Original macro_output filter logic (unchanged). */
    private function buildMacroQuery(Request $request)
    {
        $q = DB::table('macro_output');

        $start = trim((string) $request->input('start_date', ''));
        $end   = trim((string) $request->input('end_date',   ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $q->where('ts_date', '>=', $start);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   $q->where('ts_date', '<=', $end);

        $pages = (array) $request->input('pages', []);
        $pages = array_values(array_filter(array_map('trim', $pages), fn($s) => $s !== ''));
        if (!empty($pages))   $q->whereIn('PAGE', $pages);

        $items = (array) $request->input('items', []);
        $items = array_values(array_filter(array_map('trim', $items), fn($s) => $s !== ''));
        if (!empty($items))   $q->whereIn('ITEM_NAME', $items);

        $statuses = (array) $request->input('statuses', []);
        $statuses = array_values(array_filter(array_map('trim', $statuses), fn($s) => $s !== ''));
        if (!empty($statuses)) $q->whereIn('STATUS', $statuses);

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

        $cxd = trim((string) $request->input('cxd', ''));
        if ($cxd !== '') {
            $q->whereRaw('LOWER(`CXD`) LIKE ?', ['%' . mb_strtolower($cxd) . '%']);
        }

        $waybill = (string) $request->input('waybill', 'any');
        if ($waybill === 'yes') {
            $q->whereNotNull('waybill')->where('waybill', '!=', '');
        } elseif ($waybill === 'no') {
            $q->where(function ($sub) {
                $sub->whereNull('waybill')->orWhere('waybill', '');
            });
        }

        $triState = function ($field, $reqKey) use ($request, $q) {
            $v = (string) $request->input($reqKey, 'any');
            if ($v === 'yes') $q->where($field, true);
            elseif ($v === 'no') $q->where(function ($sub) use ($field) {
                $sub->where($field, false)->orWhereNull($field);
            });
        };
        $triState('validate_1',   'validate_1');
        $triState('validate_2',   'validate_2');
        $triState('item_checker', 'item_checker');
        foreach ([
            'edited_full_name', 'edited_phone_number', 'edited_address',
            'edited_province', 'edited_city', 'edited_barangay',
            'edited_cod', 'edited_item_name',
        ] as $f) $triState($f, $f);

        return $q;
    }

    /** JNT filter logic for from_jnts and from_jnts_2. */
    private function buildJntQuery(Request $request, string $table)
    {
        $q = DB::table($table);

        // Date range — signingtime
        $sStart = trim((string) $request->input('signing_start', ''));
        $sEnd   = trim((string) $request->input('signing_end',   ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $sStart)) $q->where('signingtime', '>=', $sStart . ' 00:00:00');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $sEnd))   $q->where('signingtime', '<=', $sEnd   . ' 23:59:59');

        // Date range — submission_time
        $bStart = trim((string) $request->input('submission_start', ''));
        $bEnd   = trim((string) $request->input('submission_end',   ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $bStart)) $q->where('submission_time', '>=', $bStart . ' 00:00:00');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $bEnd))   $q->where('submission_time', '<=', $bEnd   . ' 23:59:59');

        // Multi-selects
        $statuses = (array) $request->input('statuses', []);
        $statuses = array_values(array_filter(array_map('trim', $statuses), fn($s) => $s !== ''));
        if (!empty($statuses)) $q->whereIn('status', $statuses);

        $items = (array) $request->input('items', []);
        $items = array_values(array_filter(array_map('trim', $items), fn($s) => $s !== ''));
        if (!empty($items)) $q->whereIn('item_name', $items);

        $senders = (array) $request->input('senders', []);
        $senders = array_values(array_filter(array_map('trim', $senders), fn($s) => $s !== ''));
        if (!empty($senders)) $q->whereIn('sender', $senders);

        // Address (cascading multi-select)
        foreach ([
            'provinces' => 'province',
            'cities'    => 'city',
            'barangays' => 'barangay',
        ] as $reqKey => $col) {
            $vals = (array) $request->input($reqKey, []);
            $vals = array_values(array_filter(array_map('trim', $vals), fn($s) => $s !== ''));
            if (empty($vals)) continue;
            $q->whereIn($col, $vals);
        }

        // Free-text search across waybill / receiver / receiver_cellphone
        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $like = '%' . mb_strtolower($search) . '%';
            $q->where(function ($sub) use ($like) {
                $sub->whereRaw('LOWER(waybill_number) LIKE ?',     [$like])
                    ->orWhereRaw('LOWER(receiver) LIKE ?',         [$like])
                    ->orWhereRaw('LOWER(receiver_cellphone) LIKE ?', [$like]);
            });
        }

        // COD has-value (any/yes/no)
        $codState = (string) $request->input('cod_state', 'any');
        if ($codState === 'yes') {
            $q->whereNotNull('cod')->where('cod', '!=', '');
        } elseif ($codState === 'no') {
            $q->where(function ($sub) {
                $sub->whereNull('cod')->orWhere('cod', '');
            });
        }

        // RTS reason has-value (any/yes/no)
        $rtsState = (string) $request->input('rts_state', 'any');
        if ($rtsState === 'yes') {
            $q->whereNotNull('rts_reason')->where('rts_reason', '!=', '');
        } elseif ($rtsState === 'no') {
            $q->where(function ($sub) {
                $sub->whereNull('rts_reason')->orWhere('rts_reason', '');
            });
        }

        return $q;
    }

    // =========================================================================
    // Distinct values per table
    // =========================================================================

    private function loadMacroDistinctValues(): array
    {
        return cache()->remember('macro_export.distinct.macro_output.v2', 900, function () {
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

    private function loadMacroAddressTriples(): array
    {
        return cache()->remember('macro_export.addr_triples.macro_output.v1', 900, function () {
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

    private function loadJntDistinctValues(string $table): array
    {
        $key = 'macro_export.distinct.' . $table . '.v1';
        return cache()->remember($key, 900, function () use ($table) {
            $distinctOf = function (string $col) use ($table) {
                return DB::table($table)
                    ->whereNotNull($col)->where($col, '!=', '')
                    ->distinct()->orderBy($col)
                    ->pluck($col)->all();
            };
            return [
                'statuses'  => $distinctOf('status'),
                'items'     => $distinctOf('item_name'),
                'senders'   => $distinctOf('sender'),
                'provinces' => $distinctOf('province'),
                'cities'    => $distinctOf('city'),
                'barangays' => $distinctOf('barangay'),
            ];
        });
    }

    private function loadJntAddressTriples(string $table): array
    {
        $key = 'macro_export.addr_triples.' . $table . '.v1';
        return cache()->remember($key, 900, function () use ($table) {
            return DB::table($table)
                ->select('province', 'city', 'barangay')
                ->whereNotNull('province')->where('province', '!=', '')
                ->whereNotNull('city')    ->where('city',     '!=', '')
                ->whereNotNull('barangay')->where('barangay', '!=', '')
                ->distinct()
                ->orderBy('province')->orderBy('city')->orderBy('barangay')
                ->get()
                ->map(fn($r) => [
                    (string) $r->province,
                    (string) $r->city,
                    (string) $r->barangay,
                ])
                ->all();
        });
    }

    // =========================================================================
    // Streaming writers (unchanged)
    // =========================================================================

    private function streamCsv($base, array $cols, string $selectExpr, string $filename): StreamedResponse
    {
        return new StreamedResponse(function () use ($base, $cols, $selectExpr) {
            $out = fopen('php://output', 'w');
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
}
