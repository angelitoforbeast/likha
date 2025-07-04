<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\OfflineAd;

class OfflineAdController extends Controller
{
    public function store(Request $request)
    {
        $rows = json_decode($request->input('jsonData'), true);
        $inserted = 0;
        $updated = 0;

        foreach ($rows as $r) {
            $existing = OfflineAd::where('reporting_starts', $r['reporting_starts'])
                ->where('campaign_id', $r['campaign_id'])
                ->where('ad_id', $r['ad_id'])
                ->first();

            if ($existing) {
                $existing->update([
                    'campaign_name' => $r['campaign_name'],
                    'adset_name'    => $r['adset_name'],
                    'amount_spent'  => $r['amount_spent'],
                    'impressions'   => $r['impressions'],
                    'messages'      => $r['messages'],
                    'budget'        => $r['budget'],
                    'ad_delivery'   => $r['ad_delivery'],
                    'reach'         => $r['reach'],
                    'hook_rate'     => $r['hook_rate'],
                    'hold_rate'     => $r['hold_rate'],
                ]);
                $updated++;
            } else {
                OfflineAd::create([
                    'reporting_starts' => $r['reporting_starts'],
                    'campaign_id'      => $r['campaign_id'],
                    'ad_id'            => $r['ad_id'],
                    'campaign_name'    => $r['campaign_name'],
                    'adset_name'       => $r['adset_name'],
                    'amount_spent'     => $r['amount_spent'],
                    'impressions'      => $r['impressions'],
                    'messages'         => $r['messages'],
                    'budget'           => $r['budget'],
                    'ad_delivery'      => $r['ad_delivery'],
                    'reach'            => $r['reach'],
                    'hook_rate'        => $r['hook_rate'],
                    'hold_rate'        => $r['hold_rate'],
                ]);
                $inserted++;
            }
        }

        return back()->with('success', "<strong>{$inserted}</strong> inserted, <strong>{$updated}</strong> updated.");
    }

    public function index()
    {
        $ads = OfflineAd::orderBy('reporting_starts', 'desc')->paginate(25);
        return view('ads_manager.campaign_view', compact('ads'));
    }

    public function deleteAll()
    {
        OfflineAd::truncate();
        return redirect()
            ->route('offline_ads.campaign_view')
            ->with('success', 'All records deleted.');
    }
}