<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Isang padala ng chatbot (send_content) o isang sheet row, kasama ang skipped at failed. */
class ChatbotReply extends Model
{
    public $timestamps = false;

    protected $fillable = ['page_id', 'psid', 'chat_id', 'flow_id', 'node_id', 'user_id', 'kind', 'content', 'status', 'error', 'http', 'sent_at',
        'flow_version_id', 'sequence_id', 'sequence_step', 'ad_id', 'tags', 'replied_at', 'reply_seconds'];

    protected $casts = ['content' => 'array', 'sent_at' => 'datetime', 'tags' => 'array', 'replied_at' => 'datetime'];
}
