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
            $this->calls[] = ['req' => $req, 'p' => $p, 't' => $this->clock->now()];

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

        // The times Botcake (the fake handler) received each call, per page.
        $at = fn (string $page) => array_values(array_map(fn ($c) => $c['t'], array_filter($this->calls, fn ($c) => str_contains(self::req($c)['path'], '/pages/' . $page . '/'))));
        $uno = $at('1001');
        $this->assertCount(6, $uno, 'all six sent');
        for ($i = 1; $i < count($uno); $i++) $this->assertGreaterThanOrEqual(0.5 - 1e-6, $uno[$i] - $uno[$i - 1], 'at most 2 per second on one page');
        $this->assertSame($uno[0], $at('1002')[0], 'another page does not wait for it');
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
        $ok = [200, ['success' => true]];
        $read = fn (string $text) => [200, ['success' => true, 'data' => [['id' => 123, 'custom_field_id' => 7701, 'value' => $text]]]];

        return [
            'the same text: sent' => [$ok, $read('Hello Ana'), $ok, 'sent', null, ['write', 'read', 'flow']],
            'another text: field_mismatch, not sent' => [$ok, $read('Something else'), $ok, 'field_mismatch', 'The field holds a different text in the read-back; not sent', ['write', 'read']],
            'send_flow 5xx: unknown' => [$ok, $read('Hello Ana'), [500, ['message' => 'down']], 'unknown', 'Not sure it was sent (send_flow was called) (HTTP 500); check Botcake before sending again', ['write', 'read', 'flow']],
            'the write refused: failed' => [[400, ['success' => false]], $read('Hello Ana'), $ok, 'failed', 'The custom field was not written (HTTP 400)', ['write']],
            'a 401: token invalid' => [[401, ['message' => 'Unauthorized']], $read('Hello Ana'), $ok, 'failed', 'Token invalid (HTTP 401); fix the token on /page-settings', ['write']],
        ];
    }

    /** @dataProvider readBacks */
    public function test_S_089_6_a_chatblast_messages_three_steps_run_in_order_with_the_same_statuses_and_steps_as_before(array $writeR, array $readR, array $flowR, string $status, ?string $reason, array $steps): void
    {
        $message = fn () => BotcakeMessage::create(['page_name' => 'Page Uno', 'waybill' => 'JT' . uniqid(), 'kind' => 'in_transit', 'psid' => '7001',
            'text' => 'Hello Ana', 'status' => 'queued', 'is_test' => true, 'queued_at' => now()]);
        $pick = fn (string $method, string $url) => $method === 'GET' ? $readR : (str_ends_with($url, '/send_flow') ? $flowR : $writeR);
        $read = $readR[1];
        $normal = fn (BotcakeMessage $m) => [$m->status, $m->reason, array_map(fn ($s) => [$s['step'], $s['method'], $s['path'], $s['request'], $s['http'], $s['attempts'], $s['response']], (array) $m->steps)];

        // Before: the job waits for each step (no live pool).
        $this->pool->stop();
        Http::fake(['botcake.io/*' => function (HttpRequest $r) use ($pick) {
            [$status, $body] = $pick($r->method(), $r->url());
            return Http::response($body, $status);
        }]);
        $before = $message();
        app(ChatblastSender::class)->process($before->id);
        $this->assertSame(0, DB::table(BotcakeSendQueue::TABLE)->count());

        // Now: the job hands the message off and is free; the pool runs the three steps.
        app(PageSettings::class)->set('Page Uno', 'botcake', 'token_status', 'ok', false, null, true); // a 401 above marked it invalid
        $this->travel(21)->seconds(); // the customer gap after the first message
        $this->assertTrue($this->pool->start());
        $now = $message();
        app(ChatblastSender::class)->process($now->id);
        $this->assertSame('sending', $now->fresh()->status);
        $key = ChatblastSender::customerLockKey('Page Uno', '7001');
        $this->assertFalse(Cache::lock($key, 10)->get(), 'the customer lock is held while the pool sends');
        for ($n = 0; $n < 8; $n++) {
            $this->pool->tick();
            foreach ($this->calls as $c) if ($c['p']->getState() === 'pending') $this->answer($c, ...$pick($c['req']->getMethod(), (string) $c['req']->getUri()));
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
        $calls = count($this->calls);
        for ($n = 0; $n < 4; $n++) { // the old pool turns out to be alive after all: it still holds 7002 in its lane
            $this->clock->time += 0.5;
            $this->pool->tick();
        }
        $this->assertCount($calls, $this->calls, 'a pool whose tickets were taken back never calls Botcake for them');

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
    private function blast(string $psid = '7001'): BotcakeMessage
    {
        $m = BotcakeMessage::create(['page_name' => 'Page Uno', 'waybill' => 'JT' . uniqid(), 'kind' => 'in_transit', 'psid' => $psid,
            'text' => 'Hello ' . $psid, 'status' => 'queued', 'is_test' => true, 'queued_at' => now()]);
        app(ChatblastSender::class)->process($m->id);

        return $m;
    }

    /** Sagutin ang chatblast na tawag: GET = ang text ng mensahe pabalik, ang iba = success. */
    private function answerBlast(array $c): void
    {
        $psid = (string) preg_replace('#.*/customer/([^/]+)/.*#', '$1', $c['req']->getUri()->getPath());
        $this->answer($c, 200, $c['req']->getMethod() === 'GET' ? ['success' => true, 'data' => [['id' => 7701, 'value' => 'Hello ' . $psid]]] : ['success' => true]);
    }

    public function test_S_089_10_a_chatblast_message_cut_by_a_pool_restart_is_unknown_only_if_send_flow_went_out(): void
    {
        $sent = $this->blast('7001');
        $this->travel(21)->seconds();
        $this->pool->beat();
        $notYet = $this->blast('7002');
        // The pool takes both: 7001 gets to send_flow (the call goes out), 7002 only gets its write done.
        for ($n = 0; $n < 12 && !collect($this->calls)->contains(fn ($c) => str_ends_with($c['req']->getUri()->getPath(), '/send_flow')); $n++) {
            $this->pool->tick();
            foreach ($this->calls as $c) {
                if ($c['p']->getState() === 'pending' && !str_ends_with($c['req']->getUri()->getPath(), '/send_flow') && (!str_contains($c['req']->getUri()->getPath(), '/7002/') || $c['req']->getMethod() === 'POST')) $this->answerBlast($c);
            }
            $this->pool->tick(false);
            $this->clock->time += 0.5;
        }
        $this->assertSame(1, collect($this->calls)->filter(fn ($c) => str_ends_with($c['req']->getUri()->getPath(), '/send_flow'))->count());

        // The pool dies; a new one starts.
        $this->travel(11)->seconds();
        $before = count($this->calls);
        $new = $this->pool();
        $this->assertTrue($new->start());
        $this->assertSame('unknown', $sent->fresh()->status, 'send_flow went out: not sure it arrived, never sent again');
        $this->assertSame('sending', $notYet->fresh()->status, 'send_flow never went out: the steps start again');
        for ($n = 0; $n < 10; $n++) {
            $new->tick();
            foreach ($this->calls as $c) if ($c['p']->getState() === 'pending' && count($this->calls) > $before) $this->answerBlast($c);
            $new->tick(false);
            $this->clock->time += 0.5;
        }
        $this->assertSame('sent', $notYet->fresh()->status);
        $this->assertSame(['write', 'read', 'flow'], array_column((array) $notYet->fresh()->steps, 'step'), 'one fresh run of the steps');
        $flows = collect($this->calls)->filter(fn ($c) => str_ends_with($c['req']->getUri()->getPath(), '/send_flow'));
        $this->assertSame(['7001', '7002'], $flows->map(fn ($c) => json_decode((string) $c['req']->getBody(), true)['psid'])->values()->all(), 'one send_flow per message');
    }

    public function test_S_089_11_the_sweep_ends_tickets_the_pool_never_got_to(): void
    {
        $this->echoFlow();
        $this->says('hi', '7001');
        $blast = $this->blast('7002');
        $this->pool->stop(); // no pool takes them
        Http::fake(['botcake.io/*' => fn (HttpRequest $r) => Http::response($r->method() === 'GET' ? ['success' => true, 'data' => [['id' => 7701, 'value' => 'Hello 7002']]] : ['success' => true])]);

        $this->travel(241)->seconds();
        Artisan::call('chatbot:resume-skipped');
        $this->assertSame('sent', $blast->fresh()->status, 'the chatblast message is queued again, and its job sends it (no live pool: waiting, as before)');
        $this->assertSame(['write', 'read', 'flow'], array_column((array) $blast->fresh()->steps, 'step'));
        $this->assertSame(0, collect($this->calls)->count(), 'the pool never called Botcake for it');

        $this->travel(360)->seconds(); // 10 minutes after the chatbot send was handed off
        Artisan::call('chatbot:resume-skipped');
        $this->assertSame(['failed: You said: hi (The send pool did not send it in time)'], $this->replies('7001'));
        $this->assertNull((new CustomerFields(1001, 7001))->get('done'), 'the run took the failed path (a send node stops)');
        $this->assertNull((new CustomerFields(1001, 7001))->get(CustomerFields::AI), 'and the customer is free again');

        // A result whose resume job was lost is resumed by the sweep, once.
        $this->pool->beat();
        $this->says('again', '7003');
        DB::table(BotcakeSendQueue::TABLE)->where('psid', '7003')->update(['status' => 'done', 'result' => json_encode(['status' => 'sent', 'error' => null, 'http' => 200])]);
        $this->travel(31)->seconds();
        Artisan::call('chatbot:resume-skipped');
        Artisan::call('chatbot:resume-skipped');
        $this->assertSame('yes', (new CustomerFields(1001, 7003))->get('done'));
        $this->assertSame(1, DB::table('chatbot_events')->where('psid', 7003)->where('type', 'run_started')->where('detail', 'like', '%resumed after send%')->count());
    }

    public static function notNow(): array
    {
        return [
            'a human paused the bot' => [fn () => (new CustomerFields(1001, 7001))->set(CustomerFields::BOT_PAUSED, '1'), 'skipped: You said: hi (The bot was paused before the send went out)', false],
            'the emergency stop' => [fn () => config(['services.chatbot.replies_enabled' => false]), 'skipped: You said: hi (Replies are switched off)', true],
        ];
    }

    /** @dataProvider notNow */
    public function test_S_089_12_a_queued_send_checks_the_pause_and_the_emergency_stop_before_it_goes_out(\Closure $then, string $reply, bool $continues): void
    {
        $this->echoFlow();
        $this->says('first', '7009'); // takes the page's first slot in the lane
        $this->says('hi', '7001');
        $this->pool->tick();
        $this->assertCount(1, $this->calls, '7001 waits in the lane');

        $then();
        $this->clock->time += 0.5;
        $this->pool->tick();

        $this->assertCount(1, $this->calls, 'nothing goes out for 7001');
        $this->assertSame([$reply], $this->replies('7001'));
        $this->assertSame($continues ? 'yes' : null, (new CustomerFields(1001, 7001))->get('done'), 'a pause stops the run; the switch skips as before');
    }

    public function test_S_089_13_a_big_chatblast_on_one_page_never_holds_up_the_chatbot_on_other_pages(): void
    {
        for ($i = 1; $i <= 40; $i++) $this->blast((string) (7100 + $i));
        $this->echoFlow('Page Dos');
        $this->says('hi', '8001', '1002', 'Page Dos');

        $this->pool->tick();

        $held = DB::table(BotcakeSendQueue::TABLE)->where('kind', 'chatblast')->where('status', 'sending')->count();
        $this->assertLessThanOrEqual($this->pool->perPage(), $held, 'one page holds only its share of the pool');
        $this->assertTrue(collect($this->calls)->contains(fn ($c) => self::req($c)['psid'] === '8001'), 'the chatbot on another page goes out at once');

        // A chatbot backlog on one page bigger than the pool looks at in one go still lets another page's newer send through.
        config(['services.botcake.send_concurrency' => 8]);
        $this->echoFlow();
        for ($i = 1; $i <= 50; $i++) $this->says('x', (string) (9000 + $i));
        $this->says('late', '8002', '1002', 'Page Dos');
        for ($n = 0; $n < 3; $n++) {
            $this->clock->time += 0.5;
            $this->pool->tick();
        }
        $this->assertTrue(collect($this->calls)->contains(fn ($c) => self::req($c)['psid'] === '8002'), 'not hidden behind the other page\'s backlog');
    }
}
