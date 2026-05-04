<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UploadLogV2 extends Model
{
    protected $table = 'upload_logs_v2';

    protected $fillable = [
        'bulk_run_id',
        'user_id',
        'type',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'status',
        'precheck_report',
        'processed_rows',
        'total_rows',
        'inserted',
        'updated',
        'skipped',
        'error_rows',
        'errors_path',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'precheck_report' => 'array',
        'started_at'      => 'datetime',
        'finished_at'     => 'datetime',
    ];

    public function bulkRun(): BelongsTo
    {
        return $this->belongsTo(BulkUploadRun::class, 'bulk_run_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }
}
