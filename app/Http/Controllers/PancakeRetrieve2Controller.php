<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PancakeRetrieve2Controller extends Controller
{
    /**
     * Route: GET /pancake/retrieve2
     * View : resources/views/pancake/retrieve2.blade.php  (blade: pancake.retrieve2)
     *
     * Goal:
     * - Show rows from pancake_conversations that do NOT exist in macro_output
     *   comparing (pancake_page_name + full_name) vs (PAGE + fb_name), case-insensitive + trimmed.
     * - Date filter uses pancake_conversations.created_at in Asia/Manila day (UTC stored, so convert).
     * - SHOP DETAILS shows the most common macro_output.`SHOP DETAILS` for same ts_date + PAGE (page name).
     */
    public function index(Request $request)
    {
        // -----------------------------
        // ✅ Date presets (Asia/Manila)
        // -----------------------------
        $tz = 'Asia/Manila';
        $preset = (string) $request->get('preset', '');

        $now = Carbon::now($tz);
        $dateFrom = $request->get('date_from');
        $dateTo   = $request->get('date_to');

        if (!$dateFrom || !$dateTo) {
            // Default = yesterday
            $y = $now->copy()->subDay()->toDateString();
            $dateFrom = $y;
            $dateTo   = $y;

            if ($preset === 'today') {
                $t = $now->toDateString();
                $dateFrom = $t;
                $dateTo   = $t;
            } elseif ($preset === 'yesterday') {
                // already default
            } elseif ($preset === 'last7') {
                $dateFrom = $now->copy()->subDays(6)->toDateString();
                $dateTo   = $now->toDateString();
            } elseif ($preset === 'this_month') {
                $dateFrom = $now->copy()->startOfMonth()->toDateString();
                $dateTo   = $now->toDateString();
            }
        } else {
            // Normalize if user passed values
            $dateFrom = Carbon::parse($dateFrom, $tz)->toDateString();
            $dateTo   = Carbon::parse($dateTo, $tz)->toDateString();
        }

        // -----------------------------
        // ✅ Text filters
        // -----------------------------
        $pageContains = trim((string) $request->get('page_contains', ''));
        $nameContains = trim((string) $request->get('name_contains', ''));

        // -----------------------------
        // ✅ Consistent "conversation date" key (Manila day)
        // pc.created_at is stored as UTC in your DB, proven by your tinker output.
        // -----------------------------
        $convDateExpr = "DATE(CONVERT_TZ(pc.created_at,'+00:00','+08:00'))";
        $convDateTimeExpr = "CONVERT_TZ(pc.created_at,'+00:00','+08:00')";

        // Page name expression used for display + matching
        // (If pancake_id mapping is missing, it will show pancake_page_id; matching to macro_output PAGE will not hit, which is fine.)
        $pageNameExpr = "COALESCE(pi.pancake_page_name, pc.pancake_page_id)";
        $pageKeyExpr  = "LOWER(TRIM($pageNameExpr))";
        $nameKeyExpr  = "LOWER(TRIM(pc.full_name))";

        // -----------------------------
        // ✅ macro_output "existence" keys (do NOT date-filter: existence check is global)
        // Matching = trimmed + case-insensitive, as you requested.
        // -----------------------------
        $macroPairs = DB::table('macro_output')
            ->selectRaw("LOWER(TRIM(`PAGE`)) as page_key, LOWER(TRIM(`fb_name`)) as name_key")
            ->whereNotNull('PAGE')
            ->whereRaw("TRIM(`PAGE`) <> ''")
            ->whereNotNull('fb_name')
            ->whereRaw("TRIM(`fb_name`) <> ''")
            ->groupByRaw("LOWER(TRIM(`PAGE`)), LOWER(TRIM(`fb_name`))");

        // -----------------------------
        // ✅ SHOP DETAILS mode table (per ts_date + page_key within selected date range)
        // -----------------------------
        $shopCounts = DB::table('macro_output')
            ->selectRaw("
                ts_date,
                LOWER(TRIM(`PAGE`)) as page_key,
                TRIM(`SHOP DETAILS`) as shop_details,
                COUNT(*) as cnt
            ")
            ->whereNotNull('ts_date')
            ->whereRaw("TRIM(ts_date) <> ''")
            ->whereBetween('ts_date', [$dateFrom, $dateTo])
            ->whereNotNull('PAGE')
            ->whereRaw("TRIM(`PAGE`) <> ''")
            ->whereNotNull(DB::raw("`SHOP DETAILS`"))
            ->whereRaw("TRIM(`SHOP DETAILS`) <> ''")
            ->groupByRaw("ts_date, LOWER(TRIM(`PAGE`)), TRIM(`SHOP DETAILS`)");

        $shopMax = DB::query()
            ->fromSub($shopCounts, 'scm')
            ->selectRaw("ts_date, page_key, MAX(cnt) as max_cnt")
            ->groupByRaw("ts_date, page_key");

        // If tie in cnt, pick deterministic smallest shop_details (MIN)
        $shopMode = DB::query()
            ->fromSub($shopCounts, 'sc')
            ->joinSub($shopMax, 'mx', function ($join) {
                $join->on('mx.ts_date', '=', 'sc.ts_date')
                    ->on('mx.page_key', '=', 'sc.page_key')
                    ->on('mx.max_cnt', '=', 'sc.cnt');
            })
            ->selectRaw("sc.ts_date, sc.page_key, MIN(sc.shop_details) as shop_details")
            ->groupByRaw("sc.ts_date, sc.page_key");

        // -----------------------------
        // ✅ Main query: Pancake conversations missing in macro_output
        // Date filter is Manila-day based on converted created_at
        // -----------------------------
        $q = DB::table('pancake_conversations as pc')
            ->leftJoin('pancake_id as pi', 'pi.pancake_page_id', '=', 'pc.pancake_page_id')
            ->leftJoinSub($macroPairs, 'mp', function ($join) use ($pageKeyExpr, $nameKeyExpr) {
                $join->on('mp.page_key', '=', DB::raw($pageKeyExpr))
                     ->on('mp.name_key', '=', DB::raw($nameKeyExpr));
            })
            ->leftJoinSub($shopMode, 'sm', function ($join) use ($convDateExpr, $pageKeyExpr) {
                $join->on('sm.ts_date', '=', DB::raw($convDateExpr))
                     ->on('sm.page_key', '=', DB::raw($pageKeyExpr));
            })
            ->whereNull('mp.name_key') // ✅ missing in macro_output (page+name combo does not exist)
            ->whereRaw("$convDateExpr >= ?", [$dateFrom])
            ->whereRaw("$convDateExpr <= ?", [$dateTo]);

        if ($pageContains !== '') {
            $q->whereRaw("LOWER($pageNameExpr) LIKE ?", ['%' . mb_strtolower($pageContains) . '%']);
        }

        if ($nameContains !== '') {
            $q->whereRaw("LOWER(pc.full_name) LIKE ?", ['%' . mb_strtolower($nameContains) . '%']);
        }

        $q->selectRaw("
            $convDateTimeExpr as date_created,
            $pageNameExpr as page,
            sm.shop_details as shop_details,
            pc.full_name,
            pc.customers_chat
        ");

        $rows = $q
            ->orderBy('pc.created_at', 'desc')   // still ok; ordering by stored UTC datetime
            ->paginate(200)
            ->withQueryString();

        return view('pancake.retrieve2', [
            'rows'         => $rows,
            'preset'       => $preset,
            'date_from'    => $dateFrom,
            'date_to'      => $dateTo,
            'page_contains'=> $pageContains,
            'name_contains'=> $nameContains,
        ]);
    }
}
