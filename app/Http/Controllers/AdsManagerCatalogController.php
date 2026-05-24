<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only viewer for the `ad_catalog` table.
 *
 * Catalog ay auto-maintained ng Excel upload job (ProcessAdsManagerReportsUpload)
 * + one-time backfill migration. Each row = isang unique FB ad with hierarchy +
 * lifecycle dates (first_started, first_spend_day).
 *
 * Page accessible sa /ads_manager/catalog. Read-only, no edits — galing lahat sa
 * uploads. Para mag-update ang first_started → re-upload ang Excel na may earlier date.
 */
class AdsManagerCatalogController extends Controller
{
    public function index(Request $request)
    {
        if (!Schema::hasTable('ad_catalog')) {
            // Pre-migration state — friendly message
            return view('ads_manager.catalog', [
                'rows'          => collect(),
                'totalAds'      => 0,
                'totalCampaigns'=> 0,
                'totalAdSets'   => 0,
                'totalPages'    => 0,
                'allPages'      => collect(),
                'pageFilter'    => '',
                'qFilter'       => '',
                'fromDate'      => '',
                'toDate'        => '',
                'sortBy'        => 'first_started',
                'sortDir'       => 'desc',
                'tableMissing'  => true,
            ]);
        }

        $pageFilter = trim((string) $request->query('page', ''));
        $qFilter    = trim((string) $request->query('q', ''));
        $fromDate   = trim((string) $request->query('from_date', ''));
        $toDate     = trim((string) $request->query('to_date', ''));
        $sortBy     = (string) $request->query('sort_by', 'first_started');
        $sortDir    = strtolower((string) $request->query('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        // Whitelist sortable columns
        $allowedSort = ['first_started', 'first_spend_day', 'campaign_name', 'ad_set_name', 'ad_name', 'page_name', 'updated_at'];
        if (!in_array($sortBy, $allowedSort, true)) $sortBy = 'first_started';

        $base = DB::table('ad_catalog');

        if ($pageFilter !== '') {
            $base->where('page_name', $pageFilter);
        }
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

        // Paginate (50 per page); preserve filters sa link
        $rows = (clone $base)
            ->orderBy($sortBy, $sortDir)
            ->paginate(50)
            ->appends($request->query());

        // Summary counters — same filters applied
        $statsBase = (clone $base);
        $totalAds       = $statsBase->count();
        $totalCampaigns = (clone $base)->distinct('campaign_id')->whereNotNull('campaign_id')->count('campaign_id');
        $totalAdSets    = (clone $base)->distinct('ad_set_id')->whereNotNull('ad_set_id')->count('ad_set_id');
        $totalPages     = (clone $base)->distinct('page_name')->whereNotNull('page_name')->count('page_name');

        // Dropdown options
        $allPages = DB::table('ad_catalog')
            ->whereNotNull('page_name')
            ->where('page_name', '!=', '')
            ->distinct()
            ->orderBy('page_name')
            ->pluck('page_name');

        return view('ads_manager.catalog', [
            'rows'           => $rows,
            'totalAds'       => $totalAds,
            'totalCampaigns' => $totalCampaigns,
            'totalAdSets'    => $totalAdSets,
            'totalPages'     => $totalPages,
            'allPages'       => $allPages,
            'pageFilter'     => $pageFilter,
            'qFilter'        => $qFilter,
            'fromDate'       => $fromDate,
            'toDate'         => $toDate,
            'sortBy'         => $sortBy,
            'sortDir'        => $sortDir,
            'tableMissing'   => false,
        ]);
    }
}
