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
    }

    public function max(): int
    {
        return max(1, (int) config('services.botcake.sequence_concurrency', 100));
    }

    /** Buhay ang pool at ito ang bersyong nagpapadala ng sequence ticket (ang tanda sa beat). */
    public static function capable(): bool
    {
        $beat = Cache::get(self::BEAT);

        return self::alive() && (int) ($beat['sequence'] ?? 0) === 1 && empty($beat['stopped']);
    }

    protected function beatInfo(): array
    {
        return ['sequence' => 1];
    }

    /**
     * Malinis na paghinto (max-time o signal): ang beat ay naiiwan nang may "stopped", kaya walang bagong hand-off (capable),
     * pero hindi itinuturing na patay ang pool sa ilang segundo bago ang bago (walang kinakansela o binabawi).
     */
    public function stop(): void
    {
        if ((Cache::get(self::BEAT)['owner'] ?? null) === $this->owner) Cache::put(self::BEAT, ['owner' => $this->owner, 'at' => now()->getTimestamp(), 'stopped' => true], 60);
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
            $req = ['method' => 'POST', 'path' => '/pages/' . rawurlencode((string) $row->page_id) . '/flows/send_content',
                'data' => ['psid' => (string) $row->psid, 'data' => ['version' => 'v2', 'content' => ['messages' => BotcakeOutbox::wire($messages)]]]];
            $this->queue($id, ['row' => $row, 'provider' => ChatbotProvider::BOTCAKE, 'req' => $req, 'attempt' => 0, 'lane' => sha1($token), 'secret' => $token,
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
        try {
            if ($job['attempt'] === 0) {
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
                if (($check = app(ChatbotSequences::class)->sendCheck($row)) !== null) {
                    $this->forget($id);
                    // Naka-off ang mga sagot: itinatala bilang skipped gaya ng padalang naghintay, at tuloy ang step.
                    $o = $check['code'] === 'switched_off' ? ['status' => 'skipped', 'error' => $check['reason'], 'http' => null]
                        : ['status' => 'skipped', 'error' => $check['reason'], 'http' => null, 'check' => $check['code']];
                    BotcakeSendQueue::finishSequence($row, $this->owner, $o + ['via' => $via, 'ref' => $job['ref']]);
                    return null;
                }
                // Itinatala bago tumawag: hindi na inuulit pagkatapos ng crash.
                if (!BotcakeSendQueue::calledSequence($row, $this->owner, $via, $job['ref'])) {
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
                    'json' => $q['body'], 'timeout' => $q['timeout'], 'connect_timeout' => min(5, $q['timeout'])]);
            }
            $promise->then(
                function (ResponseInterface $res) use ($id) {
                    $text = (string) $res->getBody();
                    $this->respond($id, $res->getStatusCode(), json_decode($text, true) ?? $text, $res->getHeaderLine('Retry-After'));
                },
                fn ($e) => $this->respond($id, 0, null, null),
            );

            return $at;
        } catch (Throwable $e) {
            $this->failed($row, $e);
            return null;
        }
    }

    /** Ang sagot: ulit (Botcake 429 lang), o ang resulta at ang resume. */
    private function respond(string $id, int $status, mixed $body, ?string $retryAfter): void
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
                $o = BotcakeOutbox::outcome(['http' => $status, 'body' => $body, 'attempts' => $job['attempt'] + 1], $job['secret']) + ['via' => ChatbotProvider::BOTCAKE];
            } else {
                $o = GatewayProvider::outcome(['http' => $status, 'body' => $body], $job['secret']) + ['via' => (string) $job['gateway']];
            }
            $this->forget($id);
            BotcakeSendQueue::finishSequence($row, $this->owner, $o + ['ref' => $job['ref']]);
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
        unset($this->jobs[$id], $this->inflight[$id]);
    }
}
