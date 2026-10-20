<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\ResumeChatbotAi;
use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Services\BotcakeClient;
use App\Services\BotcakeMock;
use App\Services\Chatbot\ChatbotAiPool;
use App\Services\Chatbot\ChatbotAiQueue;
use App\Services\Chatbot\ChatbotEvents;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotRunner;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowGraph;
use App\Services\PageSettings;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;

/**
 * Ang AI step na hindi naghihintay (spec 087): ang ticket sa chatbot_ai_requests, ang chatbot:ai-pool (sabay-sabay, 429,
 * restart), ang pagtuloy sa sariling customer lang (mismatch, luma, isang customer sa isang pagkakataon, timeout), ang
 * switch na pabalik sa naghihintay, at ang pekeng AI ng test page. Fake na OpenAI (handler na hawak ng test) at Botcake.
 */
class ChatbotAsyncAiTest extends ChatblastTestCase
{
    private const SEND = 'https://botcake.io/api/public_api/v1/pages/1001/flows/send_content';
    private const MOCK_ID = '990000000000001';
    private const MOCK_PAGE = 'ZZ STRESS TEST';

    /** @var array<int, array{req: RequestInterface, p: Promise}> ang mga request sa "OpenAI" na hindi pa sinasagot */
    private array $calls = [];

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
        ]]);
        config(['services.chatbot.replies_enabled' => true, 'services.chatbot.ai_async' => true, 'services.chatbot.ai_concurrency' => 50,
            'services.openai.key' => 'sk-test-not-real', 'services.openai.timeout' => 60]);
        $this->page('Page Uno', '1001');
        Http::fake([self::SEND => Http::response(['success' => true])]);
    }

    /** n2 AI (save sa answer) → success: n3 "Reply: {{answer}}", failed: n4 "Sorry". */
    private function live(string $page = 'Page Uno'): ChatbotFlow
    {
        $graph = FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start'],
            ['id' => 'n2', 'type' => 'ai', 'config' => ['user' => '{{last_input}}', 'save_to' => 'answer']],
            ['id' => 'n3', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Reply: {{answer}}']]]],
            ['id' => 'n4', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Sorry']]]],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2'], ['from' => 'n2', 'port' => 'success', 'to' => 'n3'], ['from' => 'n2', 'port' => 'failed', 'to' => 'n4']]]);
        $flow = ChatbotFlow::create(['name' => 'AI flow ' . $page, 'graph' => $graph]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);
        app(ChatbotPage::class)->link($page, $flow);

        return $flow->fresh();
    }

    private function says(string $message, string $psid = '7001', bool $run = true, string $pageId = '1001', string $page = 'Page Uno'): BotcakeChat
    {
        $chat = BotcakeChat::create(['page_id' => $pageId, 'page_name' => $page, 'psid' => $psid, 'name' => 'Ana Cruz', 'message' => $message, 'payload' => [], 'received_at' => now()]);
        if ($run) app(ChatbotRunner::class)->handle($chat->id);

        return $chat;
    }

    /** @return array<int, string> ang mga text na naipadala sa Botcake para sa PSID, ayon sa ayos */
    private function sent(string $psid = '7001'): array
    {
        return Http::recorded(fn (HttpRequest $r) => $r->url() === self::SEND && ($r->data()['psid'] ?? null) === $psid)
            ->map(fn ($p) => $p[0]->data()['data']['content']['messages'][0]['text'] ?? '')->values()->all();
    }

    private function pool(): ChatbotAiPool
    {
        return new ChatbotAiPool(function (RequestInterface $req, array $options) {
            $p = new Promise();
            $this->calls[] = ['req' => $req, 'p' => $p];

            return $p;
        });
    }

    private static function ai(string $text, int $status = 200, array $headers = []): Response
    {
        return new Response($status, $headers + ['Content-Type' => 'application/json'], json_encode($status === 200 ? ['choices' => [['message' => ['content' => $text]]]] : ['error' => ['message' => $text]]));
    }

    /** Ang tanong ng customer sa request (ang huling user message). */
    private static function question(array $call): string
    {
        $messages = json_decode((string) $call['req']->getBody(), true)['messages'];

        return (string) end($messages)['content'];
    }

    /** Sagutin ang lahat ng hindi pa nasasagot ("answer to <tanong>"). */
    private function answerAll(ChatbotAiPool $pool): void
    {
        foreach ($this->calls as $c) {
            if ($c['p']->getState() === 'pending') $c['p']->resolve(self::ai('answer to ' . self::question($c)));
        }
        $pool->tick(false);
    }

    private function events(string $type, string $psid = '7001'): array
    {
        return DB::table('chatbot_events')->where('psid', (int) $psid)->where('type', $type)->orderBy('id')->get()
            ->map(fn ($e) => ChatbotEvents::text($e->type, $e->node_id, (array) json_decode($e->detail, true)))->all();
    }

    private function rows()
    {
        return DB::table(ChatbotAiQueue::TABLE)->orderBy('id');
    }

    public function test_S_087_1_answers_that_arrive_out_of_order_each_go_to_their_own_customer(): void
    {
        $this->live();
        foreach (['7001', '7002', '7003'] as $psid) $this->says('question from ' . $psid, $psid);

        $this->assertSame(['pending', 'pending', 'pending'], $this->rows()->pluck('status')->all(), 'the run handed the request off');
        $this->assertSame([], Http::recorded(fn (HttpRequest $r) => $r->url() === self::SEND)->all(), 'nothing sent while waiting');
        $this->assertSame(['AI step n2 → waiting for the answer'], $this->events('ai_pending'));

        $pool = $this->pool();
        $this->assertTrue($pool->start());
        $pool->tick();
        $this->assertCount(3, $this->calls, 'all three in flight at the same time');

        // The last request is answered first.
        foreach (array_reverse($this->calls) as $c) {
            $c['p']->resolve(self::ai('answer to ' . self::question($c)));
            $pool->tick(false);
        }

        foreach (['7001', '7002', '7003'] as $psid) $this->assertSame(['Reply: answer to question from ' . $psid], $this->sent($psid));
        $order = Http::recorded(fn (HttpRequest $r) => $r->url() === self::SEND)->map(fn ($p) => $p[0]->data()['psid'])->values()->all();
        $this->assertSame(['7003', '7002', '7001'], $order);
        $this->assertSame(['resumed', 'resumed', 'resumed'], $this->rows()->pluck('status')->all());
        $this->assertNull((new CustomerFields(1001, 7001))->get(CustomerFields::AI));
    }

    public function test_S_087_2_a_ticket_that_does_not_match_the_run_sends_nothing_and_writes_ai_mismatch(): void
    {
        $flow = $this->live();
        $chat = $this->says('hi', '7001');
        $row = $this->rows()->first();
        DB::table(ChatbotAiQueue::TABLE)->where('id', $row->id)->update(['status' => 'answered', 'answer' => 'Secret answer for Ana']);
        Log::spy();

        // The answer's ticket names another customer than the run it resumes.
        (new ResumeChatbotAi(['request_id' => $row->request_id, 'page_id' => 1001, 'psid' => 7002, 'chat_id' => $chat->id, 'flow_id' => $flow->id, 'node_id' => 'n2']))
            ->handle(app(ChatbotRunner::class));

        $this->assertSame([], $this->sent('7001'));
        $this->assertSame([], $this->sent('7002'));
        $this->assertSame(['AI answer at step n2 stopped: it belongs to another customer (nothing sent)'], $this->events('ai_mismatch'));
        $this->assertNull((new CustomerFields(1001, 7001))->get('answer'), 'not even saved to a field');
        Log::shouldHaveReceived('error')->once()->withArgs(fn ($msg, $ctx) => str_contains($msg, 'mismatch')
            && $ctx['ticket_psid'] === '7002' && $ctx['run_psid'] === '7001' && !str_contains(json_encode($ctx), 'Secret'));
    }

    public static function staleCases(): array
    {
        return [
            'a human paused the bot' => [fn () => (new CustomerFields(1001, 7001))->set(CustomerFields::BOT_PAUSED, '1'), 'AI answer at step n2 dropped: the bot paused'],
            'the run moved on to another request' => [fn () => (new CustomerFields(1001, 7001))->set(CustomerFields::AI, 'another-request'), 'AI answer at step n2 dropped: the run moved on'],
        ];
    }

    /** @dataProvider staleCases */
    public function test_S_087_3_a_stale_answer_is_dropped_with_an_event_and_never_sent(\Closure $moveOn, string $event): void
    {
        $this->live();
        $this->says('hi');
        $pool = $this->pool();
        $pool->start();
        $pool->tick();

        $moveOn();
        $this->answerAll($pool);

        $this->assertSame([], $this->sent());
        $this->assertSame([$event], $this->events('ai_dropped'));
        $this->assertSame('resumed', $this->rows()->value('status'));
    }

    public function test_S_087_4_a_customers_second_chat_waits_for_the_pending_first(): void
    {
        $this->live();
        $earlier = $this->says('earlier', '7001', false);
        (new CustomerFields(1001, 7001))->set(CustomerFields::LAST_CHAT, (string) $earlier->id); // a known customer: both new chats run
        $this->says('first', '7001', false);
        $second = $this->says('second', '7001', false);

        // The second chat's job takes both in order: the first hands its AI request off, so the second waits.
        $this->assertSame('locked', app(ChatbotRunner::class)->handle($second->id), 'the newer chat waits while the answer is pending');
        $this->assertSame(1, $this->rows()->count());
        $this->assertSame('locked', app(ChatbotRunner::class)->handle($second->id), 'and keeps waiting on a retry');
        $pool = $this->pool();
        $pool->start();
        $pool->tick();
        $this->answerAll($pool);
        $this->assertSame(['Reply: answer to first'], $this->sent());

        $this->assertSame('done', app(ChatbotRunner::class)->handle($second->id));
        $pool->tick();
        $this->answerAll($pool);
        $this->assertSame(['Reply: answer to first', 'Reply: answer to second'], $this->sent());
    }

    public function test_S_087_5_no_answer_by_the_deadline_follows_the_failed_port_and_a_late_answer_is_ignored(): void
    {
        $this->live();
        $this->says('hi');
        $pool = $this->pool();
        $pool->start();
        $pool->tick();

        $this->travel(61)->seconds();
        Artisan::call('chatbot:resume-skipped'); // the minute tick sweeps even when the pool is gone
        $this->assertSame(['Sorry'], $this->sent());
        $this->assertSame(['AI step n2 → failed: OpenAI did not answer in time.'], $this->events('ai'));

        $this->answerAll($pool); // the answer arrives after all
        $this->assertSame(['Sorry'], $this->sent());
    }

    public function test_S_087_6_a_pool_restart_with_pending_requests_gives_no_double_send(): void
    {
        $this->live();
        $this->says('one', '7001');
        $this->says('two', '7002');
        $old = $this->pool();
        $old->start();
        $old->tick();
        $this->assertSame(['sending', 'sending'], $this->rows()->pluck('status')->all());
        $oldCalls = $this->calls;
        $this->calls = [];

        // The old pool dies without a word. A new one waits until its heartbeat is stale, then takes the requests back.
        $new = $this->pool();
        $this->assertFalse($new->start(), 'refuses while the other pool looks alive');
        $this->travel(11)->seconds();
        $this->assertTrue($new->start());
        $this->assertSame(['pending', 'pending'], $this->rows()->pluck('status')->all());
        $new->tick();
        $this->assertCount(2, $this->calls);
        $this->answerAll($new);

        // The dead pool's answers arrive late, and a lost resume job is sent again.
        foreach ($oldCalls as $c) $c['p']->resolve(self::ai('late answer'));
        $old->tick(false);
        foreach ($this->rows()->get() as $row) ResumeChatbotAi::dispatch(ChatbotAiQueue::ticket($row));

        $this->assertSame(['Reply: answer to one'], $this->sent('7001'));
        $this->assertSame(['Reply: answer to two'], $this->sent('7002'));
    }

    public function test_S_087_7_a_429_slows_the_pool_down_and_drops_nothing(): void
    {
        config(['services.chatbot.ai_concurrency' => 4]);
        $this->live();
        $this->says('hi');
        $pool = $this->pool();
        $pool->start();
        $pool->tick();

        $this->calls[0]['p']->resolve(self::ai('Rate limit', 429, ['Retry-After' => '2']));
        $pool->tick();
        $row = $this->rows()->first();
        $this->assertSame(['pending', null, 1], [$row->status, $row->error, (int) $row->attempts], 'back in the queue, not failed');
        $this->assertSame(2, $pool->limit(), 'half as many at a time');
        $this->assertCount(1, $this->calls, 'no new request before Retry-After');

        $this->travel(2)->seconds();
        $pool->tick();
        $this->assertCount(2, $this->calls);
        $this->answerAll($pool);
        $this->assertSame(['Reply: answer to hi'], $this->sent());
        $this->assertSame(3, $pool->limit(), 'grows back by one per answer');
    }

    public function test_S_087_8_with_ai_async_off_the_ai_step_waits_for_the_answer_as_before(): void
    {
        config(['services.chatbot.ai_async' => false]);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'Right away']]]])]);
        $this->live();
        $this->says('hi');

        $this->assertSame(['Reply: Right away'], $this->sent());
        $this->assertSame(0, $this->rows()->count());
        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://api.openai.com/'));
    }

    public function test_S_087_9_the_mock_ai_is_refused_on_a_real_page(): void
    {
        $this->assertSame(1, Artisan::call('botcake:stress-page', ['--page' => 'Page Uno', '--mock-ai' => true]));
        $this->assertStringContainsString('Refused', Artisan::output());

        // Even with the flag written straight into its settings, a real page's AI step goes to OpenAI.
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'mock_ai', '1', false, null, true);
        BotcakeMock::forget();
        $this->live();
        $this->says('hi');
        $pool = $this->pool();
        $pool->start();
        $pool->tick();
        $this->assertCount(1, $this->calls, 'a real HTTP request, not the fake AI');
        $this->answerAll($pool);
        $this->assertSame(['Reply: answer to hi'], $this->sent());
    }

    public function test_S_087_10_stats_counts_misrouted_ai_replies(): void
    {
        $key = tempnam(sys_get_temp_dir(), 'stress');
        $this->assertSame(0, Artisan::call('botcake:stress-page', ['--seed' => 3, '--key-file' => $key]));
        $this->assertSame(0, Artisan::call('botcake:stress-page', ['--mock-ai' => true, '--ai-latency' => 0]));
        @unlink($key);
        $this->live(self::MOCK_PAGE);
        $a = $this->says('hi', '9900000000000000', true, self::MOCK_ID, self::MOCK_PAGE);
        $b = $this->says('hello', '9900000000000001', true, self::MOCK_ID, self::MOCK_PAGE);
        $pool = $this->pool();
        $pool->start();
        $pool->tick();
        $pool->tick();
        $this->assertSame([], $this->calls, 'the fake AI makes no HTTP call');

        Artisan::call('botcake:stress-page', ['--stats' => true]);
        $out = Artisan::output();
        $this->assertMatchesRegularExpression('/Mock AI replies\s+2/', $out);
        $this->assertStringContainsString('misrouted: 0', $out);

        // A reply naming customer A that reaches customer B, and one naming B's psid with A's chat.
        $client = app(BotcakeClient::class)->shared(sha1('x'));
        $client->sendContent(self::MOCK_ID, '9900000000000001', [['type' => 'text', 'text' => 'Reply: MOCK AI for psid 9900000000000000 chat ' . $a->id]], 'x');
        $client->sendContent(self::MOCK_ID, '9900000000000001', [['type' => 'text', 'text' => 'Reply: MOCK AI for psid 9900000000000001 chat ' . $a->id]], 'x');
        Artisan::call('botcake:stress-page', ['--stats' => true, '--all' => true]);
        $this->assertStringContainsString('misrouted: 2', Artisan::output());
        $this->assertNotNull($b);
    }
}
