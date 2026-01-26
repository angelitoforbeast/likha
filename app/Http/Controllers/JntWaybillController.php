<?php

namespace App\Http\Controllers;

use App\Services\Jnt\JntWaybillClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class JntWaybillController extends Controller
{
    public function index(Request $request)
    {
        $date = $request->input('date') ?: now('Asia/Manila')->toDateString();
        $page = trim((string) $request->input('page', ''));

        $pages = DB::table('macro_output')
            ->whereNotNull('PAGE')
            ->where('PAGE', '!=', '')
            ->distinct()
            ->orderBy('PAGE')
            ->pluck('PAGE')
            ->values()
            ->all();

        // IMPORTANT: We need txlogisticid (serialnumber) for ORDERQUERY
        // Adjust columns if your jnt_shipments table differs.
        $rows = DB::table('jnt_shipments as s')
            ->join('macro_output as m', 'm.id', '=', 's.macro_output_id')
            ->whereDate('m.ts_date', $date)
            ->whereNotNull('s.txlogisticid')
            ->where('s.txlogisticid', '!=', '')
            ->when($page !== '', fn ($q) => $q->where('m.PAGE', $page))
            ->select([
                's.id',
                's.mailno',
                's.txlogisticid',
                'm.PAGE as page',
                DB::raw('`m`.`FULL NAME` as full_name'),
                'm.ADDRESS as address',
                'm.COD as cod',
            ])
            ->orderByDesc('s.id')
            ->paginate(50)
            ->withQueryString();

        return view('jnt.orders.waybills', compact('date', 'page', 'pages', 'rows'));
    }

    /**
     * POST /jnt/waybills/query-one
     * Body: { "serial": "TEST-..." }
     * Returns: { ok, serial, item|null, message }
     */
    public function queryOne(Request $request, JntWaybillClient $client)
    {
        try {
            $serial = trim((string) $request->input('serial', ''));

            if ($serial === '') {
                return response()->json([
                    'ok' => false,
                    'serial' => '',
                    'item' => null,
                    'message' => 'No serial provided',
                ], 422);
            }

            // Query a single serialnumber (txlogisticid)
            $items = $client->queryOrders([$serial]);
            $item  = $items[$serial] ?? null;

            Log::info('JNT ORDERQUERY ONE', [
                'serial' => $serial,
                'found'  => is_array($item),
            ]);

            return response()->json([
                'ok' => true,
                'serial' => $serial,
                'item' => $item,
                'message' => $item ? 'FOUND' : 'NOT_FOUND',
            ]);

        } catch (\Throwable $e) {
            Log::error('JNT ORDERQUERY FAILED', [
                'error' => $e->getMessage(),
            ]);

            // Always JSON (avoid HTML error page)
            return response()->json([
                'ok' => false,
                'serial' => (string) $request->input('serial', ''),
                'item' => null,
                'message' => 'Failed to query J&T ORDERQUERY: ' . $e->getMessage(),
            ], 500);
        }
    }
}
