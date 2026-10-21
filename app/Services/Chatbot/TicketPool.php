<?php

namespace App\Services\Chatbot;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Utils;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ang balangkas ng isang pool ng ticket (chatbot:ai-pool, botcake:send-pool): iisang proseso na may heartbeat (tumatanggi
 * kapag may ibang buhay na pool, at ibinabalik ang naiwan ng patay na pool sa simula), curl multi na walang paghihintay,
 * mga timer (ang sagot ng mock), at ang loop na may heartbeat kada 2 s, sweep kada 5 s at malinis na paghinto (hinihintay
 * ang mga nasa himpapawid, hanggang 65 s). Ang bawat pool ang nagpapasya kung ano ang kukunin at paano ipapadala.
 */
abstract class TicketPool
{
    public const BEAT = '';
    public const BEAT_FRESH_SECONDS = 10;

    public readonly string $owner;
    protected Client $http;
    /** @var array<string, true> request_id ng mga hawak pa (nasa himpapawid o naghihintay ng puwesto) */
    protected array $inflight = [];
    /** @var array<string, array{0: int, 1: callable}> id => [oras (ms), gagawin] */
    private array $timers = [];

    /** @param callable|null $handler Guzzle handler (para sa test); null = curl multi */
    public function __construct(protected mixed $handler = null)
    {
        $this->owner = (string) Str::uuid();
        // select_timeout: hindi naghihintay nang 1 s ang tick habang tahimik ang mga koneksyon (may bagong pending na kukunin).
        $this->handler ??= new CurlMultiHandler(['select_timeout' => 0.01]);
        $this->http = new Client(['handler' => HandlerStack::create($this->handler), 'http_errors' => false]);
    }

    /** Isang ikot ng pool: kumuha ng bago (kapag $claim), magpadala, at tapusin ang may sagot. @return int ilang bago */
    abstract public function tick(bool $claim = true): int;

    /** Ang naiwan ng patay na pool (hindi $owner), sa simula. */
    abstract protected function recover(): void;

    /** Ang paglilinis ng mga ticket (deadline, nawalang resume, luma). */
    abstract protected function sweep(): void;

    public function inflight(): int
    {
        return count($this->inflight);
    }

    protected function nowMs(): int
    {
        return (int) now()->getTimestampMs();
    }

    /** May buhay na pool (heartbeat sa nakaraang $seconds, BEAT_FRESH_SECONDS kapag wala)? */
    public static function alive(?int $seconds = null): bool
    {
        $beat = Cache::get(static::BEAT);

        return is_array($beat) && (int) ($beat['at'] ?? 0) > now()->getTimestamp() - ($seconds ?? static::BEAT_FRESH_SECONDS);
    }

    /** Simula: tumatanggi kapag may ibang buhay na pool (heartbeat), saka ibinabalik ang naiwan ng patay na pool. */
    public function start(): bool
    {
        $beat = Cache::get(static::BEAT);
        if (is_array($beat) && ($beat['owner'] ?? '') !== $this->owner && (int) ($beat['at'] ?? 0) > now()->getTimestamp() - static::BEAT_FRESH_SECONDS) return false;
        $this->beat();
        $this->recover();

        return true;
    }

    public function beat(): void
    {
        Cache::put(static::BEAT, ['owner' => $this->owner, 'at' => now()->getTimestamp()], 60);
    }

    public function stop(): void
    {
        if ((Cache::get(static::BEAT)['owner'] ?? null) === $this->owner) Cache::forget(static::BEAT);
    }

    /** Gawin ang $then pagkalipas ng $ms (ang sagot ng mock, o ang muling subok). */
    protected function after(string $id, int $ms, callable $then): void
    {
        $this->timers[$id . ':' . count($this->timers) . ':' . mt_rand()] = [$this->nowMs() + max(0, $ms), $then];
    }

    /** Paandarin ang HTTP at ang mga promise, saka ang mga timer na due na. */
    protected function pump(): void
    {
        if ($this->handler instanceof CurlMultiHandler) $this->handler->tick();
        Utils::queue()->run();

        $now = $this->nowMs();
        foreach ($this->timers as $id => [$due, $then]) {
            if ($due > $now) continue;
            unset($this->timers[$id]);
            try {
                $then();
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** Ang loop ng command: hanggang $seconds (o hanggang $stop). */
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
                $this->beat(); // ang sweep ay maaaring matagal: buhay pa ang pool
                try {
                    $this->sweep();
                } catch (Throwable $e) {
                    report($e);
                }
                $sweepAt = microtime(true);
            }
            usleep(static::pause($started || $this->inflight));
        }
        $this->stop();
    }

    /** Ang tulog kada ikot sa µs (queue.pools): maikli kapag may ginagawa, mas mahaba kapag idle. */
    public static function pause(bool $busy): int
    {
        return max(0, (int) config($busy ? 'queue.pools.busy_ms' : 'queue.pools.idle_ms', $busy ? 5 : 200)) * 1000;
    }
}
