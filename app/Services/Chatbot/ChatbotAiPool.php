<?php

namespace App\Services\Chatbot;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Utils;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Ang pool ng AI step (chatbot:ai-pool): hanggang services.chatbot.ai_concurrency na request sa OpenAI nang sabay, sa
 * iisang proseso (curl multi, walang paghihintay sa bawat isa). Bawat sagot ay itinutugma sa sariling ticket (request_id,
 * hawak ng closure ng request na iyon), hindi sa ayos ng pagdating. Sa 429: ibinabalik ang request (walang nawawala),
 * hinahati ang sabay na bilang at humihinto muna ang bagong padala; bumabalik nang isa-isa sa bawat sagot. Ang mock page
 * (ChatbotMockAi) ay walang HTTP: sumasagot ang timer pagkatapos ng bagal nito.
 */
class ChatbotAiPool
{
    public const BEAT = 'chatbot-ai-pool:beat';
    public const BEAT_FRESH_SECONDS = 10;
    public const MAX_BACKOFF_SECONDS = 30;

    public readonly string $owner;
    private Client $http;
    /** @var array<string, true> request_id ng mga nasa himpapawid */
    private array $inflight = [];
    /** @var array<string, array{0: int, 1: object}> mock: request_id => [oras ng sagot (ms), row] */
    private array $timers = [];
    private int $limit;
    private int $pauseUntilMs = 0;
    private int $backoffs = 0;

    /** @param callable|null $handler Guzzle handler (para sa test); null = curl multi */
    public function __construct(private mixed $handler = null)
    {
        $this->owner = (string) Str::uuid();
        // select_timeout: hindi naghihintay nang 1 s ang tick habang tahimik ang mga koneksyon (may bagong pending na kukunin).
        $this->handler ??= new CurlMultiHandler(['select_timeout' => 0.01]);
        $this->http = new Client(['handler' => HandlerStack::create($this->handler), 'http_errors' => false]);
        $this->limit = $this->max();
    }

    public function max(): int
    {
        return max(1, (int) config('services.chatbot.ai_concurrency', 50));
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function inflight(): int
    {
        return count($this->inflight);
    }

    private static function nowMs(): int
    {
        return (int) now()->getTimestampMs();
    }

    /** May buhay na pool (heartbeat sa nakaraang BEAT_FRESH_SECONDS)? */
    public static function alive(): bool
    {
        $beat = Cache::get(self::BEAT);

        return is_array($beat) && (int) ($beat['at'] ?? 0) > now()->getTimestamp() - self::BEAT_FRESH_SECONDS;
    }

    /** Simula: tumatanggi kapag may ibang buhay na pool (heartbeat), saka ibinabalik ang naiwang "sending" ng patay na pool. */
    public function start(): bool
    {
        $beat = Cache::get(self::BEAT);
        if (is_array($beat) && ($beat['owner'] ?? '') !== $this->owner && (int) ($beat['at'] ?? 0) > now()->getTimestamp() - self::BEAT_FRESH_SECONDS) return false;
        $this->beat();
        ChatbotAiQueue::recover($this->owner);

        return true;
    }

    public function beat(): void
    {
        Cache::put(self::BEAT, ['owner' => $this->owner, 'at' => now()->getTimestamp()], 60);
    }

    public function stop(): void
    {
        if ((Cache::get(self::BEAT)['owner'] ?? null) === $this->owner) Cache::forget(self::BEAT);
    }

    /** Isang ikot: kumuha ng bago (kung may puwang at hindi naka-pause), paandarin ang HTTP, at sagutin ang mock na due na. */
    public function tick(bool $claim = true): int
    {
        $started = 0;
        if ($claim && self::nowMs() >= $this->pauseUntilMs) {
            foreach (ChatbotAiQueue::claim($this->owner, $this->limit - count($this->inflight)) as $row) {
                $this->send($row);
                $started++;
            }
        }
        if ($this->handler instanceof CurlMultiHandler) $this->handler->tick();
        Utils::queue()->run();

        $now = self::nowMs();
        foreach ($this->timers as $id => [$due, $row]) {
            if ($due > $now) continue;
            unset($this->timers[$id]);
            $this->done($row, ['ok' => true, 'text' => ChatbotMockAi::text($row->psid, $row->chat_id), 'error' => null], 200);
        }

        return $started;
    }

    private function send(object $row): void
    {
        $id = (string) $row->request_id;
        $this->inflight[$id] = true;
        $ms = ChatbotMockAi::latency((string) $row->page_id);
        if ($ms !== null) {
            $this->timers[$id] = [self::nowMs() + $ms, $row];
            return;
        }
        $key = (string) config('services.openai.key');
        if ($key === '') {
            $this->done($row, ['ok' => false, 'text' => '', 'error' => ChatbotAi::NO_KEY], null);
            return;
        }

        $left = max(1, now()->diffInSeconds(\Illuminate\Support\Carbon::parse($row->deadline_at), false));
        $this->http->postAsync(ChatbotAi::URL, [
            'headers' => ['Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json'],
            'json' => ChatbotAi::payload((string) $row->model, (array) json_decode((string) $row->messages, true), (int) $row->max_tokens),
            'timeout' => $left, 'connect_timeout' => min(10, $left),
        ])->then(
            fn (ResponseInterface $res) => $this->answered($row, $res),
            fn ($e) => $this->done($row, ['ok' => false, 'text' => '', 'error' => 'OpenAI did not answer.'], null),
        );
    }

    private function answered(object $row, ResponseInterface $res): void
    {
        try {
            $this->handle($row, $res);
        } catch (Throwable $e) {
            report($e); // ang row ay sending pa: ang sweep ang magpapalya sa deadline
        } finally {
            unset($this->inflight[(string) $row->request_id]); // laging bakante ang puwesto, kahit pumalya
        }
    }

    private function handle(object $row, ResponseInterface $res): void
    {
        if ($res->getStatusCode() === 429) {
            // Bumagal: kalahati ng sabay, hinto muna ang bago, at ibalik ang request (hindi failed).
            $after = $res->getHeaderLine('Retry-After');
            $wait = is_numeric($after) && (float) $after > 0 ? (int) ceil((float) $after) : 2 ** min(5, ++$this->backoffs);
            $wait = min(self::MAX_BACKOFF_SECONDS, max(1, $wait));
            $this->limit = max(1, intdiv($this->limit, 2));
            $this->pauseUntilMs = max($this->pauseUntilMs, self::nowMs() + $wait * 1000);
            unset($this->inflight[(string) $row->request_id]);
            ChatbotAiQueue::later($row, $this->owner, now()->addSeconds($wait));
            return;
        }
        $this->backoffs = 0;
        $this->limit = min($this->max(), $this->limit + 1);
        $this->done($row, ChatbotAi::parse($res->getStatusCode(), json_decode((string) $res->getBody(), true)), $res->getStatusCode());
    }

    private function done(object $row, array $res, ?int $http): void
    {
        unset($this->inflight[(string) $row->request_id]);
        try {
            ChatbotAiQueue::finish($row, $this->owner, $res, $http);
        } catch (Throwable $e) {
            report($e); // ang row ay answered/sending pa: ang sweep ang magtutuloy o magpapalya
        }
    }

    /**
     * Ang loop ng command: hanggang $seconds (o hanggang $stop), heartbeat kada 2 s, sweep kada 5 s. Kapag huminto, hindi
     * na kumukuha ng bago at hinihintay ang mga nasa himpapawid (hanggang 65 s).
     */
    public function run(int $seconds, callable $stop): void
    {
        $until = microtime(true) + $seconds;
        $beatAt = $sweepAt = 0.0;
        $drainUntil = null;
        while (true) {
            if ($drainUntil === null && ($stop() || microtime(true) >= $until)) $drainUntil = microtime(true) + 65;
            $stopping = $drainUntil !== null;
            if ($stopping && (!$this->inflight || microtime(true) >= $drainUntil)) break;
            $started = $this->tick(!$stopping);
            if (microtime(true) - $beatAt >= 2) {
                $this->beat();
                $beatAt = microtime(true);
            }
            if (microtime(true) - $sweepAt >= 5) {
                ChatbotAiQueue::sweep();
                $sweepAt = microtime(true);
            }
            usleep($started || $this->inflight ? 5_000 : 200_000);
        }
        $this->stop();
    }
}
