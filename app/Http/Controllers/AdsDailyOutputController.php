<?php

namespace App\Http\Controllers;

use App\Support\NavAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * /ads_manager/daily-output (spec 266): ano ang ginawa ng marketing sa ads sa isang araw: bagong ads, pinatay,
 * binuhay ulit, budget na tinaas o binaba, kasama ang campaign at ang promo. Kung sino ang gumawa ay hindi naka-tala.
 *
 * Basa lang, sa live tables: puro SELECT, naka-group sa SQL. Ang ads_manager_reports ay laging sinasala sa `day`
 * (unang column ng unique index nito): D at D-1 para sa araw, at ang 14 araw (+1) para sa maliit na table.
 * Walang bagong table, column o index.
 *
 * Mga kahulugan (per ad_id; spend = SUM(amount_spent_php) ng mga row ng ad sa araw na iyon):
 *   New          ad_catalog.first_started = D
 *   Turned off   spend(D-1) > 0 at spend(D) <= 0 (kasama ang ad na walang row sa D)
 *   Back on      spend(D-1) <= 0 o walang row, spend(D) > 0, at first_started < D
 *   Budget up/dn per ad_set_id, MAX(ad_set_budget) sa D kumpara sa D-1 (parehong may halaga)
 *   Running      spend(D) > 0
 *
 * Spec 268: bawat campaign ay may Item (tugma sa pangalan, o ang main item ng page sa daily_page_primary_item), ang
 * promo ng item na iyon, at ang main flow picture ng page (page_settings chatbot/picture na may bisa noon). Ang araw
 * na ginagamit: D, maliban sa Turned off: D-1 (ang huling araw na gumastos ang ad).
 */
class AdsDailyOutputController extends Controller
{
    public const NAV_URL = '/ads_manager/daily-output';

    public const MINI_DAYS = 14;

    public const KINDS = [
        'new' => 'New',
        'off' => 'Turned off',
        'on' => 'Back on',
        'budget_up' => 'Budget up',
        'budget_down' => 'Budget down',
    ];

    public function index(Request $request)
    {
        $role = preg_replace('/\s+/u', ' ', trim((string) (Auth::user()?->employeeProfile?->role ?? Auth::user()?->role ?? '')));
        if (! NavAccess::allows($role, self::NAV_URL)) {
            abort(403);
        }

        $today = now()->startOfDay();
        $day = $this->day($request) ?? $today->copy();
        $d = $day->toDateString();
        $prev = $day->copy()->subDay()->toDateString();
        $from = $day->copy()->subDays(self::MINI_DAYS - 1)->toDateString();

        $hasReports = Schema::hasTable('ads_manager_reports');
        $ads = $hasReports ? $this->adsOfDay($d, $prev) : [];
        $moves = $hasReports ? $this->spendMoves($from, $d) : [];
        $budgetMoves = $hasReports ? $this->budgetMoves($from, $d) : [];

        // Mga ad na nagsimulang gumastos (D at ang 14 araw): kailangan ang first_started para malaman kung "back on".
        $onIds = [];
        foreach ($ads as $id => $a) {
            if ($a['prev_spend'] <= 0 && $a['spend'] > 0) {
                $onIds[$id] = true;
            }
        }
        foreach ($moves as $m) {
            if ($m['prev'] <= 0 && $m['today'] > 0) {
                $onIds[$m['ad_id']] = true;
            }
        }
        $catalog = Schema::hasTable('ad_catalog') ? $this->catalog($from, $d, array_keys($onIds)) : [];

        // Isang ad = isang item sa listahan ng uri nito.
        $items = array_fill_keys(array_keys(self::KINDS), []);
        $running = ['ads' => 0, 'pages' => []];
        $syncedAt = null;
        foreach ($ads as $id => $a) {
            if ($a['synced_at'] !== null && ($syncedAt === null || $a['synced_at'] > $syncedAt)) {
                $syncedAt = $a['synced_at'];
            }
            if ($a['spend'] > 0) {
                $running['ads']++;
                $running['pages'][mb_strtolower(trim($a['page']))] = true;
            }
            if ($a['prev_spend'] > 0 && $a['spend'] <= 0) {
                $items['off'][] = $a;
            } elseif ($a['prev_spend'] <= 0 && $a['spend'] > 0) {
                $first = $catalog[$id]['first_started'] ?? null;
                if ($first !== null && $first < $d) {
                    $items['on'][] = $a;
                }
            }
        }
        foreach ($catalog as $id => $c) {
            if ($c['first_started'] === $d) {
                $items['new'][] = $ads[$id] ?? $c + ['spend' => 0.0, 'prev_spend' => 0.0];
            }
        }

        // Budget: per ad set, ang pinakamataas na budget ng mga ad nito sa D at sa D-1.
        $sets = [];
        foreach ($ads as $a) {
            $sid = $a['ad_set_id'];
            if ($sid === '') {
                continue;
            }
            $s = $sets[$sid] ?? $a + ['ads' => 0, 'spend' => 0.0, 'prev_spend' => 0.0, 'budget' => null, 'prev_budget' => null];
            $s['ads'] = ($sets[$sid]['ads'] ?? 0) + 1;
            $s['spend'] = ($sets[$sid]['spend'] ?? 0) + $a['spend'];
            $s['prev_spend'] = ($sets[$sid]['prev_spend'] ?? 0) + $a['prev_spend'];
            $s['budget'] = self::maxOf($sets[$sid]['budget'] ?? null, $a['budget']);
            $s['prev_budget'] = self::maxOf($sets[$sid]['prev_budget'] ?? null, $a['prev_budget']);
            $sets[$sid] = $s;
        }
        $budgetSets = ['budget_up' => [], 'budget_down' => []];
        foreach ($sets as $s) {
            if ($s['budget'] === null || $s['prev_budget'] === null || $s['budget'] == $s['prev_budget']) {
                continue;
            }
            $budgetSets[$s['budget'] > $s['prev_budget'] ? 'budget_up' : 'budget_down'][] = $s;
        }

        // Item, promo at larawan (spec 268): isang query bawat isa para sa lahat ng page na nasa listahan.
        $pages = [];
        foreach (array_merge(...array_values($items), ...array_values($budgetSets)) as $e) {
            if ($e['page'] !== '') {
                $pages[mb_strtolower($e['page'])] = true;
            }
        }
        $pages = array_map('strval', array_keys($pages));
        $now = now()->toDateTimeString();
        $atD = min($day->copy()->endOfDay()->toDateTimeString(), $now);
        $atPrev = min(Carbon::createFromFormat('!Y-m-d', $prev)->endOfDay()->toDateTimeString(), $now);
        $ctx = [
            'settings' => $this->itemSettings($d, $pages),
            'primary' => $this->primaryItems($day->copy()->subDays(self::MINI_DAYS)->toDateString(), $d, $pages),
            'pictures' => $this->pictures($pages, $atPrev, $atD),
        ];

        // Turned off: ang huling araw na gumastos ang ad (D-1); ang iba: D (o ngayon, para sa larawan ng araw na ito).
        $lists = [];
        foreach (array_keys(self::KINDS) as $kind) {
            [$on, $at] = $kind === 'off' ? [$prev, $atPrev] : [$d, $atD];
            $lists[$kind] = isset($budgetSets[$kind])
                ? $this->group($budgetSets[$kind], $ctx, true, $on, $at)
                : $this->group($items[$kind], $ctx, false, $on, $at);
        }

        $offCampaigns = count(array_unique(array_map(fn ($a) => $a['page'].'|'.$a['campaign_id'].'|'.$a['campaign'], $items['off'])));

        return view('ads_manager.daily_output', [
            'day' => $d,
            'prevDay' => $prev,
            'nextDay' => $day->lt($today) ? $day->copy()->addDay()->toDateString() : null,
            'isToday' => $d === $today->toDateString(),
            'syncedAt' => $syncedAt,
            'tiles' => [
                'new' => count($items['new']),
                'off' => count($items['off']),
                'off_campaigns' => $offCampaigns,
                'on' => count($items['on']),
                'budget_up' => count($budgetSets['budget_up']),
                'budget_down' => count($budgetSets['budget_down']),
                'running_ads' => $running['ads'],
                'running_pages' => count($running['pages']),
            ],
            'lists' => $lists,
            'mini' => $this->mini($from, $d, $moves, $budgetMoves, $catalog),
        ]);
    }

    /** ?day=Y-m-d; mali o wala → null (ngayon). */
    private function day(Request $request): ?Carbon
    {
        $v = $request->query('day');
        if (! is_string($v) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }
        try {
            return Carbon::createFromFormat('!Y-m-d', $v);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Isang row bawat ad na may row sa D o D-1: spend at budget ng bawat araw, pangalan, at huling sync ng D.
     *
     * @return array<string, array>
     */
    private function adsOfDay(string $d, string $prev): array
    {
        $rows = DB::table('ads_manager_reports')
            ->selectRaw('ad_id,
                MAX(page_name) AS page_name, MAX(campaign_id) AS campaign_id, MAX(campaign_name) AS campaign_name,
                MAX(ad_set_id) AS ad_set_id, MAX(ad_set_name) AS ad_set_name,
                SUM(CASE WHEN day = ? THEN COALESCE(amount_spent_php, 0) ELSE 0 END) AS spend,
                SUM(CASE WHEN day = ? THEN COALESCE(amount_spent_php, 0) ELSE 0 END) AS prev_spend,
                MAX(CASE WHEN day = ? THEN ad_set_budget END) AS budget,
                MAX(CASE WHEN day = ? THEN ad_set_budget END) AS prev_budget,
                MAX(CASE WHEN day = ? THEN updated_at END) AS synced_at', [$d, $prev, $d, $prev, $d])
            ->whereBetween('day', [$prev, $d])
            ->where('ad_id', '<>', '')
            ->groupBy('ad_id')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->ad_id] = [
                'ad_id' => (string) $r->ad_id,
                'page' => trim((string) $r->page_name),
                'campaign_id' => (string) ($r->campaign_id ?? ''),
                'campaign' => (string) ($r->campaign_name ?? ''),
                'ad_set_id' => (string) ($r->ad_set_id ?? ''),
                'ad_set' => (string) ($r->ad_set_name ?? ''),
                'spend' => (float) $r->spend,
                'prev_spend' => (float) $r->prev_spend,
                'budget' => $r->budget === null ? null : (float) $r->budget,
                'prev_budget' => $r->prev_budget === null ? null : (float) $r->prev_budget,
                'synced_at' => $r->synced_at === null ? null : (string) $r->synced_at,
            ];
        }

        return $out;
    }

    /**
     * `CASE day WHEN <araw> THEN <kinabukasan> ...` para sa bawat araw ng D-1 hanggang D-14: ang row ng isang araw ay
     * ang "kahapon" ng susunod na araw. Literal na petsa (walang DATE_ADD) para pareho sa MySQL at SQLite.
     *
     * @return array{0: string, 1: array}
     */
    private function nextDayCase(string $from): array
    {
        $sql = 'CASE day';
        $bindings = [];
        $p = Carbon::createFromFormat('!Y-m-d', $from)->subDay();
        for ($i = 0; $i < self::MINI_DAYS; $i++, $p->addDay()) {
            $sql .= ' WHEN ? THEN ?';
            $bindings[] = $p->toDateString();
            $bindings[] = $p->copy()->addDay()->toDateString();
        }

        return [$sql.' END', $bindings];
    }

    /**
     * 14 araw: bawat (araw, ad) na nagbago ang lagay (gumastos kahapon at hindi ngayon, o kabaligtaran). Naka-group sa
     * SQL; ang PHP ay tumatanggap lang ng mga nagbago (ilang dosena kada araw), hindi ang ~10k row.
     *
     * @return array<int, array{pd: string, ad_id: string, today: float, prev: float}>
     */
    private function spendMoves(string $from, string $d): array
    {
        [$case, $caseBindings] = $this->nextDayCase($from);
        $prevFrom = Carbon::createFromFormat('!Y-m-d', $from)->subDay()->toDateString();
        $prevTo = Carbon::createFromFormat('!Y-m-d', $d)->subDay()->toDateString();

        $today = DB::table('ads_manager_reports')
            ->selectRaw('CAST(day AS CHAR) AS pd, ad_id, COALESCE(amount_spent_php, 0) AS today, 0 AS prev')
            ->whereBetween('day', [$from, $d])
            ->where('ad_id', '<>', '');
        $yesterday = DB::table('ads_manager_reports')
            ->selectRaw($case.' AS pd, ad_id, 0 AS today, COALESCE(amount_spent_php, 0) AS prev', $caseBindings)
            ->whereBetween('day', [$prevFrom, $prevTo])
            ->where('ad_id', '<>', '');

        $rows = DB::query()->fromSub($today->unionAll($yesterday), 'u')
            ->selectRaw('pd, ad_id, SUM(today) AS today, SUM(prev) AS prev')
            ->groupBy('pd', 'ad_id')
            ->havingRaw('(SUM(prev) > 0 AND SUM(today) <= 0) OR (SUM(prev) <= 0 AND SUM(today) > 0)')
            ->get();

        return $rows->map(fn ($r) => [
            'pd' => (string) $r->pd, 'ad_id' => (string) $r->ad_id, 'today' => (float) $r->today, 'prev' => (float) $r->prev,
        ])->all();
    }

    /**
     * 14 araw: bawat (araw, ad set) na iba ang MAX(ad_set_budget) kaysa kahapon. Naka-group sa SQL.
     *
     * @return array<int, array{pd: string, ad_set_id: string, budget: float, prev_budget: float}>
     */
    private function budgetMoves(string $from, string $d): array
    {
        [$case, $caseBindings] = $this->nextDayCase($from);
        $prevFrom = Carbon::createFromFormat('!Y-m-d', $from)->subDay()->toDateString();
        $prevTo = Carbon::createFromFormat('!Y-m-d', $d)->subDay()->toDateString();

        $today = DB::table('ads_manager_reports')
            ->selectRaw('CAST(day AS CHAR) AS pd, ad_set_id, ad_set_budget AS bt, NULL AS bp')
            ->whereBetween('day', [$from, $d])
            ->where('ad_id', '<>', '')
            ->where('ad_set_id', '<>', '');
        $yesterday = DB::table('ads_manager_reports')
            ->selectRaw($case.' AS pd, ad_set_id, NULL AS bt, ad_set_budget AS bp', $caseBindings)
            ->whereBetween('day', [$prevFrom, $prevTo])
            ->where('ad_id', '<>', '')
            ->where('ad_set_id', '<>', '');

        $rows = DB::query()->fromSub($today->unionAll($yesterday), 'u')
            ->selectRaw('pd, ad_set_id, MAX(bt) AS budget, MAX(bp) AS prev_budget')
            ->groupBy('pd', 'ad_set_id')
            ->havingRaw('MAX(bt) IS NOT NULL AND MAX(bp) IS NOT NULL AND MAX(bt) <> MAX(bp)')
            ->get();

        return $rows->map(fn ($r) => [
            'pd' => (string) $r->pd, 'ad_set_id' => (string) $r->ad_set_id,
            'budget' => (float) $r->budget, 'prev_budget' => (float) $r->prev_budget,
        ])->all();
    }

    /**
     * ad_catalog: ang mga ad na unang nakita sa 14 araw (New) at ang first_started ng mga nagsimulang gumastos.
     *
     * @param  string[]  $adIds
     * @return array<string, array>
     */
    private function catalog(string $from, string $d, array $adIds): array
    {
        $rows = DB::table('ad_catalog')
            ->select('ad_id', 'first_started', 'page_name', 'campaign_id', 'campaign_name', 'ad_set_id', 'ad_set_name')
            ->where(function ($w) use ($from, $d, $adIds) {
                $w->whereBetween('first_started', [$from, $d]);
                if ($adIds) {
                    $w->orWhereIn('ad_id', $adIds);
                }
            })
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->ad_id] = [
                'ad_id' => (string) $r->ad_id,
                'first_started' => $r->first_started === null ? null : substr((string) $r->first_started, 0, 10),
                'page' => trim((string) $r->page_name),
                'campaign_id' => (string) ($r->campaign_id ?? ''),
                'campaign' => (string) ($r->campaign_name ?? ''),
                'ad_set_id' => (string) ($r->ad_set_id ?? ''),
                'ad_set' => (string) ($r->ad_set_name ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Ang mga item ng bawat page na nasa listahan at ang kasaysayan ng promo nila hanggang D (effective_date <= D),
     * luma muna: ang promo sa isang araw ay ang huling row na effective_date <= araw na iyon.
     *
     * @param  string[]  $pages  lowercase
     * @return array<string, array<string, array<int, array{0: string, 1: string}>>> page => item_name => [[effective, promo], ...]
     */
    private function itemSettings(string $d, array $pages): array
    {
        if (! $pages || ! Schema::hasTable('page_item_settings') || ! Schema::hasColumn('page_item_settings', 'promo')) {
            return [];
        }

        $rows = DB::table('page_item_settings')
            ->select('page_name', 'item_name', 'promo', 'effective_date')
            ->where('effective_date', '<=', $d)
            ->whereRaw('LOWER(TRIM(page_name)) IN ('.implode(', ', array_fill(0, count($pages), '?')).')', $pages)
            ->orderBy('effective_date')
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[mb_strtolower(trim((string) $r->page_name))][(string) $r->item_name][] = [substr((string) $r->effective_date, 0, 10), (string) $r->promo];
        }

        return $out;
    }

    /**
     * daily_page_primary_item: ang item na pinakamaraming benta ng page bawat araw, mula $from hanggang D, pinakabago
     * muna. Pinupuno ito ng bawat encode (LiveOrder, imports) at ng 03:50 refresh, kaya maaga sa araw ay maaaring wala
     * pang row ang araw: ang pinakahuling naunang row ang gagamitin (may tanda).
     *
     * @param  string[]  $pages  lowercase (= page_key)
     * @return array<string, array<string, string>> page_key => ts_date => primary_item
     */
    private function primaryItems(string $from, string $d, array $pages): array
    {
        if (! $pages || ! Schema::hasTable('daily_page_primary_item')) {
            return [];
        }

        $rows = DB::table('daily_page_primary_item')
            ->select('ts_date', 'page_key', 'primary_item')
            ->whereIn('page_key', $pages)
            ->whereBetween('ts_date', [$from, $d])
            ->orderByDesc('ts_date')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $item = trim((string) $r->primary_item);
            if ($item !== '') {
                $out[(string) $r->page_key][substr((string) $r->ts_date, 0, 10)] = $item;
            }
        }

        return $out;
    }

    /**
     * page_settings (chatbot, picture): ang main flow picture ng bawat page na may bisa sa pagitan ng $from at $to
     * (created_at <= $to at hindi pa napalitan bago $from), luma muna.
     *
     * @param  string[]  $pages  lowercase
     * @return array<string, array<int, array{0: string, 1: ?string, 2: string}>> page => [[created_at, replaced_at, value], ...]
     */
    private function pictures(array $pages, string $from, string $to): array
    {
        if (! $pages || ! Schema::hasTable('page_settings')) {
            return [];
        }

        $rows = DB::table('page_settings')
            ->select('page_name', 'value', 'created_at', 'replaced_at')
            ->where('provider', 'chatbot')
            ->where('key', 'picture')
            ->where('is_secret', false)
            ->whereRaw('LOWER(TRIM(page_name)) IN ('.implode(', ', array_fill(0, count($pages), '?')).')', $pages)
            ->where('created_at', '<=', $to)
            ->where(fn ($w) => $w->whereNull('replaced_at')->orWhere('replaced_at', '>', $from))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[mb_strtolower(trim((string) $r->page_name))][] = [
                (string) $r->created_at, $r->replaced_at === null ? null : (string) $r->replaced_at, trim((string) $r->value),
            ];
        }

        return $out;
    }

    /**
     * Ang item, promo at larawan ng isang campaign sa araw na $on (larawan: ang may bisa sa oras na $at).
     *   Item: ang nag-iisang item ng page na tugma sa pangalan ng campaign (ang matcher ng 266); kung wala o higit sa
     *         isa, ang main item ng page sa $on (o ang pinakahuling naunang araw, may tanda).
     *   Promo: ang promo ng item na iyon (pinakabagong effective_date <= $on); 'NONE' o wala → null.
     *
     * @return array{item: ?string, item_from: ?string, item_day: ?string, item_target: string, promo: ?string, picture: ?string}
     */
    private function describe(string $page, string $campaign, string $on, string $at, array $ctx): array
    {
        $pk = mb_strtolower(trim($page));

        $promos = []; // item_name => promo sa $on
        foreach ($ctx['settings'][$pk] ?? [] as $name => $history) {
            foreach ($history as [$effective, $promo]) {
                if ($effective <= $on) {
                    $promos[(string) $name] = $promo;
                }
            }
        }

        $item = $from = $itemDay = null;
        $matches = self::matchedItems($campaign, array_map('strval', array_keys($promos)));
        if (count($matches) === 1) {
            [$item, $from] = [$matches[0], 'campaign'];
        } else {
            foreach ($ctx['primary'][$pk] ?? [] as $tsDate => $primary) {
                if ((string) $tsDate <= $on) {
                    [$item, $from, $itemDay] = [$primary, 'main', (string) $tsDate];
                    break;
                }
            }
        }

        $promo = null;
        if ($item !== null) {
            foreach ($promos as $name => $p) {
                if (self::itemKey((string) $name) === self::itemKey($item)) {
                    $p = trim($p);
                    $promo = ($p === '' || strtoupper($p) === 'NONE') ? null : $p;
                }
            }
        }

        $picture = null;
        foreach ($ctx['pictures'][$pk] ?? [] as [$created, $replaced, $value]) {
            if ($created <= $at && ($replaced === null || $replaced > $at)) {
                $picture = $value;
            }
        }
        if ($picture !== null && (! preg_match('#^https?://#i', $picture) || filter_var($picture, FILTER_VALIDATE_URL) === false)) {
            $picture = null;
        }

        return ['item' => $item, 'item_from' => $from, 'item_day' => $itemDay, 'item_target' => $on, 'promo' => $promo, 'picture' => $picture];
    }

    /** Para itapat ang main item sa item ng page_item_settings: trim, isang espasyo, uppercase. */
    private static function itemKey(string $item): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', ' ', trim($item)));
    }

    /**
     * Mga item → page → campaign → mga row per ad set (bilang ng ads, spend kahapon/ngayon, budget), may item, promo
     * at larawan per campaign. Naka-ayos ayon sa page, campaign at ad set.
     */
    private function group(array $entries, array $ctx, bool $isBudget, string $on, string $at): array
    {
        $out = [];
        foreach ($entries as $e) {
            $page = $e['page'] !== '' ? $e['page'] : '(walang page)';
            $campaign = $e['campaign'] !== '' ? $e['campaign'] : 'Campaign '.$e['campaign_id'];
            $setKey = $e['ad_set_id'].'|'.$e['ad_set'];

            if (! isset($out[$page][$campaign])) {
                $out[$page][$campaign] = $this->describe($e['page'], $e['campaign'], $on, $at, $ctx) + ['rows' => []];
            }
            $row = $out[$page][$campaign]['rows'][$setKey] ?? [
                'ad_set' => $e['ad_set'] !== '' ? $e['ad_set'] : ($e['ad_set_id'] !== '' ? 'Ad set '.$e['ad_set_id'] : '—'),
                'ads' => 0, 'prev_spend' => 0.0, 'spend' => 0.0, 'prev_budget' => null, 'budget' => null,
            ];
            if ($isBudget) {
                $row['ads'] = $e['ads'];
                $row['prev_budget'] = $e['prev_budget'];
                $row['budget'] = $e['budget'];
            } else {
                $row['ads']++;
            }
            $row['prev_spend'] += $e['prev_spend'];
            $row['spend'] += $e['spend'];
            $out[$page][$campaign]['rows'][$setKey] = $row;
        }

        ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($out as &$campaigns) {
            ksort($campaigns, SORT_NATURAL | SORT_FLAG_CASE);
            foreach ($campaigns as &$c) {
                uasort($c['rows'], fn ($a, $b) => strnatcasecmp($a['ad_set'], $b['ad_set']));
                $c['rows'] = array_values($c['rows']);
            }
            unset($c);
        }
        unset($campaigns);

        return $out;
    }

    /** Ang 14 araw hanggang D, pinakabago muna: bilang ng New, Off, Back on at Budget changes. */
    private function mini(string $from, string $d, array $moves, array $budgetMoves, array $catalog): array
    {
        $days = [];
        for ($p = Carbon::createFromFormat('!Y-m-d', $d); $p->toDateString() >= $from; $p->subDay()) {
            $days[$p->toDateString()] = ['day' => $p->toDateString(), 'new' => 0, 'off' => 0, 'on' => 0, 'budget_up' => 0, 'budget_down' => 0];
        }
        foreach ($catalog as $c) {
            if ($c['first_started'] !== null && isset($days[$c['first_started']])) {
                $days[$c['first_started']]['new']++;
            }
        }
        foreach ($moves as $m) {
            if (! isset($days[$m['pd']])) {
                continue;
            }
            if ($m['prev'] > 0 && $m['today'] <= 0) {
                $days[$m['pd']]['off']++;
            } else {
                $first = $catalog[$m['ad_id']]['first_started'] ?? null;
                if ($first !== null && $first < $m['pd']) {
                    $days[$m['pd']]['on']++;
                }
            }
        }
        foreach ($budgetMoves as $b) {
            if (isset($days[$b['pd']])) {
                $days[$b['pd']][$b['budget'] > $b['prev_budget'] ? 'budget_up' : 'budget_down']++;
            }
        }

        return array_values($days);
    }

    private static function maxOf(?float $a, ?float $b): ?float
    {
        return $a === null ? $b : ($b === null ? $a : max($a, $b));
    }

    /** Uppercase, walang espasyo, walang "<n> x " sa unahan: "2 x SIDE MIRROR" → "SIDEMIRROR". */
    public static function normalize(string $s): string
    {
        $s = mb_strtoupper(trim($s));
        $s = preg_replace('/^\d+\s*X\s+/u', '', $s);

        return preg_replace('/\s+/u', '', $s);
    }

    /**
     * Ang promo ng campaign: bawat bahagi ng pangalan (hinati sa "|") ay itinatapat sa mga item ng page (pareho, o ang
     * isa ay nasa loob ng isa). Isang promo lang ang lumabas → iyon; wala o higit sa isang magkaibang promo → null
     * (hindi nanghuhula). Ang bahaging kulang sa 3 titik o puro numero (e.g. "FB", "1000") ay hindi itinatapat.
     *
     * @param  array<string, string>  $items  item_name => promo (ng page, sa araw na iyon)
     */
    public static function promoFor(string $campaignName, array $items): ?string
    {
        $promos = [];
        foreach (self::matchedItems($campaignName, array_map('strval', array_keys($items))) as $item) {
            $promos[trim((string) $items[$item])] = true;
        }

        if (count($promos) !== 1) {
            return null;
        }
        $promo = (string) array_key_first($promos);

        return $promo === '' ? null : $promo;
    }

    /**
     * Ang mga item na tugma sa pangalan ng campaign: bawat bahagi (hinati sa "|") ay pareho ng item, o ang isa ay nasa
     * loob ng isa, matapos i-normalize.
     *
     * @param  string[]  $itemNames
     * @return string[]
     */
    public static function matchedItems(string $campaignName, array $itemNames): array
    {
        $tokens = [];
        foreach (explode('|', $campaignName) as $part) {
            $t = self::normalize($part);
            if (self::matchable($t)) {
                $tokens[] = $t;
            }
        }

        $out = [];
        foreach ($itemNames as $item) {
            $i = self::normalize((string) $item);
            if (! self::matchable($i)) {
                continue;
            }
            foreach ($tokens as $t) {
                if ($t === $i || str_contains($t, $i) || str_contains($i, $t)) {
                    $out[] = (string) $item;
                    break;
                }
            }
        }

        return $out;
    }

    private static function matchable(string $normalized): bool
    {
        return mb_strlen($normalized) >= 3 && ! preg_match('/^[\d\W_]+$/u', $normalized);
    }
}
