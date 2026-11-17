<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Likha Chat (spec 296): one person in one conversation; left_at is set when they leave or are removed. */
class ChatMember extends Model
{
    public const MEMBER = 'member';
    public const ADMIN = 'admin';

    protected $fillable = ['conversation_id', 'user_id', 'role', 'last_read_message_id', 'joined_at', 'left_at'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'left_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->left_at === null;
    }
}
