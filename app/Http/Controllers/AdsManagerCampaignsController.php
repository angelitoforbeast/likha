<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

        // Global stats from facts table
        $global = DB::table('ads_manager_reports')->selectRaw("
            MIN(`day`)              AS earliest_day,
            MAX(`day`)              AS latest_day,
            MIN(DATE(`starts`))     AS earliest_starts,
            MAX(DATE(`starts`))     AS latest_starts,
            COUNT(*)                AS total_rows,
            SUM(CASE WHEN `starts` IS NULL THEN 1 ELSE 0 END) AS rows_with_null_starts,
            SUM(CASE WHEN `day`    IS NULL THEN 1 ELSE 0 END) AS rows_with_null_day
        ")->first();
        $global = (array) $global;

        // Account stats from creatives table (the new home for account_id)
        $hasAccountIdInCreatives = Schema::hasColumn('ad_campaign_creatives', 'account_id');
        $global['account_id_column_exists_in_creatives'] = $hasAccountIdInCreatives;
        if ($hasAccountIdInCreatives) {
            $crStats = DB::table('ad_campaign_creatives')->selectRaw("
                COUNT(*) AS total_creative_rows,
                SUM(CASE WHEN account_id IS NULL OR account_id = '' THEN 1 ELSE 0 END) AS creatives_with_null_account_id,
                COUNT(DISTINCT NULLIF(TRIM(COALESCE(account_id,'')),'')) AS distinct_account_ids
            ")->first();
            $global['total_creative_rows']            = (int) ($crStats->total_creative_rows ?? 0);
            $global['creatives_with_null_account_id'] = (int) ($crStats->creatives_with_null_account_id ?? 0);
            $global['distinct_account_ids']           = (int) ($crStats->distinct_account_ids ?? 0);
        }

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
        // Event filter: which event kinds to keep. Accepted values:
        //   'all'                  → all events (default)
        //   'turned_on'            → only ON transitions
        //   'turned_off'           → only OFF transitions
        //   'created_with_spend'   → only "created with spend"
        //   'on_off'               → ON + OFF (drop Created)
        $eventF   = (string) $request->input('event', 'all');

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

        // Build a daily aggregate per (id, day) per level. Includes the raw
        // metric components needed downstream for lifetime cumulative metrics
        // (CPM, CPP, Welcome Msg Rate, Conv Rate).
        $buildDaily = function (string $idCol) use ($base, $dayExpr) {
            return (clone $base)
                ->selectRaw("
                    $idCol AS id,
                    $dayExpr AS d,
                    COALESCE(SUM(amount_spent_php), 0) AS spend,
                    COALESCE(SUM(impressions), 0)     AS impressions,
                    COALESCE(SUM(messaging_conversations_started), 0) AS messages,
                    COALESCE(SUM(purchases), 0)       AS purchases,
                    COALESCE(SUM(link_clicks), 0)     AS link_clicks,
                    COALESCE(SUM(results), 0)         AS results,
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
            // Window functions: capture both prev_spend AND prev_day. The
            // prev_day is the date of the LAST observed record before this
            // one — used to back-shift "turned_off" events to the actual
            // last-spending day (user mental model: "I paused it on Day X
            // because Day X+1 had no spend").
            $withLag = DB::query()->fromSub($daily, 'd')->selectRaw("
                d.*,
                LAG(spend) OVER (PARTITION BY id ORDER BY d) AS prev_spend,
                LAG(d)     OVER (PARTITION BY id ORDER BY d) AS prev_day
            ");

            // Outer query: classify the event in SQL + filter to date window
            // + drop noise events. For turned_off events, the canonical
            // display day is prev_day (the last day spend > 0).
            $eventQuery = DB::query()->fromSub($withLag, 'w')
                ->selectRaw("
                    w.id,
                    CASE
                        WHEN w.prev_spend > 0 AND w.spend <= 0 THEN COALESCE(w.prev_day, w.d)
                        ELSE w.d
                    END AS event_day,
                    w.d AS observed_day,
                    w.spend, w.prev_spend, w.prev_day,
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

            // Event-kind filter — narrow down to specific transition types.
            switch ($eventF) {
                case 'turned_on':
                    $eventQuery->whereRaw("w.prev_spend <= 0 AND w.prev_spend IS NOT NULL AND w.spend > 0");
                    break;
                case 'turned_off':
                    $eventQuery->whereRaw("w.prev_spend > 0 AND w.spend <= 0");
                    break;
                case 'created_with_spend':
                    $eventQuery->whereRaw("w.prev_spend IS NULL AND w.spend > 0");
                    break;
                case 'on_off':
                    $eventQuery->whereRaw("(
                        (w.prev_spend <= 0 AND w.prev_spend IS NOT NULL AND w.spend > 0)
                        OR (w.prev_spend > 0 AND w.spend <= 0)
                    )");
                    break;
                // 'all' or anything else → no extra filter
            }

            // Date filter applies to event_day (post-shift) so user-selected
            // ranges line up with what they expect to see on screen.
            if ($start) $eventQuery->whereRaw("(CASE
                    WHEN w.prev_spend > 0 AND w.spend <= 0 THEN COALESCE(w.prev_day, w.d)
                    ELSE w.d END) >= ?", [$start]);
            if ($end)   $eventQuery->whereRaw("(CASE
                    WHEN w.prev_spend > 0 AND w.spend <= 0 THEN COALESCE(w.prev_day, w.d)
                    ELSE w.d END) <= ?", [$end]);

            $eventQuery->orderByDesc('event_day')->limit($hardLimitPerLevel);

            $rows = $eventQuery->get();

            foreach ($rows as $r) {
                // For turned_off events, prev_spend is the LAST day's spend;
                // current row's spend is 0. We display the event on prev_day
                // and report the spend that was active just before the pause
                // (so the user sees "₱473.65 was the last day's spend").
                $isTurnedOff = $r->event_kind === 'turned_off';
                $displaySpend = $isTurnedOff
                    ? (float) ($r->prev_spend ?? 0)  // last spending day's spend
                    : (float) $r->spend;
                $displayPrevSpend = $isTurnedOff
                    ? 0.0  // implied pause day spend = 0
                    : ($r->prev_spend === null ? null : (float) $r->prev_spend);

                $events[] = [
                    'day'           => (string) $r->event_day,
                    'observed_day'  => (string) $r->observed_day, // raw transition date for debug/tooltip
                    'level'         => $level === 'campaigns' ? 'campaign'
                                     : ($level === 'adsets' ? 'adset' : 'ad'),
                    'event'         => (string) $r->event_kind,
                    'entity_id'     => (string) $r->id,
                    'entity_name'   => $level === 'campaigns' ? ($r->campaign_name ?: 'Campaign '.$r->id)
                                     : ($level === 'adsets'  ? ($r->ad_set_name   ?: 'Ad set '  .$r->id)
                                     :                         ($r->headline      ?: 'Ad '      .$r->id)),
                    'page_name'     => (string) $r->page_name,
                    // account_id + account_name resolved later via campaign_id
                    'campaign_id'   => (string) ($r->campaign_id   ?? ''),
                    'campaign_name' => (string) ($r->campaign_name ?? ''),
                    'ad_set_id'     => (string) ($r->ad_set_id     ?? ''),
                    'ad_set_name'   => (string) ($r->ad_set_name   ?? ''),
                    'item_name'     => (string) ($r->item_name     ?? ''),
                    'spend'         => $displaySpend,
                    'prev_spend'    => $displayPrevSpend,
                ];
            }
        }

        // Attach creative content per event so the user can see + edit
        // headline/welcome_message/quick_replies/ad_link/feedback inline.
        // Source: ad_campaign_creatives (acc) — keyed primarily by ad_id,
        // secondarily by campaign_id.
        $campaignIds = [];
        $adSetIds    = [];
        foreach ($events as $e) {
            if ($e['level'] === 'campaign' && !empty($e['entity_id'])) $campaignIds[] = $e['entity_id'];
            if ($e['level'] === 'adset'    && !empty($e['entity_id'])) $adSetIds[]    = $e['entity_id'];
            if ($e['level'] === 'ad'       && !empty($e['campaign_id'])) $campaignIds[] = $e['campaign_id'];
        }
        $campaignIds = array_values(array_unique($campaignIds));
        $adSetIds    = array_values(array_unique($adSetIds));

        // Fetch creatives by campaign_id (representative row per campaign —
        // pick MAX(id) to grab the latest insert/update order).
        $creativeByCampaign = [];
        if (!empty($campaignIds)) {
            $rows = DB::table('ad_campaign_creatives')
                ->whereIn('campaign_id', $campaignIds)
                ->whereNotNull('campaign_id')
                ->orderBy('campaign_id')->orderByDesc('id')
                ->get(['id','campaign_id','ad_id','body_ad_settings','headline','welcome_message','quick_reply_1','quick_reply_2','quick_reply_3','ad_link','feedback']);
            foreach ($rows as $r) {
                if (!isset($creativeByCampaign[$r->campaign_id])) {
                    $creativeByCampaign[$r->campaign_id] = $r;
                }
            }
        }

        // Adset → creative: resolve via ads_manager_reports (find any ad_id
        // under each ad_set_id, then look up its creative).
        $creativeByAdSet = [];
        if (!empty($adSetIds)) {
            $adIdMap = DB::table('ads_manager_reports')
                ->whereIn('ad_set_id', $adSetIds)
                ->whereNotNull('ad_id')
                ->selectRaw('ad_set_id, MAX(ad_id) AS ad_id')
                ->groupBy('ad_set_id')
                ->pluck('ad_id', 'ad_set_id');

            if ($adIdMap->isNotEmpty()) {
                $adIds = $adIdMap->values()->all();
                $byAdId = DB::table('ad_campaign_creatives')
                    ->whereIn('ad_id', $adIds)
                    ->orderBy('ad_id')->orderByDesc('id')
                    ->get(['id','ad_id','body_ad_settings','headline','welcome_message','quick_reply_1','quick_reply_2','quick_reply_3','ad_link','feedback'])
                    ->keyBy('ad_id');

                foreach ($adIdMap as $asId => $adId) {
                    if (isset($byAdId[$adId])) $creativeByAdSet[$asId] = $byAdId[$adId];
                }
            }
        }

        // Stitch creatives into each event payload.
        foreach ($events as &$e) {
            $cre = null;
            if ($e['level'] === 'campaign')      $cre = $creativeByCampaign[$e['entity_id']]   ?? null;
            elseif ($e['level'] === 'adset')     $cre = $creativeByAdSet[$e['entity_id']]      ?? null;
            elseif ($e['level'] === 'ad')        $cre = $creativeByCampaign[$e['campaign_id']] ?? null;

            $e['creative_id']      = $cre ? (int) $cre->id : null;
            $e['body']             = $cre->body_ad_settings ?? '';
            $e['headline']         = $cre->headline         ?? '';
            $e['welcome_message']  = $cre->welcome_message  ?? '';
            $e['quick_reply_1']    = $cre->quick_reply_1    ?? '';
            $e['quick_reply_2']    = $cre->quick_reply_2    ?? '';
            $e['quick_reply_3']    = $cre->quick_reply_3    ?? '';
            $e['ad_link']          = $cre->ad_link          ?? '';
            $e['feedback']         = $cre ? (int) ($cre->feedback ?? 0) : 0;
        }
        unset($e);

        // ── Compute LIFETIME metrics per event (CPM, CPP, WMR, Conv Rate) ──
        //   For each event, we want "what was the campaign's cumulative
        //   performance up to and including event_day".
        //   Strategy: per level, fetch ALL daily aggregate rows for the
        //   relevant entity_ids (no date filter), sort by day ASC, then
        //   compute running cumulative sums in PHP. Per event, look up the
        //   running totals as of event_day.
        $entityIdsByLevel = ['campaign' => [], 'adset' => [], 'ad' => []];
        foreach ($events as $e) {
            $lvl = $e['level'];
            if (!empty($e['entity_id']) && isset($entityIdsByLevel[$lvl])) {
                $entityIdsByLevel[$lvl][] = $e['entity_id'];
            }
        }
        $lifetimeByLevel = ['campaign' => [], 'adset' => [], 'ad' => []];
        $idColPerLevel   = ['campaign' => 'campaign_id', 'adset' => 'ad_set_id', 'ad' => 'ad_id'];
        foreach ($entityIdsByLevel as $lvl => $ids) {
            $ids = array_values(array_unique($ids));
            if (empty($ids)) continue;
            $idCol = $idColPerLevel[$lvl];
            // Pull daily metrics for those entities — no date range cap;
            // we want LIFETIME totals up to each event_day.
            $rowsAll = DB::table('ads_manager_reports')
                ->whereNotNull('day')
                ->whereIn($idCol, $ids)
                ->selectRaw("
                    $idCol AS id,
                    $dayExpr AS d,
                    COALESCE(SUM(amount_spent_php), 0) AS spend,
                    COALESCE(SUM(impressions), 0)     AS impressions,
                    COALESCE(SUM(messaging_conversations_started), 0) AS messages,
                    COALESCE(SUM(purchases), 0)       AS purchases,
                    COALESCE(SUM(link_clicks), 0)     AS link_clicks,
                    COALESCE(SUM(results), 0)         AS results
                ")
                ->groupBy($idCol, DB::raw($dayExpr))
                ->orderBy(DB::raw('1'))   // by id
                ->orderBy(DB::raw('2'))   // then by day ASC
                ->get();

            // Build per-id sorted day list with running cumulatives.
            $perId = []; // [id => [['d'=>..., 'cum_spend'=>..., 'cum_impr'=>..., ...], ...]]
            $running = []; // [id => ['spend'=>..., 'impressions'=>..., ...]]
            foreach ($rowsAll as $r) {
                $id = (string) $r->id;
                if (!isset($running[$id])) {
                    $running[$id] = ['spend'=>0,'impr'=>0,'msgs'=>0,'purch'=>0,'lc'=>0,'res'=>0];
                }
                $running[$id]['spend'] += (float) $r->spend;
                $running[$id]['impr']  += (float) $r->impressions;
                $running[$id]['msgs']  += (float) $r->messages;
                $running[$id]['purch'] += (float) $r->purchases;
                $running[$id]['lc']    += (float) $r->link_clicks;
                $running[$id]['res']   += (float) $r->results;
                $perId[$id][] = [
                    'd'         => (string) $r->d,
                    'spend'     => $running[$id]['spend'],
                    'impr'      => $running[$id]['impr'],
                    'msgs'      => $running[$id]['msgs'],
                    'purch'     => $running[$id]['purch'],
                    'lc'        => $running[$id]['lc'],
                    'res'       => $running[$id]['res'],
                ];
            }
            $lifetimeByLevel[$lvl] = $perId;
        }

        // Resolver: "lifetime metrics up to <date> for entity <id> at <level>"
        // — picks the latest cumulative snapshot whose d <= the target date.
        $resolveLifetime = function (string $level, string $id, string $upTo) use (&$lifetimeByLevel) {
            $list = $lifetimeByLevel[$level][$id] ?? null;
            if (!$list) return null;
            $hit = null;
            foreach ($list as $row) {
                if ($row['d'] <= $upTo) $hit = $row;
                else break;
            }
            return $hit;
        };

        // Stitch into events. Spend = raw FB amount_spent_php (no VAT math).
        //
        // NOTE: in this page "CPM" = COST PER MESSAGING (spend / messages),
        // NOT the standard Cost-Per-Mille (cost per 1,000 impressions). User
        // request — semantics are domain-specific to the messenger ads
        // workflow, where every "M" stands for "Messaging".
        foreach ($events as &$e) {
            $life = $resolveLifetime($e['level'], $e['entity_id'], $e['day']);
            if ($life) {
                $spend = $life['spend'];
                $msgs  = $life['msgs'];
                $purch = $life['purch'];
                $lc    = $life['lc'];
                $e['lifetime_spend']   = round($spend, 2);
                $e['lifetime_cpm']     = $msgs  > 0 ? round($spend / $msgs, 2) : null; // cost per messaging
                $e['lifetime_cpp']     = $purch > 0 ? round($spend / $purch, 2) : null;
                $e['lifetime_wmr']     = $lc    > 0 ? round(($msgs * 100.0) / $lc, 1) : null;
                $e['lifetime_conv']    = $msgs  > 0 ? round(($purch * 100.0) / $msgs, 1) : null;
            } else {
                $e['lifetime_spend']   = null;
                $e['lifetime_cpm']     = null;
                $e['lifetime_cpp']     = null;
                $e['lifetime_wmr']     = null;
                $e['lifetime_conv']    = null;
            }
        }
        unset($e);

        // Resolve account_id + account_name per event via campaign_id.
        // account_id lives sa ad_campaign_creatives now (campaign-level attribute),
        // not on the daily fact rows.
        $cmpIds = [];
        foreach ($events as $e) {
            if (!empty($e['campaign_id'])) $cmpIds[] = $e['campaign_id'];
        }
        $cmpIds = array_values(array_unique($cmpIds));

        $accountByCampaign = [];
        if (!empty($cmpIds) && Schema::hasColumn('ad_campaign_creatives', 'account_id')) {
            $accountByCampaign = DB::table('ad_campaign_creatives')
                ->whereIn('campaign_id', $cmpIds)
                ->whereNotNull('account_id')
                ->where('account_id', '!=', '')
                ->selectRaw('campaign_id, MAX(account_id) AS account_id')
                ->groupBy('campaign_id')
                ->pluck('account_id', 'campaign_id')
                ->all();
        }
        $accountNameMap = [];
        if (!empty($accountByCampaign) && Schema::hasTable('ad_accounts')) {
            $accountNameMap = DB::table('ad_accounts')
                ->whereIn('ad_account_id', array_values($accountByCampaign))
                ->pluck('name', 'ad_account_id')
                ->all();
        }
        foreach ($events as &$e) {
            $cid = $e['campaign_id'] ?? null;
            $aid = $cid && isset($accountByCampaign[$cid]) ? (string) $accountByCampaign[$cid] : null;
            $e['account_id']   = $aid;
            $e['account_name'] = ($aid && isset($accountNameMap[$aid]))
                ? (string) $accountNameMap[$aid]
                : null;
        }
        unset($e);

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

    /**
     * GET /ads_manager/creative-preview
     *
     * Returns creative content (body, headline, welcome_message, quick replies,
     * ad_link) + page context for a single campaign/adset/ad. Used by the
     * /owner/private expanded-campaigns popup para mag-preview ng FB post +
     * Messenger conversation flow without leaving the page.
     *
     * Params:
     *   - level: 'campaign' | 'adset' | 'ad'
     *   - id:    campaign_id | ad_set_id | ad_id (string)
     *
     * Lookup strategy:
     *   - level=ad:       try ad_campaign_creatives.ad_id first; fallback campaign_id
     *   - level=adset:    join ads_manager_reports.ad_set_id → first matching ad_id
     *                     → look up via ad_campaign_creatives (or campaign_id if walang ad-level row)
     *   - level=campaign: ad_campaign_creatives.campaign_id (first match)
     */
    public function creativePreview(Request $request)
    {
        $level = strtolower(trim((string) $request->input('level', '')));
        $id    = trim((string) $request->input('id', ''));

        if (!in_array($level, ['campaign', 'adset', 'ad'], true) || $id === '') {
            return response()->json(['ok' => false, 'message' => 'Invalid level or id'], 422);
        }

        // Short cache — creative blobs rarely change; bumps along with owner cache.
        $cacheKey = "ads_mgr_creative_preview:{$level}:{$id}";
        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if (is_array($cached)) {
            return response()->json($cached + ['_cache' => 'hit']);
        }

        $cre = null;
        $entityName = null;
        $pageName = null;
        $campaignName = null;
        $adSetName = null;

        if ($level === 'ad') {
            // Try by ad_id first
            $cre = DB::table('ad_campaign_creatives')
                ->where('ad_id', $id)
                ->first(['campaign_id', 'ad_id', 'page_name', 'campaign_name',
                         'body_ad_settings', 'headline', 'welcome_message',
                         'quick_reply_1', 'quick_reply_2', 'quick_reply_3', 'ad_link']);
            // Fallback: lookup via reports for headline + campaign info
            $report = DB::table('ads_manager_reports')
                ->where('ad_id', $id)
                ->orderByDesc('day')
                ->first(['headline', 'campaign_id', 'campaign_name', 'ad_set_name', 'page_name']);
            if ($report) {
                $entityName   = $report->headline ?: ('Ad ' . $id);
                $pageName     = $report->page_name;
                $campaignName = $report->campaign_name;
                $adSetName    = $report->ad_set_name;
            }
            // If walang ad-level creative, try campaign-level fallback
            if (!$cre && $report && $report->campaign_id) {
                $cre = DB::table('ad_campaign_creatives')
                    ->where('campaign_id', $report->campaign_id)
                    ->first(['campaign_id', 'ad_id', 'page_name', 'campaign_name',
                             'body_ad_settings', 'headline', 'welcome_message',
                             'quick_reply_1', 'quick_reply_2', 'quick_reply_3', 'ad_link']);
            }
        } elseif ($level === 'adset') {
            // Fetch ad_set context from reports
            $report = DB::table('ads_manager_reports')
                ->where('ad_set_id', $id)
                ->orderByDesc('day')
                ->first(['ad_set_name', 'campaign_id', 'campaign_name', 'page_name', 'ad_id']);
            if ($report) {
                $entityName   = $report->ad_set_name ?: ('Ad Set ' . $id);
                $pageName     = $report->page_name;
                $campaignName = $report->campaign_name;
            }
            // Try to find the creative — adsets don't directly map sa
            // ad_campaign_creatives, so go via any matching ad_id, fallback campaign_id.
            if ($report && $report->ad_id) {
                $cre = DB::table('ad_campaign_creatives')
                    ->where('ad_id', $report->ad_id)
                    ->first(['campaign_id', 'ad_id', 'page_name', 'campaign_name',
                             'body_ad_settings', 'headline', 'welcome_message',
                             'quick_reply_1', 'quick_reply_2', 'quick_reply_3', 'ad_link']);
            }
            if (!$cre && $report && $report->campaign_id) {
                $cre = DB::table('ad_campaign_creatives')
                    ->where('campaign_id', $report->campaign_id)
                    ->first(['campaign_id', 'ad_id', 'page_name', 'campaign_name',
                             'body_ad_settings', 'headline', 'welcome_message',
                             'quick_reply_1', 'quick_reply_2', 'quick_reply_3', 'ad_link']);
            }
        } else {
            // level=campaign
            $cre = DB::table('ad_campaign_creatives')
                ->where('campaign_id', $id)
                ->first(['campaign_id', 'ad_id', 'page_name', 'campaign_name',
                         'body_ad_settings', 'headline', 'welcome_message',
                         'quick_reply_1', 'quick_reply_2', 'quick_reply_3', 'ad_link']);
            $report = DB::table('ads_manager_reports')
                ->where('campaign_id', $id)
                ->orderByDesc('day')
                ->first(['campaign_name', 'page_name']);
            if ($report) {
                $entityName = $report->campaign_name ?: ('Campaign ' . $id);
                $pageName   = $report->page_name;
            }
        }

        // Use creative's page_name as fallback if reports didn't yield one.
        if (!$pageName && $cre) $pageName = $cre->page_name ?? null;
        if (!$entityName && $cre) {
            $entityName = $cre->campaign_name ?? ($cre->headline ?? '');
        }

        $payload = [
            'ok'         => true,
            'level'      => $level,
            'id'         => $id,
            'entity_name'=> $entityName ?: ucfirst($level) . ' ' . $id,
            'page_name'  => $pageName ?: '',
            'campaign_name' => $campaignName ?: ($cre->campaign_name ?? ''),
            'ad_set_name'   => $adSetName ?: '',
            'creative'   => [
                'body'            => $cre->body_ad_settings ?? '',
                'headline'        => $cre->headline         ?? '',
                'welcome_message' => $cre->welcome_message  ?? '',
                'quick_reply_1'   => $cre->quick_reply_1    ?? '',
                'quick_reply_2'   => $cre->quick_reply_2    ?? '',
                'quick_reply_3'   => $cre->quick_reply_3    ?? '',
                'ad_link'         => $cre->ad_link          ?? '',
            ],
        ];

        \Illuminate\Support\Facades\Cache::put($cacheKey, $payload, 300); // 5 min
        return response()->json($payload + ['_cache' => 'miss']);
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
        //   include_windows — when '1', adds cpp_today / cpp_3d / cpp_7d trailing
        //                     windows anchored at end_date (independent of the
        //                     main range filter). Off by default to keep legacy
        //                     callers' response shape unchanged.
        // All optional (omit for the standalone /ads_manager/campaigns view).
        $itemName       = $request->input('item_name');
        $onlyWithSpend  = (string) $request->input('only_with_spend', '') === '1';
        $includeWindows = (string) $request->input('include_windows', '') === '1';

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

        // Search (case-insensitive) — LEVEL-AWARE so the user's intuition
        // matches: "I'm on Campaigns tab, I expect campaign-name matches".
        //   Campaigns tab: campaign_name only
        //   Adsets tab:    ad_set_name only
        //   Ads tab:       headline + item_name (the ad's identifying fields)
        // This avoids surprise matches via deep body/headline text on parent
        // levels, and also makes the search query much cheaper (1–2 LIKEs
        // instead of 5).
        if ($q) {
            $like = '%'.trim($q).'%';
            if ($level === 'campaigns') {
                $base->whereRaw('LOWER(COALESCE(campaign_name, \'\')) LIKE LOWER(?)', [$like]);
            } elseif ($level === 'adsets') {
                $base->whereRaw('LOWER(COALESCE(ad_set_name, \'\')) LIKE LOWER(?)', [$like]);
            } else { // ads
                $base->where(function ($qq) use ($like) {
                    $qq->whereRaw('LOWER(COALESCE(headline, \'\'))  LIKE LOWER(?)', [$like])
                       ->orWhereRaw('LOWER(COALESCE(item_name, \'\')) LIKE LOWER(?)', [$like]);
                });
            }
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
                // Join the adset latest-status snapshot so we can count distinct
                // ad_set_ids per campaign whose latest delivery is ACTIVE.
                // Powers the "Inside" column (active adsets per campaign).
                ->leftJoinSub($adSetLatestStatus, 'asls', function ($j) {
                    $j->on('ads_manager_reports.ad_set_id', '=', 'asls.ad_set_id');
                })
                ->selectRaw('
                    ads_manager_reports.campaign_id,
                    MAX(campaign_name) AS campaign_name,
                    MAX(page_name)     AS page_name,

                    SUM(amount_spent_php) AS spend,
                    SUM(messaging_conversations_started) AS messages,
                    SUM(purchases) AS purchases,
                    SUM(impressions) AS impressions,
                    SUM(reach) AS reach,
                    SUM(link_clicks) AS link_clicks,

                    CASE WHEN SUM(purchases) > 0 THEN SUM(amount_spent_php)/SUM(purchases) END AS cpp,
                    CASE WHEN SUM(messaging_conversations_started) > 0 THEN SUM(amount_spent_php)/SUM(messaging_conversations_started) END AS cpm_msg,
                    CASE WHEN SUM(impressions) > 0 THEN (SUM(amount_spent_php)/SUM(impressions))*1000 END AS cpm_1000,
                    CASE WHEN SUM(reach) > 0 THEN (SUM(impressions) * 1.0)/SUM(reach) END AS frequency,
                    CASE WHEN SUM(results) > 0 THEN SUM(amount_spent_php)/SUM(results) END AS cpr,
                    CASE WHEN SUM(link_clicks) > 0 THEN (SUM(messaging_conversations_started)*100.0)/SUM(link_clicks) END AS welcome_msg_rate,
                    CASE WHEN SUM(messaging_conversations_started) > 0 THEN (SUM(purchases)*100.0)/SUM(messaging_conversations_started) END AS conversion_rate,

                    COUNT(DISTINCT CASE WHEN asls.is_on_latest = 1 THEN ads_manager_reports.ad_set_id END) AS active_subcount,

                    COALESCE(MAX(ls.is_on_latest), 0) AS is_on,
                    MAX(sd.first_started)  AS first_started,
                    MAX(sd.running_at_start) AS running_at_start,
                    MAX(fs.latest_started) AS latest_started
                ')
                ->groupBy('ads_manager_reports.campaign_id');

            // Drop campaigns with zero spend in the window when caller asked.
            if ($onlyWithSpend) $query->havingRaw('COALESCE(SUM(amount_spent_php),0) > 0');

            $sortable = ['spend','messages','purchases','cpp','cpm_msg','cpm_1000','cpr','impressions','reach','frequency','link_clicks','welcome_msg_rate','conversion_rate','campaign_name','page_name','first_started','latest_started'];

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
                    // account_id + account_name are stitched in below via a
                    // batched lookup against ad_campaign_creatives + ad_accounts.

                    'spend'           => (float) ($r->spend ?? 0),
                    'cpm_1000'        => isset($r->cpm_1000) ? (float) $r->cpm_1000 : null,
                    'cpm_msg'         => isset($r->cpm_msg)  ? (float) $r->cpm_msg  : null,
                    'cpp'             => isset($r->cpp)      ? (float) $r->cpp      : null,
                    'cpr'             => isset($r->cpr)      ? (float) $r->cpr      : null,
                    'messages'        => (int)   ($r->messages ?? 0),
                    'purchases'       => (int)   ($r->purchases ?? 0),
                    'impressions'     => (int)   ($r->impressions ?? 0),
                    'reach'           => (int)   ($r->reach ?? 0),
                    'frequency'       => isset($r->frequency) ? (float) $r->frequency : null,
                    'link_clicks'      => $r->link_clicks !== null ? (int) $r->link_clicks : null,
                    'welcome_msg_rate' => isset($r->welcome_msg_rate) ? (float) $r->welcome_msg_rate : null,
                    'conversion_rate'  => isset($r->conversion_rate)  ? (float) $r->conversion_rate  : null,
                    // "Inside" — count of currently-ACTIVE adsets inside this campaign
                    // that had activity (any row) within the current filter window.
                    'active_subcount'  => (int) ($r->active_subcount ?? 0),
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

                    SUM(amount_spent_php) AS spend,
                    SUM(messaging_conversations_started) AS messages,
                    SUM(purchases) AS purchases,
                    SUM(impressions) AS impressions,
                    SUM(reach) AS reach,
                    SUM(link_clicks) AS link_clicks,

                    CASE WHEN SUM(purchases) > 0 THEN SUM(amount_spent_php)/SUM(purchases) END AS cpp,
                    CASE WHEN SUM(messaging_conversations_started) > 0 THEN SUM(amount_spent_php)/SUM(messaging_conversations_started) END AS cpm_msg,
                    CASE WHEN SUM(impressions) > 0 THEN (SUM(amount_spent_php)/SUM(impressions))*1000 END AS cpm_1000,
                    CASE WHEN SUM(reach) > 0 THEN (SUM(impressions) * 1.0)/SUM(reach) END AS frequency,
                    CASE WHEN SUM(results) > 0 THEN SUM(amount_spent_php)/SUM(results) END AS cpr,
                    CASE WHEN SUM(link_clicks) > 0 THEN (SUM(messaging_conversations_started)*100.0)/SUM(link_clicks) END AS welcome_msg_rate,
                    CASE WHEN SUM(messaging_conversations_started) > 0 THEN (SUM(purchases)*100.0)/SUM(messaging_conversations_started) END AS conversion_rate,

                    -- "Inside" — count of distinct ads inside this adset.
                    -- Ads inherit ACTIVE state from their parent adset (ls.is_on_latest).
                    -- Only counts ads where parent adset is currently ACTIVE.
                    COUNT(DISTINCT CASE WHEN ls.is_on_latest = 1 THEN ads_manager_reports.ad_id END) AS active_subcount,

                    COALESCE(MAX(ls.is_on_latest), 0) AS is_on,
                    MAX(sd.first_started)  AS first_started,
                    MAX(sd.running_at_start) AS running_at_start,
                    MAX(fs.latest_started) AS latest_started
                ')
                ->groupBy('ads_manager_reports.ad_set_id');

            if ($onlyWithSpend) $query->havingRaw('COALESCE(SUM(amount_spent_php),0) > 0');

            $sortable = ['spend','messages','purchases','cpp','cpm_msg','cpm_1000','cpr','impressions','reach','frequency','link_clicks','welcome_msg_rate','conversion_rate','ad_set_name','campaign_name','page_name','first_started','latest_started'];

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
                    // account_id + account_name are stitched in below via a
                    // batched lookup against ad_campaign_creatives + ad_accounts.

                    'spend'           => (float) ($r->spend ?? 0),
                    'cpm_1000'        => isset($r->cpm_1000) ? (float) $r->cpm_1000 : null,
                    'cpm_msg'         => isset($r->cpm_msg)  ? (float) $r->cpm_msg  : null,
                    'cpp'             => isset($r->cpp)      ? (float) $r->cpp      : null,
                    'cpr'             => isset($r->cpr)      ? (float) $r->cpr      : null,
                    'messages'        => (int)   ($r->messages ?? 0),
                    'purchases'       => (int)   ($r->purchases ?? 0),
                    'impressions'     => (int)   ($r->impressions ?? 0),
                    'reach'           => (int)   ($r->reach ?? 0),
                    'frequency'       => isset($r->frequency) ? (float) $r->frequency : null,
                    'link_clicks'      => $r->link_clicks !== null ? (int) $r->link_clicks : null,
                    'welcome_msg_rate' => isset($r->welcome_msg_rate) ? (float) $r->welcome_msg_rate : null,
                    'conversion_rate'  => isset($r->conversion_rate)  ? (float) $r->conversion_rate  : null,
                    // "Inside" — count of currently-ACTIVE ads inside this adset.
                    'active_subcount'  => (int) ($r->active_subcount ?? 0),
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

                    SUM(amount_spent_php) AS spend,
                    SUM(messaging_conversations_started) AS messages,
                    SUM(purchases) AS purchases,
                    SUM(impressions) AS impressions,
                    SUM(reach) AS reach,
                    SUM(link_clicks) AS link_clicks,

                    CASE WHEN SUM(purchases) > 0 THEN SUM(amount_spent_php)/SUM(purchases) END AS cpp,
                    CASE WHEN SUM(messaging_conversations_started) > 0 THEN SUM(amount_spent_php)/SUM(messaging_conversations_started) END AS cpm_msg,
                    CASE WHEN SUM(impressions) > 0 THEN (SUM(amount_spent_php)/SUM(impressions))*1000 END AS cpm_1000,
                    CASE WHEN SUM(reach) > 0 THEN (SUM(impressions) * 1.0)/SUM(reach) END AS frequency,
                    CASE WHEN SUM(results) > 0 THEN SUM(amount_spent_php)/SUM(results) END AS cpr,
                    CASE WHEN SUM(link_clicks) > 0 THEN (SUM(messaging_conversations_started)*100.0)/SUM(link_clicks) END AS welcome_msg_rate,
                    CASE WHEN SUM(messaging_conversations_started) > 0 THEN (SUM(purchases)*100.0)/SUM(messaging_conversations_started) END AS conversion_rate,

                    COALESCE(MAX(ls.is_on_latest), 0) AS is_on,
                    MAX(sd.first_started)  AS first_started,
                    MAX(sd.running_at_start) AS running_at_start,
                    MAX(fs.latest_started) AS latest_started
                ')
                ->groupBy('ads_manager_reports.ad_id');

            if ($onlyWithSpend) $query->havingRaw('COALESCE(SUM(amount_spent_php),0) > 0');

            $sortable = ['spend','messages','purchases','cpp','cpm_msg','cpm_1000','cpr','impressions','reach','frequency','link_clicks','welcome_msg_rate','conversion_rate','headline','item_name','first_started','latest_started'];

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
                    // account_id + account_name are stitched in below via a
                    // batched lookup against ad_campaign_creatives + ad_accounts.

                    'spend'           => (float) ($r->spend ?? 0),
                    'cpm_1000'        => isset($r->cpm_1000) ? (float) $r->cpm_1000 : null,
                    'cpm_msg'         => isset($r->cpm_msg)  ? (float) $r->cpm_msg  : null,
                    'cpp'             => isset($r->cpp)      ? (float) $r->cpp      : null,
                    'cpr'             => isset($r->cpr)      ? (float) $r->cpr      : null,
                    'messages'        => (int)   ($r->messages ?? 0),
                    'purchases'       => (int)   ($r->purchases ?? 0),
                    'impressions'     => (int)   ($r->impressions ?? 0),
                    'reach'           => (int)   ($r->reach ?? 0),
                    'frequency'       => isset($r->frequency) ? (float) $r->frequency : null,
                    'link_clicks'      => $r->link_clicks !== null ? (int) $r->link_clicks : null,
                    'welcome_msg_rate' => isset($r->welcome_msg_rate) ? (float) $r->welcome_msg_rate : null,
                    'conversion_rate'  => isset($r->conversion_rate)  ? (float) $r->conversion_rate  : null,
                    // "Inside" — ads are leaf-level, no children to count.
                    'active_subcount'  => null,
                ];
            });
        }

        // ──────────────────────────────────────────────────────────────────
        // Windowed CPPs (today / 3d / 7d) — opt-in via include_windows=1.
        // Computed via a SEPARATE aggregation against ads_manager_reports with
        // a [end_date − 6, end_date] date filter (independent of the main
        // start/end filter). Stitched into rows by id. Skipped when end_date
        // is missing.
        // ──────────────────────────────────────────────────────────────────
        if ($includeWindows && $end) {
            $endDateObj = new \DateTime($end);
            $endStr     = $endDateObj->format('Y-m-d');
            $endMinus2  = (clone $endDateObj)->modify('-2 days')->format('Y-m-d');
            $endMinus6  = (clone $endDateObj)->modify('-6 days')->format('Y-m-d');

            $idCol = $level === 'campaigns' ? 'campaign_id'
                   : ($level === 'adsets'   ? 'ad_set_id' : 'ad_id');

            // Mirror the same NON-DATE filters as $base. Date filter overridden
            // to the trailing-7-day window.
            $winQuery = DB::table('ads_manager_reports')
                ->whereRaw("$dayExpr BETWEEN ? AND ?", [$endMinus6, $endStr]);

            if ($pageName && $pageName !== 'all') {
                $winQuery->whereRaw('LOWER(TRIM(page_name)) = LOWER(TRIM(?))', [$pageName]);
            }
            if (is_string($itemName) && trim($itemName) !== '') {
                $likeItem = '%'.mb_strtolower(trim($itemName)).'%';
                $winQuery->whereRaw('LOWER(COALESCE(item_name,\'\')) LIKE ?', [$likeItem]);
            }
            if ($q) {
                $likeQ = '%'.trim($q).'%';
                if ($level === 'campaigns') {
                    $winQuery->whereRaw('LOWER(COALESCE(campaign_name, \'\')) LIKE LOWER(?)', [$likeQ]);
                } elseif ($level === 'adsets') {
                    $winQuery->whereRaw('LOWER(COALESCE(ad_set_name, \'\')) LIKE LOWER(?)', [$likeQ]);
                } else {
                    $winQuery->where(function ($qq) use ($likeQ) {
                        $qq->whereRaw('LOWER(COALESCE(headline, \'\'))  LIKE LOWER(?)', [$likeQ])
                           ->orWhereRaw('LOWER(COALESCE(item_name, \'\')) LIKE LOWER(?)', [$likeQ]);
                    });
                }
            }
            if ($level !== 'campaigns' && !empty($campaignIds)) {
                $winQuery->whereIn('campaign_id', $campaignIds);
            }
            if ($level === 'ads' && !empty($adSetIds)) {
                $winQuery->whereIn('ad_set_id', $adSetIds);
            }
            if ($level === 'campaigns' && $campaignId) {
                $winQuery->where('campaign_id', $campaignId);
            } elseif ($level === 'adsets' && empty($campaignIds) && $campaignId) {
                $winQuery->where('campaign_id', $campaignId);
            } elseif ($level === 'ads' && empty($adSetIds) && $adSetId) {
                $winQuery->where('ad_set_id', $adSetId);
            }

            $winRows = $winQuery
                ->selectRaw("
                    $idCol AS id,
                    SUM(CASE WHEN $dayExpr = ?              THEN amount_spent_php ELSE 0 END) AS spend_today,
                    SUM(CASE WHEN $dayExpr BETWEEN ? AND ?  THEN amount_spent_php ELSE 0 END) AS spend_3d,
                    SUM(amount_spent_php)                                                     AS spend_7d,
                    SUM(CASE WHEN $dayExpr = ?              THEN purchases       ELSE 0 END)           AS purch_today,
                    SUM(CASE WHEN $dayExpr BETWEEN ? AND ?  THEN purchases       ELSE 0 END)           AS purch_3d,
                    SUM(purchases)                                                                     AS purch_7d
                ", [$endStr, $endMinus2, $endStr, $endStr, $endMinus2, $endStr])
                ->groupBy($idCol)
                ->get();

            $windowMap = [];
            foreach ($winRows as $w) {
                $windowMap[(string) $w->id] = $w;
            }

            $rows = $rows->map(function ($r) use ($windowMap, $level) {
                $idKey = $level === 'campaigns' ? ($r['campaign_id'] ?? null)
                       : ($level === 'adsets'   ? ($r['ad_set_id']   ?? null)
                       : ($r['ad_id'] ?? null));
                $w = $idKey !== null ? ($windowMap[(string) $idKey] ?? null) : null;
                $r['cpp_today']       = ($w && (int) $w->purch_today > 0) ? round((float) $w->spend_today / (float) $w->purch_today, 2) : null;
                $r['cpp_3d']          = ($w && (int) $w->purch_3d    > 0) ? round((float) $w->spend_3d    / (float) $w->purch_3d,    2) : null;
                $r['cpp_7d']          = ($w && (int) $w->purch_7d    > 0) ? round((float) $w->spend_7d    / (float) $w->purch_7d,    2) : null;
                $r['spend_today']     = $w ? (float) $w->spend_today : 0.0;
                $r['spend_3d']        = $w ? (float) $w->spend_3d    : 0.0;
                $r['spend_7d']        = $w ? (float) $w->spend_7d    : 0.0;
                $r['purchases_today'] = $w ? (int)   $w->purch_today : 0;
                $r['purchases_3d']    = $w ? (int)   $w->purch_3d    : 0;
                $r['purchases_7d']    = $w ? (int)   $w->purch_7d    : 0;
                return $r;
            });
        }

        // Resolve account_id + account_name from `ad_campaign_creatives` (the
        // source of truth for the campaign→account relationship) JOIN'd with
        // the `ad_accounts` catalog (for human-readable name).
        //
        // We collect the campaign_ids appearing in the result and do a single
        // batched lookup. account_id is keyed per campaign_id (1:1).
        $campaignIdsInResult = [];
        foreach ($rows as $r) {
            $cid = $r['campaign_id'] ?? null;
            if ($cid) $campaignIdsInResult[] = (string) $cid;
        }
        $campaignIdsInResult = array_values(array_unique($campaignIdsInResult));

        $accountByCampaign = []; // campaign_id => account_id
        if (!empty($campaignIdsInResult) && Schema::hasColumn('ad_campaign_creatives', 'account_id')) {
            $accountByCampaign = DB::table('ad_campaign_creatives')
                ->whereIn('campaign_id', $campaignIdsInResult)
                ->whereNotNull('account_id')
                ->where('account_id', '!=', '')
                ->selectRaw('campaign_id, MAX(account_id) AS account_id')
                ->groupBy('campaign_id')
                ->pluck('account_id', 'campaign_id')
                ->all();
        }

        $accountNameMap = [];
        if (!empty($accountByCampaign) && Schema::hasTable('ad_accounts')) {
            $accountNameMap = DB::table('ad_accounts')
                ->whereIn('ad_account_id', array_values($accountByCampaign))
                ->pluck('name', 'ad_account_id')
                ->all();
        }
        $rows = $rows->map(function ($r) use ($accountByCampaign, $accountNameMap) {
            $cid = $r['campaign_id'] ?? null;
            $aid = $cid && isset($accountByCampaign[$cid]) ? (string) $accountByCampaign[$cid] : null;
            $r['account_id']   = $aid;
            $r['account_name'] = ($aid && isset($accountNameMap[$aid]))
                ? (string) $accountNameMap[$aid]
                : null;
            return $r;
        });

        // ── Profit% per row — DISABLED (computed client-side instead).
        // Yung parent page row's data (price, rts_pct, item_value) is already
        // loaded sa browser, so client-side compute is more efficient + no
        // duplicate DB queries. See _campaignProfitPctFromCpp() sa private.blade.
        if (false) {
        try {
            $endRef    = $end ?? (new \DateTime('now', new \DateTimeZone('Asia/Manila')))->format('Y-m-d');
            $hostName  = strtolower((string) $request->getHost());
            $SHIPPING  = \App\Models\FeeSetting::getRate('shipping_fee_per_order', $hostName, $endRef);
            $COD_RATE  = \App\Models\FeeSetting::getRate('cod_fee_rate',           $hostName, $endRef);
            $VAT_RATE  = \App\Models\FeeSetting::getRate('cod_fee_vat_rate',       $hostName, $endRef);

            // Always tag every row with diagnostic info so we can see WHY
            // profit_pct is null (even if the early-skip is hit).
            $feeMissing = [];
            if ($SHIPPING === null) $feeMissing[] = 'SHIPPING';
            if ($COD_RATE === null) $feeMissing[] = 'COD_RATE';
            if ($VAT_RATE === null) $feeMissing[] = 'VAT_RATE';

            if (!empty($feeMissing)) {
                $missList = implode(',', $feeMissing);
                $rows = $rows->map(function ($r) use ($missList, $hostName, $endRef) {
                    $isArr = is_array($r);
                    $key   = "no_fee_settings:$missList host=$hostName ref=$endRef";
                    if ($isArr) { $r['profit_pct'] = null; $r['_profit_debug'] = $key; }
                    else        { $r->profit_pct   = null; $r->_profit_debug   = $key; }
                    return $r;
                });
            }

            if ($SHIPPING !== null && $COD_RATE !== null && $VAT_RATE !== null) {
                // Collect unique page_names from rows. Some rows may be arrays
                // or stdClass — handle both.
                $pageNames = [];
                foreach ($rows as $r) {
                    $pn = is_array($r) ? ($r['page_name'] ?? '') : ($r->page_name ?? '');
                    $pn = trim((string)$pn);
                    if ($pn !== '') $pageNames[strtolower($pn)] = true;
                }
                $pageKeys = array_keys($pageNames);

                $pageInfo = [];
                if (!empty($pageKeys) && Schema::hasTable('daily_page_primary_item')) {
                    $primaryRows = DB::table('daily_page_primary_item')
                        ->whereIn('page_key', $pageKeys)
                        ->where('ts_date', '<=', $endRef)
                        ->orderBy('page_key')
                        ->orderByDesc('ts_date')
                        ->get(['page_key', 'primary_item', 'primary_item_key', 'primary_mode_cod']);
                    foreach ($primaryRows as $p) {
                        $pk = (string)$p->page_key;
                        if (!isset($pageInfo[$pk])) {
                            $pageInfo[$pk] = [
                                'item'     => (string)$p->primary_item,
                                'item_key' => (string)$p->primary_item_key,
                                'mode_cod' => $p->primary_mode_cod !== null ? (float)$p->primary_mode_cod : 0.0,
                            ];
                        }
                    }
                }

                $rtsByKey = [];
                if (Schema::hasTable('page_item_settings')) {
                    $settingRows = DB::table('page_item_settings')
                        ->where('effective_date', '<=', $endRef)
                        ->orderByDesc('effective_date')
                        ->orderByDesc('id')
                        ->get(['page_name', 'item_name', 'rts_pct']);
                    foreach ($settingRows as $s) {
                        // Skip rows with NULL rts_pct (promo-only saves post-2026-05-21).
                        if ($s->rts_pct === null) continue;
                        $k = strtolower(trim((string)$s->page_name)) . '||' . strtolower(trim((string)$s->item_name));
                        if (!isset($rtsByKey[$k])) $rtsByKey[$k] = (float)$s->rts_pct;
                    }
                }

                // TCPR per page — for orders_est = purchases / (1 − tcpr/100)
                // computation. Pulled from macro_output sa selected date range.
                // Detect actual column names sa macro_output (varies: PAGE/page/page_name)
                $tcprByPage = [];
                if (!empty($pageKeys) && $start && $end && Schema::hasTable('macro_output')) {
                    $moPageCol   = null;
                    foreach (['PAGE','page','page_name','Page','Page_Name'] as $cand) {
                        if (Schema::hasColumn('macro_output', $cand)) { $moPageCol = $cand; break; }
                    }
                    $moStatusCol = null;
                    foreach (['STATUS','status','Status'] as $cand) {
                        if (Schema::hasColumn('macro_output', $cand)) { $moStatusCol = $cand; break; }
                    }
                    $moDateCol = null;
                    foreach (['ts_date','date','order_date'] as $cand) {
                        if (Schema::hasColumn('macro_output', $cand)) { $moDateCol = $cand; break; }
                    }

                    if ($moPageCol && $moStatusCol && $moDateCol) {
                        $pq = '`' . $moPageCol   . '`';
                        $sq = '`' . $moStatusCol . '`';
                        $dq = '`' . $moDateCol   . '`';
                        $inList = "'" . implode("','", array_map(fn($k) => str_replace("'", "''", $k), $pageKeys)) . "'";
                        $tcprRows = DB::table('macro_output')
                            ->whereRaw("LOWER(TRIM($pq)) IN ($inList)")
                            ->whereRaw("$dq BETWEEN ? AND ?", [$start, $end])
                            ->selectRaw("LOWER(TRIM($pq)) AS page_key,
                                COUNT(*) AS orders_total,
                                SUM(CASE WHEN LOWER(REPLACE(REPLACE(TRIM($sq),' ',''),'_','')) = 'proceed' THEN 1 ELSE 0 END) AS proceed_total")
                            ->groupByRaw("LOWER(TRIM($pq))")
                            ->get();
                        foreach ($tcprRows as $t) {
                            $orders  = (int)$t->orders_total;
                            $proceed = (int)$t->proceed_total;
                            $tcprByPage[(string)$t->page_key] = $orders > 0
                                ? max(0.0, min(100.0, (1 - ($proceed / $orders)) * 100.0))
                                : 0.0;
                        }
                    }
                }

                $cogsByItem = [];
                if (Schema::hasTable('cogs')) {
                    // Detect actual column names sa cogs (varies by deployment)
                    $cogsItemCol = null;
                    foreach (['item_name','ITEM_NAME','product','Product','Product_Name'] as $cand) {
                        if (Schema::hasColumn('cogs', $cand)) { $cogsItemCol = $cand; break; }
                    }
                    $cogsDateCol = null;
                    foreach (['effective_date','date','valid_from','cogs_date'] as $cand) {
                        if (Schema::hasColumn('cogs', $cand)) { $cogsDateCol = $cand; break; }
                    }
                    $cogsCostCol = null;
                    foreach (['unit_cost','cost','unitprice','unit_price','price'] as $cand) {
                        if (Schema::hasColumn('cogs', $cand)) { $cogsCostCol = $cand; break; }
                    }

                    if ($cogsItemCol && $cogsDateCol && $cogsCostCol) {
                        $cogsRows = DB::table('cogs')
                            ->where($cogsDateCol, '<=', $endRef)
                            ->orderByDesc($cogsDateCol)
                            ->orderByDesc('id')
                            ->get([$cogsItemCol . ' as item_name', $cogsCostCol . ' as unit_cost']);
                        foreach ($cogsRows as $c) {
                            $k = strtolower(trim((string)$c->item_name));
                            if (!isset($cogsByItem[$k])) $cogsByItem[$k] = (float)$c->unit_cost;
                        }
                    }
                }

                $rows = $rows->map(function ($r) use ($pageInfo, $rtsByKey, $cogsByItem, $tcprByPage, $SHIPPING, $COD_RATE, $VAT_RATE) {
                    $isArr = is_array($r);
                    $get   = function ($key) use ($r, $isArr) {
                        return $isArr ? ($r[$key] ?? null) : ($r->$key ?? null);
                    };
                    $set   = function ($key, $val) use (&$r, $isArr) {
                        if ($isArr) $r[$key] = $val; else $r->$key = $val;
                    };

                    // Initialize all 4 windows to null
                    $set('profit_pct', null);
                    $set('profit_pct_7d', null);
                    $set('profit_pct_3d', null);
                    $set('profit_pct_today', null);

                    $pageName = trim((string)($get('page_name') ?? ''));
                    if ($pageName === '') { $set('_profit_debug', 'no_page_name'); return $r; }
                    $pageKey = strtolower($pageName);
                    $info = $pageInfo[$pageKey] ?? null;
                    if (!$info) { $set('_profit_debug', 'no_primary_item:' . $pageKey); return $r; }
                    $modeCod = (float)$info['mode_cod'];
                    if ($modeCod <= 0) { $set('_profit_debug', 'no_mode_cod'); return $r; }
                    $itemKey = strtolower(trim((string)$info['item']));
                    $rtsKey  = $pageKey . '||' . $itemKey;
                    $rts     = $rtsByKey[$rtsKey] ?? null;
                    $iv      = $cogsByItem[$itemKey] ?? null;
                    if ($rts === null) { $set('_profit_debug', 'no_rts:' . $rtsKey); return $r; }
                    if ($iv  === null) { $set('_profit_debug', 'no_cogs:' . $itemKey); return $r; }

                    $df            = 1.0 - ((float)$rts / 100.0);
                    $codFeePerUnit = $modeCod * (float)$COD_RATE * (1.0 + (float)$VAT_RATE);

                    // Per-window profit_pct using each CPP variant
                    // profit_per_order = df × (price − iv − cod_fee_unit) − SF − cpp
                    // profit_pct       = profit_per_order ÷ price × 100
                    $computePct = function ($cppVal) use ($df, $modeCod, $iv, $codFeePerUnit, $SHIPPING) {
                        if ($cppVal === null || !is_numeric($cppVal)) return null;
                        $profit = $df * ($modeCod - (float)$iv - $codFeePerUnit) - (float)$SHIPPING - (float)$cppVal;
                        return round(($profit / $modeCod) * 100.0, 2);
                    };

                    $set('profit_pct',       $computePct($get('cpp')));
                    $set('profit_pct_7d',    $computePct($get('cpp_7d')));
                    $set('profit_pct_3d',    $computePct($get('cpp_3d')));
                    $set('profit_pct_today', $computePct($get('cpp_today')));

                    $set('_profit_debug', 'ok');
                    return $r;
                });
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('profit_pct enrichment failed: ' . $e->getMessage());
        }
        } // end if(false) — server-side enrichment disabled, computed client-side instead

        // Totals for current filter (no group)
        $tot = (clone $base)->selectRaw('
            COALESCE(SUM(amount_spent_php),0) AS spend,
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
            'frequency'   => ($tot->reach ?? 0)       > 0 ? (float) ($tot->impressions / $tot->reach) : null,
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
