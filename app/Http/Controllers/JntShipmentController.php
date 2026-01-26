<?php

namespace App\Http\Controllers;

use App\Jobs\CreateJntOrder;
use App\Models\JntShipment;
use App\Services\Jnt\JntClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JntShipmentController extends Controller
{
    public function index(Request $req)
    {
        $q = JntShipment::query()->orderByDesc('id');

        if ($req->filled('status')) $q->where('status', $req->status);
        if ($req->filled('mailno')) $q->where('mailno', $req->mailno);
        if ($req->filled('macro_output_id')) $q->where('macro_output_id', $req->macro_output_id);

        $shipments = $q->paginate(50)->withQueryString();

        return view('jnt/shipments/index', compact('shipments'));
    }

    // Create for 1 macro_output row
    public function createOne(int $macroOutputId)
    {
        CreateJntOrder::dispatch($macroOutputId);
        return back()->with('success', "Queued J&T create for macro_output_id={$macroOutputId}");
    }

    // Bulk by date + page (adjust filters to your columns)
    public function bulkCreate(Request $req)
    {
        $date = $req->input('date'); // YYYY-MM-DD
        $page = $req->input('page'); // page name or id

        $query = DB::table('macro_output')->select('id');

        // Adjust column names based on your macro_output schema
        if ($date) {
            // try common columns: created_at or order_date
            $query->whereDate('created_at', $date);
        }
        if ($page) {
            // common: page or fb_page or page_name
            $query->where(function($qq) use ($page) {
                $qq->where('PAGE', $page)
                   ->orWhere('page', $page)
                   ->orWhere('page_name', $page);
            });
        }

        $ids = $query->limit(1000)->pluck('id')->all(); // protect server; tune later

        foreach ($ids as $id) {
            CreateJntOrder::dispatch((int)$id);
        }

        return back()->with('success', 'Queued ' . count($ids) . ' J&T create jobs.');
    }

    public function track(int $shipmentId)
    {
        $s = JntShipment::findOrFail($shipmentId);
        if (!$s->mailno) return back()->with('error', 'No mailno yet.');

        $client = JntClient::fromConfig();
        $res = $client->trackForJson($s->mailno, 'en');

        $item = $res['responseitems'][0] ?? [];
        $success = ($item['success'] ?? 'false') === 'true';

        $s->update([
            'status' => $success ? 'TRACKED' : $s->status,
            'request_protocol' => $client->lastRequest,
            'response_payload' => $res,
            'last_reason' => $item['reason'] ?? $s->last_reason,
        ]);

        return back()->with('success', 'Tracked ' . $s->mailno);
    }

    public function cancel(int $shipmentId)
    {
        $s = JntShipment::findOrFail($shipmentId);
        if (!$s->txlogisticid) return back()->with('error', 'No txlogisticid.');

        $client = JntClient::fromConfig();

        $payloadCancel = [
            'eccompanyid' => config('jnt.credentials.eccompanyid'),
            'customerid'  => config('jnt.credentials.customerid'),
            'txlogisticid'=> $s->txlogisticid,
            'country'     => 'PH',
            'reason'      => 'Cancel via Likha UI',
        ];

        $res = $client->cancelOrder($payloadCancel);
        $item = $res['responseitems'][0] ?? [];
        $success = ($item['success'] ?? 'false') === 'true';

        $s->update([
            'status' => $success ? 'CANCELED' : $s->status,
            'request_protocol' => $client->lastRequest,
            'response_payload' => $res,
            'last_reason' => $item['reason'] ?? null,
        ]);

        return back()->with('success', 'Cancel sent for ' . $s->txlogisticid);
    }
}
