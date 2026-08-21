<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BarcodePrintLog extends Model
{
    protected $fillable = [
        'target_date',
        'bundle_count',
        'waybill_count',
        'user_id',
        'user_name',
        'user_email',
        'user_role',
        'ip',
    ];

    protected $casts = [
        'target_date' => 'date',
    ];
}
