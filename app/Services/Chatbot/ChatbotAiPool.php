<?php

namespace App\Services\Chatbot;

use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Ang pool ng AI step (chatbot:ai-pool): hanggang services.chatbot.ai_concurrency na request sa OpenAI nang sabay, sa
 * iisang proseso (TicketPool: curl multi, walang paghihintay sa bawat isa). Bawat sagot ay itinutugma sa sariling ticket
 * (request_id, hawak ng closure ng request na iyon), hindi sa ayos ng pagdating. Sa 429: ibinabalik ang request (walang
 * nawawala), hinahati ang sabay na bilang at humihinto muna ang bagong padala; bumabalik nang isa-isa sa bawat sagot. Ang
 * mock page (ChatbotMockAi) ay walang HTTP: sumasagot ang timer pagkatapos ng bagal nito.
 */
class ChatbotAiPool extends TicketPool
{
    public const BEAT = 'chatbot-ai-pool:beat';
    public const MAX_BACKOFF_SECONDS = 30;

    private int $limit;
    private int $pauseUntilMs = 0;
    private int $backoffs = 0;

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
            $this->pauseUntilMs = max($this->pauseUntilMs, $this->nowMs() + $wait * 1000);
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
}
