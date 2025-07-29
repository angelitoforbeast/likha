<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DownloadedMacroOutputLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'timestamp',
        'page',
        'downloaded_by',
        'downloaded_at',
    ];
}
