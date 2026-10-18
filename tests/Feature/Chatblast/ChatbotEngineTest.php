<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\ChatbotVars;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowEngine;
use App\Services\Chatbot\FlowGraph;
use App\Services\Chatbot\FlowRun;
use App\Services\Chatbot\Outbox;
use App\Services\Chatbot\TestOutbox;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Ang engine ng sariling chatbot: ang mga node, ang mga variable, ang paghihintay ng sagot at ang mga hangganan.
 * Fake na OpenAI (Http::fake); ang mga padala at sheet row ay sa TestOutbox (walang Botcake o Google).
 */
class ChatbotEngineTest extends ChatblastTestCase
{
    private const PAGE_ID = '1001';
    private const PSID = 7001;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
        ]]);
        config(['services.openai.key' => 'sk-test-not-real']);
    }

    private const BOTFIELDS = [
        'picture' => 'https://img.example.test/product.jpg', 'main_flow' => 'Hello! This is our product.', 'main_flow_2' => 'Only {{PRICE}} pesos.',
        'Fill-Up Form' => 'Please send: name, phone, address.', 'prompt_1' => 'You sell one product for {{PRICE}}.', 'price' => '499',
    ];

    private function go(array $graph, string $input, ?int $chatId = null, ?Outbox $outbox = null, array $botfields = self::BOTFIELDS): FlowRun
    {
        $run = new FlowRun(1, FlowGraph::clean($graph), 'Page Uno', self::PAGE_ID, $botfields, new CustomerFields((int) self::PAGE_ID, self::PSID),
            $chatId, $input, 'Ana Cruz', '2026-10-19 10:00');

        return app(FlowEngine::class)->run($run, $outbox ?? new TestOutbox());
    }

    private function fields(): array
    {
        $f = (new CustomerFields((int) self::PAGE_ID, self::PSID))->fields();
        ksort($f);

        return $f;
    }

    private function node(string $id, string $type, array $config = [], int $x = 0): array
    {
        return ['id' => $id, 'type' => $type, 'x' => $x, 'y' => 0, 'config' => $config];
    }

    private function edge(string $from, string $port, string $to): array
    {
        return ['from' => $from, 'port' => $port, 'to' => $to];
    }

    /** Ang Main Flow ng owner (CBB), binuo sa ating mga node. */
    private function mainFlow(): array
    {
        $phones = "639\n+639\n09\n" . implode(',', range(901, 999));

        return ['nodes' => [
            $this->node('n1', 'start'),
            $this->node('n3', 'set', ['ops' => [
                ['op' => 'set', 'field' => 'USER INPUT', 'value' => '{{last_input}}'],
                ['op' => 'append', 'field' => 'ALL USER INPUT', 'value' => '{{last_input}}', 'separator' => ', '],
            ]]),
            $this->node('n2', 'condition', ['rows' => [
                ['variable' => 'last_input', 'rule' => 'is', 'value' => 'exitloop'],
                ['variable' => 'CHAT STATUS', 'rule' => 'is_empty', 'value' => ''],
                ['variable' => 'CHAT STATUS', 'rule' => 'is', 'value' => 'LOOP 1'],
                ['variable' => 'CHAT STATUS', 'rule' => 'is', 'value' => 'LOOP 2'],
            ]]),
            $this->node('n10', 'set', ['ops' => [['op' => 'clear_all']]]),
            $this->node('n4', 'send', ['items' => [['type' => 'image', 'value' => '{{PICTURE}}'], ['type' => 'text', 'value' => '{{Main Flow}}'], ['type' => 'text', 'value' => '{{Main Flow 2}}']]]),
            $this->node('n5', 'set', ['ops' => [['op' => 'set', 'field' => 'CHAT STATUS', 'value' => 'LOOP 1']]]),
            $this->node('n6', 'send', ['items' => [['type' => 'text', 'value' => '{{Fill-Up Form}}']], 'wait' => true, 'save_to' => 'FORM REPLY']),
            $this->node('n7', 'condition', ['rows' => [['variable' => 'USER INPUT', 'rule' => 'contains_any', 'value' => $phones]]]),
            $this->node('n8', 'send', ['items' => [['type' => 'text', 'value' => 'Thank you, {{first_name}}!']]]),
            $this->node('n9', 'ai', ['system' => 'Extract only the phone number.', 'save_to' => 'GPT PHONE NUMBER']),
            $this->node('n11', 'set', ['ops' => [['op' => 'set', 'field' => 'CHAT STATUS', 'value' => 'LOOP 2']]]),
            $this->node('n12', 'ai', ['system' => '{{Prompt 1}}', 'history' => 4, 'save_to' => 'GPT INPUT', 'send' => true]),
            $this->node('n13', 'condition', ['rows' => [['variable' => 'GPT INPUT', 'rule' => 'contains', 'value' => 'Your order is all set']]]),
            $this->node('n14', 'ai', ['system' => 'Extract address, amount and price.', 'user' => '{{ALL USER INPUT}}', 'save_to' => 'CXD']),
            $this->node('n15', 'sheet', ['sheet' => 'https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789/edit', 'tab' => 'Orders',
                'columns' => "{{current_date}}\n{{page_name}}\n{{full_name}}\n{{GPT PHONE NUMBER}}\n{{ALL USER INPUT}}\n{{Main Flow}}\n{{CXD}}\n{{PRICE}}"]),
            $this->node('n16', 'set', ['ops' => [['op' => 'set', 'field' => 'CHAT STATUS', 'value' => 'LOOP 3']]]),
            $this->node('n99', 'end'),
        ], 'edges' => [
            $this->edge('n1', 'next', 'n3'), $this->edge('n3', 'next', 'n2'),
            $this->edge('n2', 'row_1', 'n10'), $this->edge('n10', 'next', 'n99'),
            $this->edge('n2', 'row_2', 'n4'), $this->edge('n4', 'continue', 'n5'), $this->edge('n5', 'next', 'n6'), $this->edge('n6', 'reply', 'n3'),
            $this->edge('n2', 'row_3', 'n7'), $this->edge('n7', 'row_1', 'n8'), $this->edge('n8', 'continue', 'n9'), $this->edge('n9', 'success', 'n11'), $this->edge('n11', 'next', 'n12'),
            $this->edge('n2', 'row_4', 'n12'), $this->edge('n12', 'success', 'n13'),
            $this->edge('n13', 'row_1', 'n14'), $this->edge('n14', 'success', 'n15'), $this->edge('n15', 'next', 'n16'), $this->edge('n16', 'next', 'n99'),
        ]];
    }

    private static function ai(string $text): array
    {
        return ['choices' => [['message' => ['role' => 'assistant', 'content' => $text]]]];
    }

    public function test_S_069_E1_the_owners_main_flow_runs_end_to_end(): void
    {
        $this->travelTo('2026-10-19 10:00:00');
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(self::ai('09171234567'))
            ->push(self::ai('How many pieces po?'))
            ->push(self::ai('Your order is all set! Total 998.'))
            ->push(self::ai('Manila, 2, 998'))]);
        $flow = $this->mainFlow();

        // 1. Bagong chat: larawan at dalawang text, LOOP 1, saka ang form at naghihintay.
        $r = $this->go($flow, 'Hi', 11);
        $this->assertSame([
            [['type' => 'image', 'url' => 'https://img.example.test/product.jpg'], ['type' => 'text', 'text' => 'Hello! This is our product.'], ['type' => 'text', 'text' => 'Only 499 pesos.']],
            [['type' => 'text', 'text' => 'Please send: name, phone, address.']],
        ], array_column($r->sends, 'messages'));
        $this->assertSame(['all user input' => 'Hi', 'chat status' => 'LOOP 1', 'user input' => 'Hi'], $this->fields());
        $this->assertSame('1:n6', (new CustomerFields(1001, self::PSID))->get(CustomerFields::WAIT));

        // 2. Ang sagot sa form na may numero: salamat, AI ang kumuha ng numero, LOOP 2, AI ang sumagot.
        $r = $this->go($flow, 'Juan, 09171234567, Manila', 12);
        $this->assertSame(['n6', 'n3', 'n2', 'n7', 'n8', 'n9', 'n11', 'n12', 'n13'], array_column($r->path, 'node'));
        $this->assertSame(['Thank you, Ana!', 'How many pieces po?'], array_map(fn ($s) => $s['messages'][0]['text'], $r->sends));
        $f = $this->fields();
        $this->assertSame(['LOOP 2', '09171234567', 'Juan, 09171234567, Manila', 'Hi, Juan, 09171234567, Manila'],
            [$f['chat status'], $f['gpt phone number'], $f['form reply'], $f['all user input']]);
        Http::assertSent(fn (HttpRequest $q) => ($q['messages'][0] ?? null) === ['role' => 'system', 'content' => 'You sell one product for 499.']
            && $q['model'] === 'gpt-4o-mini' && $q['max_tokens'] === 250 && $q->hasHeader('Authorization', 'Bearer sk-test-not-real'));

        // 3. Kumpleto ang order: AI, saka ang AI na kumukuha ng detalye, saka isang sheet row, LOOP 3.
        $r = $this->go($flow, '2 pcs', 13);
        $this->assertSame(['n1', 'n3', 'n2', 'n12', 'n13', 'n14', 'n15', 'n16', 'n99'], array_column($r->path, 'node'));
        $this->assertSame([['node' => 'n15', 'sheet' => '1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789', 'tab' => 'Orders',
            'row' => ['2026-10-19', 'Page Uno', 'Ana Cruz', '09171234567', 'Hi, Juan, 09171234567, Manila, 2 pcs', 'Hello! This is our product.', 'Manila, 2, 998', '499'],
            'status' => 'test', 'error' => null]], $r->sheetRows);
        $this->assertSame('LOOP 3', $this->fields()['chat status']);

        // 4. exitloop: burado lahat.
        $this->go($flow, 'exitloop', 14);
        $this->assertSame([], $this->fields());
        Http::assertSentCount(4);
    }

    public function test_S_069_E2_a_wait_in_another_flow_or_a_removed_node_starts_from_the_start(): void
    {
        $flow = ['nodes' => [$this->node('n1', 'start'), $this->node('n2', 'send', ['items' => [['type' => 'text', 'value' => 'Start again']]])],
            'edges' => [$this->edge('n1', 'next', 'n2')]];
        $fields = new CustomerFields(1001, self::PSID);

        foreach (['2:n6', '1:n50'] as $wait) {
            $fields->set(CustomerFields::WAIT, $wait);
            $r = $this->go($flow, 'hello');
            $this->assertSame(['n1', 'n2'], array_column($r->path, 'node'));
            $this->assertNull((new CustomerFields(1001, self::PSID))->get(CustomerFields::WAIT));
        }
    }

    public static function rules(): array
    {
        return [
            'is'                 => ['is', 'Hello', 'hello', true],
            'is no'              => ['is', 'Hello', 'hell', false],
            'is not'             => ['is_not', 'Hello', 'bye', true],
            'contains'           => ['contains', 'Hello World', 'world', true],
            'contains any line'  => ['contains_any', 'call 09171234567', "639\n09", true],
            'contains any comma' => ['contains_any', 'call 9171234567', '639, 917', true],
            'contains any none'  => ['contains_any', 'no number', "639\n09\n\n", false],
            'not contains'       => ['not_contains', 'Hello', 'bye', true],
            'not contains no'    => ['not_contains', 'Hello', 'ell', false],
            'starts with'        => ['starts_with', 'LOOP 2', 'loop', true],
            'regex'              => ['regex', 'order #123', '#\d+$', true],
            'regex bad'          => ['regex', 'abc', '(', false],
            'is empty'           => ['is_empty', '', '', true],
            'is not empty'       => ['is_not_empty', 'x', '', true],
            'greater than'       => ['gt', 'P1,200', '999', true],
            'less than'          => ['lt', '5 pcs', '10', true],
            'less than no text'  => ['lt', 'abc', '10', false],
        ];
    }

    /** @dataProvider rules */
    public function test_S_069_E3_every_condition_rule(string $rule, string $subject, string $target, bool $expected): void
    {
        $this->assertSame($expected, FlowEngine::matches($rule, $subject, $target));
    }

    public function test_S_069_E4_the_first_matching_row_wins_and_no_match_exits_on_no(): void
    {
        $flow = ['nodes' => [$this->node('n1', 'start'), $this->node('n2', 'condition', ['rows' => [
            ['variable' => '{{ last_input }}', 'rule' => 'contains', 'value' => 'price'],
            ['variable' => 'Last_Input', 'rule' => 'contains', 'value' => 'pr'],
        ]]), $this->node('n3', 'end'), $this->node('n4', 'end'), $this->node('n5', 'end')], 'edges' => [
            $this->edge('n1', 'next', 'n2'), $this->edge('n2', 'row_1', 'n3'), $this->edge('n2', 'row_2', 'n4'), $this->edge('n2', 'no', 'n5'),
        ]];

        $this->assertSame('n3', last($this->go($flow, 'What is the PRICE?')->path)['node']);
        $this->assertSame('n4', last($this->go($flow, 'promo')->path)['node']);
        $this->assertSame('n5', last($this->go($flow, 'hi')->path)['node']);
    }

    public function test_S_069_E5_every_set_field_operation(): void
    {
        $fields = new CustomerFields(1001, self::PSID);
        $fields->set(CustomerFields::LAST_CHAT, '5');
        $flow = fn (array $ops) => ['nodes' => [$this->node('n1', 'start'), $this->node('n2', 'set', ['ops' => $ops])], 'edges' => [$this->edge('n1', 'next', 'n2')]];

        $this->go($flow([['op' => 'set', 'field' => 'Status', 'value' => 'New {{first_name}}'], ['op' => 'append', 'field' => 'log', 'value' => '{{last_input}}']]), 'a');
        $this->go($flow([['op' => 'append', 'field' => 'LOG', 'value' => '{{last_input}}', 'separator' => ' | ']]), 'b');
        $this->assertSame(['log' => 'a | b', 'status' => 'New Ana'], $this->fields());

        $this->go($flow([['op' => 'clear', 'field' => 'status']]), 'c');
        $this->assertSame(['log' => 'a | b'], $this->fields());

        (new CustomerFields(1001, self::PSID))->set(CustomerFields::WAIT, '1:n9');
        $this->go($flow([['op' => 'clear_all']]), 'd');
        $this->assertSame([], $this->fields());
        $this->assertSame(['__last_chat' => '5'], DB::table('chatbot_customer_values')->pluck('value', 'key')->all(), 'naiiwan lang ang __last_chat');
    }

    public function test_S_069_E6_the_ai_step_follows_success_or_failed(): void
    {
        $flow = ['nodes' => [$this->node('n1', 'start'), $this->node('n2', 'ai', ['save_to' => 'answer', 'send' => true]),
            $this->node('n3', 'send', ['items' => [['type' => 'text', 'value' => 'Got: {{answer}}']]]),
            $this->node('n4', 'send', ['items' => [['type' => 'text', 'value' => 'Sorry, try again later.']]])], 'edges' => [
            $this->edge('n1', 'next', 'n2'), $this->edge('n2', 'success', 'n3'), $this->edge('n2', 'failed', 'n4'),
        ]];

        Http::fake(['api.openai.com/*' => Http::sequence()->push(self::ai('Yes po'))->push(['error' => ['message' => 'Rate limit']], 429)]);
        $ok = $this->go($flow, 'available?');
        $this->assertSame(['Yes po', 'Got: Yes po'], array_map(fn ($s) => $s['messages'][0]['text'], $ok->sends));
        $this->assertSame('Yes po', $this->fields()['answer']);

        $failed = $this->go($flow, 'again?');
        $this->assertSame(['Sorry, try again later.'], array_map(fn ($s) => $s['messages'][0]['text'], $failed->sends));
        $this->assertStringContainsString('HTTP 429', $failed->path[1]['note']);

        config(['services.openai.key' => '']);
        $this->assertSame('failed', $this->go($flow, 'no key')->path[1]['port']);
        $this->assertStringNotContainsString('sk-test', json_encode($failed->path));
    }

    public static function cells(): array
    {
        return [
            'variable that looks like a formula' => ['{{last_input}}', '=IMPORTXML("x")', "'=IMPORTXML(\"x\")"],
            'plus, minus, at'                    => ['{{last_input}}', '-5+cmd', "'-5+cmd"],
            'text before the variable'           => ['={{last_input}}', '1+1', "'=1+1"],
            'variable that is plain text'        => ['Order: {{last_input}}', '=x', 'Order: =x'],
            'no variable: kept as the author typed' => ['=TODAY()', 'ignored', '=TODAY()'],
        ];
    }

    /** @dataProvider cells */
    public function test_S_069_E7_a_sheet_cell_with_a_variable_is_never_a_formula(string $template, string $input, string $expected): void
    {
        $this->assertSame($expected, ChatbotVars::sheetCell($template, ChatbotVars::merge(['last_input' => $input])));
    }

    public function test_S_069_E8_variables_ignore_case_and_spaces_and_unknown_ones_are_empty(): void
    {
        (new CustomerFields(1001, self::PSID))->set('chat_status', 'LOOP 1');
        $flow = ['nodes' => [$this->node('n1', 'start'), $this->node('n2', 'send', ['items' => [
            ['type' => 'text', 'value' => '{{ Main_Flow_2 }}|{{chat STATUS}}|{{PSID}}|{{page_name}}|{{Not A Var}}|{{current_date}}'],
        ]])], 'edges' => [$this->edge('n1', 'next', 'n2')]];
        $this->travelTo('2026-10-19 08:00:00');

        $this->assertSame('Only 499 pesos.|LOOP 1|7001|Page Uno||2026-10-19', $this->go($flow, 'x')->sends[0]['messages'][0]['text']);
    }

    public function test_S_069_E9_a_loop_stops_after_100_steps(): void
    {
        $flow = ['nodes' => [$this->node('n1', 'start'), $this->node('n2', 'set', ['ops' => [['op' => 'append', 'field' => 'n', 'value' => '1', 'separator' => '']]]),
            $this->node('n3', 'condition', ['rows' => [['variable' => 'last_input', 'rule' => 'is_not_empty']]])], 'edges' => [
            $this->edge('n1', 'next', 'n2'), $this->edge('n2', 'next', 'n3'), $this->edge('n3', 'row_1', 'n2'),
        ]];

        $r = $this->go($flow, 'go');

        $this->assertCount(FlowEngine::MAX_STEPS, $r->path);
        $this->assertStringContainsString('100 steps', (string) $r->stopped);
        $this->assertSame(50, strlen($this->fields()['n']));
    }

    public function test_S_069_E10_a_failed_send_without_a_failed_port_stops_the_run(): void
    {
        $failing = new class implements Outbox {
            public function send(FlowRun $run, string $nodeId, array $messages): array { return ['status' => 'failed', 'error' => 'Botcake said no']; }
            public function appendRow(FlowRun $run, string $nodeId, string $id, string $tab, array $row): array { return ['status' => 'failed', 'error' => 'x']; }
        };
        $flow = ['nodes' => [$this->node('n1', 'start'), $this->node('n2', 'send', ['items' => [['type' => 'text', 'value' => 'hi']]]),
            $this->node('n3', 'set', ['ops' => [['op' => 'set', 'field' => 'after', 'value' => 'x']]])], 'edges' => [
            $this->edge('n1', 'next', 'n2'), $this->edge('n2', 'continue', 'n3'),
        ]];

        $r = $this->go($flow, 'x', null, $failing);

        $this->assertSame(['n1', 'n2'], array_column($r->path, 'node'));
        $this->assertSame('Send failed: Botcake said no', $r->stopped);
        $this->assertArrayNotHasKey('after', $this->fields());
    }
}
