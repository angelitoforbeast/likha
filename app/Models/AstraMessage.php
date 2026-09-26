<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AstraMessage extends Model
{
    protected $table = 'astra_messages';

    protected $fillable = [
        'conversation_id', 'user_id', 'role', 'text', 'reasoning', 'sources', 'attachments',
        'openai_response_id', 'status', 'usage', 'duration_ms',
    ];

    protected $casts = [
        'sources'     => 'array',
        'attachments' => 'array',
        'usage'       => 'array',
        'duration_ms' => 'integer',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AstraConversation::class, 'conversation_id');
    }
}
