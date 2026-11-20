<?php

namespace App\Services\Chat;

use App\Jobs\SendChatPush;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Likha Chat (spec 308): web push kapag sarado ang Likha. Ang mga device ng bawat tao ay nasa users.chat_push (json,
 * gaya ng nav_pins): [{endpoint, p256dh, auth, added_at, ua}]. Bawat sulat dito ay sa loob ng transaction na naka-lock
 * ang user row, kaya hindi nagkakapatungan ang pag-save, pag-alis at ang paglinis ng patay na endpoint.
 *
 * Walang keys (VAPID_PUBLIC_KEY at VAPID_PRIVATE_KEY) = sarado ang push: walang button, walang padala, walang error.
 */
class ChatPush
{
    public const MAX_ENDPOINT = 2048;

    public static function enabled(): bool
    {
        return trim((string) config('chat.push.public_key')) !== '' && trim((string) config('chat.push.private_key')) !== '';
    }

    public static function publicKey(): ?string
    {
        return self::enabled() ? trim((string) config('chat.push.public_key')) : null;
    }

    // ── the endpoint allowlist (walang SSRF: ang server ay magpo-POST dito) ──────────

    /** Ang push service ng isang endpoint ("Google", "Apple"…), o null kapag hindi ito kilalang push service. */
    public static function service(mixed $endpoint): ?string
    {
        if (! is_string($endpoint) || $endpoint === '' || strlen($endpoint) > self::MAX_ENDPOINT || preg_match('/[\s\x00-\x1f]/', $endpoint)) {
            return null;
        }
        $u = parse_url($endpoint);
        if (! is_array($u) || strtolower($u['scheme'] ?? '') !== 'https' || isset($u['user']) || isset($u['pass'])
            || (isset($u['port']) && (int) $u['port'] !== 443) || empty($u['host'])) {
            return null;
        }
        $host = strtolower($u['host']);
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) || $host === 'localhost') {
            return null;
        }
        foreach ((array) config('chat.push.hosts', []) as $pattern => $name) {
            $pattern = strtolower($pattern);
            $ok = str_starts_with($pattern, '*.')
                ? str_ends_with($host, substr($pattern, 1)) && strlen($host) > strlen($pattern) - 1
                : $host === $pattern;
            if ($ok) {
                return $name;
            }
        }

        return null;
    }

    /** Base64url na eksaktong ganito karaming bytes (p256dh: 65, auth: 16). */
    public static function validKey(mixed $key, int $bytes): bool
    {
        if (! is_string($key) || strlen($key) > 120 || ! preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $key)) {
            return false;
        }
        $raw = base64_decode(strtr(rtrim($key, '='), '-_', '+/'), true);

        return $raw !== false && strlen($raw) === $bytes && ($bytes !== 65 || $raw[0] === "\x04");
    }

    public static function key(string $endpoint): string
    {
        return substr(hash('sha256', $endpoint), 0, 16);
    }

    // ── this person's devices ────────────────────────────────────────────────

    /** Ang listahan para sa page: walang endpoint at walang keys. */
    public static function devices(User $user): array
    {
        // Mula sa row mismo (hindi sa naka-load na user), dahil binabago ito ng ibang request at ng push job.
        $list = User::query()->whereKey($user->id)->first(['id', 'chat_push'])?->chat_push;

        return array_values(array_map(fn (array $d) => self::card($d), (array) $list));
    }

    private static function card(array $d): array
    {
        return ['key' => self::key((string) $d['endpoint']), 'service' => self::service($d['endpoint']), 'added_at' => $d['added_at'] ?? null];
    }

    /**
     * I-save ang device na ito (o i-update kung naroon na). Ang parehong browser na naka-save sa ibang tao (shared na
     * PC sa office) ay lumilipat sa taong ito. Hanggang max_devices; ang pinakaluma ang natatanggal.
     *
     * @return array{0: array, 1: bool} ang card at kung bago ito
     */
    public static function save(User $user, string $endpoint, string $p256dh, string $auth, ?string $ua): array
    {
        return DB::transaction(function () use ($user, $endpoint, $p256dh, $auth, $ua) {
            $others = User::query()->whereNotNull('chat_push')->where('id', '!=', $user->id)->lockForUpdate()->get(['id', 'chat_push']);
            foreach ($others as $other) {
                $list = (array) $other->chat_push;
                $kept = array_values(array_filter($list, fn ($d) => ($d['endpoint'] ?? null) !== $endpoint));
                if (count($kept) !== count($list)) {
                    $other->forceFill(['chat_push' => $kept])->save();
                }
            }

            $me = User::query()->lockForUpdate()->findOrFail($user->id);
            $list = (array) $me->chat_push;
            $device = ['endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth, 'added_at' => now()->toIso8601String(), 'ua' => $ua ? mb_substr($ua, 0, 200) : null];
            $at = array_search($endpoint, array_column($list, 'endpoint'), true);
            $created = $at === false;
            if ($created) {
                $list[] = $device;
            } else {
                $list[$at] = ['added_at' => $list[$at]['added_at'] ?? $device['added_at']] + $device;
            }
            $max = max(1, (int) config('chat.push.max_devices', 10));
            if (count($list) > $max) {
                usort($list, fn ($a, $b) => strcmp((string) ($a['added_at'] ?? ''), (string) ($b['added_at'] ?? '')));
                $list = array_slice($list, -$max);
            }
            $me->forceFill(['chat_push' => array_values($list)])->save();
            $user->setRawAttributes($me->getAttributes(), true);

            return [self::card($device), $created];
        });
    }

    /** Alisin ang device sa sariling listahan lang. */
    public static function remove(User $user, string $endpoint): bool
    {
        return self::forget($user->id, $endpoint);
    }

    /** Alisin ang isang endpoint sa listahan ng isang tao (Turn off, o 404 / 410 mula sa push service). */
    public static function forget(int $userId, string $endpoint): bool
    {
        return DB::transaction(function () use ($userId, $endpoint) {
            $me = User::query()->lockForUpdate()->find($userId);
            if (! $me) {
                return false;
            }
            $list = (array) $me->chat_push;
            $kept = array_values(array_filter($list, fn ($d) => ($d['endpoint'] ?? null) !== $endpoint));
            if (count($kept) === count($list)) {
                return false;
            }
            $me->forceFill(['chat_push' => $kept])->save();

            return true;
        });
    }

    // ── sending ──────────────────────────────────────────────────────────────

    /** Ang sender: ang peke kapag may naka-bind (tests), kung wala ang totoong WebPushSender. */
    public static function sender(): PushSender
    {
        return app()->bound(PushSender::class) ? app(PushSender::class) : new WebPushSender;
    }

    /** Tinatawag pagkatapos gawin ang message (ChatMessage::created). Naka-queue lang; hindi kailanman nagpapabagal ng post. */
    public static function queue(ChatMessage $m): void
    {
        if (! self::enabled() || $m->isSystem()) {
            return;
        }
        SendChatPush::dispatch((int) $m->id)->afterCommit();
    }

    /**
     * Ang mga padala para sa isang message: bawat device ng bawat aktibong miyembro maliban sa nagpadala, hindi
     * naka-mute, hindi deactivated. Ang CEO na hindi miyembro ay wala rito (spec 304: walang bakas, walang natatanggap).
     * $only = mga endpoint lang na ito (retry).
     */
    public static function deliveries(ChatMessage $m, ?array $only = null): array
    {
        $members = ChatMember::query()->where('conversation_id', $m->conversation_id)->whereNull('left_at')
            ->where(fn ($w) => $w->whereNull('muted_until')->orWhere('muted_until', '<=', now()))
            ->when($m->sender_user_id !== null, fn ($q) => $q->where('user_id', '!=', $m->sender_user_id))
            ->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        if (! $members) {
            return [];
        }
        $users = User::query()->whereIn('users.id', $members)->whereNotNull('chat_push')
            ->whereNotIn('users.id', self::deactivatedIds($members))->get(['id', 'chat_push']);
        if ($users->isEmpty()) {
            return [];
        }

        $base = self::payload($m);
        $out = [];
        foreach ($users as $u) {
            $devices = array_filter((array) $u->chat_push, fn ($d) => $only === null || in_array($d['endpoint'] ?? null, $only, true));
            if (! $devices) {
                continue;
            }
            $payload = json_encode($base + ['unread' => ChatPresenter::unreadTotal((int) $u->id)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            foreach ($devices as $d) {
                $out[] = ['user_id' => (int) $u->id, 'endpoint' => (string) $d['endpoint'], 'p256dh' => (string) $d['p256dh'], 'auth' => (string) $d['auth'],
                    'payload' => $payload, 'topic' => 'c' . $m->conversation_id];
            }
        }

        return $out;
    }

    /** Ang laman ng notification (pareho sa lahat ng tatanggap, maliban sa unread). */
    public static function payload(ChatMessage $m): array
    {
        $c = $m->conversation;
        if ($m->sender_ai !== null) {
            $sender = AiPost::name($m->sender_ai);
        } else {
            $sender = ChatPeople::lookup([(int) $m->sender_user_id])->get((int) $m->sender_user_id)['name'] ?? 'Someone';
        }
        $files = $m->attachments ?? [];
        $voice = trim((string) $m->body) === '' && collect($files)->contains(fn ($a) => ($a['kind'] ?? '') === 'audio');

        return [
            'c' => (int) $m->conversation_id,
            'id' => (int) $m->id,
            // Gaya ng in-page alert ng 300: "Ana Cruz in Packing team" sa group.
            'title' => $c && $c->isGroup() && trim((string) $c->title) !== '' ? "$sender in {$c->title}" : $sender,
            'body' => $voice ? 'Sent a voice message' : ChatMessenger::preview($m, 80),
            'url' => '/chat?c=' . $m->conversation_id,
            'tag' => 'likha-chat-' . $m->conversation_id,
        ];
    }

    /** Ang mga deactivated (profile status inactive / resigned / terminated) sa mga id na ito. */
    private static function deactivatedIds(array $ids): array
    {
        return DB::table('employee_profiles')->whereIn('user_id', $ids)
            ->whereRaw('LOWER(TRIM(status)) IN (?, ?, ?)', ['inactive', 'resigned', 'terminated'])
            ->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }
}
