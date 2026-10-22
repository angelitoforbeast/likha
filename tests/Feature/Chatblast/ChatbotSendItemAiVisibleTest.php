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

    private function runAi(array $fields = []): FlowRun
    {
        $graph = FlowGraph::clean(['nodes' => [['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'ai', 'config' => ['save_to' => 'GPT INPUT']]], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]]);
        $current = BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => '7001', 'name' => 'Ana Cruz', 'message' => 'hm', 'payload' => [], 'received_at' => now()]);

        return new FlowRun(1, $graph, 'Page Uno', '1001', [], new CustomerFields(1001, 7001), $current->id, 'hm', 'Ana Cruz', '2026-10-19 10:00');
    }

    public function test_the_ai_flag_is_kept_by_the_graph_cleaner_only_when_checked(): void
    {
        $c = FlowGraph::cleanConfig('send', ['items' => [
            ['type' => 'text', 'value' => 'a', 'ai' => false], ['type' => 'text', 'value' => 'b'], ['type' => 'text', 'value' => 'c', 'ai' => true]]]);
        $this->assertSame([null, null, true], array_map(fn ($i) => $i['ai'] ?? null, $c['items']));
    }

    public function test_one_bubble_with_the_ai_answer_and_a_form_gives_the_ai_only_its_answer_in_history(): void
    {
        config(['services.openai.key' => 'sk-test-not-real']);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);
        $send = fn (array $items) => FlowGraph::clean(['nodes' => [['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'ai', 'config' => ['save_to' => 'GPT INPUT']], ['id' => 'n3', 'type' => 'send', 'config' => ['items' => $items]]],
            'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n3']]]);
        $run = $this->runAi();
        $run->fields->set('gpt input', 'Opo, 500 po.');
        $run->fields->set('fill-up form', 'Pangalan:');
        $engine = app(FlowEngine::class);
        // Ang tunay na send ng engine (hindi pa naka-queue): ilagay sa talaan ng padala gaya ng ginagawa ng Outbox.
        $graph = $send([
            ['type' => 'text', 'value' => "{{GPT INPUT}}\n\n{{Fill-Up Form}}"],
            ['type' => 'text', 'value' => 'Plain flow text'],
            ['type' => 'text', 'value' => 'Shown on purpose', 'ai' => true],
            ['type' => 'text', 'value' => 'Hidden {{GPT INPUT}}'],
        ]);
        $graph['nodes'][2]['config']['items'][3]['ai'] = false; // graph na naka-save bago ang release: ang ai: false ay nababasa pa
        $run2 = new FlowRun(1, $graph, 'Page Uno', '1001', [], $run->fields, null, 'hm', 'Ana Cruz', '2026-10-19 10:00', true);
        $engine->run($run2, new TestOutbox());

        $m = $run2->sends[0]['messages'];
        $this->assertSame('Opo, 500 po.', $m[0]['ai_text']);
        $this->assertSame([false, true, false], [$m[1]['ai'], $m[2]['ai'], $m[3]['ai']]);
        $this->assertSame("Opo, 500 po.\nShown on purpose", TestHistory::text($m), 'test path: own answer + explicit message only, form never');
        $this->assertStringNotContainsString('Pangalan', TestHistory::text($m));
        $this->assertSame(['type' => 'text', 'text' => 'Plain flow text'], BotcakeOutbox::wire([$m[1]])[0]);
    }

    public function test_live_history_has_ai_answers_as_assistant_turns_and_old_rows_keep_full_text(): void
    {
        config(['services.openai.key' => 'sk-test-not-real']);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);
        $run = $this->runAi();
        $reply = fn (array $content, int $ago) => ChatbotReply::create(['page_id' => 1001, 'psid' => 7001, 'kind' => 'send', 'status' => 'sent', 'sent_at' => now()->subMinutes($ago), 'content' => $content]);
        $reply([['type' => 'text', 'text' => "Answer one\n\nFORM", 'ai_text' => 'Answer one']], 5);
        $reply([['type' => 'text', 'text' => 'Flow text', 'ai' => false]], 4);
        $reply([['type' => 'text', 'text' => 'Shown', 'ai' => true]], 3);
        $reply([['type' => 'text', 'text' => 'Old row, no flag']], 2);
        $reply([['type' => 'text', 'text' => 'Answer two', 'ai_text' => 'Answer two']], 1);

        app(FlowEngine::class)->run($run, new TestOutbox());

        Http::assertSent(fn (HttpRequest $r) => array_slice($r['messages'], -5) === [
            ['role' => 'assistant', 'content' => 'Answer one'], ['role' => 'assistant', 'content' => 'Shown'],
            ['role' => 'assistant', 'content' => 'Old row, no flag'], ['role' => 'assistant', 'content' => 'Answer two'], ['role' => 'user', 'content' => 'hm']]);
    }

    public function test_the_payload_to_botcake_and_the_gateway_has_no_ai_keys(): void
    {
        $this->assertSame([['type' => 'text', 'text' => 'a'], ['type' => 'text', 'text' => 'b']],
            BotcakeOutbox::wire([['type' => 'text', 'text' => 'a', 'ai' => false], ['type' => 'text', 'text' => 'b', 'ai_text' => 'x']]));
    }

    public function test_the_editor_has_the_checkbox_and_the_note(): void
    {
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::blank()]);
        $this->actingAs($this->user());
        $html = $this->get("/chatbot/flows/{$flow->id}")->assertOk()->getContent();
        $this->assertStringContainsString('Show this message to the AI', $html);
        $this->assertStringContainsString('The AI remembers only its own answer here', $html);
    }
}
