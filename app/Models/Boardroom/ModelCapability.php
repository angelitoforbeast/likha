<?php

namespace App\Models\Boardroom;

use Illuminate\Database\Eloquent\Model;

class ModelCapability extends Model
{
    protected $table = 'br_model_capabilities';

    protected $fillable = [
        'provider', 'model', 'label', 'endpoint', 'efforts', 'default_effort', 'thinking_modes',
        'default_thinking', 'optional_params', 'structured_output', 'max_output_tokens', 'context_window',
        'price_in', 'price_out', 'doc_url', 'verified', 'verified_at', 'live_verified_at', 'notes',
    ];

    protected $casts = [
        'efforts'          => 'array',
        'thinking_modes'   => 'array',
        'optional_params'  => 'array',
        'verified'         => 'boolean',
        'verified_at'      => 'date',
        'live_verified_at' => 'datetime',
        'price_in'         => 'float',
        'price_out'        => 'float',
    ];
}
