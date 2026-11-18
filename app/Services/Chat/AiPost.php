<?php

namespace App\Services\Chat;

use App\Models\ChatConversation;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Likha Chat (spec 299): isang AI (hal. si Mira) na nagpapadala ng message sa isang tao. Bawat tao ay may iisang
 * conversation sa bawat AI: direct, direct_key "ai:<ai>:<user_id>" (unique, kaya isa lang kahit sabay), title = ang
 * pangalan ng AI, at ang tao lang ang miyembro. Ang AI ay sender_ai, hindi user at hindi miyembro, kaya ang mga rule
 * ng ChatAccess ay pareho pa rin: ang tao at ang CEO ang nakakabasa, ang tao lang ang nakakasagot.
 */
class AiPost
{
    public const KEY_PREFIX = 'ai:';

    public static function key(string $ai, int $userId): string
    {
        return self::KEY_PREFIX . $ai . ':' . $userId;
    }

    public static function isAiConversation(ChatConversation $c): bool
    {
        return str_starts_with((string) $c->direct_key, self::KEY_PREFIX);
    }

    /** Ang sender_ai key ng isang AI conversation ("ai:mira:12" → "mira"). */
    public static function aiOf(ChatConversation $c): ?string
    {
        return self::isAiConversation($c) ? (explode(':', (string) $c->direct_key)[1] ?? null) : null;
    }

    public static function name(string $ai): string
    {
        return (string) (config("chat.ai_members.$ai") ?: Str::headline($ai));
    }

    /** Ang iisang conversation ng AI at ng tao; ginagawa kapag wala pa. */
    public static function conversation(string $ai, int $userId): ChatConversation
    {
        $key = self::key($ai, $userId);
        if ($existing = ChatConversation::where('direct_key', $key)->first()) {
            return $existing;
        }
        try {
            return DB::transaction(function () use ($ai, $userId, $key) {
                $c = ChatConversation::create(['kind' => ChatConversation::DIRECT, 'title' => self::name($ai), 'direct_key' => $key]);
                ChatMember::create(['conversation_id' => $c->id, 'user_id' => $userId, 'role' => ChatMember::MEMBER, 'joined_at' => now()]);

                return $c;
            });
        } catch (QueryException $e) {
            // Sabay na ginawa: ang unique direct_key ang nagbantay; ibalik ang nauna.
            return ChatConversation::where('direct_key', $key)->first() ?? throw $e;
        }
    }

    /** Isang message mula sa AI sa conversation nila ng tao. Hindi kailanman blangko. */
    public static function post(string $ai, int $userId, string $body): ChatMessage
    {
        $body = trim($body);
        if ($body === '') {
            throw new \InvalidArgumentException('An AI message needs text.');
        }
        $body = mb_substr($body, 0, (int) config('chat.max_body', 4000));
        $c = self::conversation($ai, $userId);

        return DB::transaction(function () use ($c, $ai, $body) {
            $m = ChatMessage::create(['conversation_id' => $c->id, 'sender_ai' => $ai, 'body' => $body]);
            $c->forceFill(['last_message_at' => $m->created_at])->save();

            return $m;
        });
    }
}
