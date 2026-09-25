<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FromJnt2 extends Model
{
    protected $table = 'from_jnts_2';

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
        'last_uploaded_by_user_id',
        'last_upload_log_id',
    ];

    protected $casts = [
        'submission_time'     => 'datetime',
        'signingtime'         => 'datetime',
        'total_shipping_cost' => 'decimal:2',
        'status_logs'         => 'array',
    ];
}
