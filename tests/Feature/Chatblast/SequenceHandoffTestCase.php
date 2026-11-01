<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\ResumeChatbotSequenceSend;
use App\Jobs\RunChatbotSequenceStep;
use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Models\ChatbotSequence;
use App\Services\Chatbot\BotcakeSendPool;
use App\Services\Chatbot\BotcakeSendQueue;
use App\Services\Chatbot\ChatbotEvents;
use App\Services\Chatbot\ChatbotSequences;
use App\Services\Chatbot\FlowGraph;
use App\Services\Chatbot\SequenceSendPool;
use App\Services\PageSettings;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Psr\Http\Message\RequestInterface;

/**
 * Ang harness ng spec 215: ang sequence step na ipinapasa sa chatbot:sequence-pool. Fake na handler (promise na hawak ng
 * test) para sa pool, fake na orasan, Queue::fake (ang mga job ay pinapatakbo ng test, gaya ng worker), at ang reply pool
 * na buhay (para sa beat nito).
 */
abstract class SequenceHandoffTestCase extends ChatblastTestCase
{
    /** @var array<int, array{req: RequestInterface, p: Promise, t: float, timeout: mixed}> */
    protected array $calls = [];
    protected SequenceSendPool $pool;
    protected BotcakeSendPool $replyPool;
    /** @var array<string, int> ilang job ng bawat klase ang napatakbo na */
    private array $ran = [];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_25_100000_create_chatbot_waits_table.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
            'database/migrations/2026_10_28_100000_create_chatbot_ai_requests_table.php',
            'database/migrations/2026_10_29_100000_create_botcake_send_requests_table.php',
            'database/migrations/2026_11_15_100000_add_send_request_to_chatbot_sequence_subscriptions.php',
        ]]);
        Artisan::call('migrate', ['--path' => array_map(fn ($m) => 'database/migrations/' . $m . '.php', ['2026_10_30_098000_add_raw_to_chatbot_replies',
            '2026_10_30_100000_add_results_to_chatbot_replies', '2026_11_09_100000_add_via_to_chatbot_replies', '2026_11_11_100000_add_real_send_to_chatbot_replies',
            '2026_11_13_100000_widen_via_on_chatbot_replies'])]);
        ChatbotSequences::forgetSchema();
        config(['services.chatbot.replies_enabled' => true, 'services.botcake.async_sends' => true, 'services.botcake.rate_per_page' => 2,
            'services.chatbot.sequence_handoff' => true]);
        foreach (['Page Uno' => '1001', 'Page Dos' => '1002'] as $page => $id) {
            $this->page($page, $id);
            app(PageSettings::class)->set($page, 'botcake', 'token', 'tok-' . $id);
        }
        Queue::fake();
        $this->travelTo('2026-11-02 10:00:00');
        $this->replyPool = new BotcakeSendPool(fn () => new Promise(), $this->clock);
        $this->assertTrue($this->replyPool->start());
        $this->pool = $this->newPool();
        $this->assertTrue($this->pool->start());
    }

    protected function newPool(): SequenceSendPool
    {
        return new SequenceSendPool(function (RequestInterface $req, array $options) {
            $p = new Promise();
            $this->calls[] = ['req' => $req, 'p' => $p, 't' => $this->clock->now(), 'timeout' => $options['timeout'] ?? null];

            return $p;
        }, $this->clock);
    }

    /** start → send(texts[0]) → send(texts[1]) … (port continue), published. $before: nodes between start and the first send. */
    protected function stepFlow(array $texts, array $before = [], string $name = 'Step'): ChatbotFlow
    {
        $nodes = [['id' => 'n1', 'type' => 'start']];
        $edges = [];
        $prev = ['n1', 'next'];
        foreach ($before as $i => $n) {
            $nodes[] = $n + ['id' => 'n' . (50 + $i)];
            $edges[] = ['from' => $prev[0], 'port' => $prev[1], 'to' => 'n' . (50 + $i)];
            $prev = ['n' . (50 + $i), $n['port'] ?? 'next'];
        }
        foreach ($texts as $i => $t) {
            $id = 'n' . (10 + $i);
            $item = is_array($t) ? $t : ['type' => 'text', 'value' => $t];
            $nodes[] = ['id' => $id, 'type' => 'send', 'config' => ['items' => [$item]]];
            $edges[] = ['from' => $prev[0], 'port' => $prev[1], 'to' => $id];
            $prev = [$id, 'continue'];
        }
        foreach ($nodes as &$n) unset($n['port']);
        $flow = ChatbotFlow::create(['name' => $name . ' ' . uniqid(), 'graph' => FlowGraph::clean(['nodes' => $nodes, 'edges' => $edges])]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);

        return $flow->fresh();
    }

    /** @param array<int, int|array{0: int, 1: int, 2: string}> $steps flow id, or [flow id, delay, unit] */
    protected function sequence(string $name, array $steps, array $attrs = []): ChatbotSequence
    {
        $seq = ChatbotSequence::create($attrs + ['name' => $name, 'active' => true, 'exit_on_reply' => true]);
        foreach ($steps as $i => $s) {
            [$flow, $amount, $unit] = is_array($s) ? $s : [$s, 0, 'minutes'];
            $seq->steps()->create(['position' => $i + 1, 'delay_amount' => $amount, 'delay_unit' => $unit, 'flow_id' => $flow, 'active' => true]);
        }

        return $seq;
    }

    protected function chat(string $psid, string $pageId = '1001', ?string $at = null, string $message = 'Hi'): BotcakeChat
    {
        return BotcakeChat::create(['page_id' => $pageId, 'page_name' => $pageId === '1001' ? 'Page Uno' : 'Page Dos', 'psid' => $psid, 'name' => 'Ana Cruz',
            'message' => $message, 'payload' => [], 'received_at' => $at ?? now()]);
    }

    /** The customer wrote a minute ago and joins the sequence; the first step is due now. */
    protected function join(ChatbotSequence $seq, string $psid, string $pageId = '1001'): int
    {
        $this->chat($psid, $pageId, now()->subMinute()->format('Y-m-d H:i:s'));
        app(ChatbotSequences::class)->subscribe($seq->id, (int) $pageId, (int) $psid, 'other');

        return (int) $this->sub($seq, $psid, $pageId)->id;
    }

    protected function sub(ChatbotSequence $seq, string $psid, string $pageId = '1001'): object
    {
        return DB::table('chatbot_sequence_subscriptions')->where('sequence_id', $seq->id)->where('page_id', (int) $pageId)->where('psid', (int) $psid)->first();
    }

    protected function tick(): int
    {
        return app(ChatbotSequences::class)->tick();
    }

    /** Run every queued job of $class not run yet, the way a worker does (the unique lock is let go first). */
    protected function work(string $class): int
    {
        $jobs = Queue::pushed($class)->values();
        $from = $this->ran[$class] ?? 0;
        $this->ran[$class] = $jobs->count();
        for ($i = $from; $i < $jobs->count(); $i++) {
            $job = $jobs[$i];
            if ($job instanceof \Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing) (new UniqueLock(app('cache.store')))->release($job);
            app()->call([$job, 'handle']);
        }

        return $jobs->count() - $from;
    }

    /** Tick, then run the step jobs. */
    protected function steps(): void
    {
        $this->tick();
        $this->work(RunChatbotSequenceStep::class);
    }

    protected function resumes(): int
    {
        return $this->work(ResumeChatbotSequenceSend::class);
    }

    /** One pool round (claims are at most every 200 ms of the pool's clock). */
    protected function round(bool $claim = true): void
    {
        $this->clock->time += 0.25;
        $this->pool->tick($claim);
    }

    /** Pool rounds until $n calls went out (the page's rate may hold the next one back). */
    protected function roundsUntil(int $n, int $max = 20): void
    {
        for ($i = 0; $i < $max && count($this->calls) < $n; $i++) $this->round();
    }

    protected function answer(array $call, int $status = 200, array|string $body = ['success' => true]): void
    {
        $call['p']->resolve(new Response($status, ['Content-Type' => 'application/json'], is_string($body) ? $body : json_encode($body)));
    }

    protected function noAnswer(array $call): void
    {
        $call['p']->reject(new ConnectException('timed out', new PsrRequest('POST', (string) $call['req']->getUri())));
    }

    /** Answer every open call with 200, then a pool round without claims. */
    protected function answerAll(int $status = 200, array $body = ['success' => true]): void
    {
        foreach ($this->calls as $c) if ($c['p']->getState() === 'pending') $this->answer($c, $status, $body);
        $this->pool->tick(false);
    }

    /** Pool rounds and resumes until nothing moves (every call answered with 200). */
    protected function settle(int $rounds = 40): void
    {
        for ($i = 0; $i < $rounds; $i++) {
            $this->round();
            $this->answerAll();
            $this->resumes();
        }
    }

    /** @return array{host: string, path: string, psid: ?string, text: string, body: array, raw: string, headers: array} */
    protected static function req(array $call): array
    {
        $raw = (string) $call['req']->getBody();
        $body = json_decode($raw, true) ?? [];
        $text = $body['data']['content']['messages'][0]['text'] ?? ($body['messages'][0]['text'] ?? '');

        return ['host' => $call['req']->getUri()->getHost(), 'path' => $call['req']->getUri()->getPath(), 'psid' => $body['psid'] ?? null, 'text' => (string) $text,
            'body' => $body, 'raw' => $raw, 'headers' => $call['req']->getHeaders()];
    }

    /** @return array<int, string> the customer's reply rows as "status: text (error)" */
    protected function replies(string $psid): array
    {
        return DB::table('chatbot_replies')->where('psid', (int) $psid)->orderBy('id')->get(['status', 'content', 'error'])
            ->map(fn ($r) => $r->status . ': ' . (json_decode($r->content, true)[0]['text'] ?? '') . ($r->error ? ' (' . $r->error . ')' : ''))->all();
    }

    /** @return array<int, string> the customer's sequence events, in order */
    protected function events(string $psid): array
    {
        return DB::table('chatbot_events')->where('psid', (int) $psid)->where('type', 'sequence')->orderBy('id')->get()
            ->map(fn ($e) => ChatbotEvents::text($e->type, $e->node_id, (array) json_decode($e->detail, true)))->all();
    }

    protected function tickets(): \Illuminate\Support\Collection
    {
        return DB::table(BotcakeSendQueue::TABLE)->orderBy('id')->get();
    }
}
