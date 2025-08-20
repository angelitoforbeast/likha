<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdsManagerCampaignsController extends Controller
{
    public function index(Request $request)
    {
        // Render UI only; data is fetched via /ads_manager/campaigns/data
        return view('ads_manager.campaigns');
    }

    public function data(Request $request)
    {
        // Inputs
        $level       = $request->input('level', 'campaigns'); // campaigns|adsets|ads
        $start       = $request->input('start_date');         // YYYY-MM-DD
        $end         = $request->input('end_date');           // YYYY-MM-DD
        $pageName    = $request->input('page_name');          // optional
        $q           = $request->input('q');                  // search text
        $sortBy      = $request->input('sort_by', 'spend');
        $sortDir     = strtolower($request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $limit       = max(1, min((int) $request->input('limit', 200), 1000));

        // Drilldown params
        $campaignId  = $request->input('campaign_id');
        $adSetId     = $request->input('ad_set_id');

        $dayExpr = 'COALESCE(`day`, DATE(`reporting_starts`))';
        $base = DB::table('ads_manager_reports');

        // Filters
        if ($start) $base->whereRaw("$dayExpr >= ?", [$start]);
        if ($end)   $base->whereRaw("$dayExpr <= ?", [$end]);
        if ($pageName && $pageName !== 'all') $base->where('page_name', $pageName);

        if ($q) {
            $base->where(function ($qq) use ($q) {
                $qq->where('campaign_name', 'like', "%{$q}%")
                   ->orWhere('ad_set_name', 'like', "%{$q}%")
                   ->orWhere('headline', 'like', "%{$q}%")
                   ->orWhere('body_ad_settings', 'like', "%{$q}%")
                   ->orWhere('item_name', 'like', "%{$q}%");
            });
        }

        // Level-specific grouping
        if ($level === 'campaigns') {
            $query = (clone $base)
                ->selectRaw('
                    campaign_id,
                    MAX(campaign_name) AS campaign_name,
                    MAX(page_name)     AS page_name,
                    MAX(campaign_delivery) AS delivery_raw,

                    SUM(amount_spent_php) AS spend,
                    SUM(messaging_conversations_started) AS messages,
                    SUM(purchases) AS purchases,
                    SUM(impressions) AS impressions,
                    SUM(reach) AS reach,

                    CASE WHEN SUM(purchases) > 0 THEN SUM(amount_spent_php)/SUM(purchases) END AS cpp,
                    CASE WHEN SUM(messaging_conversations_started) > 0 THEN SUM(amount_spent_php)/SUM(messaging_conversations_started) END AS cpm_msg,
                    CASE WHEN SUM(impressions) > 0 THEN (SUM(amount_spent_php)/SUM(impressions))*1000 END AS cpm_1000,
                    CASE WHEN SUM(results) > 0 THEN SUM(amount_spent_php)/SUM(results) END AS cpr
                ')
                ->groupBy('campaign_id');

            if ($campaignId) $query->where('campaign_id', $campaignId); // optional hard filter

            $sortable = ['spend','messages','purchases','cpp','cpm_msg','cpm_1000','cpr','impressions','reach','campaign_name','page_name'];
            if (!in_array($sortBy, $sortable)) $sortBy = 'spend';
            $rows = $query->orderBy($sortBy, $sortDir)->limit($limit)->get();

            $rows = $rows->map(function ($r) {
                $on = null;
                if (isset($r->delivery_raw) && is_string($r->delivery_raw)) {
                    $on = str_starts_with(strtolower($r->delivery_raw), 'active') ? true : null;
                }
                if ($on === null) $on = ($r->spend ?? 0) > 0; // fallback
                return [
                    'level'           => 'campaign',
                    'campaign_id'     => $r->campaign_id,
                    'campaign_name'   => $r->campaign_name,
                    'page_name'       => $r->page_name,
                    'on'              => (bool) $on,

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

        } elseif ($level === 'adsets') {
            if ($campaignId) $base->where('campaign_id', $campaignId);

            $query = (clone $base)
                ->selectRaw('
                    ad_set_id,
                    MAX(ad_set_name)   AS ad_set_name,
                    MAX(campaign_id)   AS campaign_id,
                    MAX(campaign_name) AS campaign_name,
                    MAX(page_name)     AS page_name,
                    MAX(ad_set_delivery) AS delivery_raw,

                    SUM(amount_spent_php) AS spend,
                    SUM(messaging_conversations_started) AS messages,
                    SUM(purchases) AS purchases,
                    SUM(impressions) AS impressions,
                    SUM(reach) AS reach,

                    CASE WHEN SUM(purchases) > 0 THEN SUM(amount_spent_php)/SUM(purchases) END AS cpp,
                    CASE WHEN SUM(messaging_conversations_started) > 0 THEN SUM(amount_spent_php)/SUM(messaging_conversations_started) END AS cpm_msg,
                    CASE WHEN SUM(impressions) > 0 THEN (SUM(amount_spent_php)/SUM(impressions))*1000 END AS cpm_1000,
                    CASE WHEN SUM(results) > 0 THEN SUM(amount_spent_php)/SUM(results) END AS cpr
                ')
                ->groupBy('ad_set_id');

            $sortable = ['spend','messages','purchases','cpp','cpm_msg','cpm_1000','cpr','impressions','reach','ad_set_name','campaign_name','page_name'];
            if (!in_array($sortBy, $sortable)) $sortBy = 'spend';
            $rows = $query->orderBy($sortBy, $sortDir)->limit($limit)->get();

            $rows = $rows->map(function ($r) {
                $on = null;
                if (isset($r->delivery_raw) && is_string($r->delivery_raw)) {
                    $on = str_starts_with(strtolower($r->delivery_raw), 'active') ? true : null;
                }
                if ($on === null) $on = ($r->spend ?? 0) > 0;
                return [
                    'level'           => 'adset',
                    'campaign_id'     => $r->campaign_id,
                    'campaign_name'   => $r->campaign_name,
                    'ad_set_id'       => $r->ad_set_id,
                    'ad_set_name'     => $r->ad_set_name,
                    'page_name'       => $r->page_name,
                    'on'              => (bool) $on,

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

        } else { // ads
            if ($adSetId) $base->where('ad_set_id', $adSetId);

            $query = (clone $base)
                ->selectRaw('
                    ad_id,
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

                    CASE WHEN SUM(purchases) > 0 THEN SUM(amount_spent_php)/SUM(purchases) END AS cpp,
                    CASE WHEN SUM(messaging_conversations_started) > 0 THEN SUM(amount_spent_php)/SUM(messaging_conversations_started) END AS cpm_msg,
                    CASE WHEN SUM(impressions) > 0 THEN (SUM(amount_spent_php)/SUM(impressions))*1000 END AS cpm_1000,
                    CASE WHEN SUM(results) > 0 THEN SUM(amount_spent_php)/SUM(results) END AS cpr
                ')
                ->groupBy('ad_id');

            $sortable = ['spend','messages','purchases','cpp','cpm_msg','cpm_1000','cpr','impressions','reach','headline','item_name'];
            if (!in_array($sortBy, $sortable)) $sortBy = 'spend';
            $rows = $query->orderBy($sortBy, $sortDir)->limit($limit)->get();

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
            COALESCE(SUM(amount_spent_php),0) AS spend,
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

        return response()->json([
            'level'   => $level,
            'rows'    => $rows,
            'totals'  => $totals,
        ]);
    }
}
