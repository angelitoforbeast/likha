<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\BotcakeMessage;
use App\Models\ChatbotFlow;
use App\Services\Chatbot\BotcakeSendPool;
use App\Services\Chatbot\BotcakeSendQueue;
use App\Services\Chatbot\ChatbotEvents;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotRunner;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowGraph;
use App\Services\ChatblastSender;
use App\Services\PageSettings;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;

/**
 * Ang padala sa Botcake na hindi naghihintay (spec 089): ang ticket sa botcake_send_requests, ang botcake:send-pool (lane
 * kada page na eksaktong 2 kada segundo, 429 sa page lang, restart na walang dobleng padala), ang pagtuloy sa sariling
 * customer lang, ang ayos kada customer, ang tatlong hakbang ng chatblast na pareho sa dati, at ang switch. Fake na
 * Botcake (handler na hawak ng test) at orasan.
 */
class BotcakeAsyncSendTest extends ChatblastTestCase
{
    /** @var array<int, array{req: RequestInterface, p: Promise}> */
    private array $calls = [];
    private BotcakeSendPool $pool;

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
        ]]);
        config(['services.chatbot.replies_enabled' => true, 'services.botcake.async_sends' => true, 'services.botcake.rate_per_page' => 2,
            'services.openai.key' => 'sk-test-not-real']);
        foreach (['Page Uno' => '1001', 'Page Dos' => '1002', 'Page Tres' => '1003'] as $page => $id) {
            $this->page($page, $id);
            app(PageSettings::class)->set($page, 'botcake', 'token', 'tok-' . $id); // a token, so a rate lane, of its own
        }
        $this->pool = $this->pool();
        $this->assertTrue($this->pool->start());
    }

    private function pool(): BotcakeSendPool
    {
        return new BotcakeSendPool(function (RequestInterface $req, array $options) {
            $p = new Promise();
            $this->calls[] = ['req' => $req, 'p' => $p];

            return $p;
        }, $this->clock);
    }

    private function live(array $nodes, array $edges, string $page = 'Page Uno'): ChatbotFlow
    {
        $flow = ChatbotFlow::create(['name' => 'Flow ' . $page . ' ' . uniqid(), 'graph' => FlowGraph::clean(['nodes' => $nodes, 'edges' => $edges])]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);
        app(ChatbotPage::class)->link($page, $flow);

        return $flow->fresh();
    }

    /** start → n2 "You said: {{last_input}}" → n3 sets done = yes (the run continued). */
    private function echoFlow(string $page = 'Page Uno'): ChatbotFlow
    {
        return $this->live([['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'You said: {{last_input}}']]]],
            ['id' => 'n3', 'type' => 'set', 'config' => ['ops' => [['op' => 'set', 'field' => 'done', 'value' => 'yes']]]]],
            [['from' => 'n1', 'port' => 'next', 'to' => 'n2'], ['from' => 'n2', 'port' => 'continue', 'to' => 'n3']], $page);
    }

    private function says(string $message, string $psid, string $pageId = '1001', string $page = 'Page Uno', bool $run = true): BotcakeChat
    {
        $chat = BotcakeChat::create(['page_id' => $pageId, 'page_name' => $page, 'psid' => $psid, 'name' => 'Ana Cruz', 'message' => $message, 'payload' => [], 'received_at' => now()]);
        if ($run) app(ChatbotRunner::class)->handle($chat->id);

        return $chat;
    }

    /** @return array{path: string, psid: ?string, text: string} */
    private static function req(array $call): array
    {
        $body = json_decode((string) $call['req']->getBody(), true) ?? [];

        return ['path' => $call['req']->getUri()->getPath(), 'psid' => $body['psid'] ?? null, 'text' => (string) ($body['data']['content']['messages'][0]['text'] ?? '')];
    }

    private function answer(array $call, int $status = 200, array $body = ['success' => true], array $headers = []): void
    {
        $call['p']->resolve(new Response($status, $headers + ['Content-Type' => 'application/json'], json_encode($body)));
    }

    /** Sagutin ng 200 ang lahat ng hindi pa nasasagot, at isang ikot ng pool. */
    private function answerAll(): void
    {
        foreach ($this->calls as $c) if ($c['p']->getState() === 'pending') $this->answer($c);
        $this->pool->tick(false);
    }

    private function replies(string $psid): array
    {
        return DB::table('chatbot_replies')->where('psid', (int) $psid)->orderBy('id')->get(['status', 'content', 'error'])
            ->map(fn ($r) => $r->status . ': ' . (json_decode($r->content, true)[0]['text'] ?? '') . ($r->error ? ' (' . $r->error . ')' : ''))->all();
    }

    public function test_S_089_1_results_that_arrive_out_of_order_each_continue_their_own_customers_run(): void
    {
        $customers = ['7001' => ['1001', 'Page Uno'], '7002' => ['1002', 'Page Dos'], '7003' => ['1003', 'Page Tres']];
        foreach ($customers as $psid => [$id, $page]) {
            $this->echoFlow($page);
            $this->says('q' . $psid, $psid, $id, $page);
        }
        $this->assertSame(3, DB::table(BotcakeSendQueue::TABLE)->where('status', 'pending')->count(), 'each run handed its send off');
        $this->assertNull((new CustomerFields(1001, 7001))->get('done'), 'and waits for the result');

        $this->pool->tick();
        $this->assertCount(3, $this->calls, 'three pages, three sends at the same time');
        foreach (array_reverse($this->calls) as $c) { // the last one answered first
            $this->answer($c);
            $this->pool->tick(false);
        }

        foreach ($customers as $psid => [$id]) {
            $this->assertSame(['sent: You said: q' . $psid], $this->replies($psid));
            $this->assertSame('yes', (new CustomerFields((int) $id, (int) $psid))->get('done'), "{$psid}'s run continued");
        }
        $this->assertSame(['q7001' => '7001', 'q7002' => '7002', 'q7003' => '7003'], collect($this->calls)->mapWithKeys(fn ($c) => [substr(self::req($c)['text'], 10) => self::req($c)['psid']])->sort()->all());
    }

    public function test_S_089_2_a_ticket_that_does_not_match_its_chat_sends_nothing_and_writes_send_mismatch(): void
    {
        $this->echoFlow();
        $this->says('hello', '7001');
        DB::table(BotcakeSendQueue::TABLE)->update(['psid' => '7999']); // the ticket names someone else than the chat's customer
        Log::spy();

        $this->pool->tick();

        $this->assertSame([], $this->calls, 'nothing sent');
        $this->assertSame([], $this->replies('7999'));
        $this->assertNull((new CustomerFields(1001, 7001))->get('done'), 'the run does not continue');
        $e = DB::table('chatbot_events')->where('type', 'send_mismatch')->first();
        $this->assertSame('Send at step n2 stopped: the ticket does not match the customer (nothing sent)', ChatbotEvents::text($e->type, $e->node_id, (array) json_decode($e->detail, true)));
        Log::shouldHaveReceived('error')->once()->withArgs(fn ($msg, $ctx) => str_contains($msg, 'mismatch') && $ctx['check'] === 'chat' && !str_contains(json_encode($ctx), 'hello'));
        $this->assertSame('done', app(ChatbotRunner::class)->handle($this->says('again', '7001', run: false)->id), 'the customer is not stuck');
    }

    public function test_S_089_3_a_customers_intro_goes_out_before_the_later_ai_reply(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'AI answer']]]])]);
        $this->live([['id' => 'n1', 'type' => 'start'], ['id' => 'n10', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Intro']]]],
            ['id' => 'n100', 'type' => 'ai', 'config' => ['user' => '{{last_input}}', 'send' => true]]],
            [['from' => 'n1', 'port' => 'next', 'to' => 'n10'], ['from' => 'n10', 'port' => 'continue', 'to' => 'n100']]);
        $this->says('hm', '7001');

        $this->pool->tick();
        $this->assertSame(['Intro'], array_map(fn ($c) => self::req($c)['text'], $this->calls));
        Http::assertNothingSent(); // the AI step waits until the intro is through
        $this->answerAll();

        $this->clock->time += 0.5;
        $this->pool->tick();
        $this->assertSame(['Intro', 'AI answer'], array_map(fn ($c) => self::req($c)['text'], $this->calls));
        $this->answerAll();
        $this->assertSame(['sent: Intro', 'sent: AI answer'], $this->replies('7001'));
    }

    public function test_S_089_4_the_per_page_rate_is_never_exceeded_while_other_pages_send_at_the_same_time(): void
    {
        $this->echoFlow();
        $this->echoFlow('Page Dos');
        for ($i = 1; $i <= 6; $i++) $this->says('a' . $i, (string) (7000 + $i));
        $this->says('b', '8001', '1002', 'Page Dos');

        for ($n = 0; $n < 60; $n++) {
            $this->pool->tick();
            $this->answerAll();
            $this->clock->time += 0.1;
        }

        $lanes = $this->pool->sentAt;
        $uno = $lanes[sha1('tok-1001')];
        $this->assertCount(6, $uno, 'all six sent');
        for ($i = 1; $i < count($uno); $i++) $this->assertGreaterThanOrEqual(0.5 - 1e-6, $uno[$i] - $uno[$i - 1], 'at most 2 per second on one page');
        $this->assertSame($uno[0], $lanes[sha1('tok-1002')][0], 'another page does not wait for it');
        for ($i = 1; $i <= 6; $i++) $this->assertSame(['sent: You said: a' . $i], $this->replies((string) (7000 + $i)));
    }

    public function test_S_089_5_a_429_backs_off_that_page_only_and_the_send_goes_through_after(): void
    {
        $this->echoFlow();
        $this->echoFlow('Page Dos');
        $this->says('a', '7001');
        $this->says('b1', '8001', '1002', 'Page Dos');
        $this->says('b2', '8002', '1002', 'Page Dos');
        $this->pool->tick();
        [$a, $b1] = $this->calls;
        $this->answer($a, 429, ['success' => false, 'message' => 'Too many'], ['Retry-After' => '2']);
        $this->answer($b1);
        $this->pool->tick(false);

        $this->clock->time += 0.5;
        $this->pool->tick();
        $this->assertSame(['7001', '8001', '8002'], array_map(fn ($c) => self::req($c)['psid'], $this->calls), 'Page Dos goes on, Page Uno waits');
        $this->answerAll();

        $this->clock->time += 1.6; // 2.1 s after the 429
        $this->pool->tick();
        $this->assertSame('7001', self::req(end($this->calls))['psid'], 'Page Uno again after Retry-After');
        $this->answerAll();
        $this->assertSame(['sent: You said: a'], $this->replies('7001'));
    }

    public static function readBacks(): array
    {
        return [
            'the same text: sent' => ['Hello Ana', 'sent', null, ['write', 'read', 'flow']],
            'another text: field_mismatch, not sent' => ['Something else', 'field_mismatch', 'The field holds a different text in the read-back; not sent', ['write', 'read']],
        ];
    }

    /** @dataProvider readBacks */
    public function test_S_089_6_a_chatblast_messages_three_steps_run_in_order_with_the_same_statuses_and_steps_as_before(string $readBack, string $status, ?string $reason, array $steps): void
    {
        $message = fn () => BotcakeMessage::create(['page_name' => 'Page Uno', 'waybill' => 'JT' . uniqid(), 'kind' => 'in_transit', 'psid' => '7001',
            'text' => 'Hello Ana', 'status' => 'queued', 'is_test' => true, 'queued_at' => now()]);
        $read = ['success' => true, 'data' => [['id' => 123, 'custom_field_id' => 7701, 'value' => $readBack]]];
        $normal = fn (BotcakeMessage $m) => [$m->status, $m->reason, array_map(fn ($s) => [$s['step'], $s['method'], $s['path'], $s['request'], $s['http'], $s['attempts'], $s['response']], (array) $m->steps)];

        // Before: the job waits for each step (no live pool).
        $this->pool->stop();
        Http::fake(['botcake.io/*' => fn (HttpRequest $r) => Http::response($r->method() === 'GET' ? $read : ['success' => true])]);
        $before = $message();
        app(ChatblastSender::class)->process($before->id);
        $this->assertSame(0, DB::table(BotcakeSendQueue::TABLE)->count());

        // Now: the job hands the message off and is free; the pool runs the three steps.
        $this->travel(21)->seconds(); // the customer gap after the first message
        $this->assertTrue($this->pool->start());
        $now = $message();
        app(ChatblastSender::class)->process($now->id);
        $this->assertSame('sending', $now->fresh()->status);
        $key = ChatblastSender::customerLockKey('Page Uno', '7001');
        $this->assertFalse(Cache::lock($key, 10)->get(), 'the customer lock is held while the pool sends');
        for ($n = 0; $n < 8; $n++) {
            $this->pool->tick();
            foreach ($this->calls as $c) if ($c['p']->getState() === 'pending') $this->answer($c, 200, $c['req']->getMethod() === 'GET' ? $read : ['success' => true]);
            $this->pool->tick(false);
            $this->clock->time += 0.5;
        }

        $this->assertSame($steps, array_column((array) $now->fresh()->steps, 'step'));
        $this->assertSame([$status, $reason], [$now->fresh()->status, $now->fresh()->reason]);
        $this->assertSame($normal($before->fresh()), $normal($now->fresh()), 'the same status, reason and steps as the waiting path');
        $this->assertTrue(Cache::has($key . ':gap'), 'the 20 s customer gap is set');
        $this->assertTrue(Cache::lock($key, 10)->get(), 'and the lock is released');
    }

    public function test_S_089_7_a_pool_restart_never_sends_a_message_twice(): void
    {
        $this->echoFlow();
        $this->says('one', '7001');
        $this->says('two', '7002'); // the same page: waits in the lane, claimed but not called
        $this->pool->tick();
        $this->assertSame(['7001'], array_map(fn ($c) => self::req($c)['psid'], $this->calls));
        $old = $this->calls;

        // The pool dies. A new one starts once the old heartbeat is stale.
        $this->travel(11)->seconds();
        $this->calls = [];
        $new = $this->pool();
        $this->assertTrue($new->start());
        $this->clock->time += 1;
        for ($n = 0; $n < 3; $n++) {
            $new->tick();
            foreach ($this->calls as $c) if ($c['p']->getState() === 'pending') $this->answer($c);
            $new->tick(false);
            $this->clock->time += 0.5;
        }
        $this->answer($old[0]); // the dead pool's call answers late
        $this->pool->tick(false);

        $this->assertSame(['7002'], array_map(fn ($c) => self::req($c)['psid'], $this->calls), 'the one never called is sent once; the called one is not sent again');
        $this->assertSame(['failed:  (Botcake did not answer (HTTP 0))'], array_map(fn ($r) => preg_replace('/You said: \w+/', '', $r), $this->replies('7001')));
        $this->assertSame(['sent: You said: two'], $this->replies('7002'));
    }

    public function test_S_089_8_with_async_sends_off_or_no_live_pool_the_send_waits_as_before(): void
    {
        Http::fake(['botcake.io/*' => Http::response(['success' => true])]);
        $this->echoFlow();

        config(['services.botcake.async_sends' => false]);
        $this->says('one', '7001');
        config(['services.botcake.async_sends' => true]);
        $this->pool->stop();
        $this->says('two', '7002');

        $this->assertSame(0, DB::table(BotcakeSendQueue::TABLE)->count());
        $this->assertSame([], $this->calls);
        $this->assertSame(['sent: You said: one'], $this->replies('7001'));
        $this->assertSame(['sent: You said: two'], $this->replies('7002'));
        $this->assertSame('yes', (new CustomerFields(1001, 7002))->get('done'));
    }

    /**
     * A send node has no failed port in the editor (FlowGraph::ports): a failed send stops the run, as before. The AI step's
     * failed port is for the AI answer; a failed send of the answer is logged and the step still goes on "success", as before.
     */
    public function test_S_089_9_a_failed_send_still_takes_the_failed_path_it_took_before(): void
    {
        $this->echoFlow();
        $this->says('hi', '7001');
        $this->pool->tick();
        $this->answer($this->calls[0], 500, ['success' => false, 'message' => 'Server error']);
        $this->pool->tick(false);

        $this->assertSame(['failed: You said: hi (Botcake said "Server error" (HTTP 500))'], $this->replies('7001'));
        $this->assertNull((new CustomerFields(1001, 7001))->get('done'), 'the run stopped at the failed send');
        $ended = DB::table('chatbot_events')->where('type', 'run_ended')->orderByDesc('id')->value('detail');
        $this->assertSame('Send failed: Botcake said "Server error" (HTTP 500)', json_decode($ended, true)['reason']);
        $this->clock->time += 5;
        $this->pool->tick();
        $this->assertCount(1, $this->calls, 'a 5xx on send_content is not retried (it may have arrived)');

        // The AI step with "send the answer": a failed send still continues on "success".
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'AI answer']]]])]);
        $this->live([['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'ai', 'config' => ['user' => '{{last_input}}', 'send' => true]],
            ['id' => 'n3', 'type' => 'set', 'config' => ['ops' => [['op' => 'set', 'field' => 'after_ai', 'value' => 'yes']]]]],
            [['from' => 'n1', 'port' => 'next', 'to' => 'n2'], ['from' => 'n2', 'port' => 'success', 'to' => 'n3']], 'Page Dos');
        $this->says('q', '8001', '1002', 'Page Dos');
        $this->pool->tick();
        $this->answer(end($this->calls), 500, ['success' => false]);
        $this->pool->tick(false);
        $this->assertSame('yes', (new CustomerFields(1002, 8001))->get('after ai')); // field names are stored normalised
    }
}
