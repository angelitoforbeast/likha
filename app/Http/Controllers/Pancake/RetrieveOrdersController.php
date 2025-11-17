<?php

namespace App\Http\Controllers\Pancake;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\MacroOutput;

class RetrieveOrdersController extends Controller
{
    public function index()
    {
        // Blade lang, Excel parsing nasa JS
        return view('pancake.retrieve-orders');
    }

    /**
     * Body (JSON):
     * { "senders": ["Name 1", "Name 2", ...] }
     *
     * Response:
     * { "existing": ["Name 1", "Name X", ...] }
     */
    public function check(Request $request)
{
    $data = $request->validate([
        'senders'   => ['required', 'array'],
        'senders.*' => ['string'],
    ]);

    $senderNames = $data['senders'];

    // fb_name + PAGE + SHOP_DETAILS sa macro_output
    $rows = MacroOutput::whereIn('fb_name', $senderNames)
        ->select('fb_name', 'PAGE', 'SHOP_DETAILS') // make sure column name is exactly this in DB
        ->get();

    // List ng existing fb_name
    $existing = $rows->pluck('fb_name')
        ->unique()
        ->values()
        ->all();

    // Mapping: fb_name => [unique pages...]
    $pagesByName = $rows
        ->groupBy('fb_name')
        ->map(function ($group) {
            return $group->pluck('PAGE')
                ->filter()       // tanggal null/empty
                ->unique()
                ->values()
                ->all();
        })
        ->toArray();

    // Mapping: fb_name => [unique shop details...]
    $shopsByName = $rows
        ->groupBy('fb_name')
        ->map(function ($group) {
            return $group->pluck('SHOP_DETAILS')
                ->filter()
                ->unique()
                ->values()
                ->all();
        })
        ->toArray();

    return response()->json([
        'existing' => $existing,
        'pages'    => $pagesByName,
        'shops'    => $shopsByName,
    ]);
}


}
