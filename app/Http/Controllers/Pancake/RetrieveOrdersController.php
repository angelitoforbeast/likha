<?php

namespace App\Http\Controllers\Pancake;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RetrieveOrdersController extends Controller
{
    public function index()
    {
        // ito lang, front-end na nagbabasa ng Excel
        return view('pancake.retrieve-orders');
    }

    public function check(Request $request)
    {
        // validate input galing sa JS fetch
        $data = $request->validate([
            'senders'   => 'required|array',
            'senders.*' => 'string|max:255',
        ]);

        $senders = $data['senders'];

        if (empty($senders)) {
            return response()->json([
                'existing' => [],
                'pages'    => [],
                'shops'    => [],
            ]);
        }

        // IMPORTANT:
        //  - walang schema prefix dito, assume na config mo sa DB ang bahala (search_path / db name)
        //  - "SHOP DETAILS" diretsong string, si Laravel na mag-quote per driver (MySQL/Postgres)
        $rows = DB::table('macro_output')
            ->select('fb_name', 'PAGE', 'SHOP DETAILS')
            ->whereIn('fb_name', $senders)
            ->get();

        $existing = [];
        $pages    = [];
        $shops    = [];

        foreach ($rows as $row) {
            $name = $row->fb_name;

            // mark as existing
            $existing[$name] = true;

            if (!isset($pages[$name])) {
                $pages[$name] = [];
            }
            if (!isset($shops[$name])) {
                $shops[$name] = [];
            }

            // PAGE column
            if (isset($row->PAGE) && $row->PAGE !== '') {
                $pages[$name][] = $row->PAGE;
            }

            // SHOP DETAILS column (may space, kaya object property style)
            $shopVal = $row->{'SHOP DETAILS'} ?? null;
            if (!is_null($shopVal) && $shopVal !== '') {
                $shops[$name][] = $shopVal;
            }
        }

        return response()->json([
            'existing' => array_keys($existing),
            'pages'    => $pages,
            'shops'    => $shops,
        ]);
    }
}
