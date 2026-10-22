<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Models\ChatbotReply;
use App\Services\Chatbot\BotcakeOutbox;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowEngine;
use App\Services\Chatbot\FlowGraph;
use App\Services\Chatbot\FlowRun;
use App\Services\Chatbot\TestHistory;
use App\Services\Chatbot\TestOutbox;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/** Ang "Visible to AI" ng bawat mensahe ng Send step: ang naka-uncheck ay wala sa history ng AI, at hindi napupunta sa Botcake/Forbeast. */
class ChatbotSendItemAiVisibleTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
        ]]);
    }

    public function test_the_ai_flag_is_kept_by_the_graph_cleaner_only_when_unchecked(): void
    {
        $c = FlowGraph::cleanConfig('send', ['items' => [
            ['type' => 'text', 'value' => 'a', 'ai' => false], ['type' => 'text', 'value' => 'b'], ['type' => 'text', 'value' => 'c', 'ai' => true]]]);
        $this->assertSame([false, null, null], array_map(fn ($i) => $i['ai'] ?? null, $c['items']));
    }

    public function test_history_leaves_out_hidden_messages_and_a_fully_hidden_send_adds_nothing(): void
    {
        config(['services.openai.key' => 'sk-test-not-real']);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);
        $current = BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => '7001', 'name' => 'Ana Cruz', 'message' => 'hm', 'payload' => [], 'received_at' => now()]);
        $reply = fn (array $content, int $ago) => ChatbotReply::create(['page_id' => 1001, 'psid' => 7001, 'kind' => 'send', 'status' => 'sent', 'sent_at' => now()->subMinutes($ago), 'content' => $content]);
        $reply([['type' => 'text', 'text' => 'AI answer'], ['type' => 'text', 'text' => 'Fill-up form', 'ai' => false]], 3);
        $reply([['type' => 'text', 'text' => 'Only hidden', 'ai' => false]], 2);
        $reply([['type' => 'text', 'text' => 'Old row, no flag']], 1);
        $graph = FlowGraph::clean(['nodes' => [['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'ai', 'config' => []]], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]]);
        $run = new FlowRun(1, $graph, 'Page Uno', '1001', [], new CustomerFields(1001, 7001), $current->id, 'hm', 'Ana Cruz', '2026-10-19 10:00');

        app(FlowEngine::class)->run($run, new TestOutbox());

        Http::assertSent(fn (HttpRequest $r) => array_slice($r['messages'], -3) === [
            ['role' => 'assistant', 'content' => 'AI answer'], ['role' => 'assistant', 'content' => 'Old row, no flag'], ['role' => 'user', 'content' => 'hm']]);
    }

    public function test_test_path_follows_the_same_rule_and_the_send_records_the_flag(): void
    {
        $graph = FlowGraph::clean(['nodes' => [['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [
            ['type' => 'text', 'value' => 'AI answer'], ['type' => 'text', 'value' => 'Fill-up form', 'ai' => false]]]]],
            'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]]);
        $run = new FlowRun(1, $graph, 'Page Uno', '1001', [], new CustomerFields(1001, CustomerFields::TEST_PSID), null, 'hi', 'Ana Cruz', '2026-10-19 10:00', true);

        app(FlowEngine::class)->run($run, new TestOutbox());

        $this->assertFalse($run->sends[0]['messages'][1]['ai']);
        $this->assertSame('AI answer', TestHistory::text($run->sends[0]['messages']));
    }

    public function test_the_payload_to_botcake_and_the_gateway_has_no_ai_key(): void
    {
        $this->assertSame([['type' => 'text', 'text' => 'a'], ['type' => 'text', 'text' => 'b']],
            BotcakeOutbox::wire([['type' => 'text', 'text' => 'a', 'ai' => false], ['type' => 'text', 'text' => 'b']]));
    }

    public function test_the_editor_has_the_checkbox_per_message(): void
    {
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::blank()]);
        $this->actingAs($this->user());
        $html = $this->get("/chatbot/flows/{$flow->id}")->assertOk()->getContent();
        $this->assertStringContainsString('Visible to AI', $html);
    }
}
