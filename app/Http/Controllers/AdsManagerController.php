<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\OfflineAd;

class AdsManagerController extends Controller
{
    public function index(Request $request)
    {
        $start             = $request->get('start_date');
        $end               = $request->get('end_date');
        $selectedCampaigns = $request->get('campaigns', []);

        // Fetch and filter by dates and selected campaigns
        $ads = OfflineAd::when($start, fn($q) =>
                    $q->whereDate('reporting_starts', '>=', $start)
                )
                ->when($end, fn($q) =>
                    $q->whereDate('reporting_starts', '<=', $end)
                )
                ->when($selectedCampaigns, fn($q) =>
                    $q->whereIn('campaign_name', $selectedCampaigns)
                )
                ->get()
                ->groupBy('campaign_name');

        return view('ads_manager.ads_manager', [
            'ads'               => $ads,
            'selectedCampaigns' => $selectedCampaigns,
        ]);
    }

    public function adsets(Request $request)
    {
        $start             = $request->get('start_date');
        $end               = $request->get('end_date');
        $selectedCampaigns = $request->get('campaigns', []);

        $ads = OfflineAd::when($start, fn($q) =>
                    $q->whereDate('reporting_starts', '>=', $start)
                )
                ->when($end, fn($q) =>
                    $q->whereDate('reporting_starts', '<=', $end)
                )
                ->when($selectedCampaigns, fn($q) =>
                    $q->whereIn('campaign_name', $selectedCampaigns)
                )
                ->get()
                ->groupBy(['campaign_name','adset_name']);

        return view('ads_manager.adsets', [
            'ads'               => $ads,
            'selectedCampaigns' => $selectedCampaigns,
        ]);
    }
}
