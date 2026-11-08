<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class PancakeRetrieve2Controller extends Controller
{
    private const TZ = 'Asia/Manila';

    public function index(Request $request)
    {
        [$preset, $dateFrom, $dateTo] = $this->range($request);

        $pageContains = trim((string) $request->get('page_contains', ''));
        $nameContains = trim((string) $request->get('name_contains', ''));

        $rows = $this->retrieveQuery($dateFrom, $dateTo, $pageContains, $nameContains)
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
            'tz'            => self::TZ,
        ]);
    }

    /**
     * ✅ Export ALL rows (no pagination) for current filters/date range.
     * Returns JSON rows.
     */
    public function export(Request $request)
    {
        [, $dateFrom, $dateTo] = $this->range($request);

        $pageContains = trim((string) $request->get('page_contains', ''));
        $nameContains = trim((string) $request->get('name_contains', ''));

        $all = $this->retrieveQuery($dateFrom, $dateTo, $pageContains, $nameContains)->get();

        $rows = $all->map(function ($r) {
            return [
                'date_created'   => (string) ($r->date_created ?? ''),
                'page'           => (string) ($r->page ?? ''),
                'full_name'      => (string) ($r->full_name ?? ''),
                'psid'           => $r->psid !== null ? (string) $r->psid : null,
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

    /**
     * Manila date range from the request: explicit dates win, else the preset, else yesterday.
     *
     * @return array{0: string, 1: string, 2: string} [preset, date_from, date_to]
     */
    private function range(Request $request): array
    {
        // Support legacy preset=month
        $preset = (string) $request->get('preset', '');
        if ($preset === 'month') {
            $preset = 'this_month';
        }

        $dateFrom = $request->get('date_from');
        $dateTo   = $request->get('date_to');

        if ($dateFrom && $dateTo) {
            return [$preset, Carbon::parse($dateFrom, self::TZ)->toDateString(), Carbon::parse($dateTo, self::TZ)->toDateString()];
        }

        $now = Carbon::now(self::TZ);

        // Default = yesterday
        $dateFrom = $dateTo = $now->copy()->subDay()->toDateString();

        if ($preset === 'today') {
            $dateFrom = $dateTo = $now->toDateString();
        } elseif ($preset === 'last7') {
            $dateFrom = $now->copy()->subDays(6)->toDateString();
            $dateTo   = $now->toDateString();
        } elseif ($preset === 'this_month') {
            // ✅ Month up to yesterday
            $dateFrom  = $now->copy()->startOfMonth()->toDateString();
            $yesterday = $now->copy()->subDay();
            $dateTo = $yesterday->lt($now->copy()->startOfMonth())
                ? $now->copy()->startOfMonth()->toDateString()
                : $yesterday->toDateString();
        }

        return [$preset, $dateFrom, $dateTo];
    }

    /**
     * The one query behind the page and the export: conversations in the Manila date range that are not an order yet.
     */
    private function retrieveQuery(string $dateFrom, string $dateTo, string $pageContains, string $nameContains)
    {
        // created_at is stored as Manila wall-clock time (the upload job writes now() in Asia/Manila and the MySQL
        // session is UTC, so the TIMESTAMP comes back unchanged). The Manila dates are therefore the bounds as they
        // are, with no conversion; comparing the bare column keeps the created_at index in use (spec 247, spec 248).
        $fromBound = Carbon::parse($dateFrom, self::TZ)->startOfDay()->format('Y-m-d H:i:s');
        $toBound   = Carbon::parse($dateTo, self::TZ)->addDay()->startOfDay()->format('Y-m-d H:i:s');

        $convDateExpr     = "DATE(pc.created_at)";
        $convDateTimeExpr = "pc.created_at";

        // Page name expression used for display + matching
        $pageNameExpr = "COALESCE(pi.pancake_page_name, pc.pancake_page_id)";
        $pageKeyExpr  = "LOWER(TRIM($pageNameExpr))";

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
            ->leftJoinSub($shopMode, 'sm', function ($join) use ($convDateExpr, $pageKeyExpr) {
                $join->on('sm.ts_date', '=', DB::raw($convDateExpr))
                     ->on('sm.page_key', '=', DB::raw($pageKeyExpr));
            })
            ->where(fn ($w) => $this->notYetAnOrder($w))
            ->where('pc.created_at', '>=', $fromBound)
            ->where('pc.created_at', '<', $toBound);

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
            {$this->psidSelect()},
            pc.customers_chat
        ");

        return $q->orderBy('pc.created_at', 'desc');
    }

    /**
     * No order has this conversation's page and name. The bare columns keep the (PAGE, fb_name) index usable:
     * MySQL's utf8mb4_unicode_ci already ignores letter case and trailing spaces in "=", so only the
     * conversation side is trimmed. The old LOWER(TRIM()) also matched order names with leading spaces; on
     * production those are a single space, so the name is probed as given and with one space in front, which
     * is two index lookups instead of a scan. A name with two or more leading spaces is not matched (none exist).
     * sqlite (the tests) compares binary, so it gets NOCASE there; in an IN list sqlite takes it from the left.
     */
    private function nameNotYetAnOrderSql(): string
    {
        $ci = DB::getDriverName() === 'sqlite' ? ' COLLATE NOCASE' : '';

        return "(NOT EXISTS (
                SELECT 1 FROM macro_output mo
                WHERE mo.`PAGE` = TRIM(COALESCE(pi.pancake_page_name, pc.pancake_page_id))$ci
                  AND mo.`fb_name`$ci IN (TRIM(pc.full_name), CONCAT(' ', TRIM(pc.full_name)))
                  AND TRIM(mo.`PAGE`) <> ''
                  AND TRIM(mo.`fb_name`) <> ''
            ))";
    }

    /** May PSID ba ang conversations at macro_output? Kung wala pa (hindi pa na-migrate), pangalan lang ang rule gaya ng dati. */
    private function psidRuleAvailable(): bool
    {
        return Schema::hasColumn('pancake_conversations', 'psid') && Schema::hasColumn('macro_output', 'botcake_psid');
    }

    private function psidSelect(): string
    {
        return Schema::hasColumn('pancake_conversations', 'psid') ? 'pc.psid as psid' : 'NULL as psid';
    }

    /**
     * Hindi pa order ang conversation: may PSID -> walang macro_output row na may ganoong botcake_psid
     * (at sa parehong page kung alam ang page ayon sa mapping); walang PSID -> walang (PAGE + fb_name) na tugma.
     * Isang query pa rin: ang EXISTS ay gumagamit ng index ng macro_output.botcake_psid.
     */
    private function notYetAnOrder($w): void
    {
        if (!$this->psidRuleAvailable()) {
            $w->whereRaw($this->nameNotYetAnOrderSql());
            return;
        }

        $w->whereRaw("(
            (pc.psid IS NULL AND {$this->nameNotYetAnOrderSql()})
            OR (pc.psid IS NOT NULL AND NOT EXISTS (
                SELECT 1 FROM macro_output mo
                WHERE mo.botcake_psid = pc.psid
                  AND (pi.pancake_page_name IS NULL OR LOWER(TRIM(mo.`PAGE`)) = LOWER(TRIM(pi.pancake_page_name)))
            ))
        )");
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
