<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Models\ChatbotReply;
use App\Services\Chatbot\ChatbotSheet;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowGraph;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Ang mga tab ng /chatbot: ang nav, Chats (kasama ang customer fields), Replies at Test. Fake na HTTP; walang tawag sa
 * Botcake o Google.
 */
class ChatbotTabsTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
        ]]);
        $this->page('Page Uno', '1001');
        config(['services.openai.key' => 'sk-test-not-real']);
        $this->app->instance(ChatbotSheet::class, new class extends ChatbotSheet {
            public function append(string $spreadsheetId, string $tab, array $row): void { throw new \LogicException('No sheet write in this test'); }
        });
    }

    private function navLink(string $url, array $roles): void
    {
        if (!Schema::hasTable('nav_links')) {
            Artisan::call('migrate', ['--path' => 'database/migrations/2026_05_18_140000_create_nav_links_and_visibility_tables.php', '--force' => true]);
        }
        $id = DB::table('nav_links')->insertGetId([
            'key' => 'chatblast', 'label' => 'Chatblast', 'route_url' => $url, 'icon' => 'fa-solid fa-link', 'active_pattern' => 'chatblast*',
            'is_active' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($roles as $role) {
            DB::table('nav_link_role_visibility')->insert(['nav_link_id' => $id, 'role' => $role, 'is_visible' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function reply(array $over): ChatbotReply
    {
        return ChatbotReply::create($over + ['page_id' => 1001, 'psid' => 7001, 'chat_id' => 1, 'flow_id' => 1, 'node_id' => 'n2', 'kind' => 'send',
            'content' => [['type' => 'text', 'text' => 'Hello po']], 'status' => 'sent', 'sent_at' => now()]);
    }

    public function test_S_069_T1_the_chatbot_section_has_its_own_nav_and_links_back_to_chatblast(): void
    {
        Http::fake();
        $this->actingAs($this->user());

        $html = $this->get('/chatbot')->assertOk()->getContent();
        preg_match_all('#data-cb-nav="([^"]+)"#', $html, $m);
        $this->assertSame(['Flow', 'Page settings', 'Chats', 'Replies', 'Test', 'Chatblast'], $m[1]);
        $this->assertStringContainsString('data-cb-nav="Flow" aria-current="page"', preg_replace('/\s+/', ' ', $html));
        $this->assertMatchesRegularExpression('#<a href="[^"]*/chatblast"[^>]*data-cb-nav="Chatblast"#', $html);

        $html = $this->get('/chatblast')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<a href="[^"]*/chatbot"[^>]*data-cb-nav="Chatbot"#', $html);
    }

    public function test_S_069_T2_chats_show_under_chatbot_and_the_ceo_edits_a_customers_fields(): void
    {
        Http::fake();
        BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => '7001', 'name' => 'Ana Cruz', 'message' => 'Hello', 'payload' => [], 'received_at' => now()]);
        $fields = new CustomerFields(1001, 7001);
        $fields->set('CHAT STATUS', 'LOOP 1');
        $fields->set(CustomerFields::WAIT, '1:n6');
        $this->actingAs($this->user());

        $this->get('/chatbot/chats')->assertOk()->assertSee('Ana Cruz')->assertSee(e('/chatbot/chats/customer?page_id=1001&psid=7001'), false);
        $this->get('/chatbot/chats/customer?page_id=1001&psid=7001')->assertOk()
            ->assertSeeInOrder(['Customer fields', 'chat status', 'LOOP 1'])->assertDontSee('__wait')->assertDontSee('1:n6');

        $this->postJson('/chatbot/customer-fields', ['page_id' => '1001', 'psid' => '7001', 'key' => 'Chat Status', 'value' => 'LOOP 2'])->assertOk();
        $this->postJson('/chatbot/customer-fields', ['page_id' => '1001', 'psid' => '7001', 'key' => 'GPT INPUT', 'value' => 'x'])->assertOk();
        $this->postJson('/chatbot/customer-fields', ['page_id' => '1001', 'psid' => '7001', 'key' => 'gpt input', 'clear' => true])->assertOk();
        $this->postJson('/chatbot/customer-fields', ['page_id' => '1001', 'psid' => '7001', 'key' => '__wait', 'clear' => true])->assertStatus(422);
        $this->postJson('/chatbot/customer-fields', ['page_id' => 'abc', 'psid' => '7001', 'key' => 'x', 'value' => 'y'])->assertStatus(422);
        $after = new CustomerFields(1001, 7001);
        $this->assertSame([['chat status' => 'LOOP 2'], '1:n6'], [$after->fields(), $after->get(CustomerFields::WAIT)]);

        $this->navLink('/chatblast', ['Marketing']);
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        $this->get('/chatbot/chats/customer?page_id=1001&psid=7001')->assertOk()->assertSee('LOOP 2');
        $this->postJson('/chatbot/customer-fields', ['page_id' => '1001', 'psid' => '7001', 'key' => 'chat status', 'clear' => true])->assertForbidden();
    }

    public function test_S_069_T3_replies_list_one_date_with_filters_and_details(): void
    {
        Http::fake();
        $this->travelTo('2026-10-19 12:00:00');
        $flow = ChatbotFlow::create(['name' => 'Main COD', 'graph' => FlowGraph::blank()]);
        $this->reply(['content' => [['type' => 'text', 'text' => 'Old one']], 'sent_at' => '2026-10-18 09:00:00']);
        $this->reply(['content' => [['type' => 'text', 'text' => 'Price is 499']], 'flow_id' => $flow->id, 'sent_at' => '2026-10-19 09:00:00']);
        $this->reply(['content' => [['type' => 'image', 'url' => 'https://img.example.test/a.jpg']], 'status' => 'skipped', 'error' => 'Replies are switched off', 'sent_at' => '2026-10-19 10:00:00']);
        $this->reply(['page_id' => 2002, 'content' => [['type' => 'text', 'text' => 'Other page']], 'status' => 'failed', 'error' => 'Botcake said "x" (HTTP 400)', 'sent_at' => '2026-10-19 11:00:00']);
        $this->actingAs($this->user());

        $this->get('/chatbot/replies')->assertOk()->assertSee('Replies are switched off');
        $rows = fn (string $q) => array_column($this->getJson('/chatbot/replies/data?' . $q)->assertOk()->json('rows'), 'summary');

        $this->assertSame(['Other page', '[image] https://img.example.test/a.jpg', 'Price is 499'], $rows('date=2026-10-19'));
        $this->assertSame(['Old one'], $rows('date=2026-10-18'));
        $this->assertSame(['[image] https://img.example.test/a.jpg', 'Price is 499'], $rows('date=2026-10-19&page_id=1001'));
        $this->assertSame(['Other page'], $rows('date=2026-10-19&status=failed'));
        $this->assertSame(['Price is 499'], $rows('date=2026-10-19&q=499'));
        $this->assertSame(['Other page', '[image] https://img.example.test/a.jpg', 'Price is 499'], $rows('date=not-a-date'), 'ang maling petsa ay ngayong araw');

        $id = ChatbotReply::where('sent_at', '2026-10-19 09:00:00')->value('id');
        $this->getJson('/chatbot/replies/' . $id)->assertOk()->assertJson(['flow' => 'Main COD', 'node' => 'n2', 'status' => 'sent', 'psid' => '7001',
            'content' => [['type' => 'text', 'text' => 'Price is 499']]]);
    }

    public function test_S_069_T4_test_runs_the_flow_for_a_test_customer_without_botcake_or_the_sheet(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'AI says hi']]]])]);
        config(['services.chatbot.replies_enabled' => true]); // kahit naka-on ang padala, ang Test ay hindi nagpapadala
        $flow = ChatbotFlow::create(['name' => 'Main COD', 'graph' => FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start'],
            ['id' => 'n2', 'type' => 'set', 'config' => ['ops' => [['op' => 'set', 'field' => 'CHAT STATUS', 'value' => 'LOOP 1']]]],
            ['id' => 'n3', 'type' => 'ai', 'config' => ['save_to' => 'GPT INPUT', 'send' => true]],
            ['id' => 'n4', 'type' => 'sheet', 'config' => ['sheet' => '1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789', 'tab' => 'Orders', 'columns' => "{{psid}}\n{{GPT INPUT}}"]],
            ['id' => 'n5', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Status {{CHAT STATUS}}']], 'wait' => true, 'save_to' => 'answer']],
        ], 'edges' => [
            ['from' => 'n1', 'port' => 'next', 'to' => 'n2'], ['from' => 'n2', 'port' => 'next', 'to' => 'n3'],
            ['from' => 'n3', 'port' => 'success', 'to' => 'n4'], ['from' => 'n4', 'port' => 'next', 'to' => 'n5'],
        ]])]);
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'flow', 'Main COD');
        (new CustomerFields(1001, 7001))->set('CHAT STATUS', 'REAL CUSTOMER');
        $this->actingAs($this->user());

        $this->get('/chatbot/test')->assertOk()->assertSee('Page Uno')->assertSee('calls OpenAI');
        $res = $this->postJson('/chatbot/test/send', ['page_name' => 'Page Uno', 'message' => 'Hi'])->assertOk()->json();

        $this->assertSame('Main COD', $res['flow']);
        $this->assertSame(['n1', 'n2', 'n3', 'n4', 'n5'], array_column($res['path'], 'node'));
        $this->assertSame([[['type' => 'text', 'text' => 'AI says hi']], [['type' => 'text', 'text' => 'Status LOOP 1']]], array_column($res['sends'], 'messages'));
        $this->assertSame([['TEST', 'AI says hi']], array_column($res['sheet_rows'], 'row'));
        $this->assertSame(['chat status' => 'LOOP 1', 'gpt input' => 'AI says hi'], $res['fields']);
        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'api.openai.com'));
        $this->assertSame(0, ChatbotReply::count());
        $this->assertSame('REAL CUSTOMER', (new CustomerFields(1001, 7001))->get('chat status'), 'ang totoong customer ay hindi nagalaw');

        // Ang sagot ay nagpapatuloy mula sa paghihintay; ang reset ay binubura ang test customer.
        $res = $this->postJson('/chatbot/test/send', ['page_name' => 'Page Uno', 'message' => 'Yes'])->assertOk()->json();
        $this->assertSame(['n5'], array_column($res['path'], 'node'));
        $this->assertSame('Yes', $res['fields']['answer']);
        $this->postJson('/chatbot/test/reset', ['page_name' => 'Page Uno'])->assertOk();
        $this->assertSame([], (new CustomerFields(1001, CustomerFields::TEST_PSID))->fields());

        $this->postJson('/chatbot/test/send', ['page_name' => 'Page Nowhere', 'message' => 'Hi'])->assertStatus(422);
    }

    public function test_S_069_T5_the_tabs_need_chatblast_access(): void
    {
        Http::fake();
        foreach (['/chatbot/chats', '/chatbot/replies', '/chatbot/test'] as $url) $this->get($url)->assertRedirect();

        $this->actingAs($this->user('Data Encoder', 'enc@example.test'));
        foreach (['/chatbot/chats', '/chatbot/chats/customer?page_id=1001&psid=7001', '/chatbot/replies', '/chatbot/replies/data', '/chatbot/test'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->postJson('/chatbot/test/send', ['page_name' => 'Page Uno', 'message' => 'Hi'])->assertForbidden();
        $this->postJson('/chatbot/test/reset', ['page_name' => 'Page Uno'])->assertForbidden();
        $this->postJson('/chatbot/customer-fields', ['page_id' => '1001', 'psid' => '7001', 'key' => 'x', 'value' => 'y'])->assertForbidden();
        Http::assertNothingSent();
    }
}
