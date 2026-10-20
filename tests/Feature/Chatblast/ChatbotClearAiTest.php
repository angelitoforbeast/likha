<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\ChatbotReply;
use App\Services\Chatbot\ChatbotEvents;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowEngine;
use App\Services\Chatbot\FlowGraph;
use App\Services\Chatbot\FlowRun;
use App\Services\Chatbot\TestHistory;
use App\Services\Chatbot\TestOutbox;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/**
 * Ang multi-line na value ng Set field, at ang step na "Clear AI memory": mula roon, ang history ng AI at ang
 * {{ALL USER INPUT}} ng customer na iyon ay ang mga mensahe at padala pagkatapos lang (walang binubura). Fake na OpenAI.
 */
class ChatbotClearAiTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
        ]]);
        config(['services.openai.key' => 'sk-test-not-real']);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);
    }

    private function chat(string $m, string $psid = '7001'): BotcakeChat
    {
        $this->travel(1)->seconds();

        return BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => $psid, 'name' => 'Ana Cruz', 'message' => $m, 'payload' => [], 'received_at' => now()]);
    }

    private function reply(string $t, int $psid = 7001): void
    {
        $this->travel(1)->seconds();
        ChatbotReply::create(['page_id' => 1001, 'psid' => $psid, 'kind' => 'send', 'status' => 'sent', 'sent_at' => now(), 'content' => [['type' => 'text', 'text' => $t]]]);
    }

    /** Ang mga node pagkatapos ng Start, magkakasunod sa port na next / success / continue. */
    private static function graph(array ...$nodes): array
    {
        $all = [['id' => 'n1', 'type' => 'start'], ...array_map(fn ($n, $i) => ['id' => 'n' . ($i + 2)] + $n, $nodes, array_keys($nodes))];
        $edges = [];
        for ($i = 1; $i < count($all); $i++) {
            $edges[] = ['from' => $all[$i - 1]['id'], 'port' => FlowGraph::ports($all[$i - 1])[0], 'to' => $all[$i]['id']];
        }

        return FlowGraph::clean(['nodes' => $all, 'edges' => $edges]);
    }

    private function live(array $graph, BotcakeChat $current, string $psid = '7001'): FlowRun
    {
        $run = new FlowRun(1, $graph, 'Page Uno', '1001', [], new CustomerFields(1001, (int) $psid), $current->id, (string) $current->message, 'Ana Cruz', '2026-10-20 10:00');

        return app(FlowEngine::class)->run($run, new TestOutbox());
    }

    /** @return array<int, array> ang messages ng bawat tawag sa OpenAI */
    private static function requests(): array
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'api.openai.com'))->map(fn ($p) => $p[0]->data()['messages'])->values()->all();
    }

    public function test_S_094_1_a_set_field_value_keeps_its_line_breaks_and_interpolates_each_line(): void
    {
        $this->chat('hi');
        $current = $this->chat('order po');
        $graph = self::graph(['type' => 'set', 'config' => ['ops' => [['op' => 'set', 'field' => 'all user input', 'value' => "{{ALL USER INPUT}}\n{{last_input}}"]]]]);
        $this->assertSame("{{ALL USER INPUT}}\n{{last_input}}", $graph['nodes'][1]['config']['ops'][0]['value']);

        $run = $this->live($graph, $current);

        $this->assertSame("hi\norder po\norder po", (new CustomerFields(1001, 7001))->get('all user input'));
        $this->assertSame('next', $run->path[1]['port']);
    }

    public function test_S_094_2_the_clear_step_sets_the_marker_writes_the_event_and_has_one_output(): void
    {
        $this->assertSame(['next'], FlowGraph::ports(['type' => 'clear_ai']));
        $this->assertSame(['fields' => ['gpt input', 'cxd', 'wait']], FlowGraph::cleanConfig('clear_ai', ['fields' => ['GPT INPUT', '', 'cxd', 'CXD', '__wait', ['x']], 'other' => 1]));
        $this->chat('hi');
        $current = $this->chat('start over');
        $set = fn (string $field) => ['type' => 'set', 'config' => ['ops' => [['op' => 'set', 'field' => $field, 'value' => '[{{ALL USER INPUT}}]']]]];

        $run = $this->live(self::graph($set('before'), ['type' => 'clear_ai'], $set('after')), $current);
        $this->live(self::graph($set('later')), $this->chat('hi again'));

        $this->assertSame(['after' => '[]', 'before' => "[hi\nstart over]", 'later' => '[hi again]'], (new CustomerFields(1001, 7001))->fields(), 'forgotten in the same run too; the marker is not a customer field');
        $marker = json_decode((string) (new CustomerFields(1001, 7001))->get(CustomerFields::AI_SINCE), true);
        $this->assertSame($current->id, $marker['chat'] ?? null);
        $this->assertNotEmpty($marker['at'] ?? null);
        $this->assertSame(['clear_ai', 'next'], [$run->path[2]['type'], $run->path[2]['port']]);
        $event = collect($run->events)->firstWhere('type', 'ai_cleared');
        $this->assertSame('n3', $event['node'] ?? null);
        $this->assertSame('AI memory cleared', ChatbotEvents::text('ai_cleared', 'n2', $event['detail']));
    }

    public function test_S_094_3_after_the_marker_live_history_and_all_user_input_only_have_newer_messages_and_sends_of_that_customer(): void
    {
        $this->chat('old question');
        $this->reply('old answer');
        $this->chat('other customer old', '7002');
        $this->reply('other answer old', 7002);
        $this->live(self::graph(['type' => 'clear_ai']), $this->chat('forget it'));
        $this->reply('sent after the clear');
        $this->chat('new question');
        $current = $this->chat('newest');
        $ai = ['type' => 'ai', 'config' => ['user' => '{{ALL USER INPUT}}', 'history' => 10]];

        $this->live(self::graph($ai), $current);
        $this->live(self::graph($ai), $this->chat('other customer now', '7002'), '7002');

        $this->assertSame([['role' => 'assistant', 'content' => 'sent after the clear'], ['role' => 'user', 'content' => 'new question'],
            ['role' => 'user', 'content' => "new question\nnewest"]], self::requests()[0]);
        $this->assertSame([['role' => 'user', 'content' => 'other customer old'], ['role' => 'assistant', 'content' => 'other answer old'],
            ['role' => 'user', 'content' => "other customer old\nother customer now"]], self::requests()[1], 'another customer keeps the whole conversation');
        $this->assertSame(4, BotcakeChat::where('psid', '7001')->count(), 'nothing is deleted');
    }

    public function test_S_094_4_in_test_the_step_forgets_the_test_conversation_and_the_sends_before_it(): void
    {
        $graph = self::graph(['type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Intro']]]], ['type' => 'clear_ai'],
            ['type' => 'ai', 'config' => ['user' => '{{last_input}} / {{ALL USER INPUT}}', 'history' => 10, 'send' => true]]);
        $fields = new CustomerFields(1001, CustomerFields::TEST_PSID);
        foreach (['first', 'second'] as $message) {
            $run = new FlowRun(1, $graph, 'Page Uno', '1001', [], $fields, null, $message, 'Test Customer', '2026-10-20 10:00', true);
            app(FlowEngine::class)->run($run, new TestOutbox());
            TestHistory::record($fields, $message, $run);
        }

        $this->assertSame([[['role' => 'user', 'content' => 'first / ']], [['role' => 'user', 'content' => 'second / ']]], self::requests(),
            'the intro sent before the clear, the earlier test conversation and the message itself are forgotten');
        $this->assertSame([['role' => 'assistant', 'content' => 'ok']], TestHistory::read($fields), 'only the answer after the clear is remembered');
    }

    public function test_S_094_5_the_step_also_empties_the_chosen_fields_only(): void
    {
        $fields = new CustomerFields(1001, 7001);
        foreach (['gpt input' => 'x', 'cxd' => 'y', 'name' => 'Ana'] as $k => $v) $fields->set($k, $v);

        $run = $this->live(self::graph(['type' => 'clear_ai', 'config' => ['fields' => ['GPT INPUT', 'cxd']]]), $this->chat('reset'));

        $this->assertSame(['name' => 'Ana'], (new CustomerFields(1001, 7001))->fields());
        $this->assertSame(['fields' => 'gpt input, cxd'], array_diff_key(collect($run->events)->firstWhere('type', 'ai_cleared')['detail'], ['run' => 1]));
    }

    public function test_S_094_7_the_editor_has_the_step_in_the_add_bar_and_a_multi_line_set_value(): void
    {
        $flow = \App\Models\ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::blank()]);
        $this->actingAs($this->user());
        $html = $this->get("/chatbot/flows/{$flow->id}")->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-add="ai">AI<\/button>\s*<button[^>]*data-add="clear_ai">Clear AI memory<\/button>/', $html, 'next to AI');
        $this->assertStringContainsString("clear_ai: 'Clear AI memory'", $html);
        $this->assertStringContainsString("label('Value', bind(grow(input({tag: 'textarea', rows: 2, maxlength: 2000}, o.value))", $html);
        $this->assertStringContainsString('-webkit-line-clamp: 3', $html);
    }

    public function test_S_094_6_clear_all_fields_keeps_the_marker(): void
    {
        $this->live(self::graph(['type' => 'clear_ai']), $this->chat('forget it'));
        $this->live(self::graph(['type' => 'set', 'config' => ['ops' => [['op' => 'clear_all']]]]), $this->chat('again'));

        $this->assertNotNull((new CustomerFields(1001, 7001))->get(CustomerFields::AI_SINCE));
    }
}
