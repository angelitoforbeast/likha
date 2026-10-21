<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\RunChatbotFlow;
use App\Models\BotcakeChat;
use App\Models\BotcakePsid;
use App\Models\ChatbotFlow;
use App\Models\ChatbotReply;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotRunner;
use App\Services\Chatbot\ChatbotSheet;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowGraph;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Ang pagpapadala ng chatbot: send_content sa Botcake, ang talaan sa chatbot_replies, ang global switch, at ang
 * takbo kada chat (queue chatbot, isang takbo kada chat, sunod-sunod kada customer). Fake na HTTP at sheet.
 */
class ChatbotSendingTest extends ChatblastTestCase
{
    private const PSID = '7001';
    private const SEND = 'https://botcake.io/api/public_api/v1/pages/1001/flows/send_content';

    /** Isang fake na sheet: itinatala ang mga row, walang tawag sa Google. */
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
        ]]);
        config(['services.chatbot.replies_enabled' => true]);
        $this->page('Page Uno', '1001');
        $s = app(PageSettings::class);
        $s->set('Page Uno', 'chatbot', 'picture', 'https://img.example.test/p.jpg');
        $test = $this;
        $this->app->instance(ChatbotSheet::class, new class($test) extends ChatbotSheet {
            public function __construct(private $test) {}
            public function append(string $spreadsheetId, string $tab, array $row): ?string { $this->test->rowWritten([$spreadsheetId, $tab, $row]); return null; }
        });
    }

    public function rowWritten(array $row): void
    {
        $this->rows[] = $row;
    }

    private function flow(array $nodes, array $edges, string $name = 'Main'): ChatbotFlow
    {
        $flow = ChatbotFlow::create(['name' => $name, 'graph' => FlowGraph::clean(['nodes' => $nodes, 'edges' => $edges])]);
        app(ChatbotPage::class)->link('Page Uno', $flow);

        return $flow;
    }

    private function greeting(): ChatbotFlow
    {
        return $this->flow([
            ['id' => 'n1', 'type' => 'start'],
            ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'image', 'value' => '{{PICTURE}}'], ['type' => 'text', 'value' => 'You said: {{last_input}}']]]],
        ], [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]);
    }

    private function chat(string $message, string $psid = self::PSID): BotcakeChat
    {
        return BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => $psid, 'name' => 'Ana Cruz',
            'message' => $message, 'payload' => [], 'received_at' => now()]);
    }

    public function test_S_069_S1_a_send_node_calls_send_content_with_the_exact_body_and_logs_it(): void
    {
        Http::fake([self::SEND => Http::response(['success' => true])]);
        $flow = $this->greeting();
        $chat = $this->chat('Hello');

        app(ChatbotRunner::class)->handle($chat->id);

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::SEND && $r->method() === 'POST' && $r->hasHeader('access-token', self::TOKEN)
            && $r->data() === ['psid' => '7001', 'data' => ['version' => 'v2', 'content' => ['messages' => [
                ['type' => 'image', 'url' => 'https://img.example.test/p.jpg'], ['type' => 'text', 'text' => 'You said: Hello'],
            ]]]]);
        $reply = ChatbotReply::sole();
        $this->assertSame([1001, 7001, $chat->id, $flow->id, 'n2', 'send', 'sent', null], [$reply->page_id, $reply->psid, $reply->chat_id, $reply->flow_id, $reply->node_id, $reply->kind, $reply->status, $reply->error]);
        $this->assertSame([['type' => 'image', 'url' => 'https://img.example.test/p.jpg'], ['type' => 'text', 'text' => 'You said: Hello']], $reply->content);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($reply->toArray()));
    }

    public function test_S_069_S2_with_replies_switched_off_the_run_happens_but_nothing_is_sent_or_written(): void
    {
        config(['services.chatbot.replies_enabled' => false]);
        Http::fake();
        $this->flow([
            ['id' => 'n1', 'type' => 'start'],
            ['id' => 'n2', 'type' => 'set', 'config' => ['ops' => [['op' => 'set', 'field' => 'status', 'value' => 'LOOP 1']]]],
            ['id' => 'n3', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Hi']]]],
            ['id' => 'n4', 'type' => 'sheet', 'config' => ['sheet' => '1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789', 'tab' => 'Orders', 'columns' => '{{last_input}}']],
        ], [['from' => 'n1', 'port' => 'next', 'to' => 'n2'], ['from' => 'n2', 'port' => 'next', 'to' => 'n3'], ['from' => 'n3', 'port' => 'continue', 'to' => 'n4']]);

        app(ChatbotRunner::class)->handle($this->chat('Hello')->id);

        Http::assertNothingSent();
        $this->assertSame([], $this->rows);
        $this->assertSame([['send', 'skipped', 'Replies are switched off'], ['sheet', 'skipped', 'Replies are switched off']],
            ChatbotReply::orderBy('id')->get()->map(fn ($r) => [$r->kind, $r->status, $r->error])->all());
        $this->assertSame(['sheet' => '1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789', 'tab' => 'Orders', 'row' => ["'Hello"]], ChatbotReply::where('kind', 'sheet')->sole()->content);
        $this->assertSame('LOOP 1', (new CustomerFields(1001, 7001))->get('status'), 'ang takbo ay nangyayari pa rin');
    }

    public function test_S_069_S3_a_failed_send_is_logged_and_stops_the_run_and_a_live_sheet_row_is_written(): void
    {
        Http::fake([self::SEND => Http::sequence()->push(['success' => true])->push(['success' => false, 'message' => 'Outside the 24h window'])]);
        $this->flow([
            ['id' => 'n1', 'type' => 'start'],
            ['id' => 'n2', 'type' => 'sheet', 'config' => ['sheet' => 'https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789/edit#gid=0', 'tab' => 'Orders', 'columns' => "{{full_name}}\n{{last_input}}"]],
            ['id' => 'n3', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'One']]]],
            ['id' => 'n4', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Two']]]],
            ['id' => 'n5', 'type' => 'set', 'config' => ['ops' => [['op' => 'set', 'field' => 'after', 'value' => 'x']]]],
        ], [['from' => 'n1', 'port' => 'next', 'to' => 'n2'], ['from' => 'n2', 'port' => 'next', 'to' => 'n3'], ['from' => 'n3', 'port' => 'continue', 'to' => 'n4'], ['from' => 'n4', 'port' => 'continue', 'to' => 'n5']]);

        app(ChatbotRunner::class)->handle($this->chat('=HYPERLINK("x")')->id);

        $this->assertSame([['1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789', 'Orders', ["'Ana Cruz", "'=HYPERLINK(\"x\")"]]], $this->rows);
        $this->assertSame([['sheet', 'sent', null], ['send', 'sent', null], ['send', 'failed', 'Botcake said "Outside the 24h window" (HTTP 200)']],
            ChatbotReply::orderBy('id')->get()->map(fn ($r) => [$r->kind, $r->status, $r->error])->all());
        $this->assertNull((new CustomerFields(1001, 7001))->get('after'), 'huminto ang takbo sa pumalyang padala');
    }

    public function test_S_069_S4_a_stored_chat_queues_a_run_on_the_chatbot_queue_only_for_a_page_with_a_flow(): void
    {
        Queue::fake();
        $s = app(PageSettings::class);
        $s->set('Page Uno', 'botcake', 'chat.key', 'page-key-uno-test-a1s2d3f4g5h6j7k8l9', true, null, true);
        BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Ana Cruz', 'psid' => self::PSID, 'status' => 'available', 'source' => 'api']);
        $post = fn (string $text) => $this->withServerVariables(['REMOTE_ADDR' => '203.171.22.6'])->postJson('/api/botcake/chat',
            ['page_id' => '1001', 'psid' => self::PSID, 'name' => 'Ana Cruz', 'message' => $text], ['X-Chat-Key' => 'page-key-uno-test-a1s2d3f4g5h6j7k8l9'])->assertOk();

        $post('no flow yet');
        Queue::assertNotPushed(RunChatbotFlow::class); // ang MarkChatbotReplied (Results) ay laging naipapasa

        $this->greeting();
        $post('with a flow');
        Queue::assertPushedOn('chatbot-high', RunChatbotFlow::class, fn ($job) => $job->chatId === BotcakeChat::where('message', 'with a flow')->value('id'));

        app(ChatbotPage::class)->link('Page Uno', null);
        $s->set('Page Uno', 'chatbot', 'flow', 'No Such Flow'); // FLOW text lang, walang flow_id: walang takbo
        $post('flow name unknown');
        Queue::assertPushed(RunChatbotFlow::class, 1);
    }

    public function test_S_069_S5_a_chat_is_run_at_most_once_and_a_customers_chats_run_in_order(): void
    {
        Http::fake([self::SEND => Http::response(['success' => true])]);
        $this->greeting();
        app(ChatbotRunner::class)->handle($this->chat('hello')->id); // nakausap na ng bot ang customer (may marker na)
        $first = $this->chat('first');
        $second = $this->chat('second');
        $other = $this->chat('other customer', '7002');

        app(ChatbotRunner::class)->handle($second->id); // dumating muna ang job ng pangalawa: tumatakbo ang dalawa, sunod-sunod
        app(ChatbotRunner::class)->handle($first->id);  // tapos na: walang ginagawa
        app(ChatbotRunner::class)->handle($second->id); // ulit (retry): walang ginagawa
        (new RunChatbotFlow($other->id))->handle(app(ChatbotRunner::class));

        $texts = ChatbotReply::orderBy('id')->get()->map(fn ($r) => [$r->psid, $r->content[1]['text']])->all();
        $this->assertSame([[7001, 'You said: hello'], [7001, 'You said: first'], [7001, 'You said: second'], [7002, 'You said: other customer']], $texts);
        $this->assertSame((string) $second->id, (new CustomerFields(1001, 7001))->get(CustomerFields::LAST_CHAT));
    }

    public function test_S_069_S6_a_customer_already_running_waits_and_a_page_switched_off_since_does_not_run(): void
    {
        Http::fake([self::SEND => Http::response(['success' => true])]);
        $this->greeting();
        $chat = $this->chat('hi');

        $lock = Cache::lock('chatbot-run:1001:7001', 60);
        $lock->get();
        $this->assertSame('locked', app(ChatbotRunner::class)->handle($chat->id));
        $lock->release();

        app(PageSettings::class)->set('Page Uno', 'chatbot', 'active', '0', false, null, true);
        $this->assertSame('done', app(ChatbotRunner::class)->handle($chat->id));
        Http::assertNothingSent();
        $this->assertSame((string) $chat->id, (new CustomerFields(1001, 7001))->get(CustomerFields::LAST_CHAT));
    }
}
