<?php

namespace App\Http\Controllers;

use App\Models\AdCampaignCreative;
use Illuminate\Http\Request;

class AdCampaignCreativeController extends Controller
{
    // Show the edit view
    public function editView()
    {
        $creatives = AdCampaignCreative::orderBy('campaign_id')->get();
        return view('ads_manager.edit-messaging-template', compact('creatives'));
   }

    // Update a single creative row
    public function update(Request $request, $id)
    {
        $creative = AdCampaignCreative::findOrFail($id);

        $creative->update([
            'welcome_message' => $request->input('welcome_message'),
            'quick_reply_1' => $request->input('quick_reply_1'),
            'quick_reply_2' => $request->input('quick_reply_2'),
            'quick_reply_3' => $request->input('quick_reply_3'),
        ]);

        return back()->with('success', 'Messaging template updated successfully.');
    }
    public function editMessagingTemplate(Request $request)
{
    $sortBy = $request->get('sort_by', 'campaign_id');
    $sortDirection = $request->get('sort_direction', 'asc');

    $creatives = AdCampaignCreative::orderBy($sortBy, $sortDirection)->get();

    return view('ads_manager.edit-messaging-template', compact('creatives', 'sortBy', 'sortDirection'));
}

}
