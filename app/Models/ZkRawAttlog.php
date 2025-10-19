<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZkRawAttlog extends Model
{
    protected $table = 'zk_raw_attlogs';

    protected $fillable = [
        'zk_user_id',
        'datetime_raw',
        'date',
        'time',
        'upload_batch',
    ];

    protected $casts = [
        'datetime_raw' => 'datetime',
        'date'         => 'date',
    ];
}
