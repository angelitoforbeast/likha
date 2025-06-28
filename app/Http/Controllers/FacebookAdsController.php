<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;


class FacebookAdsController extends Controller{
public function fetch(Request $request)
{
    $start = $request->start_date;
    $end = $request->end_date;
    $token = env('FB_ACCESS_TOKEN'); // store token in .env
    $adAccountId = 'act_9485468058152165'; // or make dynamic later

    $response = Http::get("https://graph.facebook.com/v19.0/{$adAccountId}/insights", [
        'fields' => 'campaign_name,spend,cost_per_action_type',
        'level' => 'campaign',
        'time_range' => ['since' => $start, 'until' => $end],
        'access_token' => $token,
    ]);

    $ads = collect($response->json('data'))->map(function ($ad) {
        $costPerMessage = collect($ad['cost_per_action_type'] ?? [])
            ->firstWhere('action_type', 'onsite_conversion.messaging_conversation_started_7d')['value'] ?? null;

        return [
            'campaign_name' => $ad['campaign_name'] ?? 'N/A',
            'spend' => $ad['spend'] ?? 0,
            'cost_per_message' => $costPerMessage,
        ];
    });

    return view('fb_ads_data', compact('ads'));
}
}