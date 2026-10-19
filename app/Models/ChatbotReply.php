<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Isang padala ng chatbot (send_content) o isang sheet row, kasama ang skipped at failed. */
class ChatbotReply extends Model
{
    public $timestamps = false;

    protected $fillable = ['page_id', 'psid', 'chat_id', 'flow_id', 'node_id', 'user_id', 'kind', 'content', 'status', 'error', 'http', 'sent_at'];

    protected $casts = ['content' => 'array', 'sent_at' => 'datetime'];
}
