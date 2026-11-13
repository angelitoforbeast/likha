<?php

namespace App\Services\Chatbot;

use App\Jobs\GatewayAfterSend;
use App\Services\BotcakeClient;
use App\Services\BotcakeMock;
use App\Services\PageSettings;
use App\Support\BotcakeClock;
use Illuminate\Support\Facades\Cache;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Ang pool ng padala ng mga sequence step (chatbot:sequence-pool): sariling proseso, hiwalay sa botcake:send-pool, kaya ang
 * mga unang sagot ay hindi naaapektuhan at ang patay na pool ng isa ay hindi humihinto sa isa. Hanggang
 * services.botcake.sequence_concurrency na ticket nang sabay. Ang daan ng page ay pinipili kapag kinuha ang ticket:
 * - Botcake: lane kada token, isang request kada 1 / rate_per_page (kahati ng lahat ng padala sa page, BotcakeClient::slot),
 *   ang parehong send_content, timeout at ulit (429 lang, humihinto ang page) gaya ng sagot ng bot;
 * - gateway: lane kada page, walang rate at walang ulit sa Likha (gaya ng GatewayProvider), hanggang
 *   services.chatbot.sequence_gateway_per_page nang sabay kada page.
 * Bago ang tawag: ang pagsusuri ng step (ChatbotSequences::sendCheck), ang daan na hindi nagbago, at walang sagot ng bot sa
 * customer na hindi pa tapos. Itinatala ang tawag bago lumabas (hindi inuulit pagkatapos ng crash).
 */
class SequenceSendPool extends TicketPool
{
    public const BEAT = 'sequence-send-pool:beat';
    public const CLAIM_EVERY = 0.2;     // segundo sa pagitan ng mga claim (hindi binabasa ang table kada 5 ms)
    public const MAX_RETRY_AFTER = 10;  // gaya ng botcake:send-pool
    public const LATE = 0.02;

    private BotcakeClock $clock;
    /** @var array<string, array{row: object, provider: string, req: array, attempt: int, lane: string, secret: string, gateway: ?string, ref: string, notBefore: float}> */
    private array $jobs = [];
    /** @var array<string, array{next: float, paused: float, booked: ?float, queue: array<int, string>, botcake: bool}> */
    private array $lanes = [];
    /** @var array<string, array{0: string, 1: float}> */
    private array $tokens = [];
    private float $claimedAt = -INF;
    /** @var array<string, array<int, float>> lane => oras ng bawat request (para sa test at sa sukat) */
    public array $sentAt = [];

    public function __construct(mixed $handler = null, ?BotcakeClock $clock = null)
    {
        parent::__construct($handler);
        $this->clock = $clock ?? app(BotcakeClock::class);
        $this->progressAt = now()->getTimestamp();
    }

    public function max(): int
    {
        return max(1, (int) config('services.botcake.sequence_concurrency', 100));
    }

    public static function beatKey(): string
    {
        return self::BEAT . ':' . ChatbotSequences::site();
    }

    public static function stalledKey(): string
    {
        return self::STALLED . ':' . ChatbotSequences::site();
    }

    /** Buhay ang pool at ito ang bersyong nagpapadala ng sequence ticket (ang tanda sa beat). */
    public static function capable(): bool
    {
        $beat = Cache::get(self::beatKey());

        return self::alive() && (int) ($beat['sequence'] ?? 0) === 1 && empty($beat['stopped']) && !self::stalled();
    }

    /** Ang huling galaw (may nakuha, may natapos, o walang naghihintay): sa beat, para makita ng tick ang pool na buhay pero hindi umuusad. */
    private int $progressAt = 0;
    public const STALL_SECONDS = 300;
    public const IDLE_CHECK_SECONDS = 60;
    public const STALLED = 'sequence-send-pool:stalled';

    protected function beatInfo(): array
    {
        return ['sequence' => 1, 'progress_at' => $this->progressAt];
    }

    /** Buhay ang beat pero walang galaw nang STALL_SECONDS habang may naghihintay. */
    public static function stalled(): bool
    {
        $beat = Cache::get(self::beatKey());

        if (!self::alive() || !empty($beat['stopped']) || !isset($beat['progress_at'])) return false;
        // Nakita nang hindi umuusad (ng tick): hindi pa rin, hangga't walang bagong galaw, kahit naubos na ang naghihintay.
        if (Cache::get(self::stalledKey()) === ($beat['owner'] ?? '') . ':' . $beat['progress_at']) return true;
        // Ang beat muna (walang query sa karaniwang takbo); saka kung may naghihintay talaga.
        if ((int) $beat['progress_at'] >= now()->getTimestamp() - self::STALL_SECONDS || !BotcakeSendQueue::sequencePending()) return false;
        Cache::put(self::stalledKey(), ($beat['owner'] ?? '') . ':' . $beat['progress_at'], 3600);

        return true;
    }

    /**
     * Malinis na paghinto (max-time o signal): ang beat ay naiiwan nang may "stopped", kaya walang bagong hand-off (capable),
     * pero hindi itinuturing na patay ang pool sa ilang segundo bago ang bago (walang kinakansela o binabawi).
     */
    public function stop(): void
    {
        if ((Cache::get(self::beatKey())['owner'] ?? null) === $this->owner) Cache::put(self::beatKey(), ['owner' => $this->owner, 'at' => now()->getTimestamp(), 'stopped' => true], 60);
    }

    protected function nowMs(): int
    {
        return (int) round($this->clock->now() * 1000);
    }

    protected function recover(): void
    {
        BotcakeSendQueue::recoverSequence($this->owner);
    }

    protected function sweep(): void
    {
        BotcakeSendQueue::sweepSequence();
    }

    public function tick(bool $claim = true): int
    {
        $started = 0;
        $now = $this->clock->now();
        if ($claim && count($this->jobs) < $this->max() && $now - $this->claimedAt >= self::CLAIM_EVERY - 0.000001) {
            $this->claimedAt = $now;
            $started = count(BotcakeSendQueue::claim($this->owner, $this->max() - count($this->jobs), fn (object $c) => $this->room($c), fn (object $row) => $this->take($row),
                $this->fullPages(), [], false, true));
            // Galaw: may nakuha, o walang hawak at walang naghihintay.
            // Walang hawak: ang "may naghihintay ba" ay binabasa lang kapag lampas 60 s na ang huling galaw (hindi kada 200 ms).
            if ($started > 0) $this->progressAt = now()->getTimestamp();
            elseif (!$this->jobs && now()->getTimestamp() - $this->progressAt >= self::IDLE_CHECK_SECONDS && !BotcakeSendQueue::sequencePending()) $this->progressAt = now()->getTimestamp();
        }
        $this->lanes();
        $this->pump();
        $this->lanes();

        return $started;
    }

    /** Ilang ticket ang hawak kada lane: sa Botcake, ang kayang ipadala sa mga 3 segundo sa rate ng page; sa gateway, ang setting. */
    public function cap(bool $botcake): int
    {
        return $botcake ? max(2, (int) ceil(3 / BotcakeClient::interval())) : max(1, (int) config('services.chatbot.sequence_gateway_per_page', 30));
    }

    /** Ang lane ng page: [key, botcake?]; ang key ng Botcake ay ang sha1 ng token (kahati ng lahat ng padala sa page). */
    private function laneOf(string $page): array
    {
        if (ChatbotProvider::name($page) === ChatbotProvider::GATEWAY) return ['gw:' . sha1($page), false];
        $token = $this->token($page);

        return [$token === '' ? '' : sha1($token), true];
    }

    private function room(object $c): bool
    {
        if (isset($this->jobs[(string) $c->request_id])) return false;
        [$lane, $botcake] = $this->laneOf((string) $c->page_name);
        $held = 0;
        foreach ($this->jobs as $job) if ($job['lane'] === $lane) $held++;

        return count($this->jobs) < $this->max() && $held < $this->cap($botcake);
    }

    /** @return array<int, string> ang mga page na puno na ang lane */
    private function fullPages(): array
    {
        $held = [];
        $pages = [];
        foreach ($this->jobs as $job) {
            $held[$job['lane']] = ($held[$job['lane']] ?? 0) + 1;
            $pages[$job['lane']][(string) $job['row']->page_name] = $job['provider'] === ChatbotProvider::BOTCAKE;
        }
        $full = [];
        foreach ($held as $lane => $n) {
            $botcake = (bool) reset($pages[$lane]);
            if ($n >= $this->cap($botcake)) $full = array_merge($full, array_keys($pages[$lane]));
        }

        return $full;
    }

    /** Ang token ng page (page settings), nasa memory nang 10 s. */
    private function token(string $page): string
    {
        $t = $this->tokens[$page] ?? null;
        if ($t === null || $this->clock->now() - $t[1] > 10) {
            $t = $this->tokens[$page] = [trim((string) app(PageSettings::class)->get($page, 'botcake', 'token')), $this->clock->now()];
        }

        return $t[0];
    }

    private function take(object $row): void
    {
        $id = (string) $row->request_id;
        $this->inflight[$id] = true;
        try {
            $page = (string) $row->page_name;
            $ref = ChatbotProvider::ref();
            // Ang page ID ng ticket ay ang page ID ng page ngayon: kapag nagbago, walang padala (ang step ay ibinabalik).
            if (trim((string) app(PageSettings::class)->get($page, 'botcake', 'page_id')) !== (string) $row->page_id) {
                $this->forget($id);
                BotcakeSendQueue::finishSequence($row, $this->owner, ['status' => 'skipped', 'error' => 'The page ID changed before the send went out', 'http' => null, 'check' => 'page_changed']);
                return;
            }
            $messages = (array) (json_decode((string) $row->payload, true)['messages'] ?? []);
            if (ChatbotProvider::name($page) === ChatbotProvider::GATEWAY) {
                $gp = new GatewayProvider();
                $q = $gp->request($page, (string) $row->page_id, (string) $row->psid, $messages, $ref, MessagingProvider::UPDATE);
                if ($q === null) {
                    $this->forget($id);
                    BotcakeSendQueue::finishSequence($row, $this->owner, ['status' => 'failed', 'error' => GatewayProvider::notSetUp((string) $gp->gateway()), 'http' => null,
                        'via' => (string) $gp->gateway(), 'ref' => $ref]);
                    return;
                }
                $this->queue($id, ['row' => $row, 'provider' => ChatbotProvider::GATEWAY, 'req' => $q, 'attempt' => 0, 'lane' => 'gw:' . sha1($page), 'secret' => $q['key'],
                    'gateway' => $q['gateway'], 'ref' => $ref, 'notBefore' => 0.0], false);
                return;
            }
            $token = $this->token($page);
            if ($token === '') {
                $this->forget($id);
                BotcakeSendQueue::finishSequence($row, $this->owner, ['status' => 'failed', 'error' => BotcakeOutbox::NO_TOKEN, 'http' => null, 'via' => ChatbotProvider::BOTCAKE, 'ref' => $ref]);
                return;
            }
            // Images on a page whose SEND IMAGE check is OK go by ImageFlow's steps (as in botcake:send-pool); else one send_content.
            $gen = ($cfg = ImageFlow::forSend($page, $messages)) !== null ? ImageFlow::steps((string) $row->page_id, (string) $row->psid, $messages, $cfg, $token) : null;
            $req = $gen?->current() ?? ['method' => 'POST', 'path' => '/pages/' . rawurlencode((string) $row->page_id) . '/flows/send_content',
                'data' => ['psid' => (string) $row->psid, 'data' => ['version' => 'v2', 'content' => ['messages' => BotcakeOutbox::wire($messages)]]]];
            $this->queue($id, ['row' => $row, 'provider' => ChatbotProvider::BOTCAKE, 'req' => $req, 'gen' => $gen, 'attempt' => 0, 'lane' => sha1($token), 'secret' => $token,
                'gateway' => null, 'ref' => $ref, 'notBefore' => 0.0], true);
        } catch (Throwable $e) {
            $this->failed($row, $e);
        }
    }

    private function queue(string $id, array $job, bool $botcake): void
    {
        $this->jobs[$id] = $job;
        $this->lanes[$job['lane']] ??= ['next' => 0.0, 'paused' => 0.0, 'booked' => null, 'queue' => [], 'botcake' => $botcake];
        $this->lanes[$job['lane']]['queue'][] = $id;
    }

    /**
     * Bawat lane na puwede na. Botcake: ang unang job na handa, isang request, saka ang susunod ay pagkalipas ng interval (ang
     * puwesto ay kinukuha nang atomic at nakareserba ang susunod na libre, gaya ng botcake:send-pool). Gateway: lahat ng handa.
     */
    private function lanes(): void
    {
        $interval = BotcakeClient::interval();
        foreach ($this->lanes as $key => $lane) {
            $now = $this->clock->now();
            if (!$lane['queue'] || $now < $lane['paused']) continue;
            if (!$lane['botcake']) {
                foreach ($lane['queue'] as $id) {
                    if (($this->jobs[$id]['notBefore'] ?? 0.0) > $now) continue;
                    $this->lanes[$key]['queue'] = array_values(array_diff($this->lanes[$key]['queue'], [$id]));
                    $this->issue($id);
                }
                continue;
            }
            if ($now < $lane['next'] - 0.000001) continue;
            $pick = null;
            foreach ($lane['queue'] as $i => $id) {
                if ($this->jobs[$id]['notBefore'] <= $now) {
                    $pick = $i;
                    break;
                }
            }
            if ($pick === null) continue;
            $free = $lane['booked'];
            if ($free !== null && $now > $free + self::LATE) $free = null;
            if ($free === null) {
                [, $free] = BotcakeClient::slot($key, $now, true);
                if ($free > $now + 0.000001) {
                    $this->lanes[$key]['booked'] = $free;
                    $this->lanes[$key]['next'] = $free;
                    continue;
                }
            }
            $this->lanes[$key]['booked'] = null;
            $id = $lane['queue'][$pick];
            array_splice($this->lanes[$key]['queue'], $pick, 1);
            $at = $this->issue($id);
            if ($at === null) continue;
            $this->lanes[$key]['next'] = $at + $interval;
            if ($at > $free + 0.000001) BotcakeClient::sentAt($key, $at);
            $this->sentAt[$key][] = $at;
            if (count($this->sentAt[$key]) > 1000) array_shift($this->sentAt[$key]);
        }
    }

    /** @return float|null ang oras na lumabas ang tawag, o null kapag walang tawag */
    private function issue(string $id): ?float
    {
        $job = $this->jobs[$id] ?? null;
        if ($job === null) return null;
        $row = $job['row'];
        // Ang ulit (429): hawak pa ba? Ang unang subok ay sinusuri ng kondisyonal na calledSequence mismo.
        if ($job['attempt'] > 0 && !BotcakeSendQueue::owns($row, $this->owner)) {
            $this->forget($id);
            return null;
        }
        // Ang ulit pagkatapos ng 429: ang ibang padala sa customer ay maaaring sinagot ng #551 habang naghihintay ito.
        if ($job['attempt'] > 0 && ($why = CustomerReach::skipReason($row->page_id, $row->psid)) !== null) {
            $this->forget($id);
            BotcakeSendQueue::finishSequence($row, $this->owner, ['status' => 'skipped', 'error' => $why, 'http' => null, 'via' => $job['provider'] === ChatbotProvider::GATEWAY
                ? (string) $job['gateway'] : ChatbotProvider::BOTCAKE, 'ref' => $job['ref']] + ($job['provider'] === ChatbotProvider::GATEWAY && isset($job['req']['route']) ? ['route' => $job['req']['route']] : []));
            return null;
        }
        try {
            // ImageFlow's field write takes the page+PSID lock first (held until its send_flow answered): a bot reply cannot
            // overwrite image_url in between. Not free: back in the lane a moment later, nothing called.
            if (($job['req']['step'] ?? '') === 'image_write' && !isset($job['lock'])) {
                $lock = Cache::lock(ImageFlow::lockKey($row->page_id, $row->psid), ImageFlow::LOCK_SECONDS);
                if (!$lock->get()) {
                    $this->jobs[$id]['notBefore'] = $this->clock->now() + 0.5;
                    array_unshift($this->lanes[$job['lane']]['queue'], $id);
                    return null;
                }
                $this->jobs[$id]['lock'] = $lock->owner();
            }
            if ($job['attempt'] === 0 && empty($job['steps'])) {
                $page = (string) $row->page_name;
                // Lumipat ang daan ng page habang naghihintay: balik sa pila, kukunin ulit sa bagong daan (isang beses lang).
                if (ChatbotProvider::name($page) !== $job['provider']) {
                    $this->forget($id);
                    BotcakeSendQueue::later($row, $this->owner, 0);
                    return null;
                }
                // Ang sagot ng bot sa customer ang nauuna: ang sequence ay naghihintay, hindi kailanman ang sagot.
                if (BotcakeSendQueue::replyOpen($row->page_id, $row->psid)) {
                    $this->forget($id);
                    BotcakeSendQueue::later($row, $this->owner, 1);
                    return null;
                }
                $via = $job['provider'] === ChatbotProvider::GATEWAY ? (string) $job['gateway'] : ChatbotProvider::BOTCAKE;
                $route = $job['provider'] === ChatbotProvider::GATEWAY ? ($job['req']['route'] ?? null) : null;
                if (($check = app(ChatbotSequences::class)->sendCheck($row)) !== null) {
                    $this->forget($id);
                    // Naka-off ang mga sagot, o hindi maabot ang customer: itinatala bilang skipped gaya ng padalang naghintay, at tuloy ang step.
                    $o = in_array($check['code'], ['switched_off', 'unreachable'], true) ? ['status' => 'skipped', 'error' => $check['reason'], 'http' => null]
                        : ['status' => 'skipped', 'error' => $check['reason'], 'http' => null, 'check' => $check['code']];
                    BotcakeSendQueue::finishSequence($row, $this->owner, $o + ['via' => $via, 'ref' => $job['ref']] + ($route !== null ? ['route' => $route] : []));
                    return null;
                }
                // Itinatala bago tumawag: hindi na inuulit pagkatapos ng crash.
                if (!BotcakeSendQueue::calledSequence($row, $this->owner, $via, $job['ref'], $route)) {
                    $this->forget($id);
                    // Hawak pa ng pool pero wala nang may hawak na subscription (hal. sumagot ang customer): walang padala.
                    if (BotcakeSendQueue::owns($row, $this->owner)) {
                        BotcakeSendQueue::finishSequence($row, $this->owner, ['status' => 'skipped', 'error' => 'The customer left the sequence before the send went out', 'http' => null,
                            'check' => 'not_holder', 'via' => $via, 'ref' => $job['ref']]);
                    }
                    return null;
                }
            }
            $at = $this->clock->now();
            $q = $job['req'];
            if ($job['provider'] === ChatbotProvider::BOTCAKE) {
                $mock = BotcakeClient::mockAnswer($q['method'], $q['path'], $q['data']);
                if ($mock !== null) {
                    $ms = (int) (BotcakeMock::pages()[(string) $row->page_id]['latency'] ?? 0);
                    $this->after($id, $ms, fn () => $this->respond($id, $mock[0], $mock[1], null));
                    return $at;
                }
                $promise = $this->http->requestAsync('POST', BotcakeClient::BASE . $q['path'], ['headers' => ['access-token' => $job['secret'], 'Accept' => 'application/json'],
                    'json' => $q['data'], 'timeout' => BotcakeProvider::timeout(), 'connect_timeout' => 10]);
            } else {
                $promise = $this->http->requestAsync('POST', $q['url'], ['headers' => ['Authorization' => 'Bearer ' . $job['secret'], 'Accept' => 'application/json'],
                    'json' => $q['body'], 'timeout' => $q['timeout'], 'connect_timeout' => min(5, $q['timeout'])] + Gateways::httpOptions());
            }
            $promise->then(
                function (ResponseInterface $res) use ($id) {
                    $text = (string) $res->getBody();
                    $this->respond($id, $res->getStatusCode(), json_decode($text, true) ?? $text, $res->getHeaderLine('Retry-After'));
                },
                fn ($e) => $this->respond($id, 0, null, null, $e instanceof \Throwable ? $e : null),
            );

            return $at;
        } catch (Throwable $e) {
            $this->failed($row, $e);
            return null;
        }
    }

    /** Ang sagot: ulit (Botcake 429 lang), o ang resulta at ang resume. */
    private function respond(string $id, int $status, mixed $body, ?string $retryAfter, ?Throwable $error = null): void
    {
        $job = $this->jobs[$id] ?? null;
        if ($job === null) return;
        $row = $job['row'];
        try {
            if ($job['provider'] === ChatbotProvider::BOTCAKE) {
                $wait = BotcakeClient::retryWait($job['attempt'], $status, $retryAfter, true, BotcakeClient::MAX_RETRIES, self::MAX_RETRY_AFTER);
                if ($wait !== null) {
                    $this->jobs[$id]['attempt']++;
                    $until = $this->clock->now() + $wait;
                    // 429: ang page lang na ito ang humihinto.
                    $this->lanes[$job['lane']]['paused'] = max($this->lanes[$job['lane']]['paused'], $until);
                    array_unshift($this->lanes[$job['lane']]['queue'], $id);
                    return;
                }
                $r = ['http' => $status, 'body' => $body, 'attempts' => $job['attempt'] + 1];
                if (($job['gen'] ?? null) !== null) {
                    // ImageFlow steps: the lock goes once the image's send_flow answered (or its write failed).
                    if (isset($this->jobs[$id]['lock']) && !ImageFlow::holds($job['req'], $r)) $this->unlock($id);
                    $job['gen']->send($r);
                    if ($job['gen']->valid()) {
                        $this->jobs[$id]['req'] = $job['gen']->current();
                        $this->jobs[$id]['attempt'] = 0;
                        $this->jobs[$id]['notBefore'] = 0.0;
                        $this->jobs[$id]['steps'] = ($job['steps'] ?? 0) + 1;
                        array_unshift($this->lanes[$job['lane']]['queue'], $id);
                        return;
                    }
                }
                $o = ($job['gen'] ?? null) !== null ? ImageFlow::after((string) $row->page_name, $job['gen']->getReturn()) + ['via' => ChatbotProvider::BOTCAKE]
                    : BotcakeOutbox::outcome($r, $job['secret']) + ['via' => ChatbotProvider::BOTCAKE];
            } else {
                $r = ['http' => $status, 'body' => $body];
                // Walang sagot: ang cURL error at mga oras, sa failure at sa log (gaya ng GatewayProvider::send).
                if ($status === 0 && $error !== null) {
                    $r['no_answer'] = GatewayProvider::noAnswer($error);
                    GatewayProvider::logNoAnswer((string) $row->page_name, $job['req'], $r['no_answer']);
                }
                $o = GatewayProvider::outcome($r, $job['secret'], $job['req']['route'] ?? null) + ['via' => (string) $job['gateway']];
            }
            $this->forget($id);
            BotcakeSendQueue::finishSequence($row, $this->owner, $o + ['ref' => $job['ref']] + ($job['provider'] === ChatbotProvider::GATEWAY && isset($job['req']['route']) ? ['route' => $job['req']['route']] : []));
            $this->progressAt = now()->getTimestamp();
            // Pagkatapos maitala ang resulta: ang pumalyang dispatch ay hindi nagiging failed na padala.
            if ($job['provider'] === ChatbotProvider::GATEWAY) {
                try {
                    GatewayAfterSend::dispatch((string) $row->page_name, array_diff_key($o, ['via' => 1]), $body, (string) $job['gateway']);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        } catch (Throwable $e) {
            $this->failed($row, $e);
        }
    }

    /** Pumalya ang pool sa ticket (hal. database): failed, gaya ng error ng padalang naghintay. */
    private function failed(object $row, Throwable $e): void
    {
        report($e);
        $this->forget((string) $row->request_id);
        try {
            BotcakeSendQueue::finishSequence($row, $this->owner, ['status' => 'failed', 'error' => 'Error: ' . mb_substr($e->getMessage(), 0, 150), 'http' => null]);
        } catch (Throwable $again) {
            report($again); // sending pa: ang susunod na pool o ang tick ang magtatapos
        }
    }

    private function forget(string $id): void
    {
        $this->unlock($id);
        unset($this->jobs[$id], $this->inflight[$id]);
    }

    /** Let go of the ImageFlow page+PSID lock the job holds, if any. */
    private function unlock(string $id): void
    {
        $job = $this->jobs[$id] ?? null;
        if (!isset($job['lock'])) return;
        unset($this->jobs[$id]['lock']);
        try {
            Cache::restoreLock(ImageFlow::lockKey($job['row']->page_id, $job['row']->psid), $job['lock'])->release();
        } catch (Throwable $e) {
            report($e); // the lock ends by itself after ImageFlow::LOCK_SECONDS
        }
    }
}
