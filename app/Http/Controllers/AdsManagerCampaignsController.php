<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdsManagerCampaignsController extends Controller
{
    public function index()
    {
        $pages = DB::table('ads_manager_reports')
            ->whereNotNull('page_name')
            ->selectRaw('TRIM(page_name) AS page_name')
            ->distinct()
            ->orderBy('page_name')
            ->pluck('page_name')
            ->toArray();

        // CEO-managed column visibility/order, shared with /owner/private's
        // inline campaigns expand. Loaded from app_settings via the settings
        // controller's resolver. Always returns a clean { order, hidden } shape.
        $colsCtrl = new \App\Http\Controllers\OwnerColumnSettingsController();
        $campaignsColsConfig     = $colsCtrl->loadConfig('campaigns');
        // Conditional formatting rules for campaigns columns (literal-only
        // applies here — ref-rules need parent /owner/private context which
        // doesn't exist sa standalone view).
        $campaignsColFormatRules = $colsCtrl->loadColFormat('campaigns')['byCol'] ?? [];

        return view('ads_manager.campaigns', compact('pages', 'campaignsColsConfig', 'campaignsColFormatRules'));
    }

    /**
     * TEMP diagnostic — verify what's actually in DB para sa first_started bug.
     * Hit /ads_manager/campaigns/_diag?q=SALES+VID+2 to investigate a campaign.
     * Remove this method + route after debugging.
     */
    public function diag(Request $request)
    {
        $q = trim((string)$request->input('q', ''));

        // Global stats
        $global = DB::table('ads_manager_reports')->selectRaw('
            MIN(`day`)              AS earliest_day,
            MAX(`day`)              AS latest_day,
            MIN(DATE(`starts`))     AS earliest_starts,
            MAX(DATE(`starts`))     AS latest_starts,
            COUNT(*)                AS total_rows,
            SUM(CASE WHEN `starts` IS NULL THEN 1 ELSE 0 END) AS rows_with_null_starts,
            SUM(CASE WHEN `day`    IS NULL THEN 1 ELSE 0 END) AS rows_with_null_day
        ')->first();

        // Per-campaign breakdown for the matching query
        $perCampaign = collect();
        if ($q !== '') {
            $like = '%' . $q . '%';
            $perCampaign = DB::table('ads_manager_reports')
                ->selectRaw('
                    campaign_id,
                    MAX(campaign_name)        AS campaign_name,
                    MAX(item_name)            AS sample_item_name,
                    MIN(page_name)            AS page_name,
                    MIN(`day`)                AS earliest_day,
                    MAX(`day`)                AS latest_day,
                    MIN(CASE WHEN COALESCE(amount_spent_php,0) > 0 THEN `day` END) AS first_spend_day,
                    SUM(CASE WHEN COALESCE(amount_spent_php,0) > 0 THEN 1 ELSE 0 END) AS days_with_spend,
                    COUNT(*)                  AS row_count,
                    SUM(COALESCE(amount_spent_php,0)) AS total_spend
                ')
                ->where(function ($w) use ($like) {
                    $w->where('campaign_name', 'like', $like)
                      ->orWhere('ad_set_name', 'like', $like)
                      ->orWhere('headline', 'like', $like);
                })
                ->groupBy('campaign_id')
                ->orderBy('earliest_day')
                ->limit(50)
                ->get();
        }

        // Distribution of `starts` values across all rows
        $startsHistogram = DB::table('ads_manager_reports')
            ->selectRaw('DATE(`starts`) AS d, COUNT(*) AS n')
            ->whereNotNull('starts')
            ->groupBy(DB::raw('DATE(`starts`)'))
            ->orderBy('d')
            ->limit(20)
            ->get();

        return response()->json([
            'global'           => $global,
            'query'            => $q,
            'per_campaign'     => $perCampaign,
            'starts_histogram_first_20' => $startsHistogram,
            'note' => 'If earliest_day is 2026-01-01 across multiple campaigns → no older import data exists. '
                   .'If earliest_starts is forced to 2026-01-01 but day records exist earlier → bug sa starts column. '
                   .'If lahat NULL ang starts → controller falls back to MIN(day), which is correct.',
        ]);
    }

    /**
     * GET /ads_manager/campaigns/history — daily change log derived from
     * spend transitions in `ads_manager_reports`.
     *
     * For each entity (campaign / adset / ad), we look at consecutive daily
     * rows ordered by day and emit events:
     *   • Created       — first day this entity ever appeared in the data
     *   • Turned ON     — spend > 0 today AND (no prior row OR prior spend ≤ 0)
     *   • Turned OFF    — spend ≤ 0 today AND prior spend > 0
     *
     * Spend-transition is preferred over delivery-status because numeric
     * metrics are preserved historically by FB exports while delivery flags
     * may be overwritten on re-import.
     */
    public function history(Request $request)
    {
        $pages = DB::table('ads_manager_reports')
            ->whereNotNull('page_name')
            ->selectRaw('TRIM(page_name) AS page_name')
            ->distinct()->orderBy('page_name')
            ->pluck('page_name')->toArray();

        return view('ads_manager.campaigns_history', compact('pages'));
    }

    /**
     * GET /ads_manager/campaigns/history/data — JSON change log.
     * Filters: start_date, end_date, page_name, level (all|campaigns|adsets|ads).
     */
    public function historyData(Request $request)
    {
        $start    = $request->input('start_date');
        $end      = $request->input('end_date');
        $pageName = $request->input('page_name');
        $levelF   = (string) $request->input('level', 'all'); // all|campaigns|adsets|ads

        $driver = DB::getDriverName();
        $dayExpr = $driver === 'pgsql'
            ? 'COALESCE(day, DATE(reporting_starts))'
            : 'COALESCE(`day`, DATE(`reporting_starts`))';

        // Per-entity-per-day spend + name + page snapshot.
        // Granularity: ad_id (lowest level). Campaign/adset events derive from
        // aggregating the ad-level events upstream (e.g. campaign turned on
        // = its first ad started spending).
        $base = DB::table('ads_manager_reports')
            ->whereNotNull('day');
        if ($pageName && $pageName !== 'all') {
            $base->whereRaw('LOWER(TRIM(page_name)) = LOWER(TRIM(?))', [$pageName]);
        }

        // Build a daily aggregate per (id, day) per level.
        $buildDaily = function (string $idCol) use ($base, $dayExpr) {
            return (clone $base)
                ->selectRaw("
                    $idCol AS id,
                    $dayExpr AS d,
                    COALESCE(SUM(amount_spent_php), 0) AS spend,
                    MAX(page_name)     AS page_name,
                    MAX(campaign_id)   AS campaign_id,
                    MAX(campaign_name) AS campaign_name,
                    MAX(ad_set_id)     AS ad_set_id,
                    MAX(ad_set_name)   AS ad_set_name,
                    MAX(headline)      AS headline,
                    MAX(item_name)     AS item_name
                ")
                ->groupBy($idCol, DB::raw($dayExpr));
        };

        // Detect transitions in SQL (not PHP) and date-filter at the outer
        // level so we never drag the full dataset to PHP. Also drop the
        // useless "created with zero spend" event — those are just FB
        // placeholder rows for inactive campaigns and pollute the log.
        $events = [];
        $hardLimitPerLevel = 5000;
        foreach (['campaigns' => 'campaign_id', 'adsets' => 'ad_set_id', 'ads' => 'ad_id'] as $level => $idCol) {
            if ($levelF !== 'all' && $levelF !== $level) continue;

            $daily = $buildDaily($idCol);
            $withLag = DB::query()->fromSub($daily, 'd')->selectRaw("
                d.*,
                LAG(spend) OVER (PARTITION BY id ORDER BY d) AS prev_spend
            ");

            // Outer query: classify the event in SQL + filter to date window
            // + drop noise events. Only meaningful transitions returned to PHP.
            $eventQuery = DB::query()->fromSub($withLag, 'w')
                ->selectRaw("
                    w.id, w.d, w.spend, w.prev_spend,
                    w.page_name, w.campaign_id, w.campaign_name,
                    w.ad_set_id, w.ad_set_name, w.headline, w.item_name,
                    CASE
                        WHEN w.prev_spend IS NULL AND w.spend > 0 THEN 'created_with_spend'
                        WHEN w.prev_spend <= 0   AND w.spend > 0 THEN 'turned_on'
                        WHEN w.prev_spend > 0    AND w.spend <= 0 THEN 'turned_off'
                        ELSE NULL
                    END AS event_kind
                ")
                ->whereRaw("(
                    (w.prev_spend IS NULL AND w.spend > 0)
                    OR (w.prev_spend <= 0 AND w.spend > 0)
                    OR (w.prev_spend > 0  AND w.spend <= 0)
                )");

            if ($start) $eventQuery->whereRaw('w.d >= ?', [$start]);
            if ($end)   $eventQuery->whereRaw('w.d <= ?', [$end]);

            $eventQuery->orderByDesc('w.d')->limit($hardLimitPerLevel);

            $rows = $eventQuery->get();

            foreach ($rows as $r) {
                $events[] = [
                    'day'           => (string) $r->d,
                    'level'         => $level === 'campaigns' ? 'campaign'
                                     : ($level === 'adsets' ? 'adset' : 'ad'),
                    'event'         => (string) $r->event_kind,
                    'entity_id'     => (string) $r->id,
                    'entity_name'   => $level === 'campaigns' ? ($r->campaign_name ?: 'Campaign '.$r->id)
                                     : ($level === 'adsets'  ? ($r->ad_set_name   ?: 'Ad set '  .$r->id)
                                     :                         ($r->headline      ?: 'Ad '      .$r->id)),
                    'page_name'     => (string) $r->page_name,
                    'campaign_name' => (string) ($r->campaign_name ?? ''),
                    'ad_set_name'   => (string) ($r->ad_set_name   ?? ''),
                    'item_name'     => (string) ($r->item_name     ?? ''),
                    'spend'         => (float) $r->spend,
                    'prev_spend'    => $r->prev_spend === null ? null : (float) $r->prev_spend,
                ];
            }
        }

        // Sort: most recent first; within day group by level then name.
        usort($events, function ($a, $b) {
            $c = strcmp($b['day'], $a['day']);
            if ($c !== 0) return $c;
            // campaign → adset → ad ordering
            $rank = ['campaign' => 0, 'adset' => 1, 'ad' => 2];
            $c = ($rank[$a['level']] ?? 9) <=> ($rank[$b['level']] ?? 9);
            if ($c !== 0) return $c;
            return strcmp($a['entity_name'], $b['entity_name']);
        });

        // Per-day summary counts for the header chips.
        $byDay = [];
        foreach ($events as $e) {
            $d = $e['day'];
            if (!isset($byDay[$d])) $byDay[$d] = [
                'created' => 0, 'turned_on' => 0, 'turned_off' => 0, 'created_with_spend' => 0,
            ];
            $byDay[$d][$e['event']]++;
        }

        return response()->json(['ok' => true, 'events' => $events, 'by_day' => $byDay]);
    }

    public function data(Request $request)
    {
        // Inputs
        $level       = $request->input('level', 'campaigns'); // campaigns|adsets|ads
        $start       = $request->input('start_date');         // YYYY-MM-DD
        $end         = $request->input('end_date');           // YYYY-MM-DD
        $pageName    = $request->input('page_name');          // optional
        $q           = $request->input('q');                  // search text
        $sortBy      = $request->input('sort_by', 'default'); // default composite sort
        $sortDir     = strtolower($request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $limit       = max(1, min((int) $request->input('limit', 200), 1000));
        $export      = $request->input('export');             // 'csv' to export
        // Item-scope filters used by /owner/private inline expand:
        //   item_name      — exact qty-variant match (e.g. "2 x MINI FLASHLIGHT")
        //   only_with_spend — when '1', drops aggregated rows where SUM(spend) <= 0
        // Both are optional (omit for the standalone /ads_manager/campaigns view).
        $itemName       = $request->input('item_name');
        $onlyWithSpend  = (string) $request->input('only_with_spend', '') === '1';

        // Drilldown params (single selection)
        $campaignId  = $request->input('campaign_id');
        $adSetId     = $request->input('ad_set_id');

        // Multi-select params (CSV)
        $campaignIdsCsv = $request->input('campaign_ids'); // e.g. "123,456"
        $adSetIdsCsv    = $request->input('ad_set_ids');   // e.g. "789,1011"
        $campaignIds    = $campaignIdsCsv ? array_values(array_filter(array_map('trim', explode(',', $campaignIdsCsv)))) : [];
        $adSetIds       = $adSetIdsCsv    ? array_values(array_filter(array_map('trim', explode(',', $adSetIdsCsv))))    : [];

        // Date expression (portable)
        $driver = DB::getDriverName(); // "pgsql" or "mysql"
        $dayExpr = $driver === 'pgsql'
            ? 'COALESCE(day, DATE(reporting_starts))'
            : 'COALESCE(`day`, DATE(`reporting_starts`))';

        // Alias-aware for joined table "a"
        $dayExprA = $driver === 'pgsql'
            ? 'COALESCE(a.day, DATE(a.reporting_starts))'
            : 'COALESCE(a.`day`, DATE(a.`reporting_starts`))';

        // --------------------------------------------------------------------
        // LATEST-DAY STATUS (GLOBAL, NOT FILTERED BY DATE RANGE)
        // Rule: on the latest day per entity, Active if ANY row is "active%".
        // --------------------------------------------------------------------

        // Latest day per campaign
        $latestCampaignDay = DB::table('ads_manager_reports')
            ->selectRaw("campaign_id, MAX($dayExpr) AS latest_day")
            ->groupBy('campaign_id');

        // Campaign status on latest day (1 row per campaign_id)
        $campaignLatestStatus = DB::table(DB::raw('ads_manager_reports a'))
            ->joinSub($latestCampaignDay, 't', function ($j) use ($dayExprA) {
                $j->on('a.campaign_id', '=', 't.campaign_id')
                  ->whereRaw("$dayExprA = t.latest_day");
            })
            ->selectRaw("
                a.campaign_id,
                MAX(CASE WHEN LOWER(TRIM(a.campaign_delivery)) LIKE 'active%' THEN 1 ELSE 0 END) AS is_on_latest
            ")
            ->groupBy('a.campaign_id');

        // ──────────────────────────────────────────────────────────────────
        // STARTED DATES (GLOBAL, NOT FILTERED BY DATE RANGE)
        //
        //   first_started  = original launch.
        //                    MIN(DATE(starts)) fallback to MIN(day).
        //
        //   latest_started = pinakahuling off→on transition. Detected by
        //                    looking at per-day delivery status: a day where
        //                    is_active=1 AND prev_day's is_active=0 (or no
        //                    prior row) is a "fresh start". MAX of those is
        //                    the latest resumption. For never-paused campaigns,
        //                    this equals first_started (only the first day
        //                    qualifies as a fresh start).
        //
        //                    NOTE: cannot use MAX(DATE(starts)) here because
        //                    FB's daily export overwrites `starts` to today on
        //                    each fresh row of an active campaign — so MAX
        //                    always returned today regardless of real history.
        //
        // Both GLOBAL — not filtered by the date range so the user sees the
        // true historic dates even when narrowing to a recent window.
        // ──────────────────────────────────────────────────────────────────
        // first_started — derived purely from MIN(day). The legacy `starts`
        // column is 100% NULL across all rows so we ignore it.
        //
        // ALSO compute `was_running_at_data_start` — true when the earliest
        // record for the entity already has spend > 0. That means the ad was
        // ALREADY active on its first day in our DB, so the actual launch
        // happened BEFORE our data window. Display logic uses this flag to
        // prefix the date with "≥" (running since at least that day, true
        // launch unknown) instead of misrepresenting it as the launch date.
        //
        // Pattern per level:
        //   1) sub_dailySpend = per (id, day) SUM(spend) — daily activity
        //   2) sub_earliest   = per id MIN(day)
        //   3) join sub_earliest back to sub_dailySpend to get spend ON
        //      that earliest day → running_at_start = (spend_on_earliest > 0)
        // first_started detection — based on actual spend, not just data presence.
        //
        //   Why: FB exports often include placeholder rows with spend = 0 for
        //   campaigns that exist but aren't actively running. Naive MIN(day)
        //   would report those as "First Launched", which is wrong — the user
        //   thinks of "launched" as "started spending".
        //
        //   Per id (campaign_id / ad_set_id / ad_id):
        //     • first_started     = MIN(day) where spend > 0  (true launch)
        //     • running_at_start  = TRUE only when first_started equals MIN(day)
        //                           overall — i.e. the campaign was already
        //                           spending on its very first record, so we
        //                           can't see when it actually started.
        //                           If we observed a clean spend=0 → spend>0
        //                           transition, we DO know the launch.
        //     • Fallback: if id never had spend > 0, first_started = MIN(day).
        $buildStarted = function (string $idCol) use ($dayExpr) {
            // 1) Daily spend aggregate per (id, day).
            $dailySpend = DB::table('ads_manager_reports')
                ->whereNotNull('day')
                ->whereNotNull($idCol)
                ->selectRaw("
                    $idCol AS id,
                    $dayExpr AS day,
                    COALESCE(SUM(amount_spent_php), 0) AS spend
                ")
                ->groupBy($idCol, DB::raw($dayExpr));

            // 2) Per id: MIN(day) of any record + MIN(day) where spend > 0.
            return DB::query()
                ->fromSub($dailySpend, 'd')
                ->selectRaw('
                    id,
                    MIN(day)                                          AS min_day,
                    MIN(CASE WHEN spend > 0 THEN day END)             AS first_spend_day,
                    COALESCE(
                        MIN(CASE WHEN spend > 0 THEN day END),
                        MIN(day)
                    )                                                  AS first_started,
                    CASE
                        WHEN MIN(CASE WHEN spend > 0 THEN day END) IS NOT NULL
                         AND MIN(CASE WHEN spend > 0 THEN day END) = MIN(day)
                        THEN 1 ELSE 0
                    END                                                AS running_at_start
                ')
                ->groupBy('id');
        };

        $campaignStartedDates = $buildStarted('campaign_id');
        $adSetStartedDates    = $buildStarted('ad_set_id');
        $adStartedDates       = $buildStarted('ad_id');

        // latest_started — built via 3-stage subqueries:
        //   1) per (id, day) is_active flag (any row marked active%)
        //   2) add LAG(is_active) over (partition by id order by day)
        //   3) keep rows where is_active=1 AND (prev=0 OR prev IS NULL),
        //      then GROUP BY id with MAX(day) — the latest fresh start.
        $buildFreshStart = function (string $idCol, string $deliveryCol) use ($dayExpr) {
            $dailyActive = DB::table('ads_manager_reports')
                ->whereNotNull('day')
                ->selectRaw("
                    $idCol AS id,
                    $dayExpr AS day,
                    MAX(CASE WHEN LOWER(TRIM($deliveryCol)) LIKE 'active%' THEN 1 ELSE 0 END) AS is_active
                ")
                ->groupBy($idCol, DB::raw($dayExpr));

            $withLag = DB::query()->fromSub($dailyActive, 'd')->selectRaw("
                id, day, is_active,
                LAG(is_active) OVER (PARTITION BY id ORDER BY day) AS prev_active
            ");

            return DB::query()->fromSub($withLag, 'lp')
                ->whereRaw('is_active = 1 AND (prev_active = 0 OR prev_active IS NULL)')
                ->selectRaw('id, MAX(day) AS latest_started')
                ->groupBy('id');
        };

        $campaignFreshStart = $buildFreshStart('campaign_id', 'campaign_delivery');
        $adSetFreshStart    = $buildFreshStart('ad_set_id',   'ad_set_delivery');
        // Ads inherit delivery from their ad set (no own delivery field).
        $adFreshStart       = $buildFreshStart('ad_id',       'ad_set_delivery');

        // Latest day per ad set
        $latestAdSetDay = DB::table('ads_manager_reports')
            ->selectRaw("ad_set_id, MAX($dayExpr) AS latest_day")
            ->groupBy('ad_set_id');

        // Ad set status on latest day (1 row per ad_set_id)
        $adSetLatestStatus = DB::table(DB::raw('ads_manager_reports a'))
            ->joinSub($latestAdSetDay, 't', function ($j) use ($dayExprA) {
                $j->on('a.ad_set_id', '=', 't.ad_set_id')
                  ->whereRaw("$dayExprA = t.latest_day");
            })
            ->selectRaw("
                a.ad_set_id,
                MAX(CASE WHEN LOWER(TRIM(a.ad_set_delivery)) LIKE 'active%' THEN 1 ELSE 0 END) AS is_on_latest
            ")
            ->groupBy('a.ad_set_id');

        // Base (filtered) query for METRICS (date/page/search filters only)
        $base = DB::table('ads_manager_reports');

        // Filters: date range
        if ($start) $base->whereRaw("$dayExpr >= ?", [$start]);
        if ($end)   $base->whereRaw("$dayExpr <= ?", [$end]);

        // Page filter (trim + case-insensitive)
        if ($pageName && $pageName !== 'all') {
            $base->whereRaw('LOWER(TRIM(page_name)) = LOWER(TRIM(?))', [$pageName]);
        }

        // Item filter — substring match (case-insensitive) to tolerate
        // formatting variations sa ads_manager_reports.item_name (e.g.
        // "RUBBER COATING SPRAY", "Rubber Coating Spray", "1 x RUBBER...").
        // Used by /owner/private inline expand to scope campaigns/adsets/ads
        // to ONLY those tied to the row's specific item. Aggregations (spend
        // totals, CPP, etc.) are computed against this filtered set.
        if (is_string($itemName) && trim($itemName) !== '') {
            $like = '%'.mb_strtolower(trim($itemName)).'%';
            $base->whereRaw('LOWER(COALESCE(item_name,\'\')) LIKE ?', [$like]);
        }

        // Search (case-insensitive)
        if ($q) {
            $like = '%'.trim($q).'%';
            $base->where(function ($qq) use ($like) {
                $qq->whereRaw('LOWER(COALESCE(campaign_name, \'\')) LIKE LOWER(?)', [$like])
                   ->orWhereRaw('LOWER(COALESCE(ad_set_name, \'\')) LIKE LOWER(?)', [$like])
                   ->orWhereRaw('LOWER(COALESCE(headline, \'\')) LIKE LOWER(?)', [$like])
                   ->orWhereRaw('LOWER(COALESCE(body_ad_settings, \'\')) LIKE LOWER(?)', [$like])
                   ->orWhereRaw('LOWER(COALESCE(item_name, \'\')) LIKE LOWER(?)', [$like]);
            });
        }

        // Apply multi-select filters to child levels
        if ($level !== 'campaigns' && !empty($campaignIds)) {
            $base->whereIn('campaign_id', $campaignIds);
        }
        if ($level === 'ads' && !empty($adSetIds)) {
            $base->whereIn('ad_set_id', $adSetIds);
        }

        // =========================
        // Level: CAMPAIGNS
        // =========================
        if ($level === 'campaigns') {
            if ($campaignId) $base->where('campaign_id', $campaignId);

            $query = (clone $base)
                ->leftJoinSub($campaignLatestStatus, 'ls', function ($j) {
                    $j->on('ads_manager_reports.campaign_id', '=', 'ls.campaign_id');
                })
                ->leftJoinSub($campaignStartedDates, 'sd', function ($j) {
                    $j->on('ads_manager_reports.campaign_id', '=', 'sd.id');
                })
                ->leftJoinSub($campaignFreshStart, 'fs', function ($j) {
                    $j->on('ads_manager_reports.campaign_id', '=', 'fs.id');
                })
                ->selectRaw('
                    ads_manager_reports.campaign_id,
                    MAX(campaign_name) AS campaign_name,
                    MAX(page_name)     AS page_name,

                    (SUM(amount_spent_php) / 1.12) AS spend,
                    SUM(messaging_conversations_started) AS messages,
                    SUM(purchases) AS purchases,
                    SUM(impressions) AS impressions,
                    SUM(reach) AS reach,
                    SUM(link_clicks) AS link_clicks,

                    CASE WHEN SUM(purchases) > 0 THEN (SUM(amount_spent_php)/1.12)/SUM(purchases) END AS cpp,
                    CASE WHEN SUM(messaging_conversations_started) > 0 THEN (SUM(amount_spent_php)/1.12)/SUM(messaging_conversations_started) END AS cpm_msg,
                    CASE WHEN SUM(impressions) > 0 THEN ((SUM(amount_spent_php)/1.12)/SUM(impressions))*1000 END AS cpm_1000,
                    CASE WHEN SUM(results) > 0 THEN (SUM(amount_spent_php)/1.12)/SUM(results) END AS cpr,
                    CASE WHEN SUM(link_clicks) > 0 THEN (SUM(messaging_conversations_started)*100.0)/SUM(link_clicks) END AS welcome_msg_rate,
                    CASE WHEN SUM(messaging_conversations_started) > 0 THEN (SUM(purchases)*100.0)/SUM(messaging_conversations_started) END AS conversion_rate,

                    COALESCE(MAX(ls.is_on_latest), 0) AS is_on,
                    MAX(sd.first_started)  AS first_started,
                    MAX(sd.running_at_start) AS running_at_start,
                    MAX(fs.latest_started) AS latest_started
                ')
                ->groupBy('ads_manager_reports.campaign_id');

            // Drop campaigns with zero spend in the window when caller asked.
            if ($onlyWithSpend) $query->havingRaw('COALESCE(SUM(amount_spent_php),0) > 0');

            $sortable = ['spend','messages','purchases','cpp','cpm_msg','cpm_1000','cpr','impressions','reach','link_clicks','welcome_msg_rate','conversion_rate','campaign_name','page_name','first_started','latest_started'];

            if ($sortBy === 'default') {
                $rows = $query->orderByDesc('is_on')
                              ->orderBy('campaign_name', 'asc')
                              ->orderBy('spend', 'desc')
                              ->limit($limit)
                              ->get();
            } else {
                if (!in_array($sortBy, $sortable)) $sortBy = 'spend';
                $rows = $query->orderBy($sortBy, $sortDir)->limit($limit)->get();
            }

            $rows = $rows->map(function ($r) {
                return [
                    'level'           => 'campaign',
                    'campaign_id'     => $r->campaign_id,
                    'campaign_name'   => $r->campaign_name,
                    'page_name'       => $r->page_name,
                    'on'              => (bool) ($r->is_on ?? 0),

                    'first_started'   => $r->first_started  ?? null,
                    'running_at_start'=> (int) ($r->running_at_start ?? 0) === 1,
                    'latest_started'  => $r->latest_started ?? null,

                    'spend'           => (float) ($r->spend ?? 0),
                    'cpm_1000'        => isset($r->cpm_1000) ? (float) $r->cpm_1000 : null,
                    'cpm_msg'         => isset($r->cpm_msg)  ? (float) $r->cpm_msg  : null,
                    'cpp'             => isset($r->cpp)      ? (float) $r->cpp      : null,
                    'cpr'             => isset($r->cpr)      ? (float) $r->cpr      : null,
                    'messages'        => (int)   ($r->messages ?? 0),
                    'purchases'       => (int)   ($r->purchases ?? 0),
                    'impressions'     => (int)   ($r->impressions ?? 0),
                    'reach'           => (int)   ($r->reach ?? 0),
                    'link_clicks'      => $r->link_clicks !== null ? (int) $r->link_clicks : null,
                    'welcome_msg_rate' => isset($r->welcome_msg_rate) ? (float) $r->welcome_msg_rate : null,
                    'conversion_rate'  => isset($r->conversion_rate)  ? (float) $r->conversion_rate  : null,
                ];
            });

        // =========================
        // Level: AD SETS
        // =========================
        } elseif ($level === 'adsets') {
            if (empty($campaignIds) && $campaignId) $base->where('campaign_id', $campaignId);

            $query = (clone $base)
                ->leftJoinSub($adSetLatestStatus, 'ls', function ($j) {
                    $j->on('ads_manager_reports.ad_set_id', '=', 'ls.ad_set_id');
                })
                ->leftJoinSub($adSetStartedDates, 'sd', function ($j) {
                    $j->on('ads_manager_reports.ad_set_id', '=', 'sd.id');
                })
                ->leftJoinSub($adSetFreshStart, 'fs', function ($j) {
                    $j->on('ads_manager_reports.ad_set_id', '=', 'fs.id');
                })
                ->selectRaw('
                    ads_manager_reports.ad_set_id,
                    MAX(ad_set_name)   AS ad_set_name,
                    MAX(campaign_id)   AS campaign_id,
                    MAX(campaign_name) AS campaign_name,
                    MAX(page_name)     AS page_name,

                    (SUM(amount_spent_php) / 1.12) AS spend,
                    SUM(messaging_conversations_started) AS messages,
                    SUM(purchases) AS purchases,
                    SUM(impressions) AS impressions,
                    SUM(reach) AS reach,
                    SUM(link_clicks) AS link_clicks,

                    CASE WHEN SUM(purchases) > 0 THEN (SUM(amount_spent_php)/1.12)/SUM(purchases) END AS cpp,
                    CASE WHEN SUM(messaging_conversations_started) > 0 THEN (SUM(amount_spent_php)/1.12)/SUM(messaging_conversations_started) END AS cpm_msg,
                    CASE WHEN SUM(impressions) > 0 THEN ((SUM(amount_spent_php)/1.12)/SUM(impressions))*1000 END AS cpm_1000,
                    CASE WHEN SUM(results) > 0 THEN (SUM(amount_spent_php)/1.12)/SUM(results) END AS cpr,
                    CASE WHEN SUM(link_clicks) > 0 THEN (SUM(messaging_conversations_started)*100.0)/SUM(link_clicks) END AS welcome_msg_rate,
                    CASE WHEN SUM(messaging_conversations_started) > 0 THEN (SUM(purchases)*100.0)/SUM(messaging_conversations_started) END AS conversion_rate,

                    COALESCE(MAX(ls.is_on_latest), 0) AS is_on,
                    MAX(sd.first_started)  AS first_started,
                    MAX(sd.running_at_start) AS running_at_start,
                    MAX(fs.latest_started) AS latest_started
                ')
                ->groupBy('ads_manager_reports.ad_set_id');

            if ($onlyWithSpend) $query->havingRaw('COALESCE(SUM(amount_spent_php),0) > 0');

            $sortable = ['spend','messages','purchases','cpp','cpm_msg','cpm_1000','cpr','impressions','reach','link_clicks','welcome_msg_rate','conversion_rate','ad_set_name','campaign_name','page_name','first_started','latest_started'];

            if ($sortBy === 'default') {
                $rows = $query->orderByDesc('is_on')
                              ->orderBy('ad_set_name', 'asc')
                              ->orderBy('spend', 'desc')
                              ->limit($limit)
                              ->get();
            } else {
                if (!in_array($sortBy, $sortable)) $sortBy = 'spend';
                $rows = $query->orderBy($sortBy, $sortDir)->limit($limit)->get();
            }

            $rows = $rows->map(function ($r) {
                return [
                    'level'           => 'adset',
                    'campaign_id'     => $r->campaign_id,
                    'campaign_name'   => $r->campaign_name,
                    'ad_set_id'       => $r->ad_set_id,
                    'ad_set_name'     => $r->ad_set_name,
                    'page_name'       => $r->page_name,
                    'on'              => (bool) ($r->is_on ?? 0),

                    'first_started'   => $r->first_started  ?? null,
                    'running_at_start'=> (int) ($r->running_at_start ?? 0) === 1,
                    'latest_started'  => $r->latest_started ?? null,

                    'spend'           => (float) ($r->spend ?? 0),
                    'cpm_1000'        => isset($r->cpm_1000) ? (float) $r->cpm_1000 : null,
                    'cpm_msg'         => isset($r->cpm_msg)  ? (float) $r->cpm_msg  : null,
                    'cpp'             => isset($r->cpp)      ? (float) $r->cpp      : null,
                    'cpr'             => isset($r->cpr)      ? (float) $r->cpr      : null,
                    'messages'        => (int)   ($r->messages ?? 0),
                    'purchases'       => (int)   ($r->purchases ?? 0),
                    'impressions'     => (int)   ($r->impressions ?? 0),
                    'reach'           => (int)   ($r->reach ?? 0),
                    'link_clicks'      => $r->link_clicks !== null ? (int) $r->link_clicks : null,
                    'welcome_msg_rate' => isset($r->welcome_msg_rate) ? (float) $r->welcome_msg_rate : null,
                    'conversion_rate'  => isset($r->conversion_rate)  ? (float) $r->conversion_rate  : null,
                ];
            });

        // =========================
        // Level: ADS
        // =========================
        } else {
            if (empty($adSetIds) && $adSetId) $base->where('ad_set_id', $adSetId);

            // Ads inherit status from latest-day Ad Set status
            $query = (clone $base)
                ->leftJoinSub($adSetLatestStatus, 'ls', function ($j) {
                    $j->on('ads_manager_reports.ad_set_id', '=', 'ls.ad_set_id');
                })
                ->leftJoinSub($adStartedDates, 'sd', function ($j) {
                    $j->on('ads_manager_reports.ad_id', '=', 'sd.id');
                })
                ->leftJoinSub($adFreshStart, 'fs', function ($j) {
                    $j->on('ads_manager_reports.ad_id', '=', 'fs.id');
                })
                ->selectRaw('
                    ads_manager_reports.ad_id,
                    MAX(headline)      AS headline,
                    MAX(item_name)     AS item_name,
                    MAX(ad_set_id)     AS ad_set_id,
                    MAX(ad_set_name)   AS ad_set_name,
                    MAX(campaign_id)   AS campaign_id,
                    MAX(campaign_name) AS campaign_name,
                    MAX(page_name)     AS page_name,

                    (SUM(amount_spent_php) / 1.12) AS spend,
                    SUM(messaging_conversations_started) AS messages,
                    SUM(purchases) AS purchases,
                    SUM(impressions) AS impressions,
                    SUM(reach) AS reach,
                    SUM(link_clicks) AS link_clicks,

                    CASE WHEN SUM(purchases) > 0 THEN (SUM(amount_spent_php)/1.12)/SUM(purchases) END AS cpp,
                    CASE WHEN SUM(messaging_conversations_started) > 0 THEN (SUM(amount_spent_php)/1.12)/SUM(messaging_conversations_started) END AS cpm_msg,
                    CASE WHEN SUM(impressions) > 0 THEN ((SUM(amount_spent_php)/1.12)/SUM(impressions))*1000 END AS cpm_1000,
                    CASE WHEN SUM(results) > 0 THEN (SUM(amount_spent_php)/1.12)/SUM(results) END AS cpr,
                    CASE WHEN SUM(link_clicks) > 0 THEN (SUM(messaging_conversations_started)*100.0)/SUM(link_clicks) END AS welcome_msg_rate,
                    CASE WHEN SUM(messaging_conversations_started) > 0 THEN (SUM(purchases)*100.0)/SUM(messaging_conversations_started) END AS conversion_rate,

                    COALESCE(MAX(ls.is_on_latest), 0) AS is_on,
                    MAX(sd.first_started)  AS first_started,
                    MAX(sd.running_at_start) AS running_at_start,
                    MAX(fs.latest_started) AS latest_started
                ')
                ->groupBy('ads_manager_reports.ad_id');

            if ($onlyWithSpend) $query->havingRaw('COALESCE(SUM(amount_spent_php),0) > 0');

            $sortable = ['spend','messages','purchases','cpp','cpm_msg','cpm_1000','cpr','impressions','reach','link_clicks','welcome_msg_rate','conversion_rate','headline','item_name','first_started','latest_started'];

            if ($sortBy === 'default') {
                $rows = $query->orderByDesc('is_on')
                              ->orderBy('headline', 'asc')
                              ->orderBy('spend', 'desc')
                              ->limit($limit)
                              ->get();
            } else {
                if (!in_array($sortBy, $sortable)) $sortBy = 'spend';
                $rows = $query->orderBy($sortBy, $sortDir)->limit($limit)->get();
            }

            $rows = $rows->map(function ($r) {
                return [
                    'level'           => 'ad',
                    'campaign_id'     => $r->campaign_id,
                    'campaign_name'   => $r->campaign_name,
                    'ad_set_id'       => $r->ad_set_id,
                    'ad_set_name'     => $r->ad_set_name,
                    'ad_id'           => $r->ad_id,
                    'headline'        => $r->headline,
                    'item_name'       => $r->item_name,
                    'page_name'       => $r->page_name,
                    'on'              => (bool) ($r->is_on ?? 0),

                    'first_started'   => $r->first_started  ?? null,
                    'running_at_start'=> (int) ($r->running_at_start ?? 0) === 1,
                    'latest_started'  => $r->latest_started ?? null,

                    'spend'           => (float) ($r->spend ?? 0),
                    'cpm_1000'        => isset($r->cpm_1000) ? (float) $r->cpm_1000 : null,
                    'cpm_msg'         => isset($r->cpm_msg)  ? (float) $r->cpm_msg  : null,
                    'cpp'             => isset($r->cpp)      ? (float) $r->cpp      : null,
                    'cpr'             => isset($r->cpr)      ? (float) $r->cpr      : null,
                    'messages'        => (int)   ($r->messages ?? 0),
                    'purchases'       => (int)   ($r->purchases ?? 0),
                    'impressions'     => (int)   ($r->impressions ?? 0),
                    'reach'           => (int)   ($r->reach ?? 0),
                    'link_clicks'      => $r->link_clicks !== null ? (int) $r->link_clicks : null,
                    'welcome_msg_rate' => isset($r->welcome_msg_rate) ? (float) $r->welcome_msg_rate : null,
                    'conversion_rate'  => isset($r->conversion_rate)  ? (float) $r->conversion_rate  : null,
                ];
            });
        }

        // Totals for current filter (no group)
        $tot = (clone $base)->selectRaw('
            (COALESCE(SUM(amount_spent_php),0) / 1.12) AS spend,
            COALESCE(SUM(messaging_conversations_started),0) AS messages,
            COALESCE(SUM(purchases),0) AS purchases,
            COALESCE(SUM(impressions),0) AS impressions,
            COALESCE(SUM(reach),0) AS reach,
            COALESCE(SUM(results),0) AS results,
            COALESCE(SUM(link_clicks),0) AS link_clicks
        ')->first();

        $totals = [
            'spend'       => (float) ($tot->spend ?? 0),
            'messages'    => (int)   ($tot->messages ?? 0),
            'purchases'   => (int)   ($tot->purchases ?? 0),
            'impressions' => (int)   ($tot->impressions ?? 0),
            'reach'       => (int)   ($tot->reach ?? 0),
            'link_clicks' => (int)   ($tot->link_clicks ?? 0),
            'cpp'         => ($tot->purchases ?? 0)   > 0 ? (float) ($tot->spend / $tot->purchases) : null,
            'cpm_msg'     => ($tot->messages ?? 0)    > 0 ? (float) ($tot->spend / $tot->messages)  : null,
            'cpm_1000'    => ($tot->impressions ?? 0) > 0 ? (float) (($tot->spend / $tot->impressions) * 1000) : null,
            'cpr'         => ($tot->results ?? 0)     > 0 ? (float) ($tot->spend / $tot->results) : null,
            'welcome_msg_rate' => ($tot->link_clicks ?? 0) > 0 ? (float) (($tot->messages * 100.0) / $tot->link_clicks) : null,
            'conversion_rate'  => ($tot->messages ?? 0)    > 0 ? (float) (($tot->purchases * 100.0) / $tot->messages) : null,
        ];

        // CSV export (optional)
        if ($export === 'csv') {
            return $this->exportCsv($rows, $level);
        }

        return response()->json([
            'level'   => $level,
            'rows'    => $rows,
            'totals'  => $totals,
        ]);
    }

    private function exportCsv($rows, string $level): StreamedResponse
    {
        $filename = 'ads_manager_' . $level . '_' . now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"$filename\""
        ];

        // Compute "days ago" from a date string (YYYY-MM-DD) using PH timezone.
        // Returns blank if the entity is OFF — matches the in-table behavior
        // where "Days ago" only shows for currently Active entities.
        $daysAgo = function ($s, $isOn) {
            if (empty($s) || !$isOn) return '';
            try {
                $d = new \DateTime(substr((string)$s, 0, 10) . ' 00:00:00', new \DateTimeZone('Asia/Manila'));
                $today = new \DateTime('now', new \DateTimeZone('Asia/Manila'));
                $today->setTime(0, 0, 0);
                $diff = (int) floor(($today->getTimestamp() - $d->getTimestamp()) / 86400);
                return (string) $diff;
            } catch (\Throwable $e) { return ''; }
        };

        return response()->stream(function () use ($rows, $level, $daysAgo) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

            // Render rate column as bare number (1 decimal) or blank — matches UI "—" semantics.
            $rate = fn($v) => ($v === null || $v === '') ? '' : number_format((float)$v, 1, '.', '');
            $intOrBlank = fn($v) => ($v === null || $v === '') ? '' : (string)(int)$v;

            if ($level === 'campaigns') {
                fputcsv($out, ['Campaign','Page','Active','First Launched','Days Running','Latest Start','Spend','CPM (1k)','Cost/Msg','Cost/Result','Cost/Purchase','Impr.','Link Clicks','Welcome Msg Rate (%)','Msgs','Conv Rate (%)','Purchases']);
                foreach ($rows as $r) {
                    fputcsv($out, [
                        $r['campaign_name'], $r['page_name'], $r['on'] ? '1':'0',
                        ((!empty($r['running_at_start']) && !empty($r['first_started'])) ? '>= ' : '') . ($r['first_started'] ?? ''),
                        ((!empty($r['running_at_start']) && !empty($r['first_started'])) ? '>= ' : '') . $daysAgo($r['first_started'] ?? null, !empty($r['on'])),
                        $r['latest_started'] ?? '',
                        $r['spend'], $r['cpm_1000'], $r['cpm_msg'], $r['cpr'], $r['cpp'],
                        $r['impressions'], $intOrBlank($r['link_clicks'] ?? null), $rate($r['welcome_msg_rate'] ?? null),
                        $r['messages'], $rate($r['conversion_rate'] ?? null), $r['purchases']
                    ]);
                }
            } elseif ($level === 'adsets') {
                fputcsv($out, ['Ad set','Campaign','Page','Active','First Launched','Days Running','Latest Start','Spend','CPM (1k)','Cost/Msg','Cost/Result','Cost/Purchase','Impr.','Link Clicks','Welcome Msg Rate (%)','Msgs','Conv Rate (%)','Purchases']);
                foreach ($rows as $r) {
                    fputcsv($out, [
                        $r['ad_set_name'], $r['campaign_name'], $r['page_name'], $r['on'] ? '1':'0',
                        ((!empty($r['running_at_start']) && !empty($r['first_started'])) ? '>= ' : '') . ($r['first_started'] ?? ''),
                        ((!empty($r['running_at_start']) && !empty($r['first_started'])) ? '>= ' : '') . $daysAgo($r['first_started'] ?? null, !empty($r['on'])),
                        $r['latest_started'] ?? '',
                        $r['spend'], $r['cpm_1000'], $r['cpm_msg'], $r['cpr'], $r['cpp'],
                        $r['impressions'], $intOrBlank($r['link_clicks'] ?? null), $rate($r['welcome_msg_rate'] ?? null),
                        $r['messages'], $rate($r['conversion_rate'] ?? null), $r['purchases']
                    ]);
                }
            } else {
                fputcsv($out, ['Ad (Headline)','Ad set','Campaign','Page','Active','First Launched','Days Running','Latest Start','Spend','CPM (1k)','Cost/Msg','Cost/Result','Cost/Purchase','Impr.','Link Clicks','Welcome Msg Rate (%)','Msgs','Conv Rate (%)','Purchases']);
                foreach ($rows as $r) {
                    fputcsv($out, [
                        ($r['headline'] ?? 'Ad '.$r['ad_id']), $r['ad_set_name'], $r['campaign_name'], $r['page_name'], $r['on'] ? '1':'0',
                        ((!empty($r['running_at_start']) && !empty($r['first_started'])) ? '>= ' : '') . ($r['first_started'] ?? ''),
                        ((!empty($r['running_at_start']) && !empty($r['first_started'])) ? '>= ' : '') . $daysAgo($r['first_started'] ?? null, !empty($r['on'])),
                        $r['latest_started'] ?? '',
                        $r['spend'], $r['cpm_1000'], $r['cpm_msg'], $r['cpr'], $r['cpp'],
                        $r['impressions'], $intOrBlank($r['link_clicks'] ?? null), $rate($r['welcome_msg_rate'] ?? null),
                        $r['messages'], $rate($r['conversion_rate'] ?? null), $r['purchases']
                    ]);
                }
            }
            fclose($out);
        }, 200, $headers);
    }
}
