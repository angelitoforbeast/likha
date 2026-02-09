<?php

namespace App\Http\Controllers;

use App\Jobs\CreateJntOrder;
use App\Models\JntBatchRun;
use App\Models\JntShipment;
use App\Services\Jnt\JntClient; // ✅ FIX: correct namespace
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JntOrderUiController extends Controller
{
    public function index(Request $request)
{
    $runId = $request->input('run_id');

    // ✅ Run only if explicitly provided
    $run = $runId ? JntBatchRun::query()->find((int)$runId) : null;

    // ✅ Base date/page from request
    $date = $request->input('date') ?: now('Asia/Manila')->toDateString();
    $page = trim((string) $request->input('page', ''));

    // ✅ If opened via ?run_id=123 only, use run->filters for date/page (controller-side)
    if ($run && !$request->filled('date') && !$request->filled('page')) {
        $filters = json_decode((string) $run->filters, true) ?: [];
        $date = $filters['date'] ?? $date;
        $page = $filters['page'] ?? $page;
    }

    // ✅ Date range (index-friendly for DATETIME ts_date)
    $dayStart = \Carbon\Carbon::parse($date, 'Asia/Manila')->startOfDay();
    $dayEnd   = \Carbon\Carbon::parse($date, 'Asia/Manila')->endOfDay();

    // ✅ Eligibility base (same as preview requirements)
    $eligibilityBase = DB::table('macro_output')
        ->whereBetween('ts_date', [$dayStart, $dayEnd])
        ->whereNotNull('PAGE')->where('PAGE', '!=', '')
        ->whereNotNull('FULL NAME')->where('FULL NAME', '!=', '')
        ->whereNotNull('PHONE NUMBER')->where('PHONE NUMBER', '!=', '')
        ->whereNotNull('ADDRESS')->where('ADDRESS', '!=', '')
        ->whereNotNull('PROVINCE')->where('PROVINCE', '!=', '')
        ->whereNotNull('CITY')->where('CITY', '!=', '')
        ->whereNotNull('BARANGAY')->where('BARANGAY', '!=', '')
        ->whereNotNull('ITEM_NAME')->where('ITEM_NAME', '!=', '')
        ->whereNotNull('COD')->where('COD', '!=', '');

    // ✅ Pages dropdown = only pages available on selected date (and eligible rows)
    $pages = \Cache::remember("jnt_orders_pages_" . $date, 60, function () use ($eligibilityBase) {
        $q = clone $eligibilityBase;

        return $q->select('PAGE')
            ->distinct()
            ->orderBy('PAGE')
            ->pluck('PAGE')
            ->values()
            ->all();
    });

    // Base macro_output filter for preview rows
    $macroBase = clone $eligibilityBase;

    if ($page !== '') {
        $macroBase->where('PAGE', $page);
    }

    if ($run) {
        // ✅ Run view: show shipments
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
            ->simplePaginate(50) // ✅ faster than paginate()
            ->withQueryString();

        // ✅ Run stats: use counters from run (NO heavy COUNT query)
        $runStats = (object) [
            'total'   => (int) ($run->total ?? 0),
            'ok'      => (int) ($run->success_count ?? 0),
            'fail'    => (int) ($run->fail_count ?? 0),
            'pending' => max(0, (int) ($run->total ?? 0) - (int) ($run->processed ?? 0)),
        ];
    } else {
        // ✅ PREVIEW
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
            ->simplePaginate(50) // ✅ faster
            ->withQueryString();

        // Attach latest shipment per macro_id (to show mailno/tx/reason in preview)
        $macroIdsOnPage = collect($rows->items())
            ->pluck('macro_id')->filter()->unique()->values()->all();

        if (!empty($macroIdsOnPage)) {
            $latestIds = DB::table('jnt_shipments')
                ->whereIn('macro_output_id', $macroIdsOnPage)
                ->selectRaw('MAX(id) as id')
                ->groupBy('macro_output_id');

            $latestShipments = DB::table('jnt_shipments as s')
                ->joinSub($latestIds, 'mx', 'mx.id', '=', 's.id')
                ->select(['s.id as shipment_id','s.macro_output_id','s.mailno','s.txlogisticid','s.success','s.reason'])
                ->get()
                ->keyBy('macro_output_id');

            $rows->setCollection(
                $rows->getCollection()->map(function ($r) use ($latestShipments) {
                    $mid = data_get($r, 'macro_id');
                    $s = $latestShipments->get($mid);
                    if (!$s) return $r;

                    $r->shipment_id  = $s->shipment_id;
                    $r->mailno       = $s->mailno;
                    $r->txlogisticid = $s->txlogisticid;
                    $r->success      = $s->success;
                    $r->reason       = $s->reason;

                    return $r;
                })
            );
        }

        $runStats = null;
    }

    return view('jnt.orders.index', compact('date','page','pages','run','rows','runStats'));
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

    $idsRaw = trim((string) $request->query('ids', ''));
    $ids = collect(explode(',', $idsRaw))
        ->map(fn ($v) => (int) trim($v))
        ->filter(fn ($v) => $v > 0)
        ->values()
        ->all();

    // ✅ Use counters from run (fast)
    $total     = (int) ($run->total ?? 0);
    $processed = (int) ($run->processed ?? 0);
    $ok        = (int) ($run->success_count ?? 0);
    $fail      = (int) ($run->fail_count ?? 0);

    // Fallback safety: if processed not maintained
    if ($processed <= 0 && ($ok + $fail) > 0) {
        $processed = $ok + $fail;
    }

    $pending = max(0, $total - $processed);

    // ✅ Auto-finish when done
    if ($pending === 0 && (string) ($run->status ?? '') === 'running') {
        $run->status = 'finished';
        $run->finished_at = now();
        $run->save();
    }

    // ✅ Shipments payload (keep, but light)
    $q = JntShipment::query()
        ->where('jnt_batch_run_id', $runId)
        ->select(['id', 'mailno', 'txlogisticid', 'success', 'reason', 'updated_at']);

    if (!empty($ids)) {
        $q->whereIn('id', $ids);
    } else {
        $q->orderByDesc('updated_at')->limit(200);
    }

    $shipments = $q->get()->values();

    return response()->json([
        'status'    => (string) ($run->status ?? 'running'),
        'total'     => $total,
        'ok'        => $ok,
        'fail'      => $fail,
        'pending'   => $pending,
        'processed' => $processed,

        'run' => [
            'id'     => (int) $run->id,
            'status' => (string) ($run->status ?? 'running'),
        ],
        'stats' => [
            'total'     => $total,
            'ok'        => $ok,
            'fail'      => $fail,
            'pending'   => $pending,
            'processed' => $processed,
        ],
        'shipments' => $shipments,
    ]);
}


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

        return response()->json([
            'id' => (int) $s->id,
            'data_digest' => (string) ($s->data_digest ?? ''),
            'logistics_interface' => (string) ($s->logistics_interface ?? ''),
            'request_payload' => $s->request_payload ?? null,
            'response_raw' => (string) ($s->response_raw ?? ''),
            'response_json' => $s->response_json ?? null,
        ]);
    }

    public function printOne(Request $request)
    {
        $request->validate([
            'shipment_id' => 'required|integer',
        ]);

        $shipment = JntShipment::query()->findOrFail((int) $request->shipment_id);

        if (empty($shipment->mailno)) {
            return back()->with('error', 'No mailno yet. Create order first.');
        }

        $client = JntClient::fromConfig();
        $res = $client->printWaybill((string) $shipment->mailno);

        $b64 = data_get($res, 'responseitems.base64Url')
            ?? data_get($res, 'responseitems.0.base64Url');

        if (!$b64) {
            return back()->with('error', 'No base64Url in print response.');
        }

        $pdfBytes = base64_decode(preg_replace('/\s+/', '', (string) $b64), true);
        if ($pdfBytes === false) {
            return back()->with('error', 'Invalid base64 PDF returned.');
        }

        $filename = 'waybill-' . $shipment->mailno . '.pdf';

        return response($pdfBytes, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    // ✅ ZIP print for whole run
    public function printRunZip(Request $request, int $runId)
    {
        set_time_limit(0);

        $run = JntBatchRun::query()->findOrFail($runId);

        // must have mailno
        $hasAny = JntShipment::query()
            ->where('jnt_batch_run_id', $runId)
            ->whereNotNull('mailno')->where('mailno', '!=', '')
            ->exists();

        if (!$hasAny) {
            return back()->with('error', 'No mailno yet in this run. Wait for create orders to finish.');
        }

        $client = JntClient::fromConfig();

        $tmpDir = storage_path('app/tmp');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }

        $zipPath = $tmpDir . '/waybills-run-' . $runId . '-' . now()->format('YmdHis') . '.zip';

        $zip = new \ZipArchive();
        $okOpen = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($okOpen !== true) {
            throw new \RuntimeException("Cannot create zip file: {$zipPath}");
        }

        $failLines = [];

        JntShipment::query()
            ->where('jnt_batch_run_id', $runId)
            ->whereNotNull('mailno')->where('mailno', '!=', '')
            ->select(['id', 'mailno'])
            ->orderBy('id')
            ->chunk(30, function ($chunk) use ($client, $zip, &$failLines) {
                foreach ($chunk as $s) {
                    $mailno = (string) $s->mailno;

                    try {
                        $res = $client->printWaybill($mailno);

                        $b64 = data_get($res, 'responseitems.base64Url')
                            ?? data_get($res, 'responseitems.0.base64Url');

                        if (!$b64) {
                            $failLines[] = "{$mailno} | no base64Url";
                            continue;
                        }

                        $pdfBytes = base64_decode(preg_replace('/\s+/', '', (string) $b64), true);
                        if ($pdfBytes === false) {
                            $failLines[] = "{$mailno} | invalid base64";
                            continue;
                        }

                        $zip->addFromString("waybill-{$mailno}.pdf", $pdfBytes);
                    } catch (\Throwable $e) {
                        $failLines[] = "{$mailno} | " . $e->getMessage();
                        continue;
                    }
                }
            });

        if (!empty($failLines)) {
            $zip->addFromString('FAILED.txt', implode("\n", $failLines));
        }

        $zip->close();

        return response()->download($zipPath)->deleteFileAfterSend(true);
    }
}
