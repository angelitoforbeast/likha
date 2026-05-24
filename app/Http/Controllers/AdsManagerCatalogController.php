<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only viewer for the `ad_catalog` table.
 *
 * Tree-style UI: Campaign → Ad Set → Ad. Each level lazy-loaded via AJAX
 * on expand. Data source: `ad_catalog` only (no JOINs). For debugging — para
 * hindi na kailangan i-tinker para tingnan kung ano laman ng catalog.
 *
 * Endpoints:
 *   GET /ads_manager/catalog                    → page shell + initial campaign list
 *   GET /ads_manager/catalog/adsets?campaign_id → JSON: ad sets for a campaign
 *   GET /ads_manager/catalog/ads?ad_set_id      → JSON: ads under an ad set
 */
class AdsManagerCatalogController extends Controller
{
    /** Main page — server-renders the campaign list with filters. */
    public function index(Request $request)
    {
        if (!Schema::hasTable('ad_catalog')) {
            return view('ads_manager.catalog', [
                'campaigns'      => collect(),
                'totalAds'       => 0,
                'totalCampaigns' => 0,
                'totalAdSets'    => 0,
                'totalPages'     => 0,
                'allPages'       => collect(),
                'pageFilter'     => '',
                'qFilter'        => '',
                'fromDate'       => '',
                'toDate'         => '',
                'tableMissing'   => true,
            ]);
        }

        $pageFilter = trim((string) $request->query('page', ''));
        $qFilter    = trim((string) $request->query('q', ''));
        $fromDate   = trim((string) $request->query('from_date', ''));
        $toDate     = trim((string) $request->query('to_date', ''));

        // Base query — apply filters that affect campaign-level aggregation.
        $base = DB::table('ad_catalog');
        if ($pageFilter !== '') $base->where('page_name', $pageFilter);
        if ($qFilter !== '') {
            $like = '%' . $qFilter . '%';
            $base->where(function ($q) use ($like) {
                $q->where('campaign_name', 'like', $like)
                  ->orWhere('ad_set_name', 'like', $like)
                  ->orWhere('ad_name', 'like', $like)
                  ->orWhere('ad_id', 'like', $like)
                  ->orWhere('campaign_id', 'like', $like)
                  ->orWhere('ad_set_id', 'like', $like);
            });
        }
        if ($fromDate !== '') $base->where('first_started', '>=', $fromDate);
        if ($toDate   !== '') $base->where('first_started', '<=', $toDate);

        // Campaign-level aggregation: GROUP BY campaign_id, derive counts + dates.
        $campaigns = (clone $base)
            ->whereNotNull('campaign_id')
            ->where('campaign_id', '!=', '')
            ->selectRaw('
                campaign_id,
                MAX(campaign_name) AS campaign_name,
                MAX(page_name)     AS page_name,
                MAX(account_id)    AS account_id,
                COUNT(*)                   AS total_ads,
                COUNT(DISTINCT ad_set_id)  AS total_ad_sets,
                MIN(first_started)         AS min_first_started,
                MIN(first_spend_day)       AS min_first_spend_day,
                MAX(updated_at)            AS last_updated
            ')
            ->groupBy('campaign_id')
            ->orderBy('min_first_started', 'desc')
            ->get();

        // Summary counters (over the same filtered base)
        $totalAds       = (clone $base)->count();
        $totalCampaigns = $campaigns->count();
        $totalAdSets    = (clone $base)->distinct('ad_set_id')->whereNotNull('ad_set_id')->count('ad_set_id');
        $totalPages     = (clone $base)->distinct('page_name')->whereNotNull('page_name')->count('page_name');

        // Dropdown options (unfiltered — show all available pages)
        $allPages = DB::table('ad_catalog')
            ->whereNotNull('page_name')
            ->where('page_name', '!=', '')
            ->distinct()
            ->orderBy('page_name')
            ->pluck('page_name');

        return view('ads_manager.catalog', [
            'campaigns'      => $campaigns,
            'totalAds'       => $totalAds,
            'totalCampaigns' => $totalCampaigns,
            'totalAdSets'    => $totalAdSets,
            'totalPages'     => $totalPages,
            'allPages'       => $allPages,
            'pageFilter'     => $pageFilter,
            'qFilter'        => $qFilter,
            'fromDate'       => $fromDate,
            'toDate'         => $toDate,
            'tableMissing'   => false,
        ]);
    }

    /** GET /ads_manager/catalog/adsets — JSON: ad sets under a campaign. */
    public function adsets(Request $request)
    {
        if (!Schema::hasTable('ad_catalog')) {
            return response()->json(['ok' => false, 'message' => 'ad_catalog missing'], 422);
        }
        $campaignId = trim((string) $request->query('campaign_id', ''));
        if ($campaignId === '') {
            return response()->json(['ok' => false, 'message' => 'campaign_id required'], 422);
        }

        $rows = DB::table('ad_catalog')
            ->where('campaign_id', $campaignId)
            ->whereNotNull('ad_set_id')
            ->where('ad_set_id', '!=', '')
            ->selectRaw('
                ad_set_id,
                MAX(ad_set_name)     AS ad_set_name,
                COUNT(*)             AS total_ads,
                MIN(first_started)   AS min_first_started,
                MIN(first_spend_day) AS min_first_spend_day,
                MAX(updated_at)      AS last_updated
            ')
            ->groupBy('ad_set_id')
            ->orderBy('min_first_started', 'desc')
            ->get();

        return response()->json(['ok' => true, 'rows' => $rows]);
    }

    /** GET /ads_manager/catalog/ads — JSON: ads under an ad set. */
    public function ads(Request $request)
    {
        if (!Schema::hasTable('ad_catalog')) {
            return response()->json(['ok' => false, 'message' => 'ad_catalog missing'], 422);
        }
        $adSetId = trim((string) $request->query('ad_set_id', ''));
        if ($adSetId === '') {
            return response()->json(['ok' => false, 'message' => 'ad_set_id required'], 422);
        }

        // Return ALL columns from ad_catalog — para sa debugging visibility.
        $rows = DB::table('ad_catalog')
            ->where('ad_set_id', $adSetId)
            ->orderBy('first_started', 'desc')
            ->get();

        return response()->json(['ok' => true, 'rows' => $rows]);
    }
}
