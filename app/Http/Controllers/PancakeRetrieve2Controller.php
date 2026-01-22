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
                    // If today is the 1st, keep dateTo = startOfMonth (no negative range)
                    $dateTo = $now->copy()->startOfMonth()->toDateString();
                } else {
                    $dateTo = $yesterday->toDateString();
                }
            }
        } else {
            // Normalize inputs
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

        // If tie: pick deterministic MIN(shop_details)
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

        // -----------------------------
        // ✅ Extract phone number from customers_chat (PHP-side)
        // -----------------------------
        $rows->getCollection()->transform(function ($r) {
            $haystack = trim((string)($r->customers_chat ?? ''));

            // OPTIONAL: minsan nilalagay sa name line yung number; safe i-append
            $haystack2 = trim((string)($r->full_name ?? ''));
            if ($haystack2 !== '') $haystack .= ' ' . $haystack2;

            $r->phone_number = $this->extractPhoneNumber($haystack);
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
     * ✅ Robust PH mobile extractor:
     * - Accepts: 09XXXXXXXXX, 9XXXXXXXXX, +63 9XXXXXXXXX, 63 9XXXXXXXXX
     * - Tolerates: spaces/dashes/newlines and common "O" typed as zero (o/O -> 0)
     * - Normalizes output to: 09XXXXXXXXX
     */
    private function extractPhoneNumber(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') return null;

        // Normalize whitespace
        $text = str_replace(["\r\n", "\n", "\r", "\t"], ' ', $text);

        // Find candidates that might contain PH numbers (includes O/o/I/l confusions)
        preg_match_all('/(?:\+?\s*63|0)?[0-9OoIiLl\-\s\(\)\.]{9,}/u', $text, $m1);
        $candidates = $m1[0] ?? [];

        // Also capture tight tokens (e.g., o992o449oo4)
        preg_match_all('/[0-9OoIiLl]{9,}/u', $text, $m2);
        $candidates = array_merge($candidates, $m2[0] ?? []);

        $seen = [];
        foreach ($candidates as $cand) {
            $cand = trim((string)$cand);
            if ($cand === '' || isset($seen[$cand])) continue;
            $seen[$cand] = true;

            $digits = $this->normalizeDigitsFromCandidate($cand);
            if ($digits === '') continue;

            $mobile = $this->normalizePhilippineMobile($digits);
            if ($mobile !== null) {
                return $mobile;
            }
        }

        return null;
    }

    private function normalizeDigitsFromCandidate(string $cand): string
    {
        // Replace common confusions: o/O->0, i/I/l/L->1
        $cand = strtr($cand, [
            'o' => '0', 'O' => '0',
            'i' => '1', 'I' => '1',
            'l' => '1', 'L' => '1',
        ]);

        $digits = preg_replace('/\D+/', '', $cand);
        return $digits ? (string)$digits : '';
    }

    private function normalizePhilippineMobile(string $digits): ?string
    {
        // Exact forms
        if (preg_match('/^09\d{9}$/', $digits)) return $digits;           // 09XXXXXXXXX
        if (preg_match('/^9\d{9}$/', $digits))  return '0' . $digits;     // 9XXXXXXXXX
        if (preg_match('/^639\d{9}$/', $digits)) return '0' . substr($digits, 2); // 639XXXXXXXXX

        // Embedded in longer digit sequences
        if (preg_match('/(09\d{9})/', $digits, $m)) return $m[1];
        if (preg_match('/(639\d{9})/', $digits, $m)) return '0' . substr($m[1], 2);
        if (preg_match('/(^|[^0-9])(9\d{9})([^0-9]|$)/', $digits, $m)) return '0' . $m[2];

        return null;
    }
}
