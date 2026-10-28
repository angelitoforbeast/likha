<?php

namespace App\Services\Chatbot;

use App\Jobs\RunChatbotFlow;
use App\Models\BotcakeChat;
use App\Models\ChatbotReply;
use App\Models\PageSetting;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Ang bantay ng Forbeast: ang page na nasa Forbeast (gateway) ay kusang inililipat sa Botcake kapag pumapalya ang Forbeast,
 * kung may Botcake token ang page. Ang mga kondisyon (ang bilang ay nasa services.gateway.auto_switch): sunod-sunod na
 * palyang padala (hindi kasama ang error na sa customer ang dahilan), error ng Meta sa koneksyon ng page (agad), walang sagot
 * ang Forbeast nang sunod-sunod, may chat ang Botcake pero wala ang Forbeast, at ang health check (GET /v1/pages/{id}) na
 * pumapalya o nagsasabing hindi konektado.
 *
 * Ang "inbound" ay lumilipat lang kapag ang Forbeast talaga ang mabagal: hindi kapag ang Likha ang huli sa pag-imbak ng
 * gateway events, at hindi kapag sinasabi ng Forbeast na mabilis ito (inbound_lag_seconds) — ang nawawalang mensahe ay hindi
 * ipinadala ng Meta, kaya sinasagot ito dito at nananatili ang page ("held"). Ang page na inilipat ng bantay dahil sa inbound,
 * sa padala o sa health ay kusang ibinabalik sa Forbeast pagkatapos ng ilang minutong sunod-sunod na maayos na health
 * (switchBack); ang lipat dahil sa koneksyon, at ang page na binago ng tao pagkatapos, ay tao ang nagbabalik.
 *
 * Bawat lipat, hindi makalipat, held at balik ay itinatala sa chatbot.auto_switch ng page (JSON: result, from, to, condition,
 * error, at mga dagdag na field), kasama ng history ng page settings; ito rin ang event feed (/api/chatbot/provider-events).
 */
class ForbeastWatch
{
    public const KEY = 'auto_switch';
    public const WHO = 'auto-switch';
    public const SWITCHED = 'switched';
    public const CANNOT = 'cannot_switch';
    public const HELD = 'held';
    public const SWITCHED_BACK = 'switched_back';
    /** Inilipat sa Forbeast ng tao o ng ibang sistema (hindi ng bantay): ang simula ng panahon sa Forbeast. */
    public const MOVED = 'moved';
    /** Ang mga lipat na kusang ibinabalik: ang sa koneksyon ng page (token, permiso) ay tao ang nag-aayos. */
    public const BACK_CONDITIONS = ['inbound', 'failed_sends', 'no_answer', 'health'];

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
        'send_lag' => 'Forbeast send lag',
    ];

    /** Ilang segundo itinatago ang bilang ng palya, at ang "naitala na" ng hindi makalipat (isang event kada oras). */
    private const COUNT_TTL = 86400;
    private const CANNOT_TTL = 3600;
    /** Ang standby na dumating sa SETTLE ay sinasagot pagkalipas nito (ang ebidensya ng lipat ay inbound_seconds). */
    private const INBOUND_GRACE = 120;
    /** Ilang minuto ang layo ng standby at ng kopya ng Forbeast na may parehong text (walang parehong mid). */
    private const TWIN_MINUTES = 2;
    /** Ang huling lag ng Likha sa pag-imbak ng gateway event (HandleGatewayEvent), ilang segundo itong itinuturing na bago. */
    private const INGEST = 'forbeast-watch:ingest';
    private const INGEST_TTL = 300;
    /** Ang maayos na health ay "sunod-sunod" habang ang pagitan ng mga check ay hindi lalampas dito (segundo). */
    private const STREAK_GAP = 180;
    /** Ilang oras ipinapakita sa /chatbot/pages ang note ng held at ng balik. */
    private const NOTE_HOURS = 24;
    /** Ang "moved" event ay itinatala lang sa lipat na ganito kabago (oras): ang lumang lipat bago ang deploy ay hindi. */
    private const MOVED_HOURS = 1;
    /** Ang sagot ng bot na ilang segundo bago dumating ang standby ay sagot na rin dito (magkasabay na dating). */
    private const REPLIED_GRACE = 5;
    /** Ang tanda sa payload ng chat ng Forbeast na nabura na ang standby na kapares nito. */
    private const TWIN_USED = '_twin_used';
    /**
     * Ang tanda sa chat na sinagot mula sa standby: ebidensya pa rin ito ng kopyang wala sa Forbeast (inbound), para ang
     * mga customer na sinagot isa-isa ay naiipon pa rin hanggang inbound_customers.
     */
    private const FROM_STANDBY = '_from_standby';

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
            // Ang inbound_lag_seconds (bagong Forbeast lang): ang tagal ng mensahe sa Forbeast bago ipasa. Wala = hindi alam.
            $lag = $res->json('inbound_lag_seconds');
            $lag = is_numeric($lag) ? max(0, (int) $lag) : null;
            if ($lag === null) $this->reset($page, 'lag'); else Cache::put($this->counter($page, 'lag'), $lag, self::INGEST_TTL);
            if ($res->json('connected') === false || $res->json('can_send') === false) {
                $this->streak($page, false);
                $this->switch($page, 'health', 'Forbeast says the page is ' . ($res->json('connected') === false ? 'not connected' : 'not able to send'));
                return;
            }
            $this->reset($page, 'health');
            $this->streak($page, $lag === null || $lag < self::limit('lag_seconds'));
            return;
        }
        $this->reset($page, 'lag');
        $this->streak($page, false);
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
        // Kasama ang TWIN_MINUTES bago ang window: ang kapares ng pinakaunang standby ay maaaring nauna nang kaunti.
        $chats = BotcakeChat::where('page_id', $pageId)->where('received_at', '>=', now()->subMinutes($minutes + self::TWIN_MINUTES))
            ->orderBy('id')->get(['id', 'psid', 'message', 'message_id', 'payload', 'received_at']);
        $settled = now()->subSeconds(self::INBOUND_GRACE);
        $proof = now()->subSeconds(self::limit('inbound_seconds'));
        $from = now()->subMinutes($minutes);
        // Ang standby (kopya ng Botcake) na walang kapares mula sa Forbeast pagkalipas ng inbound_seconds. Ebidensya lang kapag
        // galing sa inbound_customers na magkaibang customer: ang isang kakaibang mensahe ay hindi dapat maglipat ng page.
        $paired = self::pairs($chats, true);
        // Ang attachment lang (larawan, voice, …) ay hindi ebidensya habang hindi ito sinasagot ng bot (ignore).
        $ignoring = ChatAttachments::ignoring();
        $unpaired = $chats->filter(fn ($c) => self::copy($c, true) && !isset($paired[$c->id]) && $c->received_at?->lte($settled) && $c->received_at?->gte($from));
        // Ang dumating bago ang lipat sa Forbeast + settle_seconds ay hindi ebidensya, pero sinasagot dito (walang ibang sasagot):
        // ang kopya ng Botcake ay dumarating 60–80 s pagkatapos ng sa Forbeast, kaya ang mensaheng dumating bago ang lipat
        // (itinapon ng Forbeast dahil nasa Botcake pa ang page) ay may standby na walang kapares kailanman. Kahit sino ang
        // naglipat: tao, ibang sistema, o ang balik ng bantay.
        $moved = $this->settings->pageQuery($page)->where('provider', ChatbotFields::PROVIDER)->where('key', 'provider')->where('active', true)
            ->orderByDesc('id')->first();
        $movedAt = $moved?->created_at;
        if ($moved !== null) $this->noticeMove($page, $moved);
        $late = $movedAt === null ? collect() : $unpaired->filter(fn ($c) => $c->received_at->lt($movedAt->copy()->addSeconds(self::limit('settle_seconds'))));
        $orphans = $unpaired->diffKeys($late)->filter(fn ($c) => $c->received_at->lte($proof) && !($ignoring && ChatAttachments::type($c->message, (array) $c->payload) !== null));
        $customers = $orphans->pluck('psid')->map(fn ($p) => (string) $p)->unique()->count();
        $answer = $late;
        if ($orphans->isNotEmpty()) {
            $enough = $orphans->count() >= self::limit('inbound_chats') && $customers >= self::limit('inbound_customers');
            $slow = self::limit('lag_seconds');
            $likha = $this->likhaLag();
            $forbeast = Cache::get($this->counter($page, 'lag'));
            if ($likha >= $slow) {
                // Ang Likha ang huli: ang kopya ng Forbeast ay nasa queue pa, at iyon ang sasagot (walang dobleng sagot dito).
                if ($enough) $this->hold($page, 'likha', 'held: Likha ingest lag ' . $likha . ' s', ['customers' => $customers, 'likha_lag' => $likha]);
            } elseif ($forbeast !== null && $forbeast < $slow) {
                // Mabilis ang Forbeast: ang mensahe ay hindi ipinadala ng Meta sa Forbeast, kaya walang darating na kopya. Ang
                // page ay nananatili, at ang mga customer na ito ay sinasagot mula sa standby.
                if ($enough) $this->hold($page, 'forbeast', 'held: Forbeast healthy (lag ' . $forbeast . ' s), ' . $customers . ' customers missing — likely not delivered by Meta',
                    ['customers' => $customers, 'forbeast_lag' => (int) $forbeast]);
                $answer = $late->union($orphans);
            } elseif (!$enough) {
                // Kulang ang ebidensya para lumipat: ang customer na wala pa ring kopya pagkalipas ng inbound_seconds ay sinasagot
                // mula sa standby, maliban kung sinabi ng Forbeast na mabagal ito (ang kopya ay parating pa, iyon ang sasagot).
                if ($forbeast === null) $answer = $late->union($orphans);
            } else {
                $this->switch($page, 'inbound', ($forbeast !== null ? 'Forbeast slow: ' . $forbeast . ' s · ' : '') . 'Botcake chats from ' . $customers
                    . ' customers with no Forbeast copy after ' . self::limit('inbound_seconds') . ' seconds',
                    ['customers' => $customers, 'chat_ids' => $orphans->pluck('id')->take(50)->values()->all(), 'likha_lag' => $likha],
                    ['customers' => $customers, 'likha_lag' => $likha] + ($forbeast !== null ? ['forbeast_lag' => (int) $forbeast] : []));
                return; // ang lipat ang sumasagot sa lahat ng standby
            }
        }
        if ($answer->isNotEmpty()) $this->answerLate($page, $pageId, $answer->sortKeys()->pluck('id')->all());
    }

    /**
     * Ang lipat sa Forbeast na hindi gawa ng bantay (walang event ng page na mas bago sa provider row): isang "moved" event,
     * may sino at kailan, para makita sa history ang mano-manong lipat. Ang lipat na mas luma sa MOVED_HOURS ay hindi.
     */
    private function noticeMove(string $page, PageSetting $moved): void
    {
        if ($moved->plainValue() !== ChatbotProvider::GATEWAY || $moved->created_at === null || $moved->created_at->lt(now()->subHours(self::MOVED_HOURS))) return;
        $newer = $this->settings->pageQuery($page)->where('provider', ChatbotFields::PROVIDER)->where('key', self::KEY)->where('id', '>', $moved->id)->exists();
        if ($newer) return;
        $who = $moved->created_by ? (\App\Models\User::find($moved->created_by)?->name ?? 'user #' . $moved->created_by) : (PageValues::by($moved->source) ?? 'system');
        $this->event($page, self::MOVED, ChatbotProvider::GATEWAY, 'manual',
            'moved to Forbeast by ' . $who . ' at ' . $moved->created_at->copy()->timezone('Asia/Manila')->format('Y-m-d H:i'),
            ['from' => ChatbotProvider::BOTCAKE, 'by' => $who, 'gateway_since' => $moved->created_at->toIso8601String()]);
    }

    /** Hindi lumipat kahit may ebidensya: isang "held" event kada oras kada dahilan ($kind), at ang log tuwing minuto. */
    private function hold(string $page, string $kind, string $error, array $fields): void
    {
        Log::info('forbeast watch: held', ['page' => $page, 'error' => $error] + $fields);
        if (Cache::add('forbeast-watch:held:' . $kind . ':' . sha1($this->settings->pageKey($page)), 1, self::CANNOT_TTL)) {
            $this->event($page, self::HELD, null, 'inbound', $error, $fields);
        }
    }

    /** Naproseso ang isang gateway event na pumasok sa queue noong $queuedAt (unix): ang lag ng Likha. */
    public static function ingested(int $queuedAt): void
    {
        $now = now()->getTimestamp();
        Cache::put(self::INGEST, ['lag' => max(0, $now - $queuedAt), 'at' => $now], self::INGEST_TTL);
    }

    /**
     * Ilang segundo huli ang Likha sa pag-imbak ng gateway events: ang mas malaki sa (a) ang huling naprosesong event (ilang
     * segundo itong naghintay sa queue, sa huling INGEST_TTL) at (b) ang pinakamatagal na naghihintay na job sa queue ng
     * HandleGatewayEvent ngayon (database: ang jobs table; Redis: ang wait ng Horizon). Ang (b) ay para sa worker na tumigil.
     */
    private function likhaLag(): int
    {
        $last = Cache::get(self::INGEST);
        $lag = is_array($last) ? (int) ($last['lag'] ?? 0) : 0;
        $connection = (string) config('queue.chatbot_connection');
        $cfg = (array) config('queue.connections.' . $connection);
        try {
            if (($cfg['driver'] ?? null) === 'database') {
                $oldest = DB::connection($cfg['connection'] ?? null)->table($cfg['table'] ?? 'jobs')->where('queue', RunChatbotFlow::HIGH_QUEUE)
                    ->whereNull('reserved_at')->where('available_at', '<=', now()->getTimestamp())->min('available_at');
                if ($oldest !== null) $lag = max($lag, now()->getTimestamp() - (int) $oldest);
            } elseif (($cfg['driver'] ?? null) === 'redis' && class_exists(\Laravel\Horizon\WaitTimeCalculator::class)) {
                $lag = max($lag, (int) app(\Laravel\Horizon\WaitTimeCalculator::class)->calculateFor($connection . ':' . RunChatbotFlow::HIGH_QUEUE));
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $lag;
    }

    /**
     * Ang standby na walang kapares na dumating pagkalipat sa Forbeast: tumatakbo gaya ng sa lipat (runStandby), sa ilalim
     * ng lock ng lipat, at ang mga chat ay binabasa ulit sa loob nito (ang lipat na nauna ay nakasagot na sa mga ito).
     */
    private function answerLate(string $page, string $pageId, array $ids): void
    {
        $lock = Cache::lock('forbeast-watch:switch:' . sha1($this->settings->pageKey($page)), 60);
        if (!$lock->get()) return; // susubukan ulit sa susunod na minuto

        try {
            if (ChatbotProvider::name($page) !== ChatbotProvider::GATEWAY) return;
            $this->runChats($page, $pageId, BotcakeChat::whereIn('id', $ids)->orderBy('id')->get()->filter(fn ($c) => self::standby($c))->values());
        } finally {
            $lock->release();
        }
    }

    /**
     * Ang anyo ng mensahe sa paghahambing ng dalawang kopya: ang text ay walang pagkakaiba sa laki ng titik at espasyo; ang
     * attachment ay ang uri lang (ang Forbeast ay "[Audio]", ang Botcake ay "[audio](url)" o "[audio] url"). Ang larawan, sticker at Like
     * ay iisa: ang sticker ay dumarating sa Forbeast bilang larawan, at ang Like ay maaaring "[sticker] <url>" sa Botcake.
     */
    public static function kindOf(?string $message): string
    {
        $m = trim((string) $message);
        if ($m === ChatMessageCleaner::LIKE || $m === ChatMessageCleaner::PHOTO) return 'att:image';
        // Iisang pagbasa ng marka sa lahat ng anyo ("[audio](link)", "[audio] link", "[Audio]"): ChatAttachments.
        $a = ChatAttachments::fromMarker($m);
        if ($a !== null) return 'att:' . match ($a['type']) { 'sticker' => 'image', 'ig_reel' => 'reel', default => $a['type'] };

        return 'text:' . mb_strtolower(preg_replace('/\s+/u', ' ', $m));
    }

    /**
     * Ang magkapares na kopya sa mga chat (iisang page): standby ng Botcake at chat ng Forbeast ng parehong customer, may
     * parehong mid, o parehong kindOf sa loob ng TWIN_MINUTES (alinman ang nauna). Bawat chat ay isang kapares lang;
     * ang pinakamalapit sa oras ang kinukuha. @return array<int, int> id => id ng kapares (parehong direksyon)
     */
    private static function pairs(\Illuminate\Support\Collection $chats, bool $answered = false): array
    {
        $out = [];
        // Ang chat ng Forbeast na nakapagbura na ng standby nito (answered) ay hindi na kapares ng iba.
        $forbeast = $chats->filter(fn ($c) => !self::standby($c) && ($c->payload['source'] ?? null) === 'gateway' && empty($c->payload[self::TWIN_USED]));
        foreach ($chats->filter(fn ($c) => self::copy($c, $answered))->sortBy('received_at') as $s) {
            $kind = self::kindOf($s->message);
            $best = $forbeast->filter(fn ($f) => !isset($out[$f->id]) && (string) $f->psid === (string) $s->psid && $f->received_at && $s->received_at
                && (($s->message_id && $f->message_id === $s->message_id)
                    || (abs($f->received_at->getTimestamp() - $s->received_at->getTimestamp()) <= self::TWIN_MINUTES * 60 && self::kindOf($f->message) === $kind)))
                ->sortBy(fn ($f) => [$s->message_id && $f->message_id === $s->message_id ? 0 : 1, abs($f->received_at->getTimestamp() - $s->received_at->getTimestamp())])
                ->first();
            if ($best !== null) {
                $out[$s->id] = $best->id;
                $out[$best->id] = $s->id;
            }
        }

        return $out;
    }

    /**
     * Ang lipat sa Botcake: gaya ng select sa /chatbot/pages (PageSettings::set ng chatbot.provider, may history), saka ang
     * event, saka ang mga standby chat. Walang Botcake token: hindi lumilipat, isang "cannot switch" event kada oras.
     */
    public function switch(string $page, string $condition, string $error, array $evidence = [], array $fields = []): void
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
            DB::transaction(function () use ($page, $condition, $error, $fields) {
                $this->settings->set($page, ChatbotFields::PROVIDER, 'provider', ChatbotProvider::BOTCAKE);
                $this->event($page, self::SWITCHED, ChatbotProvider::BOTCAKE, $condition, $error, $fields);
            });
            // Ang ebidensya ng lipat (bilang at ID lang; ang error ay HTTP at Meta code lang, walang text ng mensahe).
            Log::warning('forbeast watch: switched', ['page' => $page, 'condition' => $condition, 'error' => $error] + $evidence);
            // Ang maayos na health bago ang lipat ay hindi binibilang sa balik: nagsisimula ang bilang pagkatapos nito.
            $this->reset($page, 'fails', 'no_answer', 'health', 'healthy');
            Cache::forget('forbeast-watch:cannot:' . $hash);
            $this->runStandby($page);
        } finally {
            $lock->release();
        }
    }

    /**
     * Ang balik sa Forbeast ng page na inilipat ng bantay (mayGoBack): ang health check ng page, at kapag back_minutes na
     * itong sunod-sunod na maayos (konektado, makapagpadala, inbound_lag_seconds < lag_seconds o wala), ibinabalik gaya ng
     * lipat (PageSettings::set ng provider, may event "switched_back" at ang ebidensya). Sa scheduled command lang.
     */
    public function switchBack(string $page): void
    {
        // Ang health ay sinusukat kahit nasa puwang pa ng huling balik (back_gap_minutes): ang puwang ay sa mismong balik lang.
        if (!$this->mayGoBack($page, false)) return;
        $this->health($page);
        $streak = Cache::get($this->counter($page, 'healthy'));
        if (!is_array($streak) || (int) $streak['since'] > now()->getTimestamp() - self::limit('back_minutes') * 60) return;

        $lock = Cache::lock('forbeast-watch:switch:' . sha1($this->settings->pageKey($page)), 60);
        if (!$lock->get()) return;
        try {
            if (!$this->mayGoBack($page)) return;
            $healthy = intdiv(now()->getTimestamp() - (int) $streak['since'], 60);
            $lag = Cache::get($this->counter($page, 'lag'));
            DB::transaction(function () use ($page, $healthy, $lag) {
                $this->settings->set($page, ChatbotFields::PROVIDER, 'provider', ChatbotProvider::GATEWAY);
                $this->event($page, self::SWITCHED_BACK, ChatbotProvider::GATEWAY, 'healthy', 'back on Forbeast after ' . $healthy . ' min healthy',
                    ['from' => ChatbotProvider::BOTCAKE, 'healthy_minutes' => $healthy] + ($lag !== null ? ['forbeast_lag' => (int) $lag] : []));
            });
            Log::warning('forbeast watch: switched back', ['page' => $page, 'healthy_minutes' => $healthy, 'forbeast_lag' => $lag]);
            $this->reset($page, 'fails', 'no_answer', 'health', 'healthy');
        } finally {
            $lock->release();
        }
    }

    /** Ang event ay lipat ng bantay na kusang ibinabalik (inbound, padala o health, papuntang Botcake). */
    public static function returnable(PageSetting $event): bool
    {
        $e = json_decode((string) $event->plainValue(), true);

        return is_array($e) && ($e['result'] ?? null) === self::SWITCHED && ($e['to'] ?? null) === ChatbotProvider::BOTCAKE
            && in_array($e['condition'] ?? null, self::BACK_CONDITIONS, true);
    }

    /** Ang event ay alarm o recovered ng send lag (ForbeastSendHealth), hindi lipat. */
    public static function alarm(PageSetting $event): bool
    {
        $result = json_decode((string) $event->plainValue(), true)['result'] ?? null;

        return in_array($result, [ForbeastSendHealth::ALARM, ForbeastSendHealth::RECOVERED], true);
    }

    /**
     * Maaaring ibalik: nasa Botcake, handa ang Forbeast token ng page, ang huling event ay lipat na kusang ibinabalik at walang
     * nagpalit ng provider pagkatapos nito (laging panalo ang tao), at ($gap) walang balik sa huling back_gap_minutes.
     */
    private function mayGoBack(string $page, bool $gap = true): bool
    {
        if (ChatbotProvider::name($page) !== ChatbotProvider::BOTCAKE || !app(ForbeastPageSetup::class)->ready($page)) return false;
        $rows = fn (string $key) => $this->settings->pageQuery($page)->where('provider', ChatbotFields::PROVIDER)->where('key', $key);
        // Ang send lag alarm (ForbeastSendHealth) ay sumusulat din dito: hindi ito lipat, kaya nilalaktawan.
        $event = $rows(self::KEY)->orderByDesc('id')->limit(20)->get()->first(fn ($s) => !self::alarm($s));
        $provider = $rows('provider')->where('active', true)->orderByDesc('id')->first(['id']);
        if ($event === null || !self::returnable($event) || ($provider !== null && $provider->id > $event->id)) return false;

        return !$gap || !$rows(self::KEY)->where('created_at', '>=', now()->subMinutes(self::limit('back_gap_minutes')))->get()
            ->contains(fn ($s) => (json_decode((string) $s->plainValue(), true)['result'] ?? null) === self::SWITCHED_BACK);
    }

    /** Ang sunod-sunod na maayos na health ng page: since = ang unang maayos; nagsisimula ulit kapag pumalya o may puwang. */
    private function streak(string $page, bool $ok): void
    {
        $key = $this->counter($page, 'healthy');
        if (!$ok) {
            Cache::forget($key);
            return;
        }
        $now = now()->getTimestamp();
        $s = Cache::get($key);
        $since = is_array($s) && (int) ($s['last'] ?? 0) >= $now - self::STREAK_GAP ? (int) $s['since'] : $now;
        Cache::put($key, ['since' => $since, 'last' => $now], self::COUNT_TTL);
    }

    /**
     * Ang note sa /chatbot/pages: ang huling event ay mas bago sa provider row (walang lumipat nang mano-mano pagkatapos).
     * Lipat: habang nasa Botcake ang page. Held at balik: habang nasa Forbeast, sa huling NOTE_HOURS.
     * @return array{at: string, reason: string, label: string}|null
     */
    public static function note(?PageSetting $event, ?PageSetting $provider, string $current = ChatbotProvider::BOTCAKE): ?array
    {
        if ($event === null || ($provider !== null && $provider->id > $event->id)) return null;
        $e = json_decode((string) $event->plainValue(), true);
        if (!is_array($e)) return null;
        $recent = $current === ChatbotProvider::GATEWAY && $event->created_at?->gte(now()->subHours(self::NOTE_HOURS));
        $label = match ($e['result'] ?? null) {
            self::SWITCHED => $current === ChatbotProvider::BOTCAKE ? 'Auto-switched' : null,
            self::HELD => $recent ? 'Held on Forbeast' : null,
            self::SWITCHED_BACK => $recent ? 'Back on Forbeast' : null,
            default => null,
        };
        if ($label === null) return null;

        return ['at' => (string) $event->created_at?->timezone('Asia/Manila')->format('Y-m-d H:i'), 'reason' => self::reason($e), 'label' => $label];
    }

    /** Ang dahilan bilang text: ang kondisyon at ang huling error; ang held at ang balik ay ang error lang (buo na ang dahilan). */
    public static function reason(array $e): string
    {
        if (in_array($e['result'] ?? null, [self::HELD, self::SWITCHED_BACK, self::MOVED], true)) return (string) ($e['error'] ?? '');
        $label = self::REASONS[$e['condition'] ?? ''] ?? (string) ($e['condition'] ?? '');

        return $label . (($e['error'] ?? '') !== '' ? ': ' . $e['error'] : '');
    }

    /** Ang kopya ng Botcake: standby, at (kung $answered) ang standby na nasagot na (FROM_STANDBY). */
    private static function copy(BotcakeChat $chat, bool $answered): bool
    {
        return self::standby($chat) || ($answered && !empty($chat->payload[self::FROM_STANDBY]));
    }

    /** Ang chat ng Botcake na iniimbak lang habang nasa Forbeast ang page (walang takbo). */
    public static function standby(BotcakeChat $chat): bool
    {
        return !empty($chat->payload['_standby']);
    }

    /**
     * Ang kopya ng parehong mensahe sa kabila: ang chat ng Forbeast ng isang standby, o ang standby ng isang chat ng
     * Forbeast, ayon sa pairs() (parehong mid, o parehong customer at kindOf sa loob ng TWIN_MINUTES, isa-sa-isa).
     */
    private static function twin(BotcakeChat $chat, bool $wantStandby): ?BotcakeChat
    {
        $at = $chat->received_at ?? now();
        // Ang mga chat ng customer sa paligid (doble ng TWIN_MINUTES), para tama ang isa-sa-isang pagpares sa magkakasunod na kopya.
        $group = BotcakeChat::where('page_id', $chat->page_id)->where('psid', $chat->psid)
            ->whereBetween('received_at', [$at->copy()->subMinutes(2 * self::TWIN_MINUTES), $at->copy()->addMinutes(2 * self::TWIN_MINUTES)])
            ->orderBy('id')->get();
        if (!$group->contains('id', $chat->id)) $group->push($chat);
        $id = self::pairs($group)[$chat->id] ?? null;
        $twin = $id !== null ? $group->firstWhere('id', $id) : null;

        return $twin !== null && self::standby($twin) === $wantStandby ? $twin : null;
    }

    /**
     * Naipadala ang sagot ng Forbeast sa chat na ito: ang standby na kopya nito (kung mayroon) ay hindi na kailangan. Habang
     * walang naipadalang sagot, naiiwan ang standby para masagot ito kapag lumipat sa Botcake.
     */
    public function answered(int $chatId): void
    {
        $chat = BotcakeChat::find($chatId);
        if ($chat === null || self::standby($chat) || ($chat->payload['source'] ?? null) !== 'gateway' || !empty($chat->payload[self::TWIN_USED])) return;
        $twin = self::twin($chat, true);
        if ($twin === null) return;
        // Isang standby lang kada chat ng Forbeast: may tanda ang chat, para ang susunod na send ng parehong flow ay hindi
        // magbura ng standby ng ibang mensahe (hal. ang pangalawang voice note na hindi natanggap ng Forbeast).
        DB::transaction(function () use ($chat, $twin) {
            $chat->update(['payload' => (array) $chat->payload + [self::TWIN_USED => true]]);
            $twin->delete();
        });
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
     * Ang mga standby chat ng huling ilang minuto, luma muna. Tumatakbo lang kapag walang naipadalang sagot ng bot sa
     * customer na iyon sa page mula sa pagdating nito (bawas REPLIED_GRACE segundo): kung mayroon, dobleng sagot. Ang
     * kopya sa Forbeast na pumalya ang sagot ay sinasagot dito.
     * Ang nilaktawan ay nasa log (id at dahilan lang). Ang tumatakbo: bagong chat (para sa takbo ayon sa id), at ang
     * standby ay tinatanggal, kaya isang beses lang.
     */
    private function runStandby(string $page): void
    {
        $pageId = trim((string) $this->settings->get($page, 'botcake', 'page_id'));
        if ($pageId === '') return;
        $chats = BotcakeChat::where('page_id', $pageId)->where('received_at', '>=', now()->subMinutes(self::limit('standby_minutes')))
            ->orderBy('id')->get()->filter(fn ($c) => self::standby($c))->values();
        $this->runChats($page, $pageId, $chats);
    }

    /** Ang pagpapatakbo ng mga standby chat (luma muna), ayon sa mga patakaran ng runStandby. */
    private function runChats(string $page, string $pageId, \Illuminate\Support\Collection $chats): void
    {
        foreach ($chats as $c) {
            try {
                // Ang kopya sa Forbeast lang ay hindi dahilan: kung pumalya ang sagot doon, ang Botcake ang sasagot. Pero kapag
                // tumatakbo pa ang takbo ng kopyang iyon (hindi pa naaabot ng runner, o may AI o padalang hinihintay), iyon
                // ang sasagot (sa Botcake na, dahil ang daan ay pinipili sa padala): dobleng sagot kapag pinatakbo pa ito.
                $skip = ChatbotReply::where('page_id', (int) $pageId)->where('psid', (int) $c->psid)->where('kind', 'send')->where('status', 'sent')
                    ->where('sent_at', '>=', ($c->received_at ?? now())->copy()->subSeconds(self::REPLIED_GRACE))->exists() ? 'already_answered' : null;
                if ($skip === null && ($twin = self::twin($c, false)) !== null && ctype_digit((string) $c->psid)) {
                    $fields = new CustomerFields((int) $pageId, (int) $c->psid);
                    if ($twin->id > (int) $fields->get(CustomerFields::LAST_CHAT) || ChatbotAiQueue::pending($fields)) $skip = 'forbeast_pending';
                }
                if ($skip !== null) {
                    Log::info('forbeast watch: standby skipped', ['page' => $page, 'chat_id' => $c->id, 'reason' => $skip]);
                    continue;
                }
                $chat = DB::transaction(function () use ($c) {
                    $new = $c->replicate();
                    $payload = (array) $c->payload;
                    unset($payload['_standby']);
                    $payload[self::FROM_STANDBY] = true;
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
        $this->event($page, $result, $to, $condition, $error);
    }

    /** Isang event row sa chatbot.auto_switch ng page (ang feed ng owner); $extra: dagdag na field (hal. pages ng send lag alarm) o ibang "from". */
    public function event(string $page, string $result, ?string $to, string $condition, string $error, array $extra = []): void
    {
        $this->settings->set($page, ChatbotFields::PROVIDER, self::KEY, json_encode(array_replace([
            'result' => $result, 'from' => ChatbotProvider::GATEWAY, 'to' => $to, 'condition' => $condition,
            'error' => mb_substr(trim($error), 0, 200), 'at' => now()->toIso8601String(),
        ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), false, null, true);
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
