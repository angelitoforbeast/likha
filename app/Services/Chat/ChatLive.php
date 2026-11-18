<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\Cache;

/**
 * Likha Chat (spec 300, MP-5): ang "is typing…" at ang "active" ay nasa cache lang, maikli ang buhay; walang row bawat
 * keystroke. Typing: isang maliit na map bawat conversation (user id → hanggang kailan). Active: ang huling paggamit
 * ng Likha bawat tao, isinusulat nang hindi hihigit sa isang beses bawat minuto.
 */
class ChatLive
{
    public static function typing(int $conversationId, int $userId): void
    {
        $key = "chat:typing:$conversationId";
        $ttl = (int) config('chat.typing_seconds', 6);
        $now = now()->getTimestamp();
        $map = array_filter((array) Cache::get($key, []), fn ($until) => (int) $until > $now);
        $map[$userId] = $now + $ttl;
        Cache::put($key, $map, $ttl + 2);
    }

    /** @return int[] ang mga nagta-type ngayon, maliban sa $exceptUserId */
    public static function typingIds(int $conversationId, int $exceptUserId): array
    {
        $now = now()->getTimestamp();
        $map = (array) Cache::get("chat:typing:$conversationId", []);

        return array_values(array_map('intval', array_keys(array_filter(
            $map, fn ($until, $uid) => (int) $until > $now && (int) $uid !== $exceptUserId, ARRAY_FILTER_USE_BOTH,
        ))));
    }

    /** Ginagamit ng tao ang Likha ngayon (unread poll ng bawat page, ang chat poll at ang chat page). */
    public static function touch(int $userId): void
    {
        $key = "chat:active:$userId";
        $now = now()->getTimestamp();
        if ((int) Cache::get($key, 0) > $now - 60) {
            return;
        }
        Cache::put($key, $now, (int) config('chat.active_minutes', 60) * 60);
    }

    /** @param  int[]  $userIds  @return array<int, string> user id → ISO time ng huling paggamit (sa loob ng isang oras) */
    public static function activeAt(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (! $userIds) {
            return [];
        }
        $oldest = now()->getTimestamp() - (int) config('chat.active_minutes', 60) * 60;
        $out = [];
        foreach (Cache::many(array_map(fn ($id) => "chat:active:$id", $userIds)) as $key => $at) {
            if ($at !== null && (int) $at >= $oldest) {
                $out[(int) substr($key, strlen('chat:active:'))] = \Illuminate\Support\Carbon::createFromTimestamp((int) $at, config('app.timezone'))->toIso8601String();
            }
        }

        return $out;
    }
}
