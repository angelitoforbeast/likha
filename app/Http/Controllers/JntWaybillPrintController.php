<?php

namespace App\Http\Controllers;

use setasign\Fpdi\Fpdi;
use App\Services\Jnt\JntClient;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JntWaybillPrintController extends Controller
{
    public function index(Request $request)
    {
        // ✅ default: yesterday (Asia/Manila)
        $date = $request->input('date')
            ?: now('Asia/Manila')->subDay()->toDateString();

        // ✅ index-friendly range filter
        $start = $date; // 'YYYY-MM-DD'
        $end   = Carbon::parse($date, 'Asia/Manila')->addDay()->toDateString();

        // filter_by: page | item
        $filterBy = $request->input('filter_by', 'page');
        if (!in_array($filterBy, ['page', 'item'], true)) {
            $filterBy = 'page';
        }

        $filterValue = trim((string) $request->input('filter_value', ''));

        /**
         * Base macro filter (date range + optional page/item)
         * NOTE: we will reuse this as subquery for macro IDs
         */
        $macroBase = DB::table('macro_output')
            ->where('ts_date', '>=', $start)
            ->where('ts_date', '<', $end);

        if ($filterValue !== '') {
            if ($filterBy === 'page') {
                $macroBase->where('PAGE', $filterValue);
            } else {
                $macroBase->where('ITEM_NAME', $filterValue);
            }
        }

        // ✅ Macro IDs for this date+filter ONLY (used to limit shipments scan)
        $macroIdsSub = (clone $macroBase)->select('id');

        // ✅ Dropdown options depend on date only (fast if indexed)
        $pages = DB::table('macro_output')
            ->where('ts_date', '>=', $start)
            ->where('ts_date', '<', $end)
            ->whereNotNull('PAGE')->where('PAGE', '!=', '')
            ->distinct()
            ->orderBy('PAGE')
            ->pluck('PAGE')
            ->values()
            ->all();

        $items = DB::table('macro_output')
            ->where('ts_date', '>=', $start)
            ->where('ts_date', '<', $end)
            ->whereNotNull('ITEM_NAME')->where('ITEM_NAME', '!=', '')
            ->distinct()
            ->orderBy('ITEM_NAME')
            ->pluck('ITEM_NAME')
            ->values()
            ->all();

        /**
         * Latest shipment WITH mailno per macro_output_id,
         * but only for macro IDs in this date+filter.
         */
        $latestShipments = DB::table('jnt_shipments')
            ->selectRaw('macro_output_id, MAX(id) as shipment_id')
            ->whereIn('macro_output_id', $macroIdsSub)
            ->whereNotNull('mailno')->where('mailno', '!=', '')
            ->groupBy('macro_output_id');

        // ✅ Main rows query (macro_output + latest mailno)
        $q = DB::table('macro_output as m')
            ->leftJoinSub($latestShipments, 'ls', function ($join) {
                $join->on('ls.macro_output_id', '=', 'm.id');
            })
            ->leftJoin('jnt_shipments as s', 's.id', '=', 'ls.shipment_id')
            ->where('m.ts_date', '>=', $start)
            ->where('m.ts_date', '<', $end)
            ->select([
                'm.id as macro_output_id',
                'm.ts_date as ts_date',
                'm.PAGE as page',
                DB::raw('`m`.`FULL NAME` as fb_name'),
                'm.ITEM_NAME as item_name',
                'm.COD as cod',
                'm.ADDRESS as address',
                'm.PROVINCE as province',
                'm.CITY as city',
                'm.BARANGAY as barangay',
                's.mailno as mailno',
            ]);

        if ($filterValue !== '') {
            if ($filterBy === 'page') {
                $q->where('m.PAGE', $filterValue);
            } else {
                $q->where('m.ITEM_NAME', $filterValue);
            }
        }

        // ✅ IMPORTANT: simplePaginate = no COUNT(*)
        $rows = $q->orderByDesc('m.id')
            ->simplePaginate(50)
            ->withQueryString();

        return view('jnt.waybills.print', compact(
            'date',
            'filterBy',
            'filterValue',
            'pages',
            'items',
            'rows'
        ));
    }

    public function printOne(Request $request)
    {
        $request->validate([
            'mailno' => 'required|string',
        ]);

        $mailno = trim((string) $request->mailno);
        if ($mailno === '') {
            return back()->with('error', 'Empty mailno.');
        }

        $client = JntClient::fromConfig();
        $res = $client->printWaybill($mailno);

        $b64 = data_get($res, 'responseitems.base64Url')
            ?? data_get($res, 'responseitems.0.base64Url');

        if (!$b64) {
            return back()->with('error', "No base64Url returned for mailno {$mailno}.");
        }

        $pdfBytes = base64_decode(preg_replace('/\s+/', '', (string) $b64), true);
        if ($pdfBytes === false) {
            return back()->with('error', "Invalid base64 PDF returned for mailno {$mailno}.");
        }

        $filename = 'waybill-' . $mailno . '.pdf';

        return response($pdfBytes, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    public function printBulk(Request $request)
{
    set_time_limit(0);

    $mode = $request->input('mode', 'selected'); // selected | all

    // ✅ default: yesterday
    $date = $request->input('date')
        ?: now('Asia/Manila')->subDay()->toDateString();

    $filterBy = $request->input('filter_by', 'page');
    if (!in_array($filterBy, ['page', 'item'], true)) $filterBy = 'page';

    $filterValue = trim((string) $request->input('filter_value', ''));

    // ✅ Collect mailnos
    $mailnos = [];

    if ($mode === 'all') {
        // index-friendly range
        $start = $date;
        $end   = Carbon::parse($date, 'Asia/Manila')->addDay()->toDateString();

        $macroBase = DB::table('macro_output')
            ->where('ts_date', '>=', $start)
            ->where('ts_date', '<', $end);

        if ($filterValue !== '') {
            if ($filterBy === 'page') {
                $macroBase->where('PAGE', $filterValue);
            } else {
                $macroBase->where('ITEM_NAME', $filterValue);
            }
        }

        $macroIdsSub = (clone $macroBase)->select('id');

        $latestShipments = DB::table('jnt_shipments')
            ->selectRaw('macro_output_id, MAX(id) as shipment_id')
            ->whereIn('macro_output_id', $macroIdsSub)
            ->whereNotNull('mailno')->where('mailno', '!=', '')
            ->groupBy('macro_output_id');

        $q = DB::table('macro_output as m')
            ->leftJoinSub($latestShipments, 'ls', fn($join) => $join->on('ls.macro_output_id', '=', 'm.id'))
            ->leftJoin('jnt_shipments as s', 's.id', '=', 'ls.shipment_id')
            ->where('m.ts_date', '>=', $start)
            ->where('m.ts_date', '<', $end)
            ->whereNotNull('s.mailno')->where('s.mailno', '!=', '')
            ->select(['s.mailno'])
            ->orderByDesc('m.id');

        // ✅ Safety cap (adjust if you want)
        $q->limit(500);

        $mailnos = $q->pluck('mailno')->filter()->values()->all();
    } else {
        // selected mode
        $mailnos = $request->input('mailnos', []);
        $mailnos = collect($mailnos)
            ->map(fn($v) => trim((string) $v))
            ->filter(fn($v) => $v !== '')
            ->unique()
            ->values()
            ->all();
    }

    if (count($mailnos) <= 0) {
        return back()->with('error', 'No mailno selected / found.');
    }

    $client = JntClient::fromConfig();

    $pdf = new Fpdi();
    $pdf->SetAutoPageBreak(false);

    $failed = [];
    $okCount = 0;

    foreach ($mailnos as $mailno) {
        try {
            $res = $client->printWaybill($mailno);

            $b64 = data_get($res, 'responseitems.base64Url')
                ?? data_get($res, 'responseitems.0.base64Url');

            if (!$b64) {
                $failed[] = "{$mailno} | no base64Url";
                continue;
            }

            $bytes = base64_decode(preg_replace('/\s+/', '', (string) $b64), true);
            if ($bytes === false) {
                $failed[] = "{$mailno} | invalid base64";
                continue;
            }

            // FPDI needs a file
            $tmp = tempnam(sys_get_temp_dir(), 'wb_') . '.pdf';
            file_put_contents($tmp, $bytes);

            $pageCount = $pdf->setSourceFile($tmp);
            for ($p = 1; $p <= $pageCount; $p++) {
                $tpl = $pdf->importPage($p);
                $size = $pdf->getTemplateSize($tpl);

                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($tpl);
            }

            @unlink($tmp);
            $okCount++;
        } catch (\Throwable $e) {
            $failed[] = "{$mailno} | " . $e->getMessage();
            continue;
        }
    }

    if ($okCount <= 0) {
        return back()->with('error', 'No waybills were generated. (All failed)');
    }

    // ✅ If may failed, add last page with failed list
    if (!empty($failed)) {
        $pdf->AddPage('P', 'A4');
        $pdf->SetFont('Arial', '', 10);
        $pdf->MultiCell(0, 5, "FAILED WAYBILLS:\n\n" . implode("\n", $failed));
    }

    $out = $pdf->Output('S');
    $filename = 'waybills-bulk-' . $date . '-' . now('Asia/Manila')->format('His') . '.pdf';

    return response($out, 200, [
        'Content-Type'        => 'application/pdf',
        'Content-Disposition' => 'inline; filename="'.$filename.'"',
    ]);
}

}
