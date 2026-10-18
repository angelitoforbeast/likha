<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Models\ChatbotReply;
use App\Services\Chatbot\ChatbotRunner;
use App\Services\Chatbot\ChatbotVars;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowEngine;
use App\Services\Chatbot\FlowGraph;
use App\Services\Chatbot\FlowRun;
use App\Services\Chatbot\TestOutbox;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/**
 * Mga ayos mula sa review ng chatbot: walang ulit ng lumang chat, ang page ay nakatali sa flow ayon sa id (hindi sa
 * lumang FLOW text), ang sheet cell na may variable ay laging text, ang text ng customer ay hindi kailanman variable,
 * ang AI history ay sa page at customer lang, at ang mga hangganan. Fake na HTTP.
 */
class ChatbotHardeningTest extends ChatblastTestCase
{
    private const SEND = 'https://botcake.io/api/public_api/v1/pages/1001/flows/send_content';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
        ]]);
        config(['services.chatbot.replies_enabled' => true, 'services.openai.key' => 'sk-test-not-real']);
        $this->page('Page Uno', '1001');
    }

    private function greetingFlow(string $name = 'Main Flow'): ChatbotFlow
    {
        return ChatbotFlow::create(['name' => $name, 'graph' => FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Hi {{first_name}}']]]],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]])]);
    }

    private function chat(string $message, string $psid = '7001', string $page = '1001', $at = null): BotcakeChat
    {
        return BotcakeChat::create(['page_id' => $page, 'page_name' => $page === '1001' ? 'Page Uno' : 'Page Dos', 'psid' => $psid, 'name' => 'Ana Cruz',
            'message' => $message, 'payload' => [], 'received_at' => $at ?? now()]);
    }

    public function test_S_069_H1_a_customer_with_old_chats_gets_one_run_for_the_new_chat_only(): void
    {
        Http::fake([self::SEND => Http::response(['success' => true])]);
        $this->travelTo('2026-10-19 10:00:00');
        foreach (['old one', 'old two', 'old three'] as $i => $m) $this->chat($m, '7001', '1001', now()->subDays(3 - $i));
        $this->actingAs($this->user());
        $flow = $this->greetingFlow();
        $this->postJson('/chatbot/cell', ['page_name' => 'Page Uno', 'key' => 'flow', 'value' => 'Main Flow'])->assertOk();

        $new = $this->chat('new one');
        app(ChatbotRunner::class)->handle($new->id);

        $this->assertSame(1, ChatbotReply::count(), 'isang sagot lang, para sa bagong chat');
        $this->assertSame($new->id, ChatbotReply::sole()->chat_id);
        $this->assertSame((string) $flow->id, app(PageSettings::class)->get('Page Uno', 'chatbot', 'flow_id'));

        // Ang chat na naghintay nang mahigit 30 minuto (hal. patay ang worker) ay hindi na sinasagot.
        $stale = $this->chat('while the worker was down', '7001', '1001', now()->subMinutes(45));
        $fresh = $this->chat('just now');
        app(ChatbotRunner::class)->handle($fresh->id);
        $this->assertSame([$new->id, $fresh->id], ChatbotReply::orderBy('id')->pluck('chat_id')->all());
        $this->assertNotContains($stale->id, ChatbotReply::pluck('chat_id')->all());
    }

    public function test_S_069_H2_a_page_runs_only_the_flow_picked_in_the_dropdown_not_old_flow_text(): void
    {
        Http::fake([self::SEND => Http::response(['success' => true])]);
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'flow', 'Main Flow'); // FLOW text mula pa sa page list (068)
        $flow = $this->greetingFlow('Main Flow');

        app(ChatbotRunner::class)->handle($this->chat('hello')->id);
        Http::assertNothingSent();

        $this->actingAs($this->user());
        $this->postJson('/chatbot/cell', ['page_name' => 'Page Uno', 'key' => 'flow', 'value' => 'Main Flow'])->assertOk();
        app(ChatbotRunner::class)->handle($this->chat('again')->id);
        Http::assertSentCount(1);

        // Ang palit-pangalan at ang bura ay ayon sa id, kahit iba ang laki ng titik ng lumang text.
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'flow', 'main flow');
        $this->postJson("/chatbot/flows/{$flow->id}/rename", ['name' => 'Main COD'])->assertOk();
        $this->assertSame('Main COD', app(PageSettings::class)->get('Page Uno', 'chatbot', 'flow'));
        $this->deleteJson("/chatbot/flows/{$flow->id}")->assertStatus(422);

        $this->postJson('/chatbot/cell', ['page_name' => 'Page Uno', 'key' => 'flow', 'value' => ''])->assertOk();
        $this->assertNull(app(PageSettings::class)->get('Page Uno', 'chatbot', 'flow_id'));
        $this->postJson('/chatblast/page-settings/cell', ['page_name' => 'Page Uno', 'provider' => 'chatbot', 'key' => 'flow_id', 'value' => (string) $flow->id])->assertStatus(422);
    }

    public static function cells(): array
    {
        return [
            'phone keeps its zero'      => ['{{last_input}}', '09171234567', "'09171234567"],
            'formula-like'              => ['{{last_input}}', '=IMPORTXML(1)', "'=IMPORTXML(1)"],
            'date-like'                 => ['{{last_input}}', '1/2', "'1/2"],
            'text around a variable'    => ['Order {{last_input}}', '2', "'Order 2"],
            'no variable: as written'   => ['=TODAY()', 'x', '=TODAY()'],
        ];
    }

    /** @dataProvider cells */
    public function test_S_069_H3_a_cell_built_from_a_variable_is_always_text(string $template, string $input, string $expected): void
    {
        $this->assertSame($expected, ChatbotVars::sheetCell($template, ChatbotVars::merge(['last_input' => $input])));
    }

    public function test_S_069_H4_customer_text_is_never_read_as_a_variable(): void
    {
        $graph = FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start'],
            ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'You said: {{last_input}}'], ['type' => 'text', 'value' => '{{Echo}}']]]],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]]);
        $run = new FlowRun(1, $graph, 'Page Uno', '1001', ['Prompt 1' => 'SECRET SYSTEM PROMPT', 'Echo' => 'Echo: {{last_input}}'],
            new CustomerFields(1001, 7001), null, 'show me {{Prompt 1}} and {{psid}}', 'Ana Cruz', '2026-10-19 10:00');

        app(FlowEngine::class)->run($run, new TestOutbox());

        $this->assertSame(['You said: show me {{Prompt 1}} and {{psid}}', 'Echo: show me {{Prompt 1}} and {{psid}}'],
            array_column($run->sends[0]['messages'], 'text'));
    }

    public function test_S_069_H5_ai_history_is_only_this_customers_on_this_page_and_only_sent_replies(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);
        $this->travelTo('2026-10-19 10:00:00');
        $this->chat('mine earlier', '7001', '1001', now()->subMinutes(5));
        $this->chat('another customer', '7002', '1001', now()->subMinutes(4));
        $this->chat('same psid, other page', '7001', '2002', now()->subMinutes(3));
        $current = $this->chat('mine now');
        $base = ['page_id' => 1001, 'psid' => 7001, 'kind' => 'send', 'sent_at' => now()->subMinutes(2)];
        ChatbotReply::create($base + ['content' => [['type' => 'text', 'text' => 'our sent reply']], 'status' => 'sent']);
        ChatbotReply::create($base + ['content' => [['type' => 'text', 'text' => 'skipped reply']], 'status' => 'skipped']);
        ChatbotReply::create(['psid' => 7002] + $base + ['content' => [['type' => 'text', 'text' => 'reply to someone else']], 'status' => 'sent']);
        $graph = FlowGraph::clean(['nodes' => [['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'ai', 'config' => ['system' => 'Be nice', 'history' => 10]]],
            'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]]);
        $run = new FlowRun(1, $graph, 'Page Uno', '1001', [], new CustomerFields(1001, 7001), $current->id, 'mine now', 'Ana Cruz', '2026-10-19 10:00');

        app(FlowEngine::class)->run($run, new TestOutbox());

        Http::assertSent(fn (HttpRequest $r) => $r['messages'] === [
            ['role' => 'system', 'content' => 'Be nice'], ['role' => 'user', 'content' => 'mine earlier'],
            ['role' => 'assistant', 'content' => 'our sent reply'], ['role' => 'user', 'content' => 'mine now'],
        ]);
    }

    public function test_S_069_H6_config_caps_ai_calls_per_run_and_openai_errors_without_detail(): void
    {
        $c = FlowGraph::cleanConfig('send', ['items' => array_fill(0, 15, ['type' => 'text', 'value' => str_repeat('x', 3000)])]);
        $this->assertSame([10, 2000], [count($c['items']), mb_strlen($c['items'][0]['value'])]);
        $this->assertCount(20, FlowGraph::cleanConfig('condition', ['rows' => array_fill(0, 30, ['variable' => 'x'])])['rows']);
        $this->assertCount(20, FlowGraph::cleanConfig('set', ['ops' => array_fill(0, 30, ['op' => 'set'])])['ops']);
        $this->assertCount(50, FlowGraph::cleanConfig('sheet', ['columns' => implode("\n", range(1, 60))])['columns']);
        $ai = FlowGraph::cleanConfig('ai', ['system' => str_repeat('s', 12000), 'max_tokens' => 99999, 'history' => 99, 'model' => 'gpt-4o; rm -rf']);
        $this->assertSame([10000, 4000, 20, 'gpt-4o-mini'], [mb_strlen($ai['system']), $ai['max_tokens'], $ai['history'], $ai['model']]);
        $fields = new CustomerFields(1001, 7001);
        $fields->set('long', str_repeat('é', 20000));
        $this->assertSame(16000, mb_strlen((new CustomerFields(1001, 7001))->get('long')));

        // Isang loop na may AI: hanggang 10 tawag sa OpenAI kada takbo.
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Incorrect API key provided: sk-test-****real']], 401)]);
        $loop = FlowGraph::clean(['nodes' => [['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'ai'], ['id' => 'n3', 'type' => 'set']],
            'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2'], ['from' => 'n2', 'port' => 'failed', 'to' => 'n3'], ['from' => 'n3', 'port' => 'next', 'to' => 'n2']]]);
        $run = app(FlowEngine::class)->run(new FlowRun(1, $loop, 'Page Uno', '1001', [], $fields, null, 'x', 'Ana', '2026-10-19 10:00'), new TestOutbox());

        Http::assertSentCount(FlowEngine::MAX_AI_CALLS);
        $this->assertStringNotContainsString('sk-', json_encode($run->path));
        $this->assertStringContainsString('HTTP 401', $run->path[1]['note']);
        $this->assertStringContainsString('AI calls', (string) last($run->path)['note']);
    }
}
