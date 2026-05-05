<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulkUploadRun extends Model
{
    protected $table = 'bulk_upload_runs';

    protected $fillable = [
        'type',
        'user_id',
        'status',
        'total_files',
        'files_done',
        'files_failed',
        'files_skipped',
        'total_processed',
        'total_inserted',
        'total_updated',
        'total_skipped',
        'total_errors',
        'consolidate_total',
        'consolidate_processed',
        'consolidate_started_at',
        'paused_at',
        'cancel_requested_at',
        'batch_at',
        'started_at',
        'finished_at',
        'message',
    ];

    protected $casts = [
        'batch_at'                => 'datetime',
        'started_at'              => 'datetime',
        'finished_at'             => 'datetime',
        'paused_at'               => 'datetime',
        'cancel_requested_at'     => 'datetime',
        'consolidate_started_at'  => 'datetime',
    ];

    public function files(): HasMany
    {
        return $this->hasMany(UploadLogV2::class, 'bulk_run_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }
}
