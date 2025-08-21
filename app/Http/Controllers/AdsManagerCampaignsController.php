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

        return view('ads_manager.campaigns', compact('pages'));
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
                ->selectRaw('
                    ads_manager_reports.campaign_id,
                    MAX(campaign_name) AS campaign_name,
                    MAX(page_name)     AS page_name,

                    (SUM(amount_spent_php) / 1.12) AS spend,
                    SUM(messaging_conversations_started) AS messages,
                    SUM(purchases) AS purchases,
                    SUM(impressions) AS impressions,
                    SUM(reach) AS reach,

                    CASE WHEN SUM(purchases) > 0 THEN (SUM(amount_spent_php)/1.12)/SUM(purchases) END AS cpp,
                    CASE WHEN SUM(messaging_conversations_started) > 0 THEN (SUM(amount_spent_php)/1.12)/SUM(messaging_conversations_started) END AS cpm_msg,
                    CASE WHEN SUM(impressions) > 0 THEN ((SUM(amount_spent_php)/1.12)/SUM(impressions))*1000 END AS cpm_1000,
                    CASE WHEN SUM(results) > 0 THEN (SUM(amount_spent_php)/1.12)/SUM(results) END AS cpr,

                    COALESCE(MAX(ls.is_on_latest), 0) AS is_on
                ')
                ->groupBy('ads_manager_reports.campaign_id');

            $sortable = ['spend','messages','purchases','cpp','cpm_msg','cpm_1000','cpr','impressions','reach','campaign_name','page_name'];

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

                    'spend'           => (float) ($r->spend ?? 0),
                    'cpm_1000'        => isset($r->cpm_1000) ? (float) $r->cpm_1000 : null,
                    'cpm_msg'         => isset($r->cpm_msg)  ? (float) $r->cpm_msg  : null,
                    'cpp'             => isset($r->cpp)      ? (float) $r->cpp      : null,
                    'cpr'             => isset($r->cpr)      ? (float) $r->cpr      : null,
                    'messages'        => (int)   ($r->messages ?? 0),
                    'purchases'       => (int)   ($r->purchases ?? 0),
                    'impressions'     => (int)   ($r->impressions ?? 0),
                    'reach'           => (int)   ($r->reach ?? 0),
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

                    CASE WHEN SUM(purchases) > 0 THEN (SUM(amount_spent_php)/1.12)/SUM(purchases) END AS cpp,
                    CASE WHEN SUM(messaging_conversations_started) > 0 THEN (SUM(amount_spent_php)/1.12)/SUM(messaging_conversations_started) END AS cpm_msg,
                    CASE WHEN SUM(impressions) > 0 THEN ((SUM(amount_spent_php)/1.12)/SUM(impressions))*1000 END AS cpm_1000,
                    CASE WHEN SUM(results) > 0 THEN (SUM(amount_spent_php)/1.12)/SUM(results) END AS cpr,

                    COALESCE(MAX(ls.is_on_latest), 0) AS is_on
                ')
                ->groupBy('ads_manager_reports.ad_set_id');

            $sortable = ['spend','messages','purchases','cpp','cpm_msg','cpm_1000','cpr','impressions','reach','ad_set_name','campaign_name','page_name'];

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

                    'spend'           => (float) ($r->spend ?? 0),
                    'cpm_1000'        => isset($r->cpm_1000) ? (float) $r->cpm_1000 : null,
                    'cpm_msg'         => isset($r->cpm_msg)  ? (float) $r->cpm_msg  : null,
                    'cpp'             => isset($r->cpp)      ? (float) $r->cpp      : null,
                    'cpr'             => isset($r->cpr)      ? (float) $r->cpr      : null,
                    'messages'        => (int)   ($r->messages ?? 0),
                    'purchases'       => (int)   ($r->purchases ?? 0),
                    'impressions'     => (int)   ($r->impressions ?? 0),
                    'reach'           => (int)   ($r->reach ?? 0),
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

                    CASE WHEN SUM(purchases) > 0 THEN (SUM(amount_spent_php)/1.12)/SUM(purchases) END AS cpp,
                    CASE WHEN SUM(messaging_conversations_started) > 0 THEN (SUM(amount_spent_php)/1.12)/SUM(messaging_conversations_started) END AS cpm_msg,
                    CASE WHEN SUM(impressions) > 0 THEN ((SUM(amount_spent_php)/1.12)/SUM(impressions))*1000 END AS cpm_1000,
                    CASE WHEN SUM(results) > 0 THEN (SUM(amount_spent_php)/1.12)/SUM(results) END AS cpr,

                    COALESCE(MAX(ls.is_on_latest), 0) AS is_on
                ')
                ->groupBy('ads_manager_reports.ad_id');

            $sortable = ['spend','messages','purchases','cpp','cpm_msg','cpm_1000','cpr','impressions','reach','headline','item_name'];

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

                    'spend'           => (float) ($r->spend ?? 0),
                    'cpm_1000'        => isset($r->cpm_1000) ? (float) $r->cpm_1000 : null,
                    'cpm_msg'         => isset($r->cpm_msg)  ? (float) $r->cpm_msg  : null,
                    'cpp'             => isset($r->cpp)      ? (float) $r->cpp      : null,
                    'cpr'             => isset($r->cpr)      ? (float) $r->cpr      : null,
                    'messages'        => (int)   ($r->messages ?? 0),
                    'purchases'       => (int)   ($r->purchases ?? 0),
                    'impressions'     => (int)   ($r->impressions ?? 0),
                    'reach'           => (int)   ($r->reach ?? 0),
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
            COALESCE(SUM(results),0) AS results
        ')->first();

        $totals = [
            'spend'       => (float) ($tot->spend ?? 0),
            'messages'    => (int)   ($tot->messages ?? 0),
            'purchases'   => (int)   ($tot->purchases ?? 0),
            'impressions' => (int)   ($tot->impressions ?? 0),
            'reach'       => (int)   ($tot->reach ?? 0),
            'cpp'         => ($tot->purchases ?? 0)   > 0 ? (float) ($tot->spend / $tot->purchases) : null,
            'cpm_msg'     => ($tot->messages ?? 0)    > 0 ? (float) ($tot->spend / $tot->messages)  : null,
            'cpm_1000'    => ($tot->impressions ?? 0) > 0 ? (float) (($tot->spend / $tot->impressions) * 1000) : null,
            'cpr'         => ($tot->results ?? 0)     > 0 ? (float) ($tot->spend / $tot->results) : null,
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

        return response()->stream(function () use ($rows, $level) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

            if ($level === 'campaigns') {
                fputcsv($out, ['Campaign','Page','Active','Spend','CPM (1k)','Cost/Msg','Cost/Result','Cost/Purchase','Impr.','Msgs','Purchases']);
                foreach ($rows as $r) {
                    fputcsv($out, [
                        $r['campaign_name'], $r['page_name'], $r['on'] ? '1':'0',
                        $r['spend'], $r['cpm_1000'], $r['cpm_msg'], $r['cpr'], $r['cpp'],
                        $r['impressions'], $r['messages'], $r['purchases']
                    ]);
                }
            } elseif ($level === 'adsets') {
                fputcsv($out, ['Ad set','Campaign','Page','Active','Spend','CPM (1k)','Cost/Msg','Cost/Result','Cost/Purchase','Impr.','Msgs','Purchases']);
                foreach ($rows as $r) {
                    fputcsv($out, [
                        $r['ad_set_name'], $r['campaign_name'], $r['page_name'], $r['on'] ? '1':'0',
                        $r['spend'], $r['cpm_1000'], $r['cpm_msg'], $r['cpr'], $r['cpp'],
                        $r['impressions'], $r['messages'], $r['purchases']
                    ]);
                }
            } else {
                fputcsv($out, ['Ad (Headline)','Ad set','Campaign','Page','Active','Spend','CPM (1k)','Cost/Msg','Cost/Result','Cost/Purchase','Impr.','Msgs','Purchases']);
                foreach ($rows as $r) {
                    fputcsv($out, [
                        ($r['headline'] ?? 'Ad '.$r['ad_id']), $r['ad_set_name'], $r['campaign_name'], $r['page_name'], $r['on'] ? '1':'0',
                        $r['spend'], $r['cpm_1000'], $r['cpm_msg'], $r['cpr'], $r['cpp'],
                        $r['impressions'], $r['messages'], $r['purchases']
                    ]);
                }
            }
            fclose($out);
        }, 200, $headers);
    }
}
