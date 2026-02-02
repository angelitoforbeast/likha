<?php

namespace App\Http\Controllers;

use App\Jobs\CreateJntOrder;
use App\Models\JntBatchRun;
use App\Models\JntShipment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JntOrderUiController extends Controller
{
    public function index(Request $request)
    {
        $date = $request->input('date') ?: now('Asia/Manila')->toDateString();
        $page = trim((string) $request->input('page', ''));
        $runId = $request->input('run_id');

        // Pages dropdown
        $pages = DB::table('macro_output')
            ->whereNotNull('PAGE')
            ->where('PAGE', '!=', '')
            ->distinct()
            ->orderBy('PAGE')
            ->pluck('PAGE')
            ->values()
            ->all();

        // ✅ Run only if explicitly provided
        $run = null;
        if ($runId) {
            $run = JntBatchRun::query()->find((int) $runId);
        }

        // Base macro_output filter
        $macroBase = DB::table('macro_output')
            ->whereDate('ts_date', $date)
            ->whereNotNull('FULL NAME')->where('FULL NAME', '!=', '')
            ->whereNotNull('PHONE NUMBER')->where('PHONE NUMBER', '!=', '')
            ->whereNotNull('ADDRESS')->where('ADDRESS', '!=', '')
            ->whereNotNull('PROVINCE')->where('PROVINCE', '!=', '')
            ->whereNotNull('CITY')->where('CITY', '!=', '')
            ->whereNotNull('BARANGAY')->where('BARANGAY', '!=', '')
            ->whereNotNull('ITEM_NAME')->where('ITEM_NAME', '!=', '')
            ->whereNotNull('COD')->where('COD', '!=', '');

        if ($page !== '') {
            $macroBase->where('PAGE', $page);
        }

        // ✅ If run exists → show shipments
        if ($run) {
            $rows = DB::table('jnt_shipments as s')
                ->join('macro_output as m', 'm.id', '=', 's.macro_output_id')
                ->where('s.jnt_batch_run_id', $run->id)
                ->select([
                    's.id as shipment_id',
                    'm.id as macro_id',
                    'm.ts_date as ts_date',
                    'm.PAGE as page',
                    DB::raw('`m`.`FULL NAME` as full_name'),
                    DB::raw('`m`.`PHONE NUMBER` as phone_number'),
                    'm.ADDRESS as address',
                    'm.PROVINCE as province',
                    'm.CITY as city',
                    'm.BARANGAY as barangay',
                    'm.ITEM_NAME as item_name',
                    'm.COD as cod',
                    's.mailno as mailno',
                    's.txlogisticid as txlogisticid',
                    's.success as success',
                    's.reason as reason',
                ])
                ->orderByDesc('s.id')
                ->paginate(50)
                ->withQueryString();
        } else {
            // ✅ Preview mode: add NULL columns so blade won't crash
            $rows = $macroBase
                ->select([
                    DB::raw('NULL as shipment_id'),
                    'id as macro_id',
                    'ts_date',
                    'PAGE as page',
                    DB::raw('`FULL NAME` as full_name'),
                    DB::raw('`PHONE NUMBER` as phone_number'),
                    'ADDRESS as address',
                    'PROVINCE as province',
                    'CITY as city',
                    'BARANGAY as barangay',
                    'ITEM_NAME as item_name',
                    'COD as cod',
                    DB::raw('NULL as mailno'),
                    DB::raw('NULL as txlogisticid'),
                    DB::raw('NULL as success'),
                    DB::raw('NULL as reason'),
                ])
                ->orderByDesc('id')
                ->paginate(50)
                ->withQueryString();
        }

        return view('jnt.orders.index', compact(
            'date',
            'page',
            'pages',
            'run',
            'rows'
        ));
    }

    public function createBatch(Request $request)
    {
        $date = $request->input('date') ?: now('Asia/Manila')->toDateString();
        $page = trim((string) $request->input('page', ''));

        $macroQ = DB::table('macro_output')
            ->whereDate('ts_date', $date)
            ->whereNotNull('FULL NAME')->where('FULL NAME', '!=', '')
            ->whereNotNull('PHONE NUMBER')->where('PHONE NUMBER', '!=', '')
            ->whereNotNull('ADDRESS')->where('ADDRESS', '!=', '')
            ->whereNotNull('PROVINCE')->where('PROVINCE', '!=', '')
            ->whereNotNull('CITY')->where('CITY', '!=', '')
            ->whereNotNull('BARANGAY')->where('BARANGAY', '!=', '')
            ->whereNotNull('ITEM_NAME')->where('ITEM_NAME', '!=', '')
            ->whereNotNull('COD')->where('COD', '!=', '');

        if ($page !== '') {
            $macroQ->where('PAGE', $page);
        }

        $macroIds = $macroQ->pluck('id')->all();
        $total = count($macroIds);

        if ($total <= 0) {
            return redirect()->to(url('/jnt/orders') . '?date=' . urlencode($date) . '&page=' . urlencode($page))
                ->with('error', 'No rows found for the selected filters.');
        }

        $run = JntBatchRun::query()->create([
            'filters'       => json_encode(['date' => $date, 'page' => $page], JSON_UNESCAPED_UNICODE),
            'total'         => $total,
            'processed'     => 0,
            'success_count' => 0,
            'fail_count'    => 0,
            'status'        => 'running',
            'started_at'    => now(),
            'finished_at'   => null,
            'created_by'    => auth()->id(),
        ]);

        foreach (array_chunk($macroIds, 500) as $chunk) {
            $toInsert = [];
            foreach ($chunk as $macroId) {
                $toInsert[] = [
                    'jnt_batch_run_id' => $run->id,
                    'macro_output_id'  => (int) $macroId,
                    'success'          => 0,
                    'created_by'       => auth()->id(),
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ];
            }

            DB::table('jnt_shipments')->insert($toInsert);

            $shipmentIds = JntShipment::query()
                ->where('jnt_batch_run_id', $run->id)
                ->whereIn('macro_output_id', $chunk)
                ->pluck('id')
                ->all();

            foreach ($shipmentIds as $sid) {
                CreateJntOrder::dispatch((int) $sid);
            }
        }

        // ✅ IMPORTANT: route mo is POST jnt/orders/batch
        // Redirect to view run
        return redirect()
            ->to(url('/jnt/orders') . '?date=' . urlencode($date) . '&page=' . urlencode($page) . '&run_id=' . $run->id)
            ->with('success', "Batch created. Run #{$run->id} with {$total} shipments queued.");
    }

    public function showRun(int $runId)
    {
        return redirect()->to(url('/jnt/orders') . '?run_id=' . $runId);
    }


public function status(Request $request, int $runId)
{
    $run = JntBatchRun::query()->findOrFail($runId);

    // ids=4347,4346,... (only update visible rows)
    $idsRaw = trim((string) $request->query('ids', ''));
    $ids = collect(explode(',', $idsRaw))
        ->map(fn ($v) => (int) trim($v))
        ->filter(fn ($v) => $v > 0)
        ->values()
        ->all();

    // ✅ Progress computed from shipments
    $stats = JntShipment::query()
        ->where('jnt_batch_run_id', $runId)
        ->selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN mailno IS NOT NULL AND mailno != '' THEN 1 ELSE 0 END) as ok,
            SUM(CASE WHEN (reason IS NOT NULL AND reason != '') AND (mailno IS NULL OR mailno = '') THEN 1 ELSE 0 END) as fail,
            SUM(CASE WHEN ((mailno IS NULL OR mailno = '') AND (reason IS NULL OR reason = '')) THEN 1 ELSE 0 END) as pending
        ")
        ->first();

    $q = JntShipment::query()
        ->where('jnt_batch_run_id', $runId)
        ->select(['id', 'mailno', 'txlogisticid', 'success', 'reason', 'updated_at']);

    if (!empty($ids)) {
        $q->whereIn('id', $ids);
    } else {
        $q->orderByDesc('updated_at')->limit(200);
    }

    $shipments = $q->get()->values();

    // ✅ Auto-finish when pending==0
    $pending = (int) ($stats->pending ?? 0);
    if ($pending === 0 && (string)($run->status ?? '') === 'running') {
        $run->status = 'finished';
        $run->finished_at = now();
        $run->save();
    }

    return response()->json([
        'run' => [
            'id' => (int) $run->id,
            'status' => (string) ($run->status ?? 'running'),
        ],
        'stats' => [
            'total' => (int) ($stats->total ?? 0),
            'ok' => (int) ($stats->ok ?? 0),
            'fail' => (int) ($stats->fail ?? 0),
            'pending' => (int) ($stats->pending ?? 0),
            'processed' => (int) ($stats->ok ?? 0) + (int) ($stats->fail ?? 0),
        ],
        'shipments' => $shipments,
    ]);
}


    // ✅ since meron kang route: POST jnt/orders/batch/{runId}/stop
    public function stop(int $runId)
    {
        $run = JntBatchRun::query()->findOrFail($runId);
        $run->status = 'stopped';
        $run->save();

        return redirect()->to(url('/jnt/orders') . '?run_id=' . $runId)
            ->with('success', "Run #{$runId} stopped.");
    }

    public function debug(int $shipmentId)
{
    $s = JntShipment::query()->findOrFail($shipmentId);

    // ✅ These are the common fields we expect you saved per shipment.
    // If iba column names mo, palitan mo dito (pero eto usually same sa logs mo).
    return response()->json([
        'id' => (int) $s->id,

        // request side
        'data_digest' => (string) ($s->data_digest ?? ''),
        'logistics_interface' => (string) ($s->logistics_interface ?? ''), // exact JSON string sent
        'request_payload' => $s->request_payload ?? null, // array/json

        // response side
        'response_raw' => (string) ($s->response_raw ?? ''),
        'response_json' => $s->response_json ?? null, // array/json
    ]);
}
}
