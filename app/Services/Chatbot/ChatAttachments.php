<?php

namespace App\Services\Chatbot;

use App\Models\AppSetting;
use App\Models\BotcakeChat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang mensaheng attachment lang ng customer (larawan, voice/audio, video, reel, file o "fallback" ng Botcake; walang
 * text): hindi sinasagot ng bot sa lahat ng page habang chatbot_attachments = ignore (ang default). Walang takbo (kaya
 * walang AI at walang padala); isang "ignored" event at ang marka sa chat (_ignored). Ang uri at link ay nasa
 * payload.attachment para sa susunod na feature (transcribe ng audio, paglalarawan ng larawan). Ang sticker at Like ay
 * hindi kasama: gaya ng dati. reply = gaya ng dati.
 */
class ChatAttachments
{
    public const SETTING = 'chatbot_attachments';
    public const IGNORE = 'ignore';
    public const REPLY = 'reply';
    public const MODES = [self::IGNORE, self::REPLY];
    public const IGNORED = ['photo', 'audio', 'video', 'reel', 'file', 'fallback'];

    /** "[audio] https://…" (Botcake) o "[Audio]" (Forbeast): ang uri at ang link kung mayroon. */
    private const MARKER = '/^\[(audio|image|photo|video|file|fallback|reel|ig_reel|sticker|location|attachment|share|template)\](?:\s+(\S+))?$/iu';
    /** Ang landas ng sticker sa Facebook CDN (ang Like at ang ibang sticker): hindi larawan ng customer. */
    private const STICKER_PATH = '/t39.1997-6/';

    public static function ignoring(): bool
    {
        return (string) AppSetting::get(self::SETTING, self::IGNORE) !== self::REPLY;
    }

    /**
     * Ang uri ng mensaheng attachment lang na hindi sinasagot (isa sa IGNORED), o null: text, Like, sticker, lokasyon,
     * o hindi kilala. Hindi tumitingin sa setting.
     */
    public static function type(?string $message, array $payload = []): ?string
    {
        $m = trim((string) $message);
        if ($m === '' || $m === ChatMessageCleaner::LIKE) return null;
        if ($m === ChatMessageCleaner::PHOTO) {
            $url = (string) ($payload['image_url'] ?? $payload['attachment']['url'] ?? '');

            return str_contains($url, self::STICKER_PATH) ? null : 'photo';
        }
        if (!preg_match(self::MARKER, $m, $x)) return null;
        $type = match (strtolower($x[1])) { 'image' => 'photo', 'ig_reel' => 'reel', default => strtolower($x[1]) };
        if ($type === 'photo' && str_contains((string) ($x[2] ?? ''), self::STICKER_PATH)) return null;

        return in_array($type, self::IGNORED, true) ? $type : null;
    }

    /** Ang uri at link mula sa "[uri] <link>" ng Botcake, para sa payload.attachment; null kapag hindi ganoon. */
    public static function fromMarker(string $message): ?array
    {
        if (!preg_match(self::MARKER, trim($message), $x)) return null;

        return ['type' => strtolower($x[1]), 'url' => isset($x[2]) && preg_match('#^https?://#i', $x[2]) ? mb_substr($x[2], 0, 2000) : null];
    }

    /**
     * Ang chat ay attachment lang at naka-ignore: itinatala (event at marka) at true, kaya hindi pinapatakbo ang flow.
     * Ang pagkabigo ng talaan ay hindi nagpapatakbo ng flow.
     */
    public static function ignore(BotcakeChat $chat): bool
    {
        $type = self::type($chat->message, (array) $chat->payload);
        if ($type === null || !self::ignoring()) return false;
        try {
            $chat->update(['payload' => (array) $chat->payload + ['_ignored' => $type]]);
            if (Schema::hasTable('chatbot_events') && ctype_digit((string) $chat->page_id) && ctype_digit((string) $chat->psid)) {
                DB::table('chatbot_events')->insert(['page_id' => (int) $chat->page_id, 'psid' => (int) $chat->psid, 'chat_id' => $chat->id, 'flow_id' => null,
                    'node_id' => null, 'type' => 'ignored', 'detail' => json_encode(['attachment' => $type]), 'created_at' => now()]);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return true;
    }
}
