<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdCampaignCreative extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'campaign_name',
        'headline',
        'body_ad_settings',
        'welcome_message',
        'quick_reply_1',
        'quick_reply_2',
        'quick_reply_3',
        'page_name',
        'ad_set_delivery',

    ];

    public function reports()
    {
        return $this->hasMany(AdsManagerReport::class, 'campaign_id', 'campaign_id');
    }
}
