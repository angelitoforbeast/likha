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

    /**
     * ✅ IMPORTANT CHANGE:
     * - Run is ONLY loaded if run_id is explicitly provided
     * - NO auto-latest-run fallback
     */
    $run = null;
    if ($runId) {
        $run = JntBatchRun::query()->find((int) $runId);
    }

    // Base macro_output filter (used for preview + batch creation)
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

    /**
     * ✅ IF may run_id → show jnt_shipments
     * ✅ ELSE → show macro_output preview (FILTER WORKS HERE)
     */
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
            ->orderByDesc('m.id')
            ->paginate(50)
            ->withQueryString();
    } else {
        $rows = $macroBase
            ->select([
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

        $run = JntBatchRun::query()->create([
            'filters'      => json_encode(['date' => $date, 'page' => $page], JSON_UNESCAPED_UNICODE),
            'total'        => $total,
            'processed'    => 0,
            'success_count'=> 0,
            'fail_count'   => 0,
            'status'       => 'running',
            'started_at'   => now(),
            'finished_at'  => null,
            'created_by'   => auth()->id(),
        ]);

        // Create placeholder shipments then dispatch jobs (shipmentId is what the Job expects)
        foreach (array_chunk($macroIds, 500) as $chunk) {
            $toInsert = [];
            foreach ($chunk as $macroId) {
                $toInsert[] = [
                    'jnt_batch_run_id' => $run->id,
                    'macro_output_id'  => (int)$macroId,
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
                CreateJntOrder::dispatch((int)$sid);
            }
        }

        return redirect()
            ->to(url('/jnt/orders') . '?date=' . urlencode($date) . '&page=' . urlencode($page) . '&run_id=' . $run->id)
            ->with('success', "Batch created. Run #{$run->id} with {$total} shipments queued.");
    }

    public function showRun(int $runId)
    {
        return redirect()->to(url('/jnt/orders') . '?run_id=' . $runId);
    }

    public function status(int $runId)
    {
        $run = JntBatchRun::query()->findOrFail($runId);

        // Return latest updates (for live polling)
        $latest = JntShipment::query()
            ->where('jnt_batch_run_id', $runId)
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get(['id','macro_output_id','mailno','txlogisticid','success','reason','updated_at'])
            ->values();

        return response()->json([
            'run' => [
                'id' => $run->id,
                'status' => $run->status,
                'total' => (int)$run->total,
                'processed' => (int)$run->processed,
                'ok' => (int)$run->success_count,
                'fail' => (int)$run->fail_count,
                'started_at' => $run->started_at,
                'finished_at' => $run->finished_at,
            ],
            'latest' => $latest,
            'server_time' => now()->toDateTimeString(),
        ]);
    }
}
