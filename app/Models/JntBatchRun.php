<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JntBatchRun extends Model
{
    protected $table = 'jnt_batch_runs';

    protected $guarded = [];

    protected $casts = [
        'filters' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
