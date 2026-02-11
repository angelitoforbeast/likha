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
        // date filter (default yesterday)
        $request->validate([
            'date' => ['nullable', 'date'],
        ]);

        $selectedDate = $request->input('date')
            ?: Carbon::now('Asia/Manila')->subDay()->toDateString();

        // Distinct pages from macro_output for the selected date
        $pagesSub = DB::table('macro_output')
            ->where('ts_date', $selectedDate)
            ->select('PAGE')
            ->distinct();

        // Latest mapping per PAGE (by max(id) = latest inserted)
        $latestMapSub = DB::table('page_sender_mappings')
            ->selectRaw('PAGE, MAX(id) as max_id')
            ->groupBy('PAGE');

        $rows = DB::query()
            ->fromSub($pagesSub, 'mo')
            ->leftJoinSub($latestMapSub, 'mx', 'mx.PAGE', '=', 'mo.PAGE')
            ->leftJoin('page_sender_mappings as p', 'p.id', '=', 'mx.max_id')
            ->selectRaw('? as `DATE`', [$selectedDate])
            ->addSelect('mo.PAGE')
            ->addSelect(DB::raw("COALESCE(p.SENDER_NAME,'') as SENDER_NAME"))
            ->orderBy('mo.PAGE')
            ->get();

        return view('jnt.waybills.sender-name', [
            'selectedDate' => $selectedDate,
            'rows' => $rows,
        ]);
    }
}
