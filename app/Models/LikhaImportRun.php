<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LikhaImportRun extends Model
{
    protected $fillable = [
        'status',
        'total_settings',
        'total_processed',
        'total_inserted',
        'total_updated',
        'total_skipped',
        'total_failed',
        'started_at',
        'finished_at',
        'message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function sheets()
    {
        return $this->hasMany(LikhaImportRunSheet::class, 'run_id');
    }
}
