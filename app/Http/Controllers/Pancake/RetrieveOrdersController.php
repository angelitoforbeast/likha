<?php

namespace App\Http\Controllers\Pancake;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        $matched  = [];

        // Ang linya na 10-25 digits ay PSID din: tugma sa botcake_psid ng order (bukod sa fb_name na rule).
        $psids = Schema::hasColumn('macro_output', 'botcake_psid')
            ? array_values(array_unique(array_filter(array_map('trim', $senders), fn ($s) => preg_match('/^\d{10,25}$/', $s) === 1)))
            : [];
        $psidRows = $psids
            ? DB::table('macro_output')->select('botcake_psid as fb_name', 'PAGE', 'SHOP DETAILS')->whereIn('botcake_psid', $psids)->get()
            : collect();

        foreach ($rows->map(fn ($r) => [$r, 'name'])->concat($psidRows->map(fn ($r) => [$r, 'psid'])) as [$row, $how]) {
            $name = $row->fb_name;

            // mark as existing, at kung paano tumugma (PSID ang nananaig)
            $existing[$name] = true;
            $matched[$name]  = ($matched[$name] ?? null) === 'psid' ? 'psid' : $how;

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
            'matched'  => $matched,
        ]);
    }
}
