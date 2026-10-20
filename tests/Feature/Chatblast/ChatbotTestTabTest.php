<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotRunner;
use App\Services\Chatbot\ChatbotSheet;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowGraph;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Ang Test tab ng /chatbot (spec 091): ang skip timer ay tumatakbo gaya ng live (countdown, Skip now, walang Botcake at
 * walang chatbot_waits), ang "Write sheet rows for real", at ang alaala ng usapan ng test customer para sa AI step.
 * Fake na OpenAI at Google Sheet.
 */
class ChatbotTestTabTest extends ChatblastTestCase
{
    /** @var array<int, array{0: string, 1: string, 2: array}> ang mga row na isinulat sa fake na sheet */
    public array $appended = [];
    public ?\Throwable $sheetError = null;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_25_100000_create_chatbot_waits_table.php',
        ]]);
        $this->page('Page Uno', '1001');
        config(['services.openai.key' => 'sk-test-not-real', 'services.chatbot.replies_enabled' => true, 'services.chatbot.sheet_account' => 'likha-bot@likha.iam.gserviceaccount.com']);
        $test = $this;
        $this->app->instance(ChatbotSheet::class, new class($test) extends ChatbotSheet {
            public function __construct(private $test) {}
            public function append(string $spreadsheetId, string $tab, array $row): ?string
            {
                if ($this->test->sheetError) throw $this->test->sheetError;
                $this->test->appended[] = [$spreadsheetId, $tab, $row];
                return "'" . $tab . "'!A42:B42";
            }
        });
        Http::fake(['botcake.io/*' => Http::response(['success' => true])]);
        $this->actingAs($this->user());
    }

    private function flow(array $nodes, array $edges): ChatbotFlow
    {
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::clean(['nodes' => $nodes, 'edges' => $edges])]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);
        app(ChatbotPage::class)->link('Page Uno', $flow);

        return $flow->fresh();
    }

    /** n2 asks and waits (skips after 1 minute): reply → n3, skipped → n4. */
    private function askFlow(): ChatbotFlow
    {
        return $this->flow([
            ['id' => 'n1', 'type' => 'start'],
            ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Want to order?']], 'wait' => true, 'save_to' => 'answer', 'skip_after' => ['amount' => 1, 'unit' => 'minutes']]],
            ['id' => 'n3', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Thanks: {{answer}}']]]],
            ['id' => 'n4', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Still there?']]]],
        ], [['from' => 'n1', 'port' => 'next', 'to' => 'n2'], ['from' => 'n2', 'port' => 'reply', 'to' => 'n3'], ['from' => 'n2', 'port' => 'skipped', 'to' => 'n4']]);
    }

    private function send(string $message, array $extra = []): array
    {
        return $this->postJson('/chatbot/test/send', ['page_name' => 'Page Uno', 'message' => $message, 'version' => 'published'] + $extra)->assertOk()->json();
    }

    private function skip(bool $now = false): array
    {
        return $this->postJson('/chatbot/test/skip', ['page_name' => 'Page Uno', 'version' => 'published', 'now' => $now])->assertOk()->json();
    }

    private static function texts(array $res): array
    {
        return array_merge(...array_map(fn ($s) => array_column($s['messages'], 'text'), $res['sends'] ?? []) ?: [[]]);
    }

    public function test_S_091_1_a_test_wait_with_a_skip_time_takes_the_skipped_port_when_the_time_is_up_without_botcake_or_real_customers(): void
    {
        $this->askFlow();
        (new CustomerFields(1001, 7001))->set('answer', 'REAL');

        $res = $this->send('Hi');
        $this->assertSame(['Want to order?'], self::texts($res));
        $this->assertSame(60, $res['skip_in']);
        $this->assertSame(0, DB::table('chatbot_waits')->count(), 'a test wait never goes to the minute tick');

        $this->travel(30)->seconds();
        $res = $this->skip();
        $this->assertSame([false, 30], [$res['ran'], $res['skip_in']]);

        Artisan::call('chatbot:resume-skipped'); // the live tick has nothing to do with it
        $this->travel(31)->seconds();
        $res = $this->skip();
        $this->assertTrue($res['ran']);
        $this->assertSame(['Still there?'], self::texts($res));
        $this->assertContains('skipped', array_column($res['path'], 'port'));
        $this->assertNull($res['skip_in']);

        Http::assertNothingSent();
        $this->assertSame('REAL', (new CustomerFields(1001, 7001))->get('answer'), 'a real customer is never touched');
        $this->assertSame(0, \App\Models\ChatbotReply::count());
    }

    public function test_S_091_2_a_reply_before_the_time_cancels_the_skip(): void
    {
        $this->askFlow();
        $this->send('Hi');
        $res = $this->send('Yes');
        $this->assertSame(['Thanks: Yes'], self::texts($res));
        $this->assertNull($res['skip_in']);

        $this->travel(2)->minutes();
        $this->assertSame(['ran' => false, 'skip_in' => null], array_intersect_key($this->skip(), ['ran' => 1, 'skip_in' => 1]));
    }

    public function test_S_091_3_skip_now_fires_at_once_and_reset_clears_the_timer(): void
    {
        $this->askFlow();
        $this->send('Hi');
        $res = $this->skip(true);
        $this->assertSame(['Still there?'], self::texts($res));

        $this->send('Hi again'); // a new run from the start: waiting again
        $this->postJson('/chatbot/test/reset', ['page_name' => 'Page Uno'])->assertOk();
        $this->assertFalse($this->skip(true)['ran'], 'reset cleared the test wait');
    }

    public function test_S_091_4_the_sheet_row_is_only_shown_unless_write_sheet_rows_for_real_is_on(): void
    {
        $this->flow([['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'sheet', 'config' => ['sheet' => 'https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789/edit', 'tab' => 'All Orders', 'columns' => ['{{full_name}}', '{{last_input}}']]]],
            [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]);

        $off = $this->send('09171234567');
        $this->assertSame([], $this->appended);
        $this->assertSame('Sheet row (not written): All Orders', $off['sheet_rows'][0]['note']);

        $on = $this->send('09171234567', ['write_sheet' => true]);
        $this->assertSame([['1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789', 'All Orders', ["'Test Customer", "'09171234567"]]], $this->appended);
        $this->assertSame("Sheet row written: All Orders, row 42 ('All Orders'!A42:B42)", $on['sheet_rows'][0]['note']);
        Http::assertNothingSent(); // the Botcake sends stay simulated

        $this->sheetError = new \Google\Service\Exception('{"error":{"code":403,"message":"The caller does not have permission"}}', 403);
        $err = $this->send('again', ['write_sheet' => true]);
        $this->assertSame('The service account can\'t open this spreadsheet. Share it with likha-bot@likha.iam.gserviceaccount.com as Editor, then try again.', $err['sheet_rows'][0]['error']);
        $this->assertStringNotContainsString('caller', json_encode($err));

        $this->sheetError = new \Google\Service\Exception('{"error":{"code":400,"message":"Unable to parse range: \'All Orders\'!A1"}}', 400);
        $this->assertSame('The tab "All Orders" was not found in the spreadsheet. Check the tab name in the Sheet step.', $this->send('again', ['write_sheet' => true])['sheet_rows'][0]['error']);
    }

    public function test_S_091_5_an_ai_step_in_test_remembers_the_test_conversation_and_reset_forgets_it(): void
    {
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => 'Noted po']]]])
            ->push(['choices' => [['message' => ['content' => 'Your number is 0917']]]])
            ->push(['choices' => [['message' => ['content' => 'Hello']]]])
            ->push(['choices' => [['message' => ['content' => 'Real answer']]]])]);
        $this->flow([['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'ai', 'config' => ['user' => '{{last_input}}', 'send' => true, 'history' => 10]]],
            [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]);
        $requests = fn () => Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'api.openai.com'))->map(fn ($p) => $p[0]->data()['messages'])->values()->all();

        $this->send('My number is 0917');
        $this->send('What is my number?');
        $this->assertSame([['role' => 'user', 'content' => 'My number is 0917'], ['role' => 'assistant', 'content' => 'Noted po'], ['role' => 'user', 'content' => 'What is my number?']], $requests()[1]);

        $this->postJson('/chatbot/test/reset', ['page_name' => 'Page Uno'])->assertOk();
        $this->send('Hi');
        $this->assertSame([['role' => 'user', 'content' => 'Hi']], $requests()[2], 'reset forgets the test conversation');

        // A real customer's AI step never sees the test conversation.
        $chat = BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => '7001', 'name' => 'Ana', 'message' => 'Hello there', 'payload' => [], 'received_at' => now()]);
        app(ChatbotRunner::class)->handle($chat->id);
        $this->assertSame([['role' => 'user', 'content' => 'Hello there']], $requests()[3]);
    }

    public function test_S_091_6_the_page_shows_the_new_banner_the_sheet_checkbox_and_the_skip_controls(): void
    {
        $this->askFlow();
        $this->get('/chatbot/test')->assertOk()
            ->assertSee("Nothing is sent to Botcake. Sheet rows are only shown, unless 'Write sheet rows for real' is on. Skip timers run as live.", false)
            ->assertSee('id="t-write-sheet"', false)->assertSee('Write sheet rows for real')
            ->assertSee('id="t-skip-now"', false)->assertSee(route('chatbot.test.skip'), false);
    }
}
