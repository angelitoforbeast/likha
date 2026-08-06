<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QueueManagerLog extends Model
{
    protected $fillable = [
        'action',
        'user_id',
        'user_name',
        'user_email',
        'user_role',
        'ip',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
    ];
}
