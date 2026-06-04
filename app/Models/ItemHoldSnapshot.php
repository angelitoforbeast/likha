<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItemHoldSnapshot extends Model
{
    protected $table = 'item_hold_snapshots';

    protected $fillable = [
        'item_key', 'item_name', 'snapshot_date', 'hold_units', 'captured_at',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'captured_at'   => 'datetime',
    ];
}
