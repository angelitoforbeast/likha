<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PancakeRetrieve2Controller extends Controller
{
    public function index(Request $request)
    {
        // -----------------------------
        // ✅ Date presets (Asia/Manila)
        // -----------------------------
        $tz = 'Asia/Manila';

        // Your UI sent preset=month, so support it
        $preset = (string) $request->get('preset', '');
        if ($preset === 'month') {
            $preset = 'this_month';
        }

        $now = Carbon::now($tz);

        // Optional manual range
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
                // already set
            } elseif ($preset === 'last7') {
                $dateFrom = $now->copy()->subDays(6)->toDateString();
                $dateTo   = $now->toDateString();
            } elseif ($preset === 'this_month') {
                $dateFrom = $now->copy()->startOfMonth()->toDateString();
                $dateTo   = $now->toDateString();
            }
        } else {
            // Normalize date strings
            $dateFrom = Carbon::parse($dateFrom, $tz)->toDateString();
            $dateTo   = Carbon::parse($dateTo, $tz)->toDateString();
        }

        // -----------------------------
        // ✅ Optional text filters
        // -----------------------------
        $pageContains = trim((string) $request->get('page_contains', ''));
        $nameContains = trim((string) $request->get('name_contains', ''));

        // -----------------------------
        // ✅ Manila day derived from pc.created_at (stored UTC)
        // -----------------------------
        $convDateExpr      = "DATE(CONVERT_TZ(pc.created_at,'+00:00','+08:00'))";
        $convDateTimeExpr  = "CONVERT_TZ(pc.created_at,'+00:00','+08:00')";

        // -----------------------------
        // ✅ Display page name from pancake_id (fallback to page_id)
        // -----------------------------
        $pageNameExpr = "COALESCE(pi.pancake_page_name, pc.pancake_page_id)";
        $pageKeyExpr  = "LOWER(TRIM($pageNameExpr))";
        $nameKeyExpr  = "LOWER(TRIM(pc.full_name))";

        // -----------------------------
        // ✅ Existing (PAGE + fb_name) combos in macro_output (global)
        // -----------------------------
        $macroPairs = DB::table('macro_output')
            ->selectRaw("LOWER(TRIM(`PAGE`)) as page_key, LOWER(TRIM(`fb_name`)) as name_key")
            ->whereNotNull('PAGE')
            ->whereRaw("TRIM(`PAGE`) <> ''")
            ->whereNotNull('fb_name')
            ->whereRaw("TRIM(`fb_name`) <> ''")
            ->groupByRaw("LOWER(TRIM(`PAGE`)), LOWER(TRIM(`fb_name`))");

        // -----------------------------
        // ✅ Mode (most common) SHOP DETAILS per ts_date + page_key, within selected date range
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
        // ✅ Main: rows in pancake_conversations missing in macro_output by (page + name)
        // Date filter based on Manila day of created_at
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
            ->whereNull('mp.name_key')
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

        $rows = $q->orderBy('pc.created_at', 'desc')
                  ->paginate(200)
                  ->withQueryString();

        return view('pancake.retrieve2', [
            'rows'          => $rows,
            'preset'        => $preset,
            'date_from'     => $dateFrom,
            'date_to'       => $dateTo,
            'page_contains' => $pageContains,
            'name_contains' => $nameContains,
            'tz'            => $tz, // ✅ FIX: so Blade can print {{ $tz }}
        ]);
    }
}
