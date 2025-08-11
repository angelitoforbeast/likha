<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UploadLog extends Model
{
    protected $fillable = [
        'type', 'disk', 'path', 'original_name', 'mime_type', 'size',
        'status', 'total_rows', 'processed_rows', 'inserted', 'updated',
        'skipped', 'error_rows', 'errors_path', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'size'         => 'integer',
        'total_rows'   => 'integer',
        'processed_rows'=> 'integer',
        'inserted'     => 'integer',
        'updated'      => 'integer',
        'skipped'      => 'integer',
        'error_rows'   => 'integer',
        'started_at'   => 'datetime',
        'finished_at'  => 'datetime',
    ];
}
