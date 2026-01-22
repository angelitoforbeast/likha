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
        // ✅ Timezone / presets
        // -----------------------------
        $tz = 'Asia/Manila';

        // Support legacy preset=month
        $preset = (string) $request->get('preset', '');
        if ($preset === 'month') {
            $preset = 'this_month';
        }

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
                // already set

            } elseif ($preset === 'last7') {
                $dateFrom = $now->copy()->subDays(6)->toDateString();
                $dateTo   = $now->toDateString();

            } elseif ($preset === 'this_month') {
                // ✅ Month up to yesterday
                $dateFrom = $now->copy()->startOfMonth()->toDateString();

                $yesterday = $now->copy()->subDay();
                if ($yesterday->lt($now->copy()->startOfMonth())) {
                    $dateTo = $now->copy()->startOfMonth()->toDateString();
                } else {
                    $dateTo = $yesterday->toDateString();
                }
            }
        } else {
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
        $convDateExpr     = "DATE(CONVERT_TZ(pc.created_at,'+00:00','+08:00'))";
        $convDateTimeExpr = "CONVERT_TZ(pc.created_at,'+00:00','+08:00')";

        // Page name expression used for display + matching
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
        // ✅ Mode SHOP DETAILS per ts_date + page_key, within selected date range
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
        // ✅ Main: pancake_conversations missing in macro_output by (page + name)
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

        $rows->getCollection()->transform(function ($r) {
            $r->phone_number = $this->extractPhoneNumber((string) ($r->customers_chat ?? ''));
            return $r;
        });

        return view('pancake.retrieve2', [
            'rows'          => $rows,
            'preset'        => $preset,
            'date_from'     => $dateFrom,
            'date_to'       => $dateTo,
            'page_contains' => $pageContains,
            'name_contains' => $nameContains,
            'tz'            => $tz,
        ]);
    }

    /**
     * ✅ Export ALL rows (no pagination) for current filters/date range.
     * Returns JSON rows.
     */
    public function export(Request $request)
    {
        $tz = 'Asia/Manila';

        $preset = (string) $request->get('preset', '');
        if ($preset === 'month') $preset = 'this_month';

        $now = Carbon::now($tz);

        $dateFrom = $request->get('date_from');
        $dateTo   = $request->get('date_to');

        if (!$dateFrom || !$dateTo) {
            $y = $now->copy()->subDay()->toDateString();
            $dateFrom = $y;
            $dateTo   = $y;

            if ($preset === 'today') {
                $t = $now->toDateString();
                $dateFrom = $t;
                $dateTo   = $t;
            } elseif ($preset === 'last7') {
                $dateFrom = $now->copy()->subDays(6)->toDateString();
                $dateTo   = $now->toDateString();
            } elseif ($preset === 'this_month') {
                $dateFrom = $now->copy()->startOfMonth()->toDateString();
                $yesterday = $now->copy()->subDay();
                $dateTo = $yesterday->lt($now->copy()->startOfMonth())
                    ? $now->copy()->startOfMonth()->toDateString()
                    : $yesterday->toDateString();
            }
        } else {
            $dateFrom = Carbon::parse($dateFrom, $tz)->toDateString();
            $dateTo   = Carbon::parse($dateTo, $tz)->toDateString();
        }

        $pageContains = trim((string) $request->get('page_contains', ''));
        $nameContains = trim((string) $request->get('name_contains', ''));

        $convDateExpr     = "DATE(CONVERT_TZ(pc.created_at,'+00:00','+08:00'))";
        $convDateTimeExpr = "CONVERT_TZ(pc.created_at,'+00:00','+08:00')";

        $pageNameExpr = "COALESCE(pi.pancake_page_name, pc.pancake_page_id)";
        $pageKeyExpr  = "LOWER(TRIM($pageNameExpr))";
        $nameKeyExpr  = "LOWER(TRIM(pc.full_name))";

        $macroPairs = DB::table('macro_output')
            ->selectRaw("LOWER(TRIM(`PAGE`)) as page_key, LOWER(TRIM(`fb_name`)) as name_key")
            ->whereNotNull('PAGE')
            ->whereRaw("TRIM(`PAGE`) <> ''")
            ->whereNotNull('fb_name')
            ->whereRaw("TRIM(`fb_name`) <> ''")
            ->groupByRaw("LOWER(TRIM(`PAGE`)), LOWER(TRIM(`fb_name`))");

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

        $all = $q->orderBy('pc.created_at', 'desc')->get();

        $rows = $all->map(function ($r) {
            return [
                'date_created'   => (string) ($r->date_created ?? ''),
                'page'           => (string) ($r->page ?? ''),
                'full_name'      => (string) ($r->full_name ?? ''),
                'phone_number'   => (string) ($this->extractPhoneNumber((string) ($r->customers_chat ?? '')) ?? ''),
                'shop_details'   => (string) ($r->shop_details ?? ''),
                'customers_chat' => (string) ($r->customers_chat ?? ''),
            ];
        });

        return response()->json([
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
            'count'     => $rows->count(),
            'rows'      => $rows,
        ]);
    }

    private function extractPhoneNumber(string $text): ?string
    {
        if ($text === '') return null;

        if (!preg_match_all('/(?:\+?63|0)?\s*9[\d\s\-]{8,14}/i', $text, $m)) {
            return null;
        }

        foreach ($m[0] as $raw) {
            $digits = preg_replace('/\D+/', '', $raw);
            if ($digits === '') continue;

            if (strlen($digits) === 12 && str_starts_with($digits, '63') && substr($digits, 2, 1) === '9') {
                $digits = '0' . substr($digits, 2);
            }

            if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
                $digits = '0' . $digits;
            }

            if (strlen($digits) === 11 && str_starts_with($digits, '09')) {
                return $digits;
            }
        }

        return null;
    }
}
