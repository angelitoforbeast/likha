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
    protected $casts = ['feedback' => 'boolean'];

    public function reports()
    {
        return $this->hasMany(AdsManagerReport::class, 'campaign_id', 'campaign_id');
    }

    /**
     * Spec 254: a campaign can have one row per ad. The row that stands for the campaign (for an ad without a row of its
     * own) is the campaign-only row (no ad id) when there is one, else the oldest row: the one that was the campaign's
     * single row before rows were kept per ad.
     */
    public static function campaignFallback(?string $campaignId, array $cols = ['*']): ?object
    {
        if ($campaignId === null || trim($campaignId) === '') return null;

        return \Illuminate\Support\Facades\DB::table('ad_campaign_creatives')
            ->where('campaign_id', $campaignId)
            ->orderByRaw('CASE WHEN ad_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('id')
            ->first($cols);
    }
}
