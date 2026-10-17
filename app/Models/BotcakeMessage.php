<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Isang mensahe ng /chatblast (order update sa customer sa Botcake) at ang talaan ng pagpapadala nito. */
class BotcakeMessage extends Model
{
    protected $fillable = [
        'page_name', 'macro_output_id', 'waybill', 'kind', 'fb_name', 'psid', 'vars', 'text', 'jnt_status', 'status',
        'reason', 'attempts', 'last_http', 'steps', 'is_test', 'test_of', 'dedupe_key', 'queued_at', 'sent_at',
        'created_by', 'sent_by',
    ];

    protected $casts = [
        'vars'      => 'array',
        'steps'     => 'array',
        'is_test'   => 'boolean',
        'queued_at' => 'datetime',
        'sent_at'   => 'datetime',
    ];
}
