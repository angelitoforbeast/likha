<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// app/Models/AdsManagerReport.php
class AdsManagerReport extends Model
{
    use HasFactory;

    protected $table = 'ads_manager_reports';

    protected $fillable = [
        'day',
        'page_name',
        'campaign_name',
        'ad_set_id',
        'ad_set_name',
        'campaign_id',
        'campaign_delivery',
        'ad_set_delivery',
        'reach',
        'impressions',
        'ad_set_budget',
        'ad_set_budget_type',
        'attribution_setting',
        'result_type',
        'results',
        'amount_spent_php',
        'cost_per_result',
        'starts',
        'ends',
        'messaging_conversations_started',
        'purchases',
        'reporting_starts',
        'reporting_ends',
        // 🔽 NEW COLUMNS
        'headline',
        'body_ad_settings',
        'ad_id',
        'welcome_message',
'quick_reply_1',
'quick_reply_2',
'quick_reply_3',
'item_name',
        // Spec 244: filled by the Meta Ads sync; null = no data (image ad), never 0.
        'video_plays_3s',
        'video_thruplays',
        'video_p50',


    ];

    public function creative()
{
    return $this->hasOne(AdCampaignCreative::class, 'campaign_id', 'campaign_id');
}

}

