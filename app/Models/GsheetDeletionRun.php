<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GsheetDeletionRun extends Model
{
    protected $fillable = [
        'group_id',
        'group_name',
        'end_row',
        'status',
        'user_id',
        'user_name',
        'before',
        'after',
        'result',
        'deleted_total',
        'message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'before'      => 'array',
        'after'       => 'array',
        'result'      => 'array',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];
}
