<?php

namespace App\Jobs;

use App\Models\BotcakeChat;
use App\Models\BotcakePsid;
use App\Services\BotcakeChatGuard;
use App\Services\Chatbot\ChatbotInbound;
use App\Services\Chatbot\ChatbotProvider;
use App\Services\Chatbot\ChatMessageCleaner;
use App\Services\Chatbot\ForbeastWatch;
use App\Services\PageSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * Isang event ng Forbeast Gateway (pinirmahan na, GatewayEventController). Ang message, postback at referral ng page na
 * nasa gateway (chatbot.provider) ay iniimbak bilang chat gaya ng mula sa Botcake, saka ang parehong kasunod
 * (ChatbotInbound). Isang beses lang kada mid (o event_id kapag walang mid), pati laban sa chat na naimbak na ng Botcake.
 * Ang ibang event, at ang event ng page na nasa Botcake, ay binibilang lang (walang takbo).
 */
class HandleGatewayEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const CHATS = ['message', 'postback', 'referral'];
    private const DEDUPE_SECONDS = 86400;

    public function __construct(public array $event)
    {
        $this->onConnection(config('queue.chatbot_connection'))->onQueue(RunChatbotFlow::HIGH_QUEUE);
    }

    /** Ang mga field na ginagamit lang, may hangganan ang haba (ang laman ng event ay galing sa customer). */
    public static function fields(array $e): array
    {
        $s = fn ($v, int $max) => is_scalar($v) ? mb_substr(mb_scrub(trim((string) $v), 'UTF-8'), 0, $max) : null;
        $attachments = [];
        foreach (array_slice(is_array($e['attachments'] ?? null) ? $e['attachments'] : [], 0, 10) as $a) {
            if (is_array($a)) $attachments[] = ['type' => $s($a['type'] ?? null, 20), 'url' => $s($a['url'] ?? null, 2000)];
        }
        $referral = is_array($e['referral'] ?? null) ? $e['referral'] : [];
        $postback = is_array($e['postback'] ?? null) ? $e['postback'] : [];

        return [
            'kind' => $s($e['kind'] ?? 'event', 30), 'event_id' => $s($e['event_id'] ?? null, 40), 'type' => $s($e['type'] ?? null, 20),
            'page_id' => $s($e['page_id'] ?? null, 64), 'psid' => $s($e['psid'] ?? null, 64), 'mid' => $s($e['mid'] ?? null, 191),
            'text' => $s($e['text'] ?? null, 5000), 'attachments' => $attachments, 'is_echo' => (bool) ($e['is_echo'] ?? false),
            'quick_reply' => $s($e['quick_reply_payload'] ?? null, 1000),
            'postback' => ['title' => $s($postback['title'] ?? null, 1000), 'payload' => $s($postback['payload'] ?? null, 1000)],
            'ad_id' => $s($referral['ad_id'] ?? null, 64),
        ];
    }

    /** Bilang kada araw (Manila), 3 araw: ang type ng event, 'echo', 'botcake_page', 'duplicate', … */
    public static function count(string $what): void
    {
        $key = 'gateway-events:' . now('Asia/Manila')->toDateString() . ':' . $what;
        Cache::add($key, 0, now()->addDays(3));
        Cache::increment($key);
    }

    public function handle(PageSettings $settings, BotcakeChatGuard $guard): void
    {
        $e = $this->event;
        if ($e['kind'] !== 'event' || !in_array($e['type'], self::CHATS, true) || $e['is_echo']) {
            self::count($e['is_echo'] ? 'echo' : ($e['kind'] !== 'event' ? 'other' : (string) $e['type']));
            return;
        }
        $pageName = BotcakeChat::pageMap()[(string) $e['page_id']] ?? null;
        if ($pageName === null || (string) $e['psid'] === '') {
            self::count('unknown_page');
            return;
        }
        // Ang page na nasa Botcake: doon nanggagaling ang takbo; binibilang lang ito (para sa paghahambing balang araw).
        if (ChatbotProvider::name($pageName) !== ChatbotProvider::GATEWAY) {
            self::count('botcake_page');
            return;
        }
        if ($guard->botOff($pageName)) {
            self::count('bot_off');
            return;
        }

        // Walang mid at walang event_id: hindi masusuri kung ulit, kaya hindi iniimbak (walang dobleng sagot).
        if (!$e['mid'] && (string) $e['event_id'] === '') {
            self::count('no_id');
            return;
        }
        $ref = $e['mid'] ?: 'gw-event-' . $e['event_id'];
        // Ang standby na kopya ng Botcake (hindi tumakbo) ay hindi ulit: ang Forbeast ang sasagot.
        $stored = BotcakeChat::where('message_id', $ref)->get(['id', 'payload'])->contains(fn ($c) => !ForbeastWatch::standby($c));
        if (!Cache::add('gateway-events:seen:' . sha1($ref), 1, self::DEDUPE_SECONDS) || $stored) {
            self::count('duplicate');
            return;
        }

        try {
            $message = $this->message($e, $payload);
            $chat = BotcakeChat::create([
                'page_id' => (string) $e['page_id'], 'page_name' => $pageName, 'psid' => (string) $e['psid'], 'name' => $this->name($settings, $pageName, $e),
                'message' => $message, 'message_id' => $ref, 'payload' => $payload, 'received_at' => now(),
            ]);
        } catch (\Throwable $ex) {
            Cache::forget('gateway-events:seen:' . sha1($ref)); // hindi naimbak: ang ulit ng job ay tatanggapin
            throw $ex;
        }
        self::count((string) $e['type']);
        ChatbotInbound::afterStore($chat, $pageName);
    }

    /** Ang pangalan: sa botcake_psids (page + PSID), saka sa huling chat na may pangalan; kung wala, blangko. */
    private function name(PageSettings $settings, string $pageName, array $e): ?string
    {
        $key = $settings->pageKey($pageName);
        $known = BotcakePsid::where('psid', (string) $e['psid'])->get(['page', 'fb_name'])
            ->first(fn ($r) => $settings->pageKey((string) $r->page) === $key && trim((string) $r->fb_name) !== '');
        if ($known) return mb_substr((string) $known->fb_name, 0, 255);

        return BotcakeChat::where('page_id', (string) $e['page_id'])->where('psid', (string) $e['psid'])->whereNotNull('name')->where('name', '!=', '')
            ->orderByDesc('id')->value('name');
    }

    /** Ang mensahe gaya ng sa Botcake: ang text, ang pamagat ng postback, o 👍 / [Photo] / [Video] para sa attachment. */
    private function message(array $e, ?array &$payload): ?string
    {
        $payload = ['source' => 'gateway'];
        if ($e['ad_id'] !== null && preg_match('/^\d{1,64}$/', $e['ad_id'])) $payload['ad_id'] = $e['ad_id'];
        if ($e['quick_reply'] !== null && $e['quick_reply'] !== '') $payload['quick_reply'] = $e['quick_reply'];
        if ($e['type'] === 'postback') {
            $payload['postback'] = $e['postback']['payload'];
            return $e['postback']['title'] ?: $e['postback']['payload'];
        }
        if ($e['text'] !== null && $e['text'] !== '') return $e['text'];
        $first = $e['attachments'][0] ?? null;
        if (!$first) return null;
        if ($first['type'] === 'image' && $first['url']) {
            if (ChatMessageCleaner::clean($first['url'])['kind'] === 'like') return ChatMessageCleaner::LIKE;
            $payload['image_url'] = $first['url'];
            return ChatMessageCleaner::PHOTO;
        }

        return '[' . ucfirst((string) ($first['type'] ?: 'attachment')) . ']';
    }
}
