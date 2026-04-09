<?php

namespace App\Http\Controllers;

use App\Jobs\CreateJntOrder;
use App\Models\JntBatchRun;
use App\Models\JntShipment;
use App\Models\PageJntMapping;
use App\Services\Jnt\JntClient; // ✅ FIX: correct namespace
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;


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

    // ✅ If opened via ?run_id=123 only, use run->filters for date/page
    if ($run && !$request->filled('date') && !$request->filled('page')) {
        $filters = json_decode((string) $run->filters, true) ?: [];
        $date = $filters['date'] ?? $date;
        $page = $filters['page'] ?? $page;
    }

    // ✅ Date range (index-friendly for DATETIME ts_date)
    $dayStart = Carbon::parse($date, 'Asia/Manila')->startOfDay();
    $dayEnd   = Carbon::parse($date, 'Asia/Manila')->endOfDay();

    // ✅ Sender Preview (same as you have)
    $senderPreview = Cache::remember('jnt_sender_preview:' . ($page !== '' ? $page : 'default'), 60, function () use ($page) {

        $mappedSenderName = null;
        if ($page !== '' && Schema::hasTable('page_sender_mappings')) {
            $mappedSenderName = DB::table('page_sender_mappings')
                ->where('PAGE', $page)
                ->orderByDesc('id')
                ->value('SENDER_NAME');
        }

        $addrRow = null;
        if (Schema::hasTable('sender_addresses')) {
            $addrRow = DB::table('sender_addresses')
                ->orderByDesc('id')
                ->first();
        }

        return (object) [
            'sender_name'    => $mappedSenderName ?: (config('jnt.sender.name') ?? ''),
            'sender_phone'   => $addrRow->jnt_sender_phone   ?? (config('jnt.sender.phone') ?? ''),
            'sender_prov'    => $addrRow->jnt_sender_prov    ?? (config('jnt.sender.prov') ?? ''),
            'sender_city'    => $addrRow->jnt_sender_city    ?? (config('jnt.sender.city') ?? ''),
            'sender_brgy'    => $addrRow->jnt_sender_area    ?? (config('jnt.sender.area') ?? ''),
            'sender_address' => $addrRow->jnt_sender_address ?? (config('jnt.sender.address') ?? ''),
            'source_mapping' => $mappedSenderName ? 'page_sender_mappings' : 'config_default',
            'source_addr'    => $addrRow ? 'sender_addresses' : 'config_default',
        ];
    });

    // ✅ JNT Account Check (preview only, when page is selected)
    $accountCheck = (object) [
        'ok'      => true,
        'account' => null,
        'message' => null,
    ];

    if ($page !== '' && !$run) {
        $mapping = PageJntMapping::with('account')->where('page', $page)->first();

        if ($mapping && $mapping->account) {
            $accountCheck->ok      = true;
            $accountCheck->account = $mapping->account;
        } else {
            $accountCheck->ok      = false;
            $accountCheck->message = 'No JNT Account mapped for this page. Go to Page Mapping to assign one.';
        }
    }

    /**
     * ✅ STATUS + PROCEED VALIDATION GATE (preview only)
     */
    $statusGate = (object) [
        'enabled'        => false,
        'ok'             => true,
        'total_all'      => 0,
        'missing_status' => 0,
        'proceed_total'  => 0,
        'invalid_proceed'=> 0,
        'message'        => null,
    ];

    // Gate applies ONLY on preview + if page selected
    if (!$run && $page !== '') {
        $statusGate->enabled = true;

        $gateBase = DB::table('macro_output')
            ->whereBetween('ts_date', [$dayStart, $dayEnd])
            ->where('PAGE', $page);

        $statusGate->total_all = (int) (clone $gateBase)->count();

        // 1) STATUS must be filled for ALL rows
        $statusGate->missing_status = (int) (clone $gateBase)
            ->where(function ($q) {
                $q->whereNull('STATUS')
                  ->orWhere('STATUS', '=', '');
            })
            ->count();

        // 2) For PROCEED rows, validate_1/validate_2/item_checker must be 1
        $statusGate->proceed_total = (int) (clone $gateBase)
            ->where('STATUS', 'PROCEED')
            ->count();

        $statusGate->invalid_proceed = (int) (clone $gateBase)
            ->where('STATUS', 'PROCEED')
            ->where(function ($q) {
                $q->whereNull('validate_1')->orWhere('validate_1', '!=', 1)
                  ->orWhereNull('validate_2')->orWhere('validate_2', '!=', 1)
                  ->orWhereNull('item_checker')->orWhere('item_checker', '!=', 1);
            })
            ->count();

        $statusGate->ok = ($statusGate->missing_status === 0) && ($statusGate->invalid_proceed === 0);

        if (!$statusGate->ok) {
            if ($statusGate->missing_status > 0) {
                $statusGate->message = "STATUS INCOMPLETE: {$statusGate->missing_status} missing STATUS out of {$statusGate->total_all} rows. Fill STATUS for ALL rows first.";
            } else {
                $statusGate->message = "VALIDATION INCOMPLETE: {$statusGate->invalid_proceed} invalid PROCEED rows out of {$statusGate->proceed_total}. Required: validate_1=1, validate_2=1, item_checker=1 for ALL PROCEED rows.";
            }
        }
    }

    // ✅ Pages dropdown (broad)
    $pages = Cache::remember("jnt_orders_pages_" . $date, 60, function () use ($dayStart, $dayEnd) {
        return DB::table('macro_output')
            ->whereBetween('ts_date', [$dayStart, $dayEnd])
            ->whereNotNull('PAGE')->where('PAGE', '!=', '')
            ->select('PAGE')->distinct()
            ->orderBy('PAGE')
            ->pluck('PAGE')
            ->values()
            ->all();
    });

    // ✅ If gated (preview), block showing rows
    if (!$run && $statusGate->enabled && !$statusGate->ok) {
        $rows = collect([]);   // no data
        $runStats = null;
        $retryRun = null;

        return view('jnt.orders.index', compact(
            'date','page','pages','run','rows','runStats','senderPreview','statusGate','accountCheck','retryRun'
        ));
    }

    /**
     * ✅ Eligibility base:
     * - STATUS = PROCEED
     * - validate_1/validate_2/item_checker = 1
     * - required fields
     */
    $eligibilityBase = DB::table('macro_output')
        ->whereBetween('ts_date', [$dayStart, $dayEnd])
        ->whereNotNull('PAGE')->where('PAGE', '!=', '')
        ->where('STATUS', 'PROCEED')
        ->where('validate_1', 1)
        ->where('validate_2', 1)
        ->where('item_checker', 1)
        ->whereNotNull('FULL NAME')->where('FULL NAME', '!=', '')
        ->whereNotNull('PHONE NUMBER')->where('PHONE NUMBER', '!=', '')
        ->whereNotNull('ADDRESS')->where('ADDRESS', '!=', '')
        ->whereNotNull('PROVINCE')->where('PROVINCE', '!=', '')
        ->whereNotNull('CITY')->where('CITY', '!=', '')
        ->whereNotNull('BARANGAY')->where('BARANGAY', '!=', '')
        ->whereNotNull('ITEM_NAME')->where('ITEM_NAME', '!=', '')
        ->whereNotNull('COD')->where('COD', '!=', '');

    $macroBase = clone $eligibilityBase;
    if ($page !== '') $macroBase->where('PAGE', $page);

    if ($run) {
        // ✅ Run view: show shipments (no gating here)
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
            ->simplePaginate(50)
            ->withQueryString();

        $runStats = (object) [
            'total'   => (int) ($run->total ?? 0),
            'ok'      => (int) ($run->success_count ?? 0),
            'fail'    => (int) ($run->fail_count ?? 0),
            'pending' => max(0, (int) ($run->total ?? 0) - (int) ($run->processed ?? 0)),
        ];
    } else {
        // ✅ PREVIEW (PROCEED + validations only)
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
            ->simplePaginate(50)
            ->withQueryString();

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

    // ✅ Option A: In preview mode, detect latest run with failures for this date+page
    $retryRun = null;
    if (!$run && $page !== '') {
        $retryRun = JntBatchRun::query()
            ->where('fail_count', '>', 0)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(filters, '$.date')) = ?", [$date])
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(filters, '$.page')) = ?", [$page])
            ->whereIn('status', ['finished', 'done', 'stopped'])
            ->orderByDesc('id')
            ->first();
    }

    return view('jnt.orders.index', compact(
        'date','page','pages','run','rows','runStats','senderPreview','statusGate','accountCheck','retryRun'
    ));
}


    public function createBatch(Request $request)
{
    $date = $request->input('date') ?: now('Asia/Manila')->toDateString();
    $page = trim((string) $request->input('page', ''));

    if ($page === '') {
        return redirect()->to(url('/jnt/orders') . '?date=' . urlencode($date))
            ->with('error', 'Please select a Page first.');
    }

    // ✅ JNT Account mapping check — hard block if no account mapped
    $mapping = PageJntMapping::with('account')->where('page', $page)->first();
    if (!$mapping || !$mapping->account) {
        return redirect()->to(url('/jnt/orders') . '?date=' . urlencode($date) . '&page=' . urlencode($page))
            ->with('error', 'No JNT Account mapped for "' . $page . '". Go to JNT Accounts → Page Mapping to assign one before creating a batch.');
    }

    $dayStart = Carbon::parse($date, 'Asia/Manila')->startOfDay();
    $dayEnd   = Carbon::parse($date, 'Asia/Manila')->endOfDay();

    $gateBase = DB::table('macro_output')
        ->whereBetween('ts_date', [$dayStart, $dayEnd])
        ->where('PAGE', $page);

    $totalAll = (int) (clone $gateBase)->count();

    // 1) STATUS completeness gate (ALL rows)
    $missingStatus = (int) (clone $gateBase)
        ->where(function ($q) {
            $q->whereNull('STATUS')
              ->orWhere('STATUS', '=', '');
        })
        ->count();

    if ($missingStatus > 0) {
        return redirect()->to(url('/jnt/orders') . '?date=' . urlencode($date) . '&page=' . urlencode($page))
            ->with('error', "STATUS INCOMPLETE: {$missingStatus} missing STATUS out of {$totalAll} rows. Fill STATUS for ALL rows first.");
    }

    // 2) PROCEED validation gate (PROCEED rows must have validate_1/2/item_checker = 1)
    $proceedTotal = (int) (clone $gateBase)->where('STATUS', 'PROCEED')->count();

    $invalidProceed = (int) (clone $gateBase)
        ->where('STATUS', 'PROCEED')
        ->where(function ($q) {
            $q->whereNull('validate_1')->orWhere('validate_1', '!=', 1)
              ->orWhereNull('validate_2')->orWhere('validate_2', '!=', 1)
              ->orWhereNull('item_checker')->orWhere('item_checker', '!=', 1);
        })
        ->count();

    if ($invalidProceed > 0) {
        return redirect()->to(url('/jnt/orders') . '?date=' . urlencode($date) . '&page=' . urlencode($page))
            ->with('error', "VALIDATION INCOMPLETE: {$invalidProceed} invalid PROCEED rows out of {$proceedTotal}. Required: validate_1=1, validate_2=1, item_checker=1 for ALL PROCEED rows.");
    }

    // ✅ Only PROCEED + validated rows are eligible
    $macroQ = DB::table('macro_output')
        ->whereBetween('ts_date', [$dayStart, $dayEnd])
        ->where('PAGE', $page)
        ->where('STATUS', 'PROCEED')
        ->where('validate_1', 1)
        ->where('validate_2', 1)
        ->where('item_checker', 1)
        ->whereNotNull('FULL NAME')->where('FULL NAME', '!=', '')
        ->whereNotNull('PHONE NUMBER')->where('PHONE NUMBER', '!=', '')
        ->whereNotNull('ADDRESS')->where('ADDRESS', '!=', '')
        ->whereNotNull('PROVINCE')->where('PROVINCE', '!=', '')
        ->whereNotNull('CITY')->where('CITY', '!=', '')
        ->whereNotNull('BARANGAY')->where('BARANGAY', '!=', '')
        ->whereNotNull('ITEM_NAME')->where('ITEM_NAME', '!=', '')
        ->whereNotNull('COD')->where('COD', '!=', '');

    $macroIds = $macroQ->pluck('id')->all();

    // ✅ Exclude macro IDs that already have a shipment record (successful OR failed)
    // Successful → already done, no need to re-batch
    // Failed → use "Retry Failed" button instead
    if (!empty($macroIds)) {
        $alreadyQueued = DB::table('jnt_shipments')
            ->whereIn('macro_output_id', $macroIds)
            ->pluck('macro_output_id')
            ->all();

        $macroIds = array_values(array_diff($macroIds, $alreadyQueued));
    }

    $total = count($macroIds);

    if ($total <= 0) {
        return redirect()->to(url('/jnt/orders') . '?date=' . urlencode($date) . '&page=' . urlencode($page))
            ->with('error', 'No new orders to batch. All PROCEED rows already have shipment records. Use "Retry Failed" for failed ones.');
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

    public function retryFailed(int $runId)
    {
        $run = JntBatchRun::query()->findOrFail($runId);

        // Get failed shipments only — success=0 and no mailno yet
        $failedIds = JntShipment::query()
            ->where('jnt_batch_run_id', $runId)
            ->where('success', 0)
            ->whereNull('mailno')
            ->pluck('id')
            ->all();

        if (empty($failedIds)) {
            return redirect()->to(url('/jnt/orders') . '?run_id=' . $runId)
                ->with('error', 'No failed shipments to retry.');
        }

        $count = count($failedIds);

        // Reset run counters for retry
        $run->fail_count  = max(0, (int)$run->fail_count - $count);
        $run->processed   = max(0, (int)$run->processed - $count);
        $run->status      = 'running';
        $run->finished_at = null;
        $run->save();

        // Re-dispatch only failed shipments
        foreach ($failedIds as $sid) {
            CreateJntOrder::dispatch((int) $sid);
        }

        return redirect()->to(url('/jnt/orders') . '?run_id=' . $runId)
            ->with('success', "Retrying {$count} failed shipment(s) in Run #{$runId}.");
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
