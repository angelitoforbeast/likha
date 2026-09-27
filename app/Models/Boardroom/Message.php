<?php

namespace App\Models\Boardroom;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $table = 'br_messages';

    protected $fillable = [
        'meeting_id', 'turn_id', 'author_type', 'agent_id', 'user_id', 'recipient_agent_id',
        'reply_to_message_id', 'issue_id', 'kind', 'cycle', 'body', 'meta', 'provider', 'model',
    ];

    protected $casts = ['meta' => 'array'];
}
