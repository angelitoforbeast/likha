<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CancelDetectorRun extends Model
{
    protected $fillable = [
        'status',
        'total_settings',
        'total_processed',
        'total_inserted',
        'total_updated',
        'total_skipped',
        'total_failed',
        'message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function sheets(): HasMany
    {
        return $this->hasMany(CancelDetectorRunSheet::class, 'run_id');
    }
}
