<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplyItemSetting extends Model
{
    protected $table = 'supply_item_settings';

    protected $fillable = [
        'item_name',
        'lead_time_days',
        'safety_days',
        'is_running',
        'notes',
        'lifecycle_override',
        'class_override',
    ];

    protected $casts = [
        'is_running'     => 'boolean',
        'lead_time_days' => 'integer',
        'safety_days'    => 'integer',
    ];
}
