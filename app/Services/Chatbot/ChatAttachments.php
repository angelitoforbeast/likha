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
    public const IGNORED = ['photo', 'audio', 'video', 'reel', 'file', 'fallback', 'gif'];

    /**
     * Ang marka ng attachment: "[audio](https://…)" (Botcake, markdown link), "[audio] https://…", o "[Audio]" (Forbeast),
     * walang pagkakaiba sa laki ng titik. Link lang ang puwedeng kasunod: ang text na isinulat ng customer (hal.
     * "[photo] ito") ay text, sinasagot.
     */
    private const MARKER = '/^\[(audio|image|photo|video|file|fallback|reel|ig_reel|sticker|location|attachment|share|template)\](?:\((https?:\/\/[^\s)]+)\)|\s+(https?:\/\/\S+))?$/iu';
    /** Ang landas ng sticker sa Facebook CDN (ang Like at ang ibang sticker): hindi larawan ng customer. */
    private const STICKER_PATH = '/t39.1997-6/';
    /**
     * Ang GIF na pinili ng customer sa Messenger: dumarating mula sa Botcake bilang link lang, walang marka. Ang buong
     * mensahe ay isang link sa cdn.fbsbx.com, may bahaging "t59.2708-21" sa landas, at .gif ang file. Ang ibang link
     * (giphy, ibang site) ay tunay na share ng customer: text pa rin.
     */
    private const GIF = '#^https?://cdn\.fbsbx\.com/(?:[^\s/?\#]+/)*t59\.2708-21/(?:[^\s/?\#]+/)*[^\s/?\#]+\.gif(?:[?\#]\S*)?$#i';

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
        if (self::gif($m) !== null) return 'gif';
        $a = self::fromMarker($m);
        if ($a === null) return null;
        $type = match ($a['type']) { 'image' => 'photo', 'ig_reel' => 'reel', default => $a['type'] };
        if ($type === 'photo' && str_contains((string) $a['url'], self::STICKER_PATH)) return null;

        return in_array($type, self::IGNORED, true) ? $type : null;
    }

    /**
     * Ang uri at link mula sa marka (MARKER, alinmang anyo), para sa payload.attachment at sa pagpares ng kopya; null
     * kapag hindi ganoon. Ang uri ay maliliit na titik, ang larawan ay "image" (gaya ng uri ng Meta).
     * @return array{type: string, url: ?string}|null
     */
    public static function fromMarker(string $message): ?array
    {
        if (!preg_match(self::MARKER, trim($message), $x)) return null;
        $url = ($x[2] ?? '') !== '' ? $x[2] : (($x[3] ?? '') !== '' ? $x[3] : null);

        return ['type' => strtolower($x[1]) === 'photo' ? 'image' : strtolower($x[1]), 'url' => $url !== null ? mb_substr($url, 0, 2000) : null];
    }

    /**
     * Ang uri at link ng GIF ng Messenger (ang anyo sa GIF sa itaas) para sa payload.attachment; null kapag hindi ganoon.
     * @return array{type: string, url: string}|null
     */
    public static function gif(string $message): ?array
    {
        $m = trim($message);

        return preg_match(self::GIF, $m) ? ['type' => 'gif', 'url' => mb_substr($m, 0, 2000)] : null;
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
