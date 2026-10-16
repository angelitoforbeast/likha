<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotcakePsid extends Model
{
    protected $table = 'botcake_psids';

    protected $fillable = [
        'setting_id',
        'page',
        'fb_name',
        'psid',
        'sheet_date',
        'status',
        'first_seen_at',
        'matched_at',
        'matched_rows',
        'last_checked_at',
    ];

    protected $casts = [
        'first_seen_at'   => 'datetime',
        'matched_at'      => 'datetime',
        'last_checked_at' => 'datetime',
    ];
}
