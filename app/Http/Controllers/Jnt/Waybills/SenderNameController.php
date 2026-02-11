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

        // date-only object for computations
        $selectedDateObj = Carbon::createFromFormat('Y-m-d', $selectedDate, 'Asia/Manila')->startOfDay();

        // display format: February 07, 2026
        $displayDate = $selectedDateObj->format('F d, Y');

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
            ->get();

        // Add formatted mapping created date + stale logic (DATE - MAPPING_CREATED > 8 days)
        $rows->transform(function ($r) use ($selectedDateObj) {
            $r->mapping_created_display = null;
            $r->days_old = null;
            $r->is_stale = false;

            if (!empty($r->mapping_created_at)) {
                // date-only (ignore time)
                $mappingDateObj = Carbon::parse($r->mapping_created_at)
                    ->timezone('Asia/Manila')
                    ->startOfDay();

                $r->mapping_created_display = $mappingDateObj->format('F d, Y');

                // date-only diff (can be negative)
                $daysOld = $mappingDateObj->diffInDays($selectedDateObj, false);
                $r->days_old = $daysOld;

                // stale if selectedDate - mappingCreated > 8
                $r->is_stale = ($daysOld !== null && $daysOld > 8);
            }

            return $r;
        });

        // ✅ Move red/stale rows to the top, then sort by PAGE
        $rows = $rows
            ->sortBy(function ($r) {
                $group = $r->is_stale ? '0' : '1'; // 0 first
                $page  = mb_strtoupper((string) ($r->PAGE ?? ''));
                return $group . '|' . $page;
            })
            ->values();

        return view('jnt.waybills.sender-name', [
            'selectedDate' => $selectedDate, // for <input type="date">
            'displayDate'  => $displayDate,  // Month Day, Year for UI
            'rows'         => $rows,
        ]);
    }
}
