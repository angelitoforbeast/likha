<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowEngine;
use App\Services\Chatbot\FlowGraph;
use App\Services\Chatbot\FlowRun;
use App\Services\Chatbot\TestOutbox;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Ang {{ALL USER INPUT}}: ang mga mensahe ng customer sa page na ito (botcake_chats), pinakaluma muna, hanggang sa
 * kasalukuyang chat, gaya ng Chatbot Builder. Fake na OpenAI; ang sheet row ay sa TestOutbox.
 */
class ChatbotAllUserInputTest extends ChatblastTestCase
{
    /** Isang flow na gumagamit ng variable (binabasa lang ang mga chat kapag ginagamit). */
    private const USES = ['nodes' => [['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'ai', 'config' => ['user' => '{{ALL USER INPUT}}']]], 'edges' => []];
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
        ]]);
    }

    private function chat(string $m, string $psid = '7001', string $page = '1001'): BotcakeChat
    {
        return BotcakeChat::create(['page_id' => $page, 'page_name' => 'Page Uno', 'psid' => $psid, 'name' => 'Ana Cruz', 'message' => $m, 'payload' => [], 'received_at' => now()]);
    }

    private function flowRun(BotcakeChat $current, array $botfields = [], array $graph = self::USES): FlowRun
    {
        return new FlowRun(1, FlowGraph::clean($graph), 'Page Uno', '1001', $botfields, new CustomerFields(1001, 7001), $current->id, (string) $current->message, 'Ana Cruz', '2026-10-20 10:00');
    }

    public function test_S_093_1_lists_this_customers_messages_on_this_page_in_order_up_to_the_current_chat(): void
    {
        $this->chat('hi');
        $this->chat('other customer', '7002');
        $this->chat('other page', '7001', '2002');
        $this->chat('[Photo]');
        $current = $this->chat('09171234567');
        $this->chat('later, after this chat');

        $this->assertSame("hi\n[Photo]\n09171234567", $this->flowRun($current)->text('{{ALL USER INPUT}}'));
        $this->assertSame("hi\n[Photo]\n09171234567", $this->flowRun($current)->text('{{ all_user_input }}'), 'case and _ do not matter');
    }

    public function test_S_093_2_the_50_messages_and_4000_characters_limits_keep_the_newest(): void
    {
        for ($i = 1; $i <= 60; $i++) $this->chat("m{$i}");
        $current = $this->chat('m61');
        $expected = implode("\n", array_map(fn ($i) => "m{$i}", range(12, 61)));
        $this->assertSame($expected, $this->flowRun($current)->text('{{ALL USER INPUT}}'), 'the newest 50');

        $this->chat(str_repeat('a', 3000));
        $current = $this->chat(str_repeat('b', 3000));
        $text = $this->flowRun($current)->text('{{ALL USER INPUT}}');
        $this->assertSame(4000, mb_strlen($text));
        $this->assertStringEndsWith(str_repeat('b', 3000), $text, 'the newest characters stay');
    }

    public function test_S_093_3_a_non_empty_field_or_botfield_named_all_user_input_takes_precedence(): void
    {
        $this->chat('hi');
        $current = $this->chat('order po');
        $this->assertSame('from botfield', $this->flowRun($current, ['ALL USER INPUT' => 'from botfield'])->text('{{ALL USER INPUT}}'));

        (new CustomerFields(1001, 7001))->set('ALL USER INPUT', 'from field');
        $this->assertSame('from field', $this->flowRun($current)->text('{{ALL USER INPUT}}'));

        (new CustomerFields(1001, 7001))->set('ALL USER INPUT', '  ');
        $this->assertSame("hi\norder po", $this->flowRun($current, ['ALL USER INPUT' => ''])->text('{{ALL USER INPUT}}'), 'an empty one does not');
    }

    public function test_S_093_4_works_in_an_ai_user_prompt_and_in_a_sheet_cell_with_one_query_per_run(): void
    {
        config(['services.openai.key' => 'sk-test-not-real']);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '09171234567']]]])]);
        $this->chat('=HYPERLINK("x")');
        $current = $this->chat('09171234567 po');
        $graph = ['nodes' => [['id' => 'n1', 'type' => 'start'],
            ['id' => 'n2', 'type' => 'ai', 'config' => ['system' => 'extract the phone number', 'user' => '{{ALL USER INPUT}}', 'history' => 0, 'save_to' => 'gpt phone number']],
            ['id' => 'n3', 'type' => 'sheet', 'config' => ['sheet' => 'https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789/edit', 'tab' => 'Orders',
                'columns' => ['{{gpt phone number}}', '{{ALL USER INPUT}}', '{{all user input}}']]]],
            'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2'], ['from' => 'n2', 'port' => 'success', 'to' => 'n3']]];
        $run = $this->flowRun($current, [], $graph);

        DB::enableQueryLog();
        app(FlowEngine::class)->run($run, new TestOutbox());
        $reads = array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'botcake_chats'));
        DB::disableQueryLog();

        Http::assertSent(fn (HttpRequest $r) => $r['messages'] === [['role' => 'system', 'content' => 'extract the phone number'], ['role' => 'user', 'content' => "=HYPERLINK(\"x\")\n09171234567 po"]]);
        $this->assertSame(["'09171234567", "'=HYPERLINK(\"x\")\n09171234567 po", "'=HYPERLINK(\"x\")\n09171234567 po"], $run->sheetRows[0]['row'] ?? null);
        $this->assertCount(1, $reads, 'one read of botcake_chats for the whole run');
    }

    public function test_S_093_5_a_test_run_uses_the_current_test_message(): void
    {
        $this->chat('a real chat of PSID 0', '0');
        $run = new FlowRun(1, FlowGraph::clean(self::USES), 'Page Uno', '1001', [],
            new CustomerFields(1001, CustomerFields::TEST_PSID), null, 'test message', 'Test Customer', '2026-10-20 10:00', true);

        $this->assertSame('test message', $run->text('{{ALL USER INPUT}}'));
    }

    public function test_S_093_6_the_editor_shows_the_chip_with_its_tooltip(): void
    {
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::blank()]);
        $this->actingAs($this->user());
        $html = $this->get("/chatbot/flows/{$flow->id}")->assertOk()->getContent();
        $this->assertStringContainsString("All the customer's messages (built in)", html_entity_decode($html, ENT_QUOTES));
        $data = $this->getJson("/chatbot/flows/{$flow->id}/graph")->json();
        $this->assertContains('ALL USER INPUT', $data['variables'] ?? []);
    }
}
