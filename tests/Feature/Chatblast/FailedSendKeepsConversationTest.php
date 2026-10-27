<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Services\Chatbot\BotcakeSendPool;
use App\Services\Chatbot\ChatbotEvents;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotRunner;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowGraph;
use App\Services\PageSettings;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;

/**
 * Ang padalang tinanggihan ng Botcake (HTTP 5xx) o walang sagot ay inuulit nang isang beses pagkalipas ng ~3 s, at ang
 * padalang pumalya pa rin ay hindi na humihinto sa takbo: tuloy sa susunod na node (o naka-park ang wait), kaya ang
 * susunod na mensahe ng customer ay hindi nagsisimula ulit sa Start. Fake na Botcake (handler na hawak ng test) at orasan.
 */
class FailedSendKeepsConversationTest extends ChatblastTestCase
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
        $this->page('Page Uno', '1001');
        app(PageSettings::class)->set('Page Uno', 'botcake', 'token', 'tok-1001');
        $this->pool = new BotcakeSendPool(function (RequestInterface $req, array $options) {
            $p = new Promise();
            $this->calls[] = ['req' => $req, 'p' => $p];

            return $p;
        }, $this->clock);
        $this->assertTrue($this->pool->start());
    }

    private function live(array $nodes, array $edges): ChatbotFlow
    {
        $flow = ChatbotFlow::create(['name' => 'Flow ' . uniqid(), 'graph' => FlowGraph::clean(['nodes' => $nodes, 'edges' => $edges])]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);
        app(ChatbotPage::class)->link('Page Uno', $flow);

        return $flow->fresh();
    }

    private static function text(string $id, string $text, bool $wait = false): array
    {
        return ['id' => $id, 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => $text]]] + ($wait ? ['wait' => true] : [])];
    }

    private static function set(string $id, string $field): array
    {
        return ['id' => $id, 'type' => 'set', 'config' => ['ops' => [['op' => 'set', 'field' => $field, 'value' => 'yes']]]];
    }

    private static function edge(string $from, string $port, string $to): array
    {
        return ['from' => $from, 'port' => $port, 'to' => $to];
    }

    /** start → n2 "Hello" → n3 sets done = yes. */
    private function helloFlow(): ChatbotFlow
    {
        return $this->live([['id' => 'n1', 'type' => 'start'], self::text('n2', 'Hello'), self::set('n3', 'done')],
            [self::edge('n1', 'next', 'n2'), self::edge('n2', 'continue', 'n3')]);
    }

    private function says(string $message, string $psid = '7001'): void
    {
        $chat = BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => $psid, 'name' => 'Ana Cruz', 'message' => $message, 'payload' => [], 'received_at' => now()]);
        app(ChatbotRunner::class)->handle($chat->id);
    }

    /** Ang susunod na padala ng pool, sinagot ng $status (HTTP status na may body; null = walang sagot). */
    private function next(?int $status, array $body = ['success' => true]): void
    {
        $this->clock->time += 1; // the page rate (2/s) lets the next send go
        $before = count($this->calls);
        $this->pool->tick();
        $this->assertCount($before + 1, $this->calls, 'the pool sent the next message');
        $p = end($this->calls)['p'];
        if ($status === null) $p->reject(new \RuntimeException('cURL error 28: timed out'));
        else $p->resolve(new Response($status, ['Content-Type' => 'application/json'], json_encode($body)));
        $this->pool->tick(false);
    }

    /** Lampas sa ~3 s na hinihintay ng ulit. */
    private function later(): void
    {
        $this->travel(4)->seconds();
        $this->clock->time += 4;
    }

    private function sentTexts(): array
    {
        return array_map(fn ($c) => (string) (json_decode((string) $c['req']->getBody(), true)['data']['content']['messages'][0]['text'] ?? ''), $this->calls);
    }

    private function replies(string $psid = '7001'): array
    {
        return DB::table('chatbot_replies')->where('psid', (int) $psid)->orderBy('id')->get(['status', 'content', 'error'])
            ->map(fn ($r) => $r->status . ': ' . (json_decode($r->content, true)[0]['text'] ?? '') . ($r->error ? ' (' . $r->error . ')' : ''))->all();
    }

    private function events(string $type): array
    {
        return DB::table('chatbot_events')->where('type', $type)->orderBy('id')->get()
            ->map(fn ($e) => ChatbotEvents::text($e->type, $e->node_id, (array) json_decode($e->detail, true)))->all();
    }

    private function fields(string $psid = '7001'): CustomerFields
    {
        return new CustomerFields(1001, (int) $psid);
    }

    public static function retryable(): array
    {
        return [
            'HTTP 500' => [500, ['success' => false], 'Botcake refused it (HTTP 500)', 'HTTP 500'],
            'HTTP 502 with a message' => [502, ['message' => 'Bad gateway'], 'Botcake said "Bad gateway" (HTTP 502)', 'HTTP 502'],
            'no answer' => [null, [], 'Botcake did not answer (HTTP 0)', 'no answer'],
        ];
    }

    #[DataProvider('retryable')]
    public function test_S_168_1_a_send_refused_with_5xx_or_no_answer_is_sent_once_more_after_3_s_and_the_run_goes_on(?int $status, array $body, string $error, string $why): void
    {
        $this->helloFlow();
        $this->says('hi');
        $this->next($status, $body);

        $this->pool->tick();
        $this->assertCount(1, $this->calls, 'the retry waits ~3 s');
        $this->assertNull($this->fields()->get('done'), 'the run waits for the retry');

        $this->later();
        $this->next(200);

        $this->assertSame(['Hello', 'Hello'], $this->sentTexts());
        $this->assertSame(['failed: Hello (' . $error . ')', 'sent: Hello'], $this->replies());
        $this->assertSame(['Send at step n2 retried after ' . $why], $this->events('send_retry'));
        $this->assertSame('yes', $this->fields()->get('done'), 'the run continued normally');
    }

    public function test_S_168_2_a_send_that_fails_twice_goes_on_to_the_next_wait_and_the_next_message_resumes_there(): void
    {
        $this->live([['id' => 'n1', 'type' => 'start'], self::text('n2', 'Picture'), self::text('n3', 'What size?', true), self::set('n4', 'answered')],
            [self::edge('n1', 'next', 'n2'), self::edge('n2', 'continue', 'n3'), self::edge('n3', 'reply', 'n4')]);
        $this->says('hi');
        $this->next(500, ['success' => false]);
        $this->later();
        $this->next(500, ['success' => false]);
        $this->next(200);

        $this->assertSame(['Picture', 'Picture', 'What size?'], $this->sentTexts());
        $this->assertSame(['failed: Picture (Botcake refused it (HTTP 500))', 'failed: Picture (Botcake refused it (HTTP 500))', 'sent: What size?'], $this->replies());
        $flowId = (int) DB::table('chatbot_flows')->value('id');
        $this->assertSame($flowId . ':n3', $this->fields()->get(CustomerFields::WAIT), 'the wait is parked at n3');

        $this->says('large');
        $this->assertSame('yes', $this->fields()->get('answered'), 'the next message resumed at n3, not at the start');
        $this->assertSame(['Picture', 'Picture', 'What size?'], $this->sentTexts(), 'the opening is not sent again');
    }

    public function test_S_168_3_a_wait_send_that_fails_twice_parks_the_wait_at_itself(): void
    {
        $this->live([['id' => 'n1', 'type' => 'start'], self::text('n2', 'What size?', true), self::set('n3', 'answered')],
            [self::edge('n1', 'next', 'n2'), self::edge('n2', 'reply', 'n3')]);
        $this->says('hi');
        $this->next(null);
        $this->later();
        $this->next(503, ['success' => false]);

        $flowId = (int) DB::table('chatbot_flows')->value('id');
        $this->assertSame($flowId . ':n2', $this->fields()->get(CustomerFields::WAIT));
        $this->says('large');
        $this->assertSame('yes', $this->fields()->get('answered'));
        $this->assertCount(2, $this->calls, 'nothing sent again on the reply');
    }

    public static function notRetryable(): array
    {
        return [
            '#551 on HTTP 500' => [500, ['success' => false, 'message' => '(#551) This person isn\'t available right now.']],
            '#10 on HTTP 200' => [200, ['success' => false, 'message' => '(#10) This message is sent outside of allowed window.']],
            'HTTP 400' => [400, ['message' => 'Bad request']],
            'HTTP 404' => [404, ['success' => false]],
        ];
    }

    #[DataProvider('notRetryable')]
    public function test_S_168_4_a_facebook_error_or_4xx_is_not_retried_and_the_run_still_goes_on(int $status, array $body): void
    {
        $this->helloFlow();
        $this->says('hi');
        $this->next($status, $body);
        $this->later();
        $this->pool->tick();

        $this->assertCount(1, $this->calls, 'not sent again');
        $this->assertSame([], $this->events('send_retry'));
        $this->assertCount(1, $this->replies());
        $this->assertStringStartsWith('failed: Hello', $this->replies()[0]);
        $this->assertSame('yes', $this->fields()->get('done'), 'the run went on past the failed send');
    }

    public function test_S_168_5_a_node_with_a_failed_edge_still_follows_it_and_its_send_gets_the_one_retry(): void
    {
        // The AI step: its failed port is for the AI answer; the answer's send gets the one retry and then goes on "success".
        Http::fake(['api.openai.com/*' => Http::sequence()->push(['choices' => [['message' => ['content' => 'AI answer']]]])->push(['error' => ['message' => 'no']], 500)]);
        $this->live([['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'ai', 'config' => ['user' => '{{last_input}}', 'send' => true]],
            self::set('n3', 'after ai'), self::set('n4', 'ai failed')],
            [self::edge('n1', 'next', 'n2'), self::edge('n2', 'success', 'n3'), self::edge('n2', 'failed', 'n4')]);
        $this->says('q');
        $this->next(500, ['success' => false]);
        $this->later();
        $this->next(500, ['success' => false]);
        $this->later();
        $this->pool->tick();

        $this->assertSame(['AI answer', 'AI answer'], $this->sentTexts(), 'one retry, no more');
        $this->assertSame(['Send at step n2 retried after HTTP 500'], $this->events('send_retry'));
        $this->assertSame('yes', $this->fields()->get('after ai'));

        // The AI answer itself fails: the failed edge is followed, as before.
        $this->fields()->clearAll();
        $this->says('q2');
        $this->assertSame('yes', $this->fields()->get('ai failed'));
        $this->assertCount(2, $this->calls);
    }

    public function test_S_168_6_the_reported_path_reaches_the_wait_after_a_text_fails_twice(): void
    {
        // n118 image ok, n119 text ok, n120 text 500 twice → n10 (send with wait) is reached and parked.
        $this->live([['id' => 'n1', 'type' => 'start'],
            ['id' => 'n118', 'type' => 'send', 'config' => ['items' => [['type' => 'image', 'value' => 'https://example.com/a.jpg']]]],
            self::text('n119', 'Price list'), self::text('n120', 'Free shipping'), self::text('n10', 'Order now?', true), self::set('n11', 'answered')],
            [self::edge('n1', 'next', 'n118'), self::edge('n118', 'continue', 'n119'), self::edge('n119', 'continue', 'n120'),
                self::edge('n120', 'continue', 'n10'), self::edge('n10', 'reply', 'n11')]);
        $this->says('hi');
        $this->next(200);
        $this->next(200);
        $this->next(500, ['success' => false]);
        $this->later();
        $this->next(500, ['success' => false]);
        $this->next(200);

        $flowId = (int) DB::table('chatbot_flows')->value('id');
        $this->assertSame($flowId . ':n10', $this->fields()->get(CustomerFields::WAIT));
        $this->says('magkano po?');
        $this->assertSame('yes', $this->fields()->get('answered'));
        $this->assertCount(5, $this->calls, 'the opening picture is not sent again');
    }
}
