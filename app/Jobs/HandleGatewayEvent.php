<?php

namespace App\Jobs;

use App\Models\BotcakeChat;
use App\Models\BotcakePsid;
use App\Services\BotcakeChatGuard;
use App\Services\Chatbot\ChatbotInbound;
use App\Services\Chatbot\ChatbotProvider;
use App\Services\Chatbot\ChatEchoes;
use App\Services\Chatbot\ChatMessageCleaner;
use App\Services\Chatbot\CommentReplies;
use App\Services\Chatbot\ForbeastWatch;
use App\Services\Chatbot\Gateways;
use App\Services\Chatbot\SendStatus;
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
 *
 * Dalawang gateway (Gateways): ang parehong mensahe ng Meta ay dumarating mula sa dalawa (isa kada app). Ang mid ay iisa
 * sa dalawa, kaya ang unang dating lang ang pinoproseso. Walang mid (hal. referral): ang susi ay ang page, customer, uri at
 * oras ng Meta (iisa sa dalawang app), bago ang event_id (sariling bilang ng bawat gateway, kaya may pangalan ng gateway
 * ang ref ng hindi forbeast). Ang chat mula sa hindi forbeast ay may payload.gateway.
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
    public static function fields(array $e, string $gateway = Gateways::FORBEAST): array
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
            // Ang app ng nagpadala (echo) at ang oras ng Meta (ms), para sa ChatEchoes.
            'app_id' => is_scalar($e['app_id'] ?? null) && preg_match('/^\d{1,32}$/', (string) $e['app_id']) ? (string) $e['app_id'] : null,
            'timestamp' => is_int($e['timestamp'] ?? null) ? $e['timestamp'] : null,
            'quick_reply' => $s($e['quick_reply_payload'] ?? null, 1000),
            'postback' => ['title' => $s($postback['title'] ?? null, 1000), 'payload' => $s($postback['payload'] ?? null, 1000)],
            'ad_id' => $s($referral['ad_id'] ?? null, 64),
            // Ang comment (type comment): ang mga ID lang; ang text ng comment ay hindi ipinapasa sa queue.
            'comment_id' => $s($e['comment_id'] ?? null, 100), 'post_id' => $s($e['post_id'] ?? null, 100), 'parent_id' => $s($e['parent_id'] ?? null, 100),
            'from_id' => $s($e['from_id'] ?? null, 64), 'is_reply' => (bool) ($e['is_reply'] ?? false), 'is_page_author' => (bool) ($e['is_page_author'] ?? false),
            // Ang oras ng pagpasok sa queue: ang tagal bago ito naproseso ang lag ng Likha (ForbeastWatch::ingested).
            'queued_at' => now()->getTimestamp(),
        ] + (($e['type'] ?? null) === 'send_status' ? ['send' => self::send($e['send'] ?? null, $s)] : [])
            // Ang gateway na naghatid (wala = forbeast, gaya ng dati).
            + ($gateway !== Gateways::FORBEAST && Gateways::valid($gateway) ? ['gateway' => $gateway] : []);
    }

    /** Ang send ng send_status (SendStatus): ang mga field na ginagamit lang. */
    private static function send(mixed $send, \Closure $s): array
    {
        $d = is_array($send) ? $send : [];
        $int = fn ($v) => is_int($v) || (is_string($v) && ctype_digit($v)) ? (int) $v : null;

        return ['client_ref' => $s($d['client_ref'] ?? null, 100), 'status' => $s($d['status'] ?? null, 10), 'accepted_at' => $s($d['accepted_at'] ?? null, 40),
            'sent_at' => $s($d['sent_at'] ?? null, 40), 'message_count' => $int($d['message_count'] ?? null), 'sent_count' => $int($d['sent_count'] ?? null),
            'error_code' => $int($d['error_code'] ?? null), 'error_subcode' => $int($d['error_subcode'] ?? null), 'error' => $s($d['error'] ?? null, 500)];
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
        if (isset($e['queued_at'])) ForbeastWatch::ingested((int) $e['queued_at']);
        // Ang totoong oras ng padala (para sa Bot Health): walang takbo ng bot.
        if ($e['kind'] === 'event' && $e['type'] === 'send_status' && isset($e['send'])) {
            self::count(app(SendStatus::class)->receive($e));
            return;
        }
        if ($e['kind'] === 'event' && $e['type'] === 'comment' && array_key_exists('comment_id', $e)) {
            $pageName = BotcakeChat::pageMap()[(string) $e['page_id']] ?? null;
            self::count($pageName === null ? 'unknown_page' : app(CommentReplies::class)->receive($e, $pageName));
            return;
        }
        // Ang echo (padala ng page, alinmang app): iniimbak kahit nasa Botcake ang page o naka-Off ang bot, walang takbo.
        if ($e['kind'] === 'event' && $e['is_echo'] && array_key_exists('app_id', $e)) {
            self::count(ChatEchoes::store($e));
            return;
        }
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
        $gw = Gateways::valid($e['gateway'] ?? null) ? $e['gateway'] : Gateways::FORBEAST;
        // Ang event_id ay sariling bilang ng gateway: ang ref ng hindi forbeast ay may pangalan nito (walang banggaan).
        $ref = $e['mid'] ?: ($gw === Gateways::FORBEAST ? 'gw-event-' : 'gw-' . $gw . '-event-') . $e['event_id'];
        // Walang mid: ang susi na iisa sa dalawang gateway (page, customer, uri at oras ng Meta), kapag may oras at kapag
        // dalawa ang naka-set up na gateway (iisang gateway: gaya ng dati, ang event_id lang).
        $meta = !$e['mid'] && is_int($e['timestamp'] ?? null) && count(Gateways::setUpList()) > 1 ?'gateway-events:meta:' . sha1($e['page_id'] . '|' . $e['psid'] . '|' . $e['type'] . '|' . $e['timestamp']) : null;
        // Ang standby na kopya ng Botcake (hindi tumakbo) ay hindi ulit: ang gateway ang sasagot.
        $stored = BotcakeChat::where('message_id', $ref)->get(['id', 'payload'])->contains(fn ($c) => !ForbeastWatch::standby($c));
        if (!Cache::add('gateway-events:seen:' . sha1($ref), 1, self::DEDUPE_SECONDS) || $stored) {
            self::count('duplicate');
            return;
        }
        if ($meta !== null && !Cache::add($meta, 1, self::DEDUPE_SECONDS)) {
            self::count('duplicate');
            return;
        }

        try {
            $message = $this->message($e, $payload);
            if ($gw !== Gateways::FORBEAST) $payload['gateway'] = $gw;
            // Ang marka ng pinagmulan (forbeast / incepxionbot / botcake), sa bawat chat: ang pumasok kada bot sa Live.
            $payload['_via'] = $gw;
            $chat = BotcakeChat::create([
                'page_id' => (string) $e['page_id'], 'page_name' => $pageName, 'psid' => (string) $e['psid'], 'name' => $this->name($settings, $pageName, $e),
                'message' => $message, 'message_id' => $ref, 'payload' => $payload, 'received_at' => now(),
            ]);
        } catch (\Throwable $ex) {
            // hindi naimbak: ang ulit ng job (o ang kopya mula sa kabilang gateway) ay tatanggapin
            Cache::forget('gateway-events:seen:' . sha1($ref));
            if ($meta !== null) Cache::forget($meta);
            throw $ex;
        }
        self::count((string) $e['type']);
        // Ang pagbukas ng ad ay itinatala lang; ang mensahe o postback ng customer ang nagsisimula ng takbo.
        if ($e['type'] === 'referral') ChatbotInbound::adOpened($chat);
        else ChatbotInbound::afterStore($chat, $pageName, waitForAd: true);
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
        // Ang pagbukas ng ad (walang isinulat ang customer): binabasa ang ad gaya ng dati, pero hindi ito sinasagot.
        if ($e['type'] === 'referral') $payload[ChatbotInbound::AD_OPEN] = true;
        if ($e['quick_reply'] !== null && $e['quick_reply'] !== '') $payload['quick_reply'] = $e['quick_reply'];
        if ($e['type'] === 'postback') {
            $payload['postback'] = $e['postback']['payload'];
            return $e['postback']['title'] ?: $e['postback']['payload'];
        }
        $first = $e['attachments'][0] ?? null;
        // Ang uri at link ng unang attachment, para sa susunod na transcribe o paglalarawan.
        if ($first && $first['type']) {
            $payload['attachment'] = ['type' => $first['type'], 'url' => $first['url'] !== null && preg_match('#^https?://#i', $first['url']) ? $first['url'] : null];
        }
        if ($e['text'] !== null && $e['text'] !== '') return $e['text'];
        if (!$first) return null;
        if ($first['type'] === 'image' && $first['url']) {
            if (ChatMessageCleaner::clean($first['url'])['kind'] === 'like') return ChatMessageCleaner::LIKE;
            $payload['image_url'] = $first['url'];
            return ChatMessageCleaner::PHOTO;
        }

        return '[' . ucfirst((string) ($first['type'] ?: 'attachment')) . ']';
    }
}
