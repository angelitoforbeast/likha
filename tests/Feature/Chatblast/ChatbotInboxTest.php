<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\BotcakePsid;
use App\Models\ChatbotFlow;
use App\Models\ChatbotReply;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowGraph;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * /chatbot/inbox: ang listahan ng usapan kada page (pinakabago muna, may tuldok kapag hindi pa sinasagot), ang usapan
 * (papasok at palabas, sunod-sunod sa oras), ang panel ng customer, at ang mga hangganan ng query. Walang tawag sa labas.
 */
class ChatbotInboxTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_22_100000_widen_source_on_botcake_psids.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_inbox_indexes.php',
        ]]);
        $this->page('Page Uno', '1001');
        $this->page('Page Dos', '2002');
        $this->travelTo('2026-10-19 12:00:00');
    }

    private function in(string $psid, string $message, string $at, string $page = '1001', string $name = 'Ana Cruz', array $payload = []): BotcakeChat
    {
        return BotcakeChat::create(['page_id' => $page, 'page_name' => $page === '1001' ? 'Page Uno' : 'Page Dos', 'psid' => $psid, 'name' => $name,
            'message' => $message, 'payload' => $payload, 'received_at' => $at]);
    }

    private function out(string $psid, array $content, string $at, string $status = 'sent', ?string $error = null, string $kind = 'send', int $page = 1001): ChatbotReply
    {
        return ChatbotReply::create(['page_id' => $page, 'psid' => (int) $psid, 'chat_id' => null, 'flow_id' => null, 'node_id' => 'n2', 'kind' => $kind,
            'content' => $content, 'status' => $status, 'error' => $error, 'sent_at' => $at]);
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

    public function test_S_074_1_the_list_is_newest_activity_first_with_a_dot_when_the_customer_waits(): void
    {
        $this->in('7001', 'Hello?', '2026-10-19 10:00:00', '1001', 'Ana Cruz');               // walang sagot: may tuldok
        $this->in('7002', 'Magkano po?', '2026-10-19 09:00:00', '1001', 'Ben Reyes');
        $this->out('7002', [['type' => 'image', 'url' => 'https://img.example.test/p.jpg'], ['type' => 'text', 'text' => 'It is 499 pesos.']], '2026-10-19 11:00:00');
        $this->in('7003', 'Old one', '2026-10-11 09:00:00', '1001', 'Carla Diaz');              // 8 araw na
        $this->in('7004', 'Failed answer', '2026-10-19 08:00:00', '1001', 'Dan Lim');
        $this->out('7004', [['type' => 'text', 'text' => 'x']], '2026-10-19 08:01:00', 'failed', 'Botcake said "no" (HTTP 400)'); // pumalya: naghihintay pa
        $this->in('7009', 'Other page', '2026-10-19 11:30:00', '2002', 'Eve Tan');
        $this->actingAs($this->user());

        $rows = $this->getJson('/chatbot/inbox/conversations?page_id=1001')->assertOk()->json('rows');
        $this->assertSame(['7002', '7001', '7004'], array_column($rows, 'psid'));
        $this->assertSame(['Ben Reyes', 'Bot: It is 499 pesos.', false], [$rows[0]['name'], $rows[0]['preview'], $rows[0]['waiting']]);
        $this->assertSame(['Ana Cruz', 'Hello?', true, '2026-10-19T10:00:00+08:00'], [$rows[1]['name'], $rows[1]['preview'], $rows[1]['waiting'], $rows[1]['at']]);
        $this->assertTrue($rows[2]['waiting'], 'ang pumalyang padala ay hindi sagot');

        $this->assertSame(['7002', '7001', '7004', '7003'], array_column($this->getJson('/chatbot/inbox/conversations?page_id=1001&window=30d')->json('rows'), 'psid'));
        $this->assertSame(['7001'], array_column($this->getJson('/chatbot/inbox/conversations?page_id=1001&window=24h&q=ana')->json('rows'), 'psid'));
        $this->assertSame(['7002'], array_column($this->getJson('/chatbot/inbox/conversations?page_id=1001&q=7002')->json('rows'), 'psid'));
        $this->getJson('/chatbot/inbox/conversations?page_id=9999')->assertStatus(422);
    }

    public function test_S_074_2_the_thread_merges_both_sides_in_time_order_with_failed_skipped_sheet_photo_and_like(): void
    {
        $this->in('7001', 'Hi', '2026-10-19 10:00:00');
        $this->out('7001', [['type' => 'image', 'url' => 'https://img.example.test/p.jpg'], ['type' => 'text', 'text' => 'Welcome!']], '2026-10-19 10:00:05');
        $this->out('7001', [['type' => 'text', 'text' => 'Price?']], '2026-10-19 10:00:06', 'failed', 'Botcake said "outside the 24h window" (HTTP 400)');
        $this->out('7001', [['type' => 'text', 'text' => 'Later']], '2026-10-19 10:00:07', 'skipped', 'Replies are switched off');
        $this->in('7001', '[Photo]', '2026-10-19 10:01:00', '1001', 'Ana Cruz', ['image_url' => 'https://scontent.xx.fbcdn.net/v/p.jpg']);
        $this->in('7001', '👍', '2026-10-19 10:02:00');
        $this->out('7001', ['sheet' => 'abc', 'tab' => 'Orders', 'row' => ['x']], '2026-10-19 10:03:00', 'sent', null, 'sheet');
        $this->out('7001', ['sheet' => 'abc', 'tab' => 'Orders', 'row' => ['x']], '2026-10-19 10:04:00', 'failed', 'Google refused the row: quota', 'sheet');
        $this->in('7002', 'Someone else', '2026-10-19 10:01:30');
        $this->actingAs($this->user());

        $items = $this->getJson('/chatbot/inbox/thread?page_id=1001&psid=7001')->assertOk()->json('items');

        $this->assertSame(['in', 'out', 'out', 'out', 'in', 'in', 'system', 'system'], array_column($items, 'side'));
        $this->assertSame('Hi', $items[0]['text']);
        $this->assertSame([['type' => 'image', 'url' => 'https://img.example.test/p.jpg'], ['type' => 'text', 'text' => 'Welcome!']], $items[1]['messages']);
        $this->assertSame(['failed', 'Botcake said "outside the 24h window" (HTTP 400)'], [$items[2]['status'], $items[2]['error']]);
        $this->assertSame(['skipped', 'Replies are switched off'], [$items[3]['status'], $items[3]['error']]);
        $this->assertSame(['[Photo]', 'https://scontent.xx.fbcdn.net/v/p.jpg'], [$items[4]['text'], $items[4]['image_url']]);
        $this->assertTrue($items[5]['like']);
        $this->assertSame(['Order row written to sheet', 'Order row failed: Google refused the row: quota'], [$items[6]['text'], $items[7]['text']]);
        $this->assertSame('2026-10-19T10:00:00+08:00', $items[0]['at']);
    }

    public function test_S_074_3_load_older_pages_by_50_and_polling_returns_only_newer_items(): void
    {
        for ($i = 0; $i < 60; $i++) $this->in('7001', 'msg ' . $i, now()->subMinutes(120 - $i)->format('Y-m-d H:i:s'));
        $this->out('7001', [['type' => 'text', 'text' => 'bot reply']], now()->subMinutes(30)->format('Y-m-d H:i:s'));
        $this->actingAs($this->user());

        $first = $this->getJson('/chatbot/inbox/thread?page_id=1001&psid=7001')->assertOk()->json();
        $this->assertCount(50, $first['items']);
        $this->assertSame('bot reply', $first['items'][49]['messages'][0]['text']);
        $this->assertSame('msg 11', $first['items'][0]['text']);
        $this->assertTrue($first['has_older']);

        $older = $this->getJson('/chatbot/inbox/thread?page_id=1001&psid=7001&' . http_build_query(['before' => $first['older']]))->assertOk()->json();
        $this->assertSame(array_map(fn ($i) => 'msg ' . $i, range(0, 10)), array_column($older['items'], 'text'));
        $this->assertFalse($older['has_older']);

        $none = $this->getJson('/chatbot/inbox/thread?page_id=1001&psid=7001&' . http_build_query(['after' => $first['newer']]))->assertOk()->json();
        $this->assertSame([], $none['items']);
        $this->in('7001', 'just now', now()->format('Y-m-d H:i:s'));
        $this->out('7001', [['type' => 'text', 'text' => 'answer']], now()->format('Y-m-d H:i:s'));
        $new = $this->getJson('/chatbot/inbox/thread?page_id=1001&psid=7001&' . http_build_query(['after' => $first['newer']]))->assertOk()->json();
        $this->assertSame(['in', 'out'], array_column($new['items'], 'side'));
        $again = $this->getJson('/chatbot/inbox/thread?page_id=1001&psid=7001&' . http_build_query(['after' => $new['newer']]))->assertOk()->json();
        $this->assertSame([], $again['items']);
    }

    public function test_S_074_4_the_panel_shows_the_customer_fields_the_wait_and_the_page(): void
    {
        $this->in('7001', 'Hi', '2026-10-18 09:00:00', '1001', 'Ana Cruz', ['ad_id' => '120200001']);
        BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Ana Cruz', 'psid' => '7001', 'status' => 'verified', 'source' => 'chat_verify', 'ad_id' => '120200001',
            'first_seen_at' => '2026-10-18 09:00:00']);
        $fields = new CustomerFields(1001, 7001);
        $fields->set('CHAT STATUS', 'LOOP 1');
        $fields->set('ALL USER INPUT', str_repeat('long text ', 50));
        $fields->set(CustomerFields::WAIT, '5:n6');
        $fields->set(CustomerFields::LAST_CHAT, '99');
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'price', '499');
        $flow = ChatbotFlow::create(['name' => 'Main COD', 'graph' => FlowGraph::blank()]);
        app(ChatbotPage::class)->link('Page Uno', $flow);
        $this->actingAs($this->user());

        $p = $this->getJson('/chatbot/inbox/panel?page_id=1001&psid=7001')->assertOk()->json();

        $this->assertSame(['Ana Cruz', '7001', '120200001', 'chat_verify', '2026-10-18T09:00:00+08:00'], [$p['name'], $p['psid'], $p['ad_id'], $p['source'], $p['first_seen']]);
        $this->assertSame(['LOOP 1', 'Waiting for reply at step n6'], [$p['chat_status'], $p['waiting']]);
        $this->assertSame(['all user input', 'chat status'], array_column($p['fields'], 'key'));
        $this->assertStringNotContainsString('__', json_encode($p['fields']));
        $this->assertStringNotContainsString('99', json_encode($p['fields']));
        $this->assertSame(['Main COD', url('/chatbot/flows/' . $flow->id)], [$p['flow']['name'], $p['flow']['url']]);
        $this->assertContains(['label' => 'PRICE', 'value' => '499'], $p['botfields']);
        $this->assertTrue($p['can_edit']);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($p));
    }

    public function test_S_074_5_only_the_ceo_edits_fields_and_the_inbox_needs_chatblast_access(): void
    {
        $this->in('7001', 'Hi', '2026-10-19 10:00:00');
        foreach (['/chatbot/inbox', '/chatbot/inbox/conversations?page_id=1001'] as $url) $this->get($url)->assertRedirect();

        $this->actingAs($this->user('Data Encoder', 'enc@example.test'));
        foreach (['/chatbot/inbox', '/chatbot/inbox/conversations?page_id=1001', '/chatbot/inbox/thread?page_id=1001&psid=7001', '/chatbot/inbox/panel?page_id=1001&psid=7001'] as $url) {
            $this->get($url)->assertForbidden();
        }

        $this->navLink('/chatblast', ['Marketing']);
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        $this->get('/chatbot/inbox')->assertOk()->assertSee('Page Uno');
        $this->assertFalse($this->getJson('/chatbot/inbox/panel?page_id=1001&psid=7001')->json('can_edit'));
        $this->postJson('/chatbot/customer-fields', ['page_id' => '1001', 'psid' => '7001', 'key' => 'chat status', 'value' => 'x'])->assertForbidden();

        $this->actingAs($this->user());
        $html = $this->get('/chatbot/inbox')->assertOk()->getContent();
        preg_match_all('#data-cb-nav="([^"]+)"#', $html, $m);
        $this->assertSame(['Flow', 'Page settings', 'Chats', 'Inbox', 'Replies', 'Test', 'Chatblast'], $m[1]);
        $this->postJson('/chatbot/customer-fields', ['page_id' => '1001', 'psid' => '7001', 'key' => 'chat status', 'value' => 'LOOP 2'])->assertOk();
        $this->assertSame('LOOP 2', $this->getJson('/chatbot/inbox/panel?page_id=1001&psid=7001')->json('chat_status'));
    }

    public function test_S_074_6_the_list_and_thread_queries_are_bounded_by_page_and_time_or_customer(): void
    {
        $this->in('7001', 'Hi', '2026-10-19 10:00:00');
        $this->out('7001', [['type' => 'text', 'text' => 'Hello']], '2026-10-19 10:00:05');
        $this->actingAs($this->user());

        DB::enableQueryLog();
        $this->getJson('/chatbot/inbox/conversations?page_id=1001&q=ana')->assertOk();
        $list = $this->tableQueries();
        DB::flushQueryLog();
        $this->getJson('/chatbot/inbox/thread?page_id=1001&psid=7001')->assertOk();
        $thread = $this->tableQueries();

        $this->assertNotEmpty($list);
        foreach ($list as $q) {
            $this->assertMatchesRegularExpression('/"page_id" = \?/', $q['query'], $q['query']);
            $this->assertMatchesRegularExpression('/"(received_at|sent_at)" >= \?|"id" in \(/', $q['query'], 'bounded by time or by ids: ' . $q['query']);
            $this->assertMatchesRegularExpression('/limit \d+|"id" in \(/', $q['query'], $q['query']);
        }
        $this->assertNotEmpty($thread);
        foreach ($thread as $q) $this->assertMatchesRegularExpression('/"page_id" = \? and "psid" = \?.*limit \d+/', $q['query'], $q['query']);

        $indexes = fn (string $t) => array_map(fn ($i) => $i['columns'], Schema::getIndexes($t));
        $this->assertContains(['page_id', 'psid', 'id'], $indexes('botcake_chats'));
        $this->assertContains(['page_id', 'psid', 'id'], $indexes('chatbot_replies'));
    }

    /** @return array<int, array{query: string}> ang mga query sa botcake_chats at chatbot_replies */
    private function tableQueries(): array
    {
        return array_values(array_filter(DB::getQueryLog(), fn ($q) => preg_match('/from "(botcake_chats|chatbot_replies)"/', $q['query'])));
    }
}
