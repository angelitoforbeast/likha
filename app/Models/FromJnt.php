<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FromJnt extends Model
{
    // Adjust kung iba talaga table name mo
    protected $table = 'from_jnts';

    protected $fillable = [
        'waybill_number',
        'sender',
        'cod',
        'status',
        'item_name',
        'submission_time',
        'receiver',
        'receiver_cellphone',
        'signingtime',
        'remarks',
        'province',
        'city',
        'barangay',
        'total_shipping_cost',
        'rts_reason',
        'status_logs',
    ];

    protected $casts = [
        'submission_time'     => 'datetime',
        'signingtime'         => 'datetime',
        'total_shipping_cost' => 'decimal:2',
        'status_logs'         => 'array',
    ];
}
