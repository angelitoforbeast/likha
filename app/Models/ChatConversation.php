<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Likha Chat (spec 296): direct (two people, one per pair via direct_key) or group (title + members). */
class ChatConversation extends Model
{
    use SoftDeletes;

    public const DIRECT = 'direct';
    public const GROUP = 'group';

    protected $fillable = ['kind', 'title', 'direct_key', 'created_by', 'last_message_at'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function members(): HasMany
    {
        return $this->hasMany(ChatMember::class, 'conversation_id');
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->whereNull('left_at');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }

    public function isGroup(): bool
    {
        return $this->kind === self::GROUP;
    }

    public static function directKey(int $a, int $b): string
    {
        return min($a, $b) . ':' . max($a, $b);
    }
}
