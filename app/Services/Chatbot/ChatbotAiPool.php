<?php

namespace App\Services\Chatbot;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Ang pool ng AI step (chatbot:ai-pool): hanggang services.chatbot.ai_concurrency na request sa OpenAI nang sabay, sa
 * iisang proseso (TicketPool: curl multi, walang paghihintay sa bawat isa). Bawat sagot ay itinutugma sa sariling ticket
 * (request_id, hawak ng closure ng request na iyon), hindi sa ayos ng pagdating. Sa 429: ibinabalik ang request (walang
 * nawawala), hinahati ang sabay na bilang at humihinto muna ang bagong padala; bumabalik nang isa-isa sa bawat sagot. Ang
 * mock page (ChatbotMockAi) ay walang HTTP: sumasagot ang timer pagkatapos ng bagal nito.
 * Ang page na may chain (ChatbotAiChain, hal. Local → OpenAI): ang pumalyang subok ay lipat agad sa susunod na provider sa
 * loob ng deadline ng row, at ang wakas lang ang tinatapos (finish), kaya iisa ang sagot.
 */
class ChatbotAiPool extends TicketPool
{
    public const BEAT = 'chatbot-ai-pool:beat';
    public const MAX_BACKOFF_SECONDS = 30;

    private int $limit;
    private int $pauseUntilMs = 0;
    private int $backoffs = 0;
    /** @var array<string, array> request_id => ang chain na tumatakbo (mga hakbang, subok, token ng kasalukuyang subok, slot) */
    private array $chains = [];
    /** @var array<string, array> request_id => ang mga subok bago ang 429 ng OpenAI (itinutuloy sa susunod na kuha) */
    private array $carried = [];

    public function __construct(mixed $handler = null)
    {
        parent::__construct($handler);
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

    protected function recover(): void
    {
        ChatbotAiQueue::recover($this->owner);
    }

    protected function sweep(): void
    {
        ChatbotAiQueue::sweep();
    }

    /** Isang ikot: kumuha ng bago (kung may puwang at hindi naka-pause), paandarin ang HTTP, at sagutin ang mock na due na. */
    public function tick(bool $claim = true): int
    {
        $started = 0;
        if ($claim && $this->nowMs() >= $this->pauseUntilMs) {
            foreach (ChatbotAiQueue::claim($this->owner, $this->limit - count($this->inflight)) as $row) {
                $this->send($row);
                $started++;
            }
        }
        $this->pump();

        return $started;
    }

    private function send(object $row): void
    {
        $id = (string) $row->request_id;
        $this->inflight[$id] = true;
        $ms = ChatbotMockAi::latency((string) $row->page_id);
        if ($ms !== null) {
            $this->after($id, $ms, fn () => $this->done($row, ['ok' => true, 'text' => ChatbotMockAi::text($row->psid, $row->chat_id), 'error' => null], 200));
            return;
        }
        // Ang chain ng page; pagkatapos ng 429 ng OpenAI, itinutuloy mula sa provider na iyon (hindi inuulit ang pumalya na).
        $tried = $this->carried[$id] ?? [];
        unset($this->carried[$id]);
        $failed = array_column(array_filter($tried, fn ($a) => !$a['ok']), 'provider');
        $steps = array_values(array_filter(ChatbotAiChain::forPage((string) $row->page_name), fn ($s) => !in_array($s['provider'], $failed, true)));
        $this->chains[$id] = ['steps' => $steps, 'i' => 0, 'attempts' => $tried, 'token' => 0, 'slot' => null, 'http' => null];
        $this->next($row);
    }

    /** Ang susunod na provider ng chain na maaaring tawagan, o ang wakas (sagot o palya) kapag wala na o ubos na ang oras. */
    private function next(object $row): void
    {
        $id = (string) $row->request_id;
        while ($this->chains[$id]['i'] < count($this->chains[$id]['steps'])) {
            $step = $this->chains[$id]['steps'][$this->chains[$id]['i']++];
            $p = $step['provider'];
            $model = $step['model'] ?? (string) $row->model;
            if ($skip = ChatbotAiChain::skip($step)) {
                $this->chains[$id]['attempts'][] = ChatbotAiChain::skipped($step, $model, $skip);
                continue;
            }
            $left = now()->diffInSeconds(Carbon::parse($row->deadline_at), false);
            if ($left < 1) {
                // Walang isang segundo para sa provider na ito: isang palya lang (kondisyonal ang finish, kaya walang ikalawang resume kahit sa sweep).
                $late = ChatbotAiProviders::late($p);
                $this->chains[$id]['attempts'][] = ChatbotAiChain::skipped($step, $model, ['why' => $late, 'error' => $late]);
                break;
            }
            $timeout = $step['timeout'] !== null ? (int) min($step['timeout'], max(1, floor($left))) : max(1, $left);
            $slot = ChatbotAiChain::capped($p) ? ChatbotAiChain::slot($p, (int) ceil($timeout) + 5) : false;
            if ($slot === null) {
                $busy = ChatbotAiProviders::label($p) . ' is busy.';
                $this->chains[$id]['attempts'][] = ChatbotAiChain::skipped($step, $model, ['why' => $busy, 'error' => $busy]);
                continue;
            }
            $this->call($row, $step, $model, $timeout, $slot ?: null);
            return;
        }
        $this->done($row, ChatbotAiChain::outcome($this->chains[$id]['attempts']), $this->chains[$id]['http']);
    }

    private function call(object $row, array $step, string $model, float|int $timeout, ?Lock $slot): void
    {
        $id = (string) $row->request_id;
        $p = $step['provider'];
        $token = ++$this->chains[$id]['token'];
        $this->chains[$id]['slot'] = $slot;
        $this->chains[$id]['start'] = $this->nowMs();
        $r = ChatbotAiChain::request($p, $model, (array) json_decode((string) $row->messages, true), (int) $row->max_tokens);
        $opts = ['headers' => $r['headers'], 'json' => $r['body'], 'timeout' => $timeout, 'connect_timeout' => min(10, $timeout)];
        // Ang hindi OpenAI (Local): walang sinusundang redirect, mabilis na palya ng patay na koneksyon, at ang sariling oras ay
        // binabantayan din ng pool (lumilipat kahit hindi pa sumusuko ang koneksyon; ang huling sagot ay hindi na binibilang).
        if ($p !== ChatbotAiProviders::OPENAI) {
            $opts = ['connect_timeout' => min(5, $timeout), 'allow_redirects' => false] + $opts;
            $this->after($id, (int) ($timeout * 1000) + 250, fn () => $this->current($id, $token)
                && $this->attempted($row, $step, $model, self::failure(ChatbotAiProviders::noAnswer($p, true)), null));
        }
        $this->http->postAsync($r['url'], $opts)->then(
            fn (ResponseInterface $res) => $this->answered($row, $step, $model, $token, $res),
            fn ($e) => $this->current($id, $token)
                && $this->attempted($row, $step, $model, self::failure(ChatbotAiProviders::noAnswer($p, $e instanceof Throwable && ChatbotAiChain::timedOut($e->getMessage()))), null),
        );
    }

    private static function failure(string $error): array
    {
        return ['ok' => false, 'text' => '', 'error' => $error, 'prompt_tokens' => null, 'completion_tokens' => null];
    }

    /** Ang subok na ito pa ba ang hinihintay ng request (hindi pa tapos, hindi pa lumipat)? */
    private function current(string $id, int $token): bool
    {
        return isset($this->chains[$id]) && $this->chains[$id]['token'] === $token;
    }

    private function answered(object $row, array $step, string $model, int $token, ResponseInterface $res): void
    {
        $id = (string) $row->request_id;
        if (!$this->current($id, $token)) return; // huli na: may iba nang sumagot o lumipat na
        try {
            $this->handle($row, $step, $model, $res);
        } catch (Throwable $e) {
            report($e); // ang row ay sending pa: ang sweep ang magpapalya sa deadline
            $this->release($id); // laging bakante ang puwesto, kahit pumalya
        }
    }

    private function handle(object $row, array $step, string $model, ResponseInterface $res): void
    {
        $p = $step['provider'];
        if ($res->getStatusCode() === 429 && $p === ChatbotAiProviders::OPENAI) {
            // Bumagal: kalahati ng sabay, hinto muna ang bago, at ibalik ang request (hindi failed).
            $after = $res->getHeaderLine('Retry-After');
            $wait = is_numeric($after) && (float) $after > 0 ? (int) ceil((float) $after) : 2 ** min(5, ++$this->backoffs);
            $wait = min(self::MAX_BACKOFF_SECONDS, max(1, $wait));
            $this->limit = max(1, intdiv($this->limit, 2));
            $this->pauseUntilMs = max($this->pauseUntilMs, $this->nowMs() + $wait * 1000);
            $id = (string) $row->request_id;
            $this->carried[$id] = $this->chains[$id]['attempts'];
            $this->release($id);
            ChatbotAiQueue::later($row, $this->owner, now()->addSeconds($wait));
            return;
        }
        if ($p === ChatbotAiProviders::OPENAI) {
            $this->backoffs = 0;
            $this->limit = min($this->max(), $this->limit + 1);
        }
        $this->attempted($row, $step, $model, ChatbotAiProviders::parse($p, $res->getStatusCode(), json_decode((string) $res->getBody(), true)), $res->getStatusCode());
    }

    /** Isang tapos na subok: ang sagot ang wakas; ang palya ay lipat sa susunod na provider (o ang wakas kapag wala na). */
    private function attempted(object $row, array $step, string $model, array $out, ?int $http): bool
    {
        $id = (string) $row->request_id;
        $this->chains[$id]['token']++; // ang anumang huling callback ng subok na ito ay hindi na binibilang
        $this->chains[$id]['slot']?->release();
        $this->chains[$id]['slot'] = null;
        $p = $step['provider'];
        $out['ok'] ? ChatbotAiChain::succeeded($p) : ChatbotAiChain::failed($p);
        $this->chains[$id]['attempts'][] = ['provider' => $p, 'model' => $model, 'ms' => max(0, $this->nowMs() - (int) $this->chains[$id]['start']),
            'ok' => $out['ok'], 'why' => $out['error'], 'error' => $out['error'], 'text' => $out['text'],
            'prompt_tokens' => $out['prompt_tokens'], 'completion_tokens' => $out['completion_tokens'], 'called' => true];
        $this->chains[$id]['http'] = $http;
        $out['ok'] ? $this->done($row, ChatbotAiChain::outcome($this->chains[$id]['attempts']), $http) : $this->next($row);

        return true;
    }

    /** Bitawan ang request sa pool (slot ng provider, chain, puwesto). */
    private function release(string $id): void
    {
        ($this->chains[$id]['slot'] ?? null)?->release();
        unset($this->chains[$id], $this->inflight[$id]);
    }

    private function done(object $row, array $res, ?int $http): void
    {
        $this->release((string) $row->request_id);
        try {
            ChatbotAiQueue::finish($row, $this->owner, $res, $http);
        } catch (Throwable $e) {
            report($e); // ang row ay answered/sending pa: ang sweep ang magtutuloy o magpapalya
        }
    }
}
