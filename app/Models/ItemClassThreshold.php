<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItemClassThreshold extends Model
{
    protected $table = 'item_class_thresholds';

    protected $fillable = [
        'class_key',
        'label',
        'min_velocity',
        'sort_order',
    ];

    protected $casts = [
        'min_velocity' => 'float',
        'sort_order'   => 'integer',
    ];
}
