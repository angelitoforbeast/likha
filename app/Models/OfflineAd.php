<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OfflineAd extends Model
{
    use HasFactory;

    protected $table = 'offline_ads';

    protected $fillable = [
        'reporting_starts',
        'campaign_name',
        'adset_name',
        'amount_spent',
        'impressions',
        'messages',
        'budget',
        'ad_delivery',
        'campaign_id',
        'ad_id',
        'reach',
        'date_created',
        'hook_rate',
        'hold_rate',
    ];
}