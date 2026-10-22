<?php

namespace App\Services\Chatbot;

use App\Models\BotcakeChat;
use App\Models\ChatbotReply;
use App\Models\PageSetting;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Ang bantay ng Forbeast: ang page na nasa Forbeast (gateway) ay kusang inililipat sa Botcake kapag pumapalya ang Forbeast,
 * kung may Botcake token ang page. Hindi kailanman ibinabalik nang kusa (tao ang nagbabalik). Ang mga kondisyon (ang bilang
 * ay nasa services.gateway.auto_switch): sunod-sunod na palyang padala (hindi kasama ang error na sa customer ang dahilan),
 * error ng Meta sa koneksyon ng page (agad), walang sagot ang Forbeast nang sunod-sunod, may chat ang Botcake pero wala ang
 * Forbeast, at ang health check (GET /v1/pages/{id}) na pumapalya o nagsasabing hindi konektado.
 *
 * Bawat lipat (o hindi makalipat) ay itinatala sa chatbot.auto_switch ng page (JSON: result, from, to, condition, error),
 * kasama ng history ng page settings; ito rin ang event feed (/api/chatbot/provider-events).
 */
class ForbeastWatch
{
    public const KEY = 'auto_switch';
    public const WHO = 'auto-switch';
    public const SWITCHED = 'switched';
    public const CANNOT = 'cannot_switch';

    /** Mga error ng Meta na sa customer ang dahilan (wala na, hindi na maabot, labas sa 24 oras, naka-block): hindi binibilang. */
    public const CUSTOMER_CODES = [551, 10];
    public const CUSTOMER_SUBCODES = [1545041, 2018001, 2018108, 2018278];
    /** Mga error ng Meta sa koneksyon ng page (token, permiso, rate limit): lipat agad. */
    public const CONNECTION_CODES = [190, 200, 2022, 613, 4, 32];

    /** Ang dahilan sa screen, kada kondisyon. */
    public const REASONS = [
        'failed_sends' => 'Forbeast sends failed in a row',
        'connection_error' => 'Meta connection error',
        'no_answer' => 'Forbeast did not answer',
        'inbound' => 'Botcake got chats, Forbeast none',
        'health' => 'Forbeast health check failed',
    ];

    /** Ilang segundo itinatago ang bilang ng palya, at ang "naitala na" ng hindi makalipat (isang event kada oras). */
    private const COUNT_TTL = 86400;
    private const CANNOT_TTL = 3600;
    private const INBOUND_GRACE = 60;
    /** Ilang minuto ang layo ng standby at ng kopya ng Forbeast na may parehong text (walang parehong mid). */
    private const TWIN_MINUTES = 2;

    public function __construct(private PageSettings $settings)
    {
    }

    /** Pagkatapos ng bawat padala sa Forbeast (GatewayProvider). $body: ang sagot ng Forbeast. */
    public function afterSend(string $page, array $outcome, mixed $body): void
    {
        if (ChatbotProvider::name($page) !== ChatbotProvider::GATEWAY) return;
        if ($outcome['status'] === 'sent') {
            $this->reset($page, 'fails', 'no_answer');
            return;
        }
        if ($outcome['error'] === GatewayProvider::NOT_SET_UP) return; // walang tawag: hindi palya ng Forbeast

        [$code, $sub] = self::metaCode($body);
        if (in_array($code, self::CUSTOMER_CODES, true) || in_array($sub, self::CUSTOMER_SUBCODES, true)) return;
        // Ang HTTP at ang code lang: ang text ng sagot ng Forbeast ay maaaring may laman ng mensahe (napupunta sa feed at sa screen).
        $http = (int) ($outcome['http'] ?? 0);
        $error = ($http === 0 ? 'Forbeast did not answer' : 'HTTP ' . $http) . ($code !== null ? ' (Meta #' . $code . ')' : '');
        if (in_array($code, self::CONNECTION_CODES, true)) {
            $this->switch($page, 'connection_error', $error);
            return;
        }

        $noAnswer = $http === 0 || $http >= 500;
        $fails = $this->bump($page, 'fails');
        if ($noAnswer && $this->bump($page, 'no_answer') >= self::limit('no_answer')) $this->switch($page, 'no_answer', $error);
        elseif ($fails >= self::limit('failed_sends')) $this->switch($page, 'failed_sends', $error);
        if (!$noAnswer) $this->reset($page, 'no_answer');
    }

    /** Ang health check ng page: GET /v1/pages/{id} gamit ang Forbeast token ng page (o ang global key). */
    public function health(string $page): void
    {
        $url = rtrim((string) config('services.gateway.url'), '/');
        $pageId = trim((string) $this->settings->get($page, 'botcake', 'page_id'));
        // Sariling token lang ng page: ang system key (GATEWAY_KEY, fbs_) ay 404 sa /v1/pages/{id}, kaya hindi ito sukatan.
        $key = app(ForbeastPageSetup::class)->token($page);
        if ($url === '' || $pageId === '' || $key === '') return;

        try {
            $res = Http::withToken($key)->acceptJson()->connectTimeout(3)->timeout(5)->get($url . '/v1/pages/' . rawurlencode($pageId));
        } catch (ConnectionException) {
            $res = null;
        } catch (Throwable $e) {
            report($e);
            $res = null;
        }
        if ($res !== null && $res->successful()) {
            if ($res->json('connected') === false || $res->json('can_send') === false) {
                $this->switch($page, 'health', 'Forbeast says the page is ' . ($res->json('connected') === false ? 'not connected' : 'not able to send'));
                return;
            }
            $this->reset($page, 'health');
            return;
        }
        if ($this->bump($page, 'health') >= self::limit('health_fails')) {
            $this->switch($page, 'health', $res === null ? 'Forbeast did not answer' : 'Forbeast answered HTTP ' . $res->status());
        }
    }

    /**
     * May chat ang Botcake (standby) sa huling ilang minuto pero wala ang Forbeast: sira ang pagtanggap ng Forbeast. Ang
     * standby na kararating lang (wala pang INBOUND_GRACE segundo) ay hindi binibilang: ang kopya ng Forbeast ay maaaring
     * nasa queue pa. Sa scheduled command lang.
     */
    public function inbound(string $page, string $pageId): void
    {
        if (ChatbotProvider::name($page) !== ChatbotProvider::GATEWAY) return;
        $minutes = self::limit('inbound_minutes');
        $chats = BotcakeChat::where('page_id', $pageId)->where('received_at', '>=', now()->subMinutes($minutes))->get(['payload', 'received_at']);
        $settled = now()->subSeconds(self::INBOUND_GRACE);
        $standby = $chats->filter(fn ($c) => self::standby($c) && $c->received_at?->lte($settled))->count();
        $forbeast = $chats->filter(fn ($c) => ($c->payload['source'] ?? null) === 'gateway')->count();
        if ($standby >= self::limit('inbound_chats') && $forbeast === 0) {
            $this->switch($page, 'inbound', $standby . ' Botcake chats and no Forbeast chat in ' . $minutes . ' minutes');
        }
    }

    /**
     * Ang lipat sa Botcake: gaya ng select sa /chatbot/pages (PageSettings::set ng chatbot.provider, may history), saka ang
     * event, saka ang mga standby chat. Walang Botcake token: hindi lumilipat, isang "cannot switch" event kada oras.
     */
    public function switch(string $page, string $condition, string $error): void
    {
        $hash = sha1($this->settings->pageKey($page));
        $lock = Cache::lock('forbeast-watch:switch:' . $hash, 60);
        if (!$lock->get()) return;

        try {
            if (ChatbotProvider::name($page) !== ChatbotProvider::GATEWAY) return;
            if (trim((string) $this->settings->get($page, 'botcake', 'token')) === '') {
                if (Cache::add('forbeast-watch:cannot:' . $hash, 1, self::CANNOT_TTL)) $this->record($page, self::CANNOT, null, $condition, $error);
                return;
            }
            DB::transaction(function () use ($page, $condition, $error) {
                $this->settings->set($page, ChatbotFields::PROVIDER, 'provider', ChatbotProvider::BOTCAKE);
                $this->record($page, self::SWITCHED, ChatbotProvider::BOTCAKE, $condition, $error);
            });
            $this->reset($page, 'fails', 'no_answer', 'health');
            Cache::forget('forbeast-watch:cannot:' . $hash);
            $this->runStandby($page);
        } finally {
            $lock->release();
        }
    }

    /**
     * Ang note sa /chatbot/pages: ang huling event ay lipat na mas bago sa provider row (walang lumipat nang mano-mano
     * pagkatapos). @return array{at: string, reason: string}|null
     */
    public static function note(?PageSetting $event, ?PageSetting $provider): ?array
    {
        if ($event === null || ($provider !== null && $provider->id > $event->id)) return null;
        $e = json_decode((string) $event->plainValue(), true);
        if (!is_array($e) || ($e['result'] ?? null) !== self::SWITCHED) return null;

        return ['at' => (string) $event->created_at?->timezone('Asia/Manila')->format('Y-m-d H:i'), 'reason' => self::reason($e)];
    }

    /** Ang dahilan bilang text: ang kondisyon at ang huling error. */
    public static function reason(array $e): string
    {
        $label = self::REASONS[$e['condition'] ?? ''] ?? (string) ($e['condition'] ?? '');

        return $label . (($e['error'] ?? '') !== '' ? ': ' . $e['error'] : '');
    }

    /** Ang chat ng Botcake na iniimbak lang habang nasa Forbeast ang page (walang takbo). */
    public static function standby(BotcakeChat $chat): bool
    {
        return !empty($chat->payload['_standby']);
    }

    /**
     * Ang kopya ng parehong mensahe sa kabila: ang chat ng Forbeast ng isang standby, o ang standby ng isang chat ng
     * Forbeast. Parehong message_id (mid), o parehong customer at text sa loob ng TWIN_MINUTES.
     */
    private static function twin(BotcakeChat $chat, bool $wantStandby): ?BotcakeChat
    {
        $at = $chat->received_at ?? now();

        return BotcakeChat::where('page_id', $chat->page_id)->where('psid', $chat->psid)->where('id', '!=', $chat->id)
            ->whereBetween('received_at', [$at->copy()->subMinutes(self::TWIN_MINUTES), $at->copy()->addMinutes(self::TWIN_MINUTES)])
            ->orderBy('id')->get()
            ->first(fn ($c) => self::standby($c) === $wantStandby
                && (($chat->message_id !== null && $c->message_id === $chat->message_id) || $c->message === $chat->message));
    }

    /**
     * Naipadala ang sagot ng Forbeast sa chat na ito: ang standby na kopya nito (kung mayroon) ay hindi na kailangan. Habang
     * walang naipadalang sagot, naiiwan ang standby para masagot ito kapag lumipat sa Botcake.
     */
    public function answered(int $chatId): void
    {
        $chat = BotcakeChat::find($chatId);
        if ($chat === null || self::standby($chat) || ($chat->payload['source'] ?? null) !== 'gateway') return;
        self::twin($chat, true)?->delete();
    }

    /**
     * Binubura ang standby na mas luma sa standby_minutes + 5 (ang lipat ay hindi na ito kukuha): ang kopya ng chat na
     * walang naipadalang sagot (hal. wait step) ay hindi mananatili habambuhay. @return int ilang row ang nabura
     */
    public function prune(): int
    {
        if (!Schema::hasTable('botcake_chats')) return 0;

        // Sa isang araw lang ang saklaw (index ng received_at), at burahin ayon sa id: tumatakbo ito kada minuto, kaya hindi
        // dapat mag-scan at mag-lock ng 90 araw ng table. Ang mas luma pa ay kukunin ng 90-araw na prune (BotcakeChat::prune).
        $cutoff = now()->subMinutes(self::limit('standby_minutes') + 5);
        $ids = BotcakeChat::whereNotNull('payload->_standby')->where('received_at', '<', $cutoff)->where('received_at', '>=', $cutoff->copy()->subDay())->pluck('id');

        return $ids->isEmpty() ? 0 : BotcakeChat::whereIn('id', $ids)->delete();
    }

    /**
     * Ang mga standby chat ng huling ilang minuto, luma muna, maliban sa may kopya sa Forbeast na may naipadala nang sagot:
     * bagong chat (para sa takbo ayon sa id), at ang standby ay tinatanggal, kaya isang beses lang.
     */
    private function runStandby(string $page): void
    {
        $pageId = trim((string) $this->settings->get($page, 'botcake', 'page_id'));
        if ($pageId === '') return;
        $chats = BotcakeChat::where('page_id', $pageId)->where('received_at', '>=', now()->subMinutes(self::limit('standby_minutes')))
            ->orderBy('id')->get()->filter(fn ($c) => self::standby($c))->values();
        foreach ($chats as $c) {
            try {
                $twin = self::twin($c, false);
                if ($twin !== null && ChatbotReply::where('chat_id', $twin->id)->where('kind', 'send')->where('status', 'sent')->exists()) continue;
                $chat = DB::transaction(function () use ($c) {
                    $new = $c->replicate();
                    $payload = (array) $c->payload;
                    unset($payload['_standby']);
                    $new->payload = $payload;
                    $new->save();
                    $c->delete();

                    return $new;
                });
                ChatbotInbound::afterStore($chat, $page);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    private function record(string $page, string $result, ?string $to, string $condition, string $error): void
    {
        $this->settings->set($page, ChatbotFields::PROVIDER, self::KEY, json_encode([
            'result' => $result, 'from' => ChatbotProvider::GATEWAY, 'to' => $to, 'condition' => $condition,
            'error' => mb_substr(trim($error), 0, 200), 'at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), false, null, true);
    }

    /**
     * Ang code at subcode ng error ng Meta sa sagot ng Forbeast: sa error / meta / meta_error, o ang "#123" sa mensahe.
     * @return array{0: ?int, 1: ?int}
     */
    public static function metaCode(mixed $body): array
    {
        if (!is_array($body)) return [null, null];
        $int = fn ($v) => is_int($v) || (is_string($v) && ctype_digit($v)) ? (int) $v : null;
        $code = $sub = null;
        foreach (['error', 'meta', 'meta_error', 'error.meta', null] as $root) {
            $node = $root === null ? $body : data_get($body, $root);
            if (!is_array($node)) continue;
            $code ??= $int($node['code'] ?? null);
            $sub ??= $int($node['error_subcode'] ?? null);
        }
        if ($code === null && preg_match('/#(\d{1,7})\b/', \App\Services\BotcakeClient::messageText($body), $m)) $code = (int) $m[1];

        return [$code, $sub];
    }

    private static function limit(string $key): int
    {
        return max(1, (int) config('services.gateway.auto_switch.' . $key));
    }

    private function bump(string $page, string $what): int
    {
        $key = $this->counter($page, $what);
        Cache::add($key, 0, self::COUNT_TTL);

        return (int) Cache::increment($key);
    }

    private function reset(string $page, string ...$what): void
    {
        foreach ($what as $w) Cache::forget($this->counter($page, $w));
    }

    private function counter(string $page, string $what): string
    {
        return 'forbeast-watch:' . $what . ':' . sha1($this->settings->pageKey($page));
    }
}
