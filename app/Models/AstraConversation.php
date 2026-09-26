<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AstraConversation extends Model
{
    use SoftDeletes;

    protected $table = 'astra_conversations';

    protected $fillable = [
        'user_id', 'title', 'model', 'effort', 'search_mode', 'max_output_tokens',
        'openai_last_response_id', 'last_message_at',
    ];

    protected $casts = [
        'max_output_tokens' => 'integer',
        'last_message_at'   => 'datetime',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(AstraMessage::class, 'conversation_id')->orderBy('id');
    }
}
