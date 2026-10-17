<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Isang campaign ng /chatblast: trigger na J&T status, mga page, window, mga araw ng text, mode at on/off. */
class ChatblastCampaign extends Model
{
    /** Lahat maliban sa log (na maaaring malaki): para sa pagbuo, pagpapadala at listahan. */
    public const COLUMNS = [
        'id', 'kind', 'name', 'statuses', 'pages', 'window_days', 'days', 'requires_on_delivery', 'mode', 'send_times', 'active',
        'auto_ran_at', 'mode_changed_by', 'mode_changed_at', 'created_at', 'updated_at',
    ];

    protected $fillable = [
        'kind', 'name', 'statuses', 'pages', 'window_days', 'days', 'requires_on_delivery', 'mode', 'send_times', 'active',
        'auto_ran_at', 'mode_changed_by', 'mode_changed_at', 'log',
    ];

    protected $casts = [
        'statuses'             => 'array',
        'pages'                => 'array',
        'window_days'          => 'integer',
        'days'                 => 'array',
        'requires_on_delivery' => 'boolean',
        'send_times'           => 'array',
        'active'               => 'boolean',
        'auto_ran_at'          => 'datetime',
        'mode_changed_at'      => 'datetime',
        'log'                  => 'array',
    ];
}
