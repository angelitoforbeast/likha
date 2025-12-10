<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UploadLog extends Model
{
    protected $table = 'upload_logs';

    // kung dati naka-$guarded = [], puwede mo nang iwan;
    // pero para sure, gawin nating explicit:
    protected $fillable = [
        'type',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'status',
        'processed_rows',
        'total_rows',
        'inserted',
        'updated',
        'skipped',
        'error_rows',
        'errors_path',
        'started_at',
        'finished_at',
        'batch_at',     // ✅ IMPORTANT
    ];

    protected $casts = [
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
        'batch_at'    => 'datetime',  // ✅ para Carbon na pag kinuha sa Job
    ];
}
