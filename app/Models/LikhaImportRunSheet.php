<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LikhaImportRunSheet extends Model
{
    protected $fillable = [
        'run_id',
        'setting_id',
        'status',
        'processed_count',
        'inserted_count',
        'updated_count',
        'skipped_count',
        'message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function run()
    {
        return $this->belongsTo(LikhaImportRun::class, 'run_id');
    }

    public function setting()
    {
        return $this->belongsTo(LikhaOrderSetting::class, 'setting_id');
    }
}
