<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Models\ChatbotReply;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowEngine;
use App\Services\Chatbot\FlowGraph;
use App\Services\Chatbot\FlowRun;
use App\Services\Chatbot\TestOutbox;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/**
 * Ang "Remember the last N messages" ng AI step: hanggang 100, 100 kapag bago ang step, at ang history ay ang usapan
 * ng customer sa page na ito lang (mga chat niya at ang ating naipadalang sagot), sa ayos ng oras. Fake na OpenAI.
 */
class ChatbotAiHistoryTest extends ChatblastTestCase
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

    public function test_S_077_1_a_new_ai_step_remembers_100_messages(): void
    {
        $this->assertSame(100, FlowGraph::cleanConfig('ai', [])['history']);
        $this->assertSame(0, FlowGraph::cleanConfig('ai', ['history' => 0])['history'], 'an explicit 0 stays 0');
        $this->assertSame(5, FlowGraph::cleanConfig('ai', ['history' => 5])['history'], 'a saved value stays');

        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::blank()]);
        $this->actingAs($this->user());
        $html = $this->get("/chatbot/flows/{$flow->id}")->assertOk()->getContent();
        $this->assertStringContainsString('history: 100, max_tokens: 250', $html, 'the editor default for a new AI step');
        $this->assertStringContainsString('Remember the last N messages of this customer on this page (0 = off, max 100)', $html);
    }

    public function test_S_077_2_100_is_accepted_and_out_of_range_is_clamped(): void
    {
        foreach ([100 => 100, 101 => 100, 5000 => 100, -5 => 0] as $in => $out) {
            $this->assertSame($out, FlowGraph::cleanConfig('ai', ['history' => $in])['history'], "history {$in}");
        }
    }

    public function test_S_077_3_the_request_carries_the_newest_n_of_this_customer_on_this_page_in_time_order(): void
    {
        config(['services.openai.key' => 'sk-test-not-real']);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);
        $this->travelTo('2026-10-19 10:00:00');
        $start = now()->subHours(5);
        $chat = fn (string $m, string $psid, string $page, $at) => BotcakeChat::create(['page_id' => $page, 'page_name' => 'Page Uno', 'psid' => $psid, 'name' => 'Ana Cruz', 'message' => $m, 'payload' => [], 'received_at' => $at]);
        $reply = fn (string $t, int $psid, int $page, $at) => ChatbotReply::create(['page_id' => $page, 'psid' => $psid, 'kind' => 'send', 'status' => 'sent', 'sent_at' => $at, 'content' => [['type' => 'text', 'text' => $t]]]);
        // 120 chats and 120 replies of this customer on this page, one after the other; others in between.
        for ($i = 1; $i <= 120; $i++) {
            $chat("in {$i}", '7001', '1001', $start->copy()->addMinutes(2 * $i));
            $reply("out {$i}", 7001, 1001, $start->copy()->addMinutes(2 * $i)->addSeconds(30));
            if ($i % 10 === 0) {
                $chat("other customer {$i}", '7002', '1001', $start->copy()->addMinutes(2 * $i)->addSeconds(10));
                $chat("other page {$i}", '7001', '2002', $start->copy()->addMinutes(2 * $i)->addSeconds(20));
                $reply("other reply {$i}", 7002, 1001, $start->copy()->addMinutes(2 * $i)->addSeconds(40));
            }
        }
        $current = $chat('mine now', '7001', '1001', now());
        $graph = FlowGraph::clean(['nodes' => [['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'ai', 'config' => ['system' => 'Be nice']]],
            'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]]);
        $run = new FlowRun(1, $graph, 'Page Uno', '1001', [], new CustomerFields(1001, 7001), $current->id, 'mine now', 'Ana Cruz', '2026-10-19 10:00');

        app(FlowEngine::class)->run($run, new TestOutbox());

        // The newest 100: in 71 … out 120 (50 of each), oldest first.
        $expected = [];
        for ($i = 71; $i <= 120; $i++) array_push($expected, ['role' => 'user', 'content' => "in {$i}"], ['role' => 'assistant', 'content' => "out {$i}"]);
        Http::assertSent(fn (HttpRequest $r) => $r['messages'] === array_merge([['role' => 'system', 'content' => 'Be nice']], $expected, [['role' => 'user', 'content' => 'mine now']]));
    }

    public function test_S_077_4_a_new_customer_with_no_earlier_chat_but_a_reply_already_gets_the_ai_answer(): void
    {
        config(['services.openai.key' => 'sk-test-not-real']);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);
        // The first chat of this customer; the flow's intro was already sent in this run, before the AI step.
        $current = BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => '7001', 'name' => 'Ana Cruz', 'message' => 'hm', 'payload' => [], 'received_at' => now()]);
        ChatbotReply::create(['page_id' => 1001, 'psid' => 7001, 'kind' => 'send', 'status' => 'sent', 'sent_at' => now(), 'content' => [['type' => 'text', 'text' => 'Hi! Salamat']]]);
        $graph = FlowGraph::clean(['nodes' => [['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'ai', 'config' => []]], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]]);
        $run = new FlowRun(1, $graph, 'Page Uno', '1001', [], new CustomerFields(1001, 7001), $current->id, 'hm', 'Ana Cruz', '2026-10-19 10:00');

        app(FlowEngine::class)->run($run, new TestOutbox());

        $this->assertSame('success', $run->path[1]['port'], (string) ($run->path[1]['note'] ?? ''));
        Http::assertSent(fn (HttpRequest $r) => $r['messages'] === [['role' => 'assistant', 'content' => 'Hi! Salamat'], ['role' => 'user', 'content' => 'hm']]);
    }
}
