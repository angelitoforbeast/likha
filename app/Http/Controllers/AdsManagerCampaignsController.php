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
        $level       = $request->input('level', 'campaigns'); // campaigns|adsets|ads
        $start       = $request->input('start_date');
        $end         = $request->input('end_date');
        $pageName    = $request->input('page_name');
        $q           = $request->input('q');
        $sortBy      = $request->input('sort_by', 'spend');
        $sortDir     = strtolower($request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $limit       = max(1, min((int) $request->input('limit', 200), 1000));
        $export      = $request->input('export'); // 'csv' to export

        $campaignId  = $request->input('campaign_id');
        $adSetId     = $request->input('ad_set_id');

        $driver = DB::getDriverName(); // 'pgsql' | 'mysql' | etc.
        $dayExpr = $driver === 'pgsql'
            ? 'COALESCE(day, DATE(reporting_starts))'
            : 'COALESCE(`day`, DATE(`reporting_starts`))';

        $base = DB::table('ads_manager_reports');

        // Date range
        if ($start) $base->whereRaw("$dayExpr >= ?", [$start]);
        if ($end)   $base->whereRaw("$dayExpr <= ?", [$end]);

        // Page filter (trim + case-insensitive)
        if ($pageName && $pageName !== 'all') {
            $base->whereRaw('LOWER(TRIM(page_name)) = LOWER(TRIM(?))', [$pageName]);
        }

        // Search (case-insensitive, safe for NULLs)
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

        // ---- Level: Campaigns ------------------------------------------------
        if ($level === 'campaigns') {
            if ($campaignId) $base->where('campaign_id', $campaignId);

            $query = (clone $base)->selectRaw('
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
            ')->groupBy('campaign_id');

            $sortable = ['spend','messages','purchases','cpp','cpm_msg','cpm_1000','cpr','impressions','reach','campaign_name','page_name'];
            if (!in_array($sortBy, $sortable)) $sortBy = 'spend';

            $rows = $query->orderBy($sortBy, $sortDir)->limit($limit)->get()->map(function ($r) {
                $on = null;
                if (is_string($r->delivery_raw)) $on = str_starts_with(strtolower($r->delivery_raw), 'active') ? true : null;
                if ($on === null) $on = ($r->spend ?? 0) > 0;
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

        // ---- Level: Ad sets --------------------------------------------------
        } elseif ($level === 'adsets') {
            if ($campaignId) $base->where('campaign_id', $campaignId);

            $query = (clone $base)->selectRaw('
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
            ')->groupBy('ad_set_id');

            $sortable = ['spend','messages','purchases','cpp','cpm_msg','cpm_1000','cpr','impressions','reach','ad_set_name','campaign_name','page_name'];
            if (!in_array($sortBy, $sortable)) $sortBy = 'spend';

            $rows = $query->orderBy($sortBy, $sortDir)->limit($limit)->get()->map(function ($r) {
                $on = null;
                if (is_string($r->delivery_raw)) $on = str_starts_with(strtolower($r->delivery_raw), 'active') ? true : null;
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

        // ---- Level: Ads ------------------------------------------------------
        } else {
            if ($adSetId) $base->where('ad_set_id', $adSetId);

            $query = (clone $base)->selectRaw('
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
            ')->groupBy('ad_id');

            $sortable = ['spend','messages','purchases','cpp','cpm_msg','cpm_1000','cpr','impressions','reach','headline','item_name'];
            if (!in_array($sortBy, $sortable)) $sortBy = 'spend';

            $rows = $query->orderBy($sortBy, $sortDir)->limit($limit)->get()->map(function ($r) {
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

        // Totals for current filter
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

        // CSV export (optional)
        if ($export === 'csv') {
            return $this->exportCsv($rows, $level);
        }

        return response()->json([
            'level'  => $level,
            'rows'   => $rows,
            'totals' => $totals,
        ]);
    }

    private function exportCsv($rows, string $level): StreamedResponse
    {
        $filename = 'ads_manager_' . $level . '_' . now()->format('Ymd_His') . '.csv';
        $headers = ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"$filename\""];

        return response()->stream(function () use ($rows, $level) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM

            if ($level === 'campaigns') {
                fputcsv($out, ['Campaign','Page','Spend','CPM (1k)','Cost/Msg','Cost/Result','Cost/Purchase','Impr.','Msgs','Purchases']);
                foreach ($rows as $r) {
                    fputcsv($out, [$r['campaign_name'],$r['page_name'],$r['spend'],$r['cpm_1000'],$r['cpm_msg'],$r['cpr'],$r['cpp'],$r['impressions'],$r['messages'],$r['purchases']]);
                }
            } elseif ($level === 'adsets') {
                fputcsv($out, ['Ad set','Campaign','Page','Spend','CPM (1k)','Cost/Msg','Cost/Result','Cost/Purchase','Impr.','Msgs','Purchases']);
                foreach ($rows as $r) {
                    fputcsv($out, [$r['ad_set_name'],$r['campaign_name'],$r['page_name'],$r['spend'],$r['cpm_1000'],$r['cpm_msg'],$r['cpr'],$r['cpp'],$r['impressions'],$r['messages'],$r['purchases']]);
                }
            } else {
                fputcsv($out, ['Ad (Headline)','Ad set','Campaign','Page','Spend','CPM (1k)','Cost/Msg','Cost/Result','Cost/Purchase','Impr.','Msgs','Purchases']);
                foreach ($rows as $r) {
                    fputcsv($out, [($r['headline'] ?? 'Ad '.$r['ad_id']),$r['ad_set_name'],$r['campaign_name'],$r['page_name'],$r['spend'],$r['cpm_1000'],$r['cpm_msg'],$r['cpr'],$r['cpp'],$r['impressions'],$r['messages'],$r['purchases']]);
                }
            }
            fclose($out);
        }, 200, $headers);
    }
}
