<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class JntHoldDownloadController extends Controller
{
    private const MO_TABLE       = 'macro_output';
    private const FJ_TABLE       = 'from_jnts';
    private const MO_WAYBILL_COL = 'waybill';
    private const FJ_WAYBILL_COL = 'waybill_number';

    /**
     * Build the base HOLD query: macro_output rows that have a waybill
     * but no matching record in from_jnts.
     */
    private function holdBaseQuery(string $driver)
    {
        $mo = self::MO_TABLE . ' as mo';
        $fj = self::FJ_TABLE . ' as fj';

        return DB::table($mo)
            ->leftJoin($fj, 'fj.' . self::FJ_WAYBILL_COL, '=', 'mo.' . self::MO_WAYBILL_COL)
            ->whereNull('fj.' . self::FJ_WAYBILL_COL)
            ->whereRaw("NULLIF(TRIM(mo." . self::MO_WAYBILL_COL . "), '') IS NOT NULL");
    }

    /**
     * Parse TIMESTAMP column to a proper datetime expression.
     */
    private function tsExpr(string $driver): string
    {
        $moTsCol = $driver === 'pgsql' ? 'mo."TIMESTAMP"' : 'mo.`TIMESTAMP`';
        return $driver === 'pgsql'
            ? "to_timestamp($moTsCol, 'HH24:MI DD-MM-YYYY')"
            : "STR_TO_DATE($moTsCol, '%H:%i %d-%m-%Y')";
    }

    /**
     * Apply common filters (date range, page, search) to the query.
     */
    private function applyFilters($query, Request $request, string $driver): array
    {
        $tsExpr = $this->tsExpr($driver);
        $likeOp = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';

        // Quoted column refs
        $moItemRef    = $driver === 'pgsql' ? 'mo."ITEM_NAME"' : 'mo.`ITEM_NAME`';
        $moWaybillRef = $driver === 'pgsql' ? 'mo."waybill"' : 'mo.`waybill`';
        $moPageRef    = $driver === 'pgsql' ? 'mo."PAGE"' : 'mo.`PAGE`';
        $moFullRef    = $driver === 'pgsql' ? 'mo."FULL NAME"' : 'mo.`FULL NAME`';

        $startAt = $endAt = null;
        $rangeSta = $request->input('start');
        $rangeEnd = $request->input('end');

        $dateRange = trim((string) $request->input('date_range', ''));
        if ($dateRange !== '') {
            $parts = preg_split('/\s+(?:to|-)\s+/i', $dateRange);
            $rangeSta = $parts[0] ?? null;
            $rangeEnd = $parts[1] ?? $parts[0] ?? null;
        }

        if ($rangeSta && $rangeEnd) {
            $startAt = Carbon::createFromFormat('Y-m-d', $rangeSta)->startOfDay()->format('Y-m-d H:i:s');
            $endAt   = Carbon::createFromFormat('Y-m-d', $rangeEnd)->endOfDay()->format('Y-m-d H:i:s');
            $query->whereBetween(DB::raw($tsExpr), [$startAt, $endAt]);
        } elseif ($rangeSta) {
            $startAt = Carbon::createFromFormat('Y-m-d', $rangeSta)->startOfDay()->format('Y-m-d H:i:s');
            $endAt   = Carbon::createFromFormat('Y-m-d', $rangeSta)->endOfDay()->format('Y-m-d H:i:s');
            $query->whereBetween(DB::raw($tsExpr), [$startAt, $endAt]);
        }

        // Page filter
        $page = $request->input('PAGE', '');
        if ($page !== '') {
            $query->where('mo.PAGE', $page);
        }

        // Status filter
        $status = $request->input('status', '');
        if ($status !== '') {
            if ($status === 'BLANK') {
                $query->where(function ($q) {
                    $q->whereNull('mo.STATUS')->orWhere('mo.STATUS', '');
                });
            } else {
                $query->where('mo.STATUS', $status);
            }
        }

        // Search
        $q = trim((string) $request->input('q', ''));
        if ($q !== '') {
            $query->where(function ($w) use ($q, $likeOp, $moItemRef, $moWaybillRef, $moPageRef, $moFullRef) {
                $w->whereRaw("$moItemRef $likeOp ?", ["%{$q}%"])
                  ->orWhereRaw("$moWaybillRef $likeOp ?", ["%{$q}%"])
                  ->orWhereRaw("$moPageRef $likeOp ?", ["%{$q}%"])
                  ->orWhereRaw("$moFullRef $likeOp ?", ["%{$q}%"]);
            });
        }

        return [$rangeSta, $rangeEnd, $q, $page, $status];
    }

    /**
     * Display the hold orders list page.
     */
    public function index(Request $request)
    {
        $driver = DB::getDriverName();
        $query  = $this->holdBaseQuery($driver);

        [$rangeSta, $rangeEnd, $q, $page, $status] = $this->applyFilters($query, $request, $driver);

        // Get total hold count (before pagination)
        $totalHolds = (clone $query)->count();

        // Status counts
        $wrap = fn (string $col) => DB::getQueryGrammar()->wrap($col);
        $STATUS = 'mo.STATUS';

        $sc = (clone $query)->selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN {$STATUS} = 'PROCEED' THEN 1 ELSE 0 END) as proceed,
            SUM(CASE WHEN {$STATUS} = 'CANNOT PROCEED' THEN 1 ELSE 0 END) as cannot_proceed,
            SUM(CASE WHEN {$STATUS} = 'ODZ' THEN 1 ELSE 0 END) as odz,
            SUM(CASE WHEN {$STATUS} IS NULL OR {$STATUS} = '' THEN 1 ELSE 0 END) as blank
        ")->first();

        $statusCounts = [
            'TOTAL'          => (int) ($sc->total ?? 0),
            'PROCEED'        => (int) ($sc->proceed ?? 0),
            'CANNOT PROCEED' => (int) ($sc->cannot_proceed ?? 0),
            'ODZ'            => (int) ($sc->odz ?? 0),
            'BLANK'          => (int) ($sc->blank ?? 0),
        ];

        // Pages dropdown
        $pages = (clone $query)
            ->select('mo.PAGE')
            ->whereNotNull('mo.PAGE')
            ->distinct()
            ->orderBy('mo.PAGE')
            ->pluck('PAGE');

        // Select columns for display
        $moFullRef = $driver === 'pgsql' ? 'mo."FULL NAME"' : 'mo.`FULL NAME`';
        $moPhoneRef = $driver === 'pgsql' ? 'mo."PHONE NUMBER"' : 'mo.`PHONE NUMBER`';

        $records = $query->select([
                'mo.id',
                DB::raw("$moFullRef as full_name"),
                DB::raw("$moPhoneRef as phone_number"),
                'mo.ADDRESS as address',
                'mo.PROVINCE as province',
                'mo.CITY as city',
                'mo.BARANGAY as barangay',
                'mo.STATUS as status',
                'mo.PAGE as page',
                'mo.TIMESTAMP as timestamp',
                'mo.ITEM_NAME as item_name',
                'mo.COD as cod',
                'mo.waybill as waybill',
            ])
            ->orderByDesc('mo.id')
            ->paginate(100)
            ->withQueryString();

        return view('jnt.hold-download', compact(
            'records', 'pages', 'totalHolds', 'statusCounts',
            'rangeSta', 'rangeEnd', 'q', 'page', 'status'
        ));
    }

    /**
     * Export hold orders as CSV.
     */
    public function export(Request $request)
    {
        $driver = DB::getDriverName();
        $query  = $this->holdBaseQuery($driver);

        [$rangeSta, $rangeEnd, $q, $page, $status] = $this->applyFilters($query, $request, $driver);

        $moFullRef  = $driver === 'pgsql' ? 'mo."FULL NAME"' : 'mo.`FULL NAME`';
        $moPhoneRef = $driver === 'pgsql' ? 'mo."PHONE NUMBER"' : 'mo.`PHONE NUMBER`';

        $records = $query->select([
                DB::raw("$moFullRef as full_name"),
                DB::raw("$moPhoneRef as phone_number"),
                'mo.ADDRESS as address',
                'mo.PROVINCE as province',
                'mo.CITY as city',
                'mo.BARANGAY as barangay',
                'mo.STATUS as status',
                'mo.PAGE as page',
                'mo.TIMESTAMP as timestamp',
                'mo.ITEM_NAME as item_name',
                'mo.COD as cod',
                'mo.waybill as waybill',
                'mo.fb_name as fb_name',
            ])
            ->orderByDesc('mo.id')
            ->get();

        if ($records->isEmpty()) {
            return back()->with('error', 'No HOLD orders found for the selected filters.');
        }

        // Build CSV with same template as encoder/checker_1
        $handle = fopen('php://temp', 'w+');

        // Load template header rows
        $templatePath = resource_path('templates/exptemplete.xls');
        if (file_exists($templatePath)) {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($templatePath);
            $sheet = $spreadsheet->getActiveSheet();
            $templateData = $sheet->rangeToArray('A1:N8', null, true, true, false);
            foreach ($templateData as $row) {
                fputcsv($handle, $row);
            }
        } else {
            // Fallback header
            fputcsv($handle, [
                'FULL NAME', 'PHONE NUMBER', 'ADDRESS', 'PROVINCE', 'CITY', 'BARANGAY',
                'SERVICE', 'ITEM NAME', 'WEIGHT', 'CATEGORY', 'VALUE', 'COD', 'REMARKS', 'FB NAME'
            ]);
        }

        $UP = function ($v) {
            $s = is_null($v) ? '' : (string) $v;
            $s = trim($s);
            return $s === '' ? '' : mb_strtoupper($s, 'UTF-8');
        };

        foreach ($records as $row) {
            $fullName = $UP($row->full_name ?? '');
            $address  = $UP($row->address ?? '');
            $prov     = $UP($row->province ?? '');
            $city     = $UP($row->city ?? '');
            $brgy     = $UP($row->barangay ?? '');
            $fbName   = $UP($row->fb_name ?? '');
            $phone    = trim((string) ($row->phone_number ?? ''));
            $cod      = trim((string) ($row->cod ?? ''));
            $colH     = $UP($row->item_name ?? '');
            $colJ     = $colH ? strtok($colH, ' ') : '';

            fputcsv($handle, [
                $fullName,   // A
                $phone,      // B
                $address,    // C
                $prov,       // D
                $city,       // E
                $brgy,       // F
                'EZ',        // G
                $colH,       // H
                '0.5',       // I
                $colJ,       // J
                '549',       // K
                $cod,        // L
                $colH,       // M
                $fbName,     // N
            ]);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        $datePart = $rangeSta ?? now()->format('Y-m-d');
        $timePart = now()->format('H-i-s');
        $pagePart = $page !== '' ? preg_replace('/[^a-zA-Z0-9_]/', '_', $page) : 'AllPages';
        $filename = "HOLD_{$pagePart}_{$datePart}_{$timePart}.csv";

        return response($content, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename={$filename}",
        ]);
    }
}
