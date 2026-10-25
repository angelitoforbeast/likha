<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Isang comment mula sa Forbeast at ang ginawa rito (private message, public reply, o skipped at bakit). */
class ChatbotCommentEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['page_id', 'comment_id', 'post_id', 'from_id', 'status', 'reason', 'pm_status', 'pm_text', 'pm_error',
        'reply_status', 'reply_text', 'reply_error', 'reply_comment_id', 'received_at', 'handled_at'];

    protected $casts = ['received_at' => 'datetime', 'handled_at' => 'datetime'];
}
