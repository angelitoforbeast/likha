<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversationFlowContent extends Model
{
    protected $fillable = [
        'page_name',
        'flow_name',
        'bubbles',
    ];

    protected $casts = [
        'bubbles' => 'array',
    ];
}
