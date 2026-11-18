<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Likha Chat (spec 296): a message from a person (sender_user_id), an AI (sender_ai) or, with neither, a system line.
 * Soft deletes: a removed message keeps its row and shows "Message removed".
 */
class ChatMessage extends Model
{
    use SoftDeletes;

    protected $fillable = ['conversation_id', 'sender_user_id', 'sender_ai', 'body', 'reply_to_id', 'attachments'];

    protected function casts(): array
    {
        // Spec 300: reactions (user id → emoji), edited_at, edit_history (the earlier bodies; nothing is deleted).
        return ['attachments' => 'array', 'reactions' => 'array', 'edit_history' => 'array', 'edited_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function isSystem(): bool
    {
        return $this->sender_user_id === null && $this->sender_ai === null;
    }
}
