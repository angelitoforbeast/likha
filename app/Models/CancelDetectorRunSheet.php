<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CancelDetectorRunSheet extends Model
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
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(CancelDetectorRun::class, 'run_id');
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(CancelDetectorSetting::class, 'setting_id');
    }
}
