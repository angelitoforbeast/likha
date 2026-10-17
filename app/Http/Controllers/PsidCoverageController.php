<?php

namespace App\Http\Controllers;

use App\Models\PageSetting;
use App\Support\NavAccess;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * /page-settings/psid-coverage: ilang order ang may Botcake PSID kada page sa isang date range (max 31 araw), at
 * ang mga wala, paginated 50. CEO lang (404 sa iba). Malaki ang macro_output: iisang grouped query ang summary, at
 * ang listahan ay laging paginated. Ang dahilan (ambiguous / no match / maikakabit pa) ay kinukwenta on demand sa SQL
 * mula sa available na Botcake customers ng page (naka-index na name_key / phone_key), gaya ng matching.
 */
class PsidCoverageController extends Controller
{
    public const MAX_DAYS = 31;
    public const JNT = ['all', 'In Transit', 'Delivering'];
    public const REASONS = ['all', 'ambiguous', 'no_match', 'not_synced'];
    public const PSID = ['all', 'with', 'without'];

    /** Walang PSID: NULL o ''. Naka-COALESCE para hindi piliin ang botcake_psid index (halos lahat ay NULL). */
    private const EMPTY_PSID = "COALESCE(mo.botcake_psid, '') = ''";

    private function getNormalizedRole(): string
    {
        $raw = Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '';
        return preg_replace('/\s+/u', ' ', trim((string) $raw));
    }

    private function checkAccess(): void
    {
        // CEO, o ang role na naka-check sa /owner/nav-settings para sa Page Settings (404 sa iba).
        if (!NavAccess::allows($this->getNormalizedRole(), '/page-settings')) abort(404);
    }

    private function trimFn(): string
    {
        return DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';
    }

    private function col(string $name): string
    {
        return DB::getDriverName() === 'pgsql' ? 'mo."' . $name . '"' : 'mo.`' . $name . '`';
    }

    private function pageExpr(): string
    {
        return 'LOWER(' . $this->trimFn() . '(' . $this->col('PAGE') . '))';
    }

    /** @return array{0:string, 1:string} [from, to] (Y-m-d, Manila); default huling 7 araw, max 31 araw */
    private function range(Request $request): array
    {
        $today = Carbon::now('Asia/Manila')->startOfDay();
        $parse = function ($v) {
            try {
                return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? Carbon::createFromFormat('Y-m-d', $v, 'Asia/Manila')->startOfDay() : null;
            } catch (\Throwable $e) {
                return null;
            }
        };

        $to   = $parse($request->query('to')) ?? $today;
        $from = $parse($request->query('from')) ?? $to->copy()->subDays(6);
        if ($from->gt($to)) [$from, $to] = [$to, $from];
        if ($from->diffInDays($to) > self::MAX_DAYS - 1) $from = $to->copy()->subDays(self::MAX_DAYS - 1);

        return [$from->toDateString(), $to->toDateString()];
    }


    /** Isang grouped query: bawat page, ilang order at ilan ang may PSID. */
    public function summaryQuery(string $from, string $to): Builder
    {
        $empty = "(mo.botcake_psid IS NULL OR mo.botcake_psid = '')";

        return DB::table('macro_output as mo')
            ->whereBetween('mo.ts_date', [$from, $to])
            ->whereNotNull('mo.PAGE')
            ->selectRaw($this->pageExpr() . ' AS page_key')
            ->selectRaw('MIN(' . $this->trimFn() . '(' . $this->col('PAGE') . ')) AS page_name')
            ->selectRaw('COUNT(*) AS orders')
            ->selectRaw("SUM(CASE WHEN $empty THEN 0 ELSE 1 END) AS with_psid")
            ->groupByRaw($this->pageExpr())
            ->orderBy('page_name');
    }

    /** Ang fb_name ng order na naka-normalise gaya ng BotcakeCustomerMatcher::normName (trim, lower, isang espasyo). */
    private function nameExpr(): string
    {
        $x = 'TRIM(' . $this->col('fb_name') . ')';
        for ($i = 0; $i < 3; $i++) $x = "REPLACE($x, '  ', ' ')"; // hanggang 8 magkakasunod na espasyo
        return "LOWER($x)";
    }

    /** Ang phone ng order bilang 09XXXXXXXXX, gaya ng BotcakeCustomerMatcher::normPhone. */
    private function phoneExpr(): string
    {
        $d = $this->col('PHONE NUMBER');
        foreach ([' ', '-', '+', '(', ')', '.'] as $ch) $d = "REPLACE($d, '$ch', '')";
        $cat = fn ($a, $b) => DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb' ? "CONCAT($a, $b)" : "($a || $b)";

        return "CASE WHEN LENGTH($d) = 12 AND SUBSTR($d, 1, 3) = '639' THEN " . $cat("'0'", "SUBSTR($d, 3)")
            . " WHEN LENGTH($d) = 10 AND SUBSTR($d, 1, 1) = '9' THEN " . $cat("'0'", $d)
            . " ELSE $d END";
    }

    /** Ang pangalan ng page gaya ng nasa Botcake settings (iyon ang naitala sa botcake_psids.page), o null. */
    public function apiPageName(string $pageKey): ?string
    {
        $trim = $this->trimFn();
        return PageSetting::where('provider', 'botcake')->where('active', true)
            ->whereRaw("LOWER($trim(page_name)) = ?", [$pageKey])->orderBy('id')->value('page_name');
    }

    /**
     * Ang orders ng isang page (lahat, may PSID, o wala), pinakabago muna. Ang walang PSID ay may dahilan; ang may
     * PSID ay reason = null. $apiPage = null: walang Botcake customers ang page, kaya "hindi pa nasusuri" ang wala.
     * @param bool $withReasonFilter false = para sa bilang kada dahilan
     * @param string $psid all | with | without
     */
    public function ordersQuery(string $pageKey, ?string $apiPage, string $from, string $to, string $jnt, string $reason, bool $withReasonFilter = true, string $psid = 'all'): Builder
    {
        $q = DB::table('macro_output as mo')
            ->whereBetween('mo.ts_date', [$from, $to])
            ->whereRaw($this->pageExpr() . ' = ?', [$pageKey])
            ->select('mo.id', 'mo.ts_date', 'mo.fb_name', 'mo.waybill', 'mo.botcake_psid', 'mo.botcake_ad_id')
            ->selectRaw($this->col('PHONE NUMBER') . ' AS phone')
            ->selectSub(fn ($s) => $s->from('from_jnts as js')->whereColumn('js.waybill_number', 'mo.waybill')->selectRaw('MAX(js.status)'), 'jnt_status');

        if ($jnt !== 'all') {
            // Kapareho ng /jnt status: ang Delivering ay walang RTS reason.
            $q->whereExists(function ($e) use ($jnt) {
                $e->from('from_jnts as j')->whereColumn('j.waybill_number', 'mo.waybill')->where('j.status', $jnt)->selectRaw('1');
                if ($jnt === 'Delivering') {
                    $e->where(fn ($r) => $r->whereNull('j.rts_reason')->orWhereRaw("TRIM(j.rts_reason) = ''"));
                }
            });
        }

        if ($psid === 'with') $q->whereRaw('NOT (' . self::EMPTY_PSID . ')');
        if ($psid === 'without') $q->whereRaw(self::EMPTY_PSID);

        if ($apiPage === null) {
            $withReason = DB::query()->fromSub($q, 'o')->select('o.*')
                ->selectRaw("CASE WHEN COALESCE(o.botcake_psid, '') = '' THEN 'not_synced' END AS reason");
        } else {
            // Bilang ng available na customer na tugma sa pangalan, sa phone, at sa dalawa; bawat isa ay index lookup,
            // at para lang sa walang PSID (ang may PSID ay 0, hindi na hinahanap).
            $count = fn (string $where) => '(CASE WHEN ' . self::EMPTY_PSID . " THEN (SELECT COUNT(DISTINCT b.psid) FROM botcake_psids b WHERE b.source = 'api' AND b.page = ? AND b.status = 'available' AND $where) ELSE 0 END)";
            $name  = 'b.name_key = ' . $this->nameExpr();
            $phone = 'b.phone_key = ' . $this->phoneExpr();
            $q->selectRaw($count($name) . ' AS n_name', [$apiPage])
              ->selectRaw($count($phone) . ' AS n_phone', [$apiPage])
              ->selectRaw($count("$name AND $phone") . ' AS n_both', [$apiPage]);

            // Gaya ng BotcakeCustomerMatcher::decide: ang "maikakabit" ay hindi pa lang dinadaanan ng matching.
            $withReason = DB::query()->fromSub($q, 'o')
                ->select('o.id', 'o.ts_date', 'o.fb_name', 'o.waybill', 'o.phone', 'o.jnt_status', 'o.botcake_psid', 'o.botcake_ad_id')
                ->selectRaw("CASE
                    WHEN COALESCE(o.botcake_psid, '') <> '' THEN NULL
                    WHEN o.n_name = 1 THEN 'not_synced'
                    WHEN o.n_name > 1 THEN CASE WHEN o.n_both = 1 THEN 'not_synced' ELSE 'ambiguous' END
                    WHEN o.n_phone = 1 THEN 'not_synced'
                    WHEN o.n_phone > 1 THEN 'ambiguous'
                    ELSE 'no_match' END AS reason");
        }

        $out = DB::query()->fromSub($withReason, 'r');
        if ($withReasonFilter && $reason !== 'all') $out->where('r.reason', $reason);

        return $out->orderByDesc('r.ts_date')->orderByDesc('r.id');
    }
    public function index(Request $request)
    {
        $this->checkAccess();
        [$from, $to] = $this->range($request);

        $summary = $this->summaryQuery($from, $to)->get()->map(function ($r) {
            $orders  = (int) $r->orders;
            $with    = (int) $r->with_psid;
            $without = $orders - $with;

            return [
                'page_key'     => (string) $r->page_key,
                'page_name'    => (string) $r->page_name,
                'orders'       => $orders,
                'with_psid'    => $with,
                'without_psid' => $without,
                'pct'          => $orders ? round($with * 100 / $orders, 1) : 0.0,
            ];
        })->all();

        return view('page_settings.psid_coverage', compact('summary', 'from', 'to'));
    }

    public function orders(Request $request)
    {
        $this->checkAccess();
        [$from, $to] = $this->range($request);

        $pageName = preg_replace('/\s+/u', ' ', trim((string) $request->query('page_name', '')));
        $jnt      = in_array($request->query('jnt'), self::JNT, true) ? $request->query('jnt') : 'all';
        $reason   = in_array($request->query('reason'), self::REASONS, true) ? $request->query('reason') : 'all';
        $psid     = in_array($request->query('psid'), self::PSID, true) ? $request->query('psid') : 'all';

        $pageKey = mb_strtolower($pageName, 'UTF-8');
        $apiPage = $this->apiPageName($pageKey);

        $orders = $this->ordersQuery($pageKey, $apiPage, $from, $to, $jnt, $reason, true, $psid)->paginate(50)->withQueryString();

        // Bilang ng may PSID at kada dahilan ng wala, para sa page, range at J&T filter na ipinapakita (isang grouped query).
        $split = ['with' => 0, 'ambiguous' => 0, 'no_match' => 0, 'not_synced' => 0];
        $counts = DB::query()->fromSub($this->ordersQuery($pageKey, $apiPage, $from, $to, $jnt, 'all', false)->reorder(), 'x')
            ->selectRaw('x.reason, COUNT(*) AS c')->groupBy('x.reason')->get();
        foreach ($counts as $row) $split[$row->reason ?? 'with'] = (int) $row->c;

        $adNames = $this->adNames(collect($orders->items())->pluck('botcake_ad_id')->filter()->unique()->values()->all());

        return view('page_settings.psid_coverage_orders', compact('orders', 'split', 'adNames', 'pageName', 'from', 'to', 'jnt', 'reason', 'psid'));
    }

    /**
     * Pangalan ng bawat ad mula sa pinakahuling row nito sa ads_manager_reports (campaign / ad set / headline).
     * Isang query para sa lahat ng ad id na nakikita.
     * @param array<int, string> $adIds
     * @return array<string, string>
     */
    private function adNames(array $adIds): array
    {
        if (!$adIds) return [];

        return DB::table('ads_manager_reports')
            ->whereIn('id', fn ($q) => $q->from('ads_manager_reports')->whereIn('ad_id', $adIds)->selectRaw('MAX(id)')->groupBy('ad_id'))
            ->get(['ad_id', 'campaign_name', 'ad_set_name', 'headline'])
            ->mapWithKeys(fn ($r) => [(string) $r->ad_id => implode(' / ', array_filter([$r->campaign_name, $r->ad_set_name, $r->headline], fn ($v) => trim((string) $v) !== ''))])
            ->all();
    }
}
