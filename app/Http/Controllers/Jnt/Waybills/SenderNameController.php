<?php

namespace App\Http\Controllers\Jnt\Waybills;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SenderNameController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'date' => ['nullable', 'date'],
        ]);

        // default = yesterday (Asia/Manila)
        $selectedDate = $request->input('date')
            ?: Carbon::now('Asia/Manila')->subDay()->toDateString(); // Y-m-d

        // display format: February 07, 2026
        $displayDate = Carbon::createFromFormat('Y-m-d', $selectedDate, 'Asia/Manila')
            ->format('F d, Y');

        // distinct pages from macro_output for selected date
        $pagesSub = DB::table('macro_output')
            ->where('ts_date', $selectedDate)
            ->select('PAGE')
            ->distinct();

        // latest mapping per PAGE (latest insert by MAX(id))
        $latestMapSub = DB::table('page_sender_mappings')
            ->selectRaw('PAGE, MAX(id) as max_id')
            ->groupBy('PAGE');

        $rows = DB::query()
            ->fromSub($pagesSub, 'mo')
            ->leftJoinSub($latestMapSub, 'mx', 'mx.PAGE', '=', 'mo.PAGE')
            ->leftJoin('page_sender_mappings as p', 'p.id', '=', 'mx.max_id')
            ->select([
                'mo.PAGE',
                DB::raw("COALESCE(p.SENDER_NAME,'') as SENDER_NAME"),
                'p.created_at as mapping_created_at',
            ])
            ->orderBy('mo.PAGE')
            ->get();

        // add formatted mapping created date (Month Day, Year)
        $rows->transform(function ($r) {
            $r->mapping_created_display = $r->mapping_created_at
                ? Carbon::parse($r->mapping_created_at)->timezone('Asia/Manila')->format('F d, Y')
                : null;
            return $r;
        });

        return view('jnt.waybills.sender-name', [
            'selectedDate' => $selectedDate, // for <input type="date">
            'displayDate'  => $displayDate,  // for UI table
            'rows'         => $rows,
        ]);
    }
}
