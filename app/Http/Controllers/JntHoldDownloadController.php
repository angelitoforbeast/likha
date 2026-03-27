<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
     * Always filtered to STATUS = 'PROCEED' only.
     */
    private function holdBaseQuery(string $driver)
    {
        $mo = self::MO_TABLE . ' as mo';
        $fj = self::FJ_TABLE . ' as fj';

        return DB::table($mo)
            ->leftJoin($fj, 'fj.' . self::FJ_WAYBILL_COL, '=', 'mo.' . self::MO_WAYBILL_COL)
            ->whereNull('fj.' . self::FJ_WAYBILL_COL)
            ->whereRaw("NULLIF(TRIM(mo." . self::MO_WAYBILL_COL . "), '') IS NOT NULL")
            ->where('mo.STATUS', 'PROCEED');
    }

    /**
     * Apply common filters (date range via ts_date, page, search) to the query.
     */
    private function applyFilters($query, Request $request, string $driver): array
    {
        $likeOp = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';

        // Quoted column refs
        $moItemRef    = $driver === 'pgsql' ? 'mo."ITEM_NAME"' : 'mo.`ITEM_NAME`';
        $moWaybillRef = $driver === 'pgsql' ? 'mo."waybill"' : 'mo.`waybill`';
        $moPageRef    = $driver === 'pgsql' ? 'mo."PAGE"' : 'mo.`PAGE`';
        $moFullRef    = $driver === 'pgsql' ? 'mo."FULL NAME"' : 'mo.`FULL NAME`';

        // --- Date range filter using ts_date ---
        $rangeSta = null;
        $rangeEnd = null;

        $dateRange = trim((string) $request->input('date_range', ''));
        if ($dateRange !== '') {
            $parts = preg_split('/\s+to\s+/i', $dateRange);
            $rangeSta = trim($parts[0] ?? '');
            $rangeEnd = trim($parts[1] ?? $parts[0] ?? '');
        }

        // Also accept start/end params directly
        if (!$rangeSta) $rangeSta = $request->input('start');
        if (!$rangeEnd) $rangeEnd = $request->input('end');

        if ($rangeSta && $rangeEnd) {
            // Detect ts_date column type
            $tsType = null;
            try {
                $tsType = Schema::getColumnType('macro_output', 'ts_date');
            } catch (\Throwable $e) {
                $tsType = null;
            }

            if ($tsType === 'date') {
                $query->whereNotNull('mo.ts_date')
                      ->whereBetween('mo.ts_date', [$rangeSta, $rangeEnd]);
            } else {
                $startDt = Carbon::parse($rangeSta)->startOfDay()->toDateTimeString();
                $endDt   = Carbon::parse($rangeEnd)->endOfDay()->toDateTimeString();
                $query->whereNotNull('mo.ts_date')
                      ->whereBetween('mo.ts_date', [$startDt, $endDt]);
            }
        }

        // Page filter
        $page = trim((string) $request->input('PAGE', ''));
        if ($page !== '') {
            $query->where('mo.PAGE', $page);
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

        return [$rangeSta, $rangeEnd, $q, $page];
    }

    /**
     * Display the hold orders list page.
     */
    public function index(Request $request)
    {
        $driver = DB::getDriverName();
        $query  = $this->holdBaseQuery($driver);

        [$rangeSta, $rangeEnd, $q, $page] = $this->applyFilters($query, $request, $driver);

        // Total hold count
        $totalHolds = (clone $query)->count();

        // Pages dropdown (from the filtered hold set)
        $pages = (clone $query)
            ->select('mo.PAGE')
            ->whereNotNull('mo.PAGE')
            ->distinct()
            ->orderBy('mo.PAGE')
            ->pluck('PAGE');

        // Select columns for display
        $moFullRef  = $driver === 'pgsql' ? 'mo."FULL NAME"' : 'mo.`FULL NAME`';
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
                'mo.ts_date',
                'mo.ITEM_NAME as item_name',
                'mo.COD as cod',
                'mo.waybill as waybill',
            ])
            ->orderByDesc('mo.id')
            ->paginate(100)
            ->withQueryString();

        return view('jnt.hold-download', compact(
            'records', 'pages', 'totalHolds',
            'rangeSta', 'rangeEnd', 'q', 'page'
        ));
    }

    /**
     * Export hold orders as CSV.
     */
    public function export(Request $request)
    {
        $driver = DB::getDriverName();
        $query  = $this->holdBaseQuery($driver);

        [$rangeSta, $rangeEnd, $q, $page] = $this->applyFilters($query, $request, $driver);

        $moFullRef  = $driver === 'pgsql' ? 'mo."FULL NAME"' : 'mo.`FULL NAME`';
        $moPhoneRef = $driver === 'pgsql' ? 'mo."PHONE NUMBER"' : 'mo.`PHONE NUMBER`';

        $records = $query->select([
                DB::raw("$moFullRef as full_name"),
                DB::raw("$moPhoneRef as phone_number"),
                'mo.ADDRESS as address',
                'mo.PROVINCE as province',
                'mo.CITY as city',
                'mo.BARANGAY as barangay',
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
