<?php

namespace App\Http\Controllers;

use setasign\Fpdi\Fpdi;
use App\Services\Jnt\JntClient;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\JntWaybillPrintRun;
use App\Models\JntWaybillPrintRunItem;
use App\Jobs\BuildJntWaybillBulkPrint;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class JntWaybillPrintController extends Controller
{

    public function index(Request $request)
{
    $date = $request->input('date') ?: now('Asia/Manila')->subDay()->toDateString();

    $start = $date;
    $end   = Carbon::parse($date, 'Asia/Manila')->addDay()->toDateString();

    $filterBy = $request->input('filter_by', 'page');
    if (!in_array($filterBy, ['page','item','item_type'], true)) $filterBy = 'page';

    $filterValue = trim((string) $request->input('filter_value',''));

    $pages = DB::table('macro_output')
        ->where('ts_date', '>=', $start)->where('ts_date', '<', $end)
        ->whereNotNull('PAGE')->where('PAGE','!=','')
        ->distinct()->orderBy('PAGE')->pluck('PAGE')->values()->all();

    $items = DB::table('macro_output')
        ->where('ts_date', '>=', $start)->where('ts_date', '<', $end)
        ->whereNotNull('ITEM_NAME')->where('ITEM_NAME','!=','')
        ->distinct()->orderBy('ITEM_NAME')->pluck('ITEM_NAME')->values()->all();

    $itemTypes = DB::table('item_type_mappings')
        ->select('item_type')->distinct()->orderBy('item_type')
        ->whereIn('item_name', function ($q) use ($start, $end) {
            $q->select('ITEM_NAME')->from('macro_output')
              ->where('ts_date', '>=', $start)->where('ts_date', '<', $end)
              ->whereNotNull('ITEM_NAME')->where('ITEM_NAME', '!=', '');
        })
        ->pluck('item_type')->values()->all();

    $macroBase = DB::table('macro_output')
        ->where('ts_date', '>=', $start)->where('ts_date', '<', $end);

    if ($filterValue !== '') {
        if ($filterBy === 'page') $macroBase->where('PAGE', $filterValue);
        if ($filterBy === 'item') $macroBase->where('ITEM_NAME', $filterValue);
        if ($filterBy === 'item_type') {
            $itemNamesForType = DB::table('item_type_mappings')
                ->where('item_type', $filterValue)->pluck('item_name');
            $macroBase->whereIn('ITEM_NAME', $itemNamesForType);
        }
    }

    $macroIdsSub = (clone $macroBase)->select('id');

    $latestShipments = DB::table('jnt_shipments')
        ->selectRaw('macro_output_id, MAX(id) as shipment_id')
        ->whereIn('macro_output_id', $macroIdsSub)
        ->whereNotNull('mailno')->where('mailno','!=','')
        ->groupBy('macro_output_id');

    $q = DB::table('macro_output as m')
        ->leftJoinSub($latestShipments, 'ls', fn($join) => $join->on('ls.macro_output_id','=','m.id'))
        ->leftJoin('jnt_shipments as s', 's.id','=','ls.shipment_id')
        ->where('m.ts_date','>=',$start)->where('m.ts_date','<',$end)
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
        if ($filterBy === 'page') $q->where('m.PAGE', $filterValue);
        if ($filterBy === 'item') $q->where('m.ITEM_NAME', $filterValue);
        if ($filterBy === 'item_type') {
            $itemNamesForType = DB::table('item_type_mappings')
                ->where('item_type', $filterValue)->pluck('item_name');
            $q->whereIn('m.ITEM_NAME', $itemNamesForType);
        }
    }

    $rows = $q->orderByDesc('m.id')->simplePaginate(50)->withQueryString();

    return view('jnt.waybills.print', [
        'date'        => $date,
        'filterBy'    => $filterBy,
        'filterValue' => $filterValue,
        'pages'       => $pages,
        'items'       => $items,
        'itemTypes'   => $itemTypes,
        'rows'        => $rows,
    ]);
}

    public function printBulk(Request $request)
{
    $mode = $request->input('mode', 'selected'); // selected|all
    if (!in_array($mode, ['selected','all'], true)) $mode = 'selected';

    $date = $request->input('date') ?: now('Asia/Manila')->subDay()->toDateString();

    $filterBy = $request->input('filter_by', 'page');
    if (!in_array($filterBy, ['page','item','item_type'], true)) $filterBy = 'page';

    $filterValue = trim((string) $request->input('filter_value', ''));

    $run = JntWaybillPrintRun::query()->create([
        'created_by'   => auth()->id(),
        'date'         => $date,
        'mode'         => $mode,
        'filter_by'    => $filterBy,
        'filter_value' => $filterValue,
        'status'       => 'queued',
        'message'      => 'Queued...',
        'total'        => 0,
        'processed'    => 0,
        'ok_count'     => 0,
        'fail_count'   => 0,
    ]);

    // If selected mode, store selected mailnos immediately (fast)
    if ($mode === 'selected') {
        $mailnos = collect($request->input('mailnos', []))
            ->map(fn($v) => trim((string)$v))
            ->filter(fn($v) => $v !== '')
            ->unique()
            ->values()
            ->all();

        if (count($mailnos) <= 0) {
            return back()->with('error', 'No mailno selected.');
        }

        // Page-aware account selection happens at print time inside the job
        // (BuildJntWaybillBulkPrint::handle resolves page per mailno via
        // jnt_shipments → macro_output JOIN). No need to snapshot here.
        $seq = 1;
        foreach (array_chunk($mailnos, 1000) as $chunk) {
            $toInsert = [];
            foreach ($chunk as $m) {
                $toInsert[] = [
                    'run_id' => $run->id,
                    'macro_output_id' => null,
                    'mailno' => $m,
                    'seq' => $seq++,
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            DB::table('jnt_waybill_print_run_items')->insertOrIgnore($toInsert);
        }

        $run->total = (int) JntWaybillPrintRunItem::query()->where('run_id',$run->id)->count();
        $run->save();
    }

    BuildJntWaybillBulkPrint::dispatch((int)$run->id);

    return redirect()->to(url("/jnt/waybills/print-bulk/run/{$run->id}"));
}

public function runPage(int $runId)
{
    $run = JntWaybillPrintRun::query()->findOrFail($runId);
    return view('jnt.waybills.print_run', compact('run'));
}

public function statusRun(int $runId)
{
    $run = JntWaybillPrintRun::query()->findOrFail($runId);

    $fails = JntWaybillPrintRunItem::query()
        ->where('run_id', $runId)
        ->where('status', 'fail')
        ->orderByDesc('updated_at')
        ->limit(10)
        ->pluck('error')
        ->values();

    return response()->json([
        'id' => (int)$run->id,
        'status' => (string)$run->status,
        'message' => (string)($run->message ?? ''),
        'total' => (int)$run->total,
        'processed' => (int)$run->processed,
        'ok' => (int)$run->ok_count,
        'fail' => (int)$run->fail_count,
        'output_type' => $run->output_type,
        'output_path' => $run->output_path,
        'recent_fails' => $fails,
    ]);
}

public function download(int $runId)
{
    $run = JntWaybillPrintRun::findOrFail($runId);

    // only allow download when finished
    abort_unless((string)$run->status === 'finished', 409, 'Run not finished yet.');

    $rel = (string)($run->output_path ?? '');
    abort_if($rel === '', 410, 'Output already cleaned or missing.');

    $disk = Storage::disk('local');
    abort_unless($disk->exists($rel), 404, "Output file does not exist.");

    $abs = $disk->path($rel);

    // ✅ Filename: FEB_09_2026_Alexa_Angeles_021026_2344.pdf/zip
    $filterDate = $run->date
        ? Carbon::parse($run->date, 'Asia/Manila')
        : now('Asia/Manila')->subDay();

    $filterDatePart = strtoupper($filterDate->format('M')) . '_' . $filterDate->format('d') . '_' . $filterDate->format('Y'); // FEB_09_2026

    $rawName = trim((string)($run->filter_value ?? ''));
    if ($rawName === '') {
        $rawName = ((string)($run->filter_by ?? 'page') === 'item') ? 'ALL_ITEMS' : 'ALL_PAGES';
    }

    // sanitize: keep alnum, convert others to underscore
    $namePart = (string) Str::of($rawName)
        ->replaceMatches('/[^A-Za-z0-9]+/', '_')
        ->replaceMatches('/_+/', '_')
        ->trim('_');

    // cap length for filesystem safety
    $namePart = Str::limit($namePart, 60, '');

    $downloadPart = now('Asia/Manila')->format('mdy_Hi'); // 021026_2344

    $ext = ((string)$run->output_type === 'zip') ? 'zip' : 'pdf';

    $filename = "{$filterDatePart}_{$namePart}_{$downloadPart}.{$ext}";

    // NOTE: Hindi na auto-deleted ang file pagkatapos i-download — nananatili ito
    // sa /jnt/waybills/files (para makita ang timing/metrics at ma-redownload).
    // Manual delete (per-file o "Delete All 1 day+") na lang ang paglilinis.
    return response()->download($abs, $filename, [
        'Cache-Control' => 'no-store, no-cache',
    ]);
}


public function cancel(int $runId)
{
    $run = JntWaybillPrintRun::query()->findOrFail($runId);
    if (!in_array((string)$run->status, ['finished','failed'], true)) {
        $run->status = 'cancelled';
        $run->message = 'Cancelled by user.';
        $run->finished_at = now();
        $run->save();
    }
    return back()->with('success', "Run #{$runId} cancelled.");
}



}
