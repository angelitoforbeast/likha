<?php

namespace App\Http\Controllers;

use App\Services\Jnt\JntOrderQueryClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class JntOrderManagementController extends Controller
{
    public function index(Request $request)
    {
        $upload_date = $request->input('upload_date') ?: now('Asia/Manila')->toDateString();
        $page = trim((string) $request->input('page', ''));

        $pages = DB::table('jnt_shipments as s')
            ->join('macro_output as m', 'm.id', '=', 's.macro_output_id')
            ->whereNotNull('m.PAGE')
            ->where('m.PAGE', '!=', '')
            ->distinct()
            ->orderBy('m.PAGE')
            ->pluck('m.PAGE')
            ->values()
            ->all();

        $rows = DB::table('jnt_shipments as s')
            ->join('macro_output as m', 'm.id', '=', 's.macro_output_id')
            ->whereDate('s.created_at', $upload_date)
            ->when($page !== '', fn ($q) => $q->where('m.PAGE', $page))
            ->whereNotNull('s.txlogisticid')
            ->where('s.txlogisticid', '!=', '')
            ->select([
                's.id',
                's.created_at as uploaded_at',
                's.mailno',
                's.txlogisticid',
                's.success',
                's.reason',

                'm.ts_date',
                'm.PAGE as page',
                DB::raw('`m`.`FULL NAME` as full_name'),
                DB::raw('`m`.`PHONE NUMBER` as phone_number'),
                'm.ADDRESS as address',
                'm.PROVINCE as province',
                'm.CITY as city',
                'm.BARANGAY as barangay',
                'm.ITEM_NAME as item_name',
                'm.COD as cod',
            ])
            ->orderByDesc('s.id')
            ->paginate(50)
            ->withQueryString();

        return view('jnt.orders.order-management', compact(
            'upload_date',
            'page',
            'pages',
            'rows'
        ));
    }

    public function query(Request $request, JntOrderQueryClient $client)
    {
        try {
            // ✅ Force JSON expectation for this endpoint
            $request->headers->set('Accept', 'application/json');

            $serials = $request->input('serials', []);
            if (!is_array($serials)) $serials = [];

            $serials = array_values(array_filter(array_map(fn ($v) => trim((string) $v), $serials)));

            if (count($serials) === 0) {
                return response()->json([
                    'ok' => true,
                    'items' => [],
                    'count' => 0,
                ]);
            }

            $items = $client->queryOrders($serials);

            Log::info('JNT ORDERQUERY RESULT', [
                'requested_serials' => $serials,
                'returned_count' => count($items),
            ]);

            return response()->json([
                'ok' => true,
                'items' => $items,
                'count' => count($items),
            ]);

        } catch (Throwable $e) {

            Log::error('JNT ORDERQUERY FAILED', [
                'serials' => $request->input('serials', []),
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 2000),
            ]);

            // ✅ Always JSON, never HTML
            return response()->json([
                'ok' => false,
                'message' => 'ORDERQUERY failed: ' . $e->getMessage(),
                'items' => [],
                'count' => 0,
            ], 500);
        }
    }
}
