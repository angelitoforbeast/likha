<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Models\ChatbotReply;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotRunner;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowGraph;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Amendment 1 at 2 ng 074: ang manual na sagot (24 oras, sino ang puwede, throttle, log na manual), ang pag-pause ng
 * bot kada customer, ang executed actions (chatbot_events), ang mga seksyon ng panel at ang Human / Bot. Fake na HTTP.
 */
class ChatbotInboxActionsTest extends ChatblastTestCase
{
    private const SEND = 'https://botcake.io/api/public_api/v1/pages/1001/flows/send_content';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_22_100000_widen_source_on_botcake_psids.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_inbox_indexes.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
        ]]);
        config(['services.chatbot.replies_enabled' => true, 'services.openai.key' => 'sk-test-not-real']);
        $this->page('Page Uno', '1001');
        $this->travelTo('2026-10-19 12:00:00');
    }

    private function in(string $psid, string $message, string $at, array $payload = []): BotcakeChat
    {
        return BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => $psid, 'name' => 'Ana Cruz', 'message' => $message, 'payload' => $payload, 'received_at' => $at]);
    }

    private function navLink(array $roles): void
    {
        if (!Schema::hasTable('nav_links')) {
            Artisan::call('migrate', ['--path' => 'database/migrations/2026_05_18_140000_create_nav_links_and_visibility_tables.php', '--force' => true]);
        }
        $id = DB::table('nav_links')->insertGetId(['key' => 'chatblast', 'label' => 'Chatblast', 'route_url' => '/chatblast', 'icon' => 'x', 'active_pattern' => 'chatblast*',
            'is_active' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($roles as $role) DB::table('nav_link_role_visibility')->insert(['nav_link_id' => $id, 'role' => $role, 'is_visible' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function cbbFlow(): ChatbotFlow
    {
        $flow = ChatbotFlow::create(['name' => 'Main COD', 'graph' => FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start'],
            ['id' => 'n2', 'type' => 'set', 'config' => ['ops' => [['op' => 'set', 'field' => 'CHAT STATUS', 'value' => 'LOOP 1'], ['op' => 'append', 'field' => 'ALL USER INPUT', 'value' => '{{last_input}}']]]],
            ['id' => 'n3', 'type' => 'condition', 'config' => ['rows' => [['variable' => 'last_input', 'rule' => 'contains', 'value' => 'price']]]],
            ['id' => 'n4', 'type' => 'ai', 'config' => ['system' => 'SECRET PROMPT TEXT', 'save_to' => 'GPT INPUT', 'send' => true]],
            ['id' => 'n5', 'type' => 'sheet', 'config' => ['sheet' => '1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789', 'tab' => 'Orders', 'columns' => '{{last_input}}']],
            ['id' => 'n6', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Anything else?']], 'wait' => true, 'save_to' => 'ANSWER']],
        ], 'edges' => [
            ['from' => 'n1', 'port' => 'next', 'to' => 'n2'], ['from' => 'n2', 'port' => 'next', 'to' => 'n3'], ['from' => 'n3', 'port' => 'row_1', 'to' => 'n4'],
            ['from' => 'n4', 'port' => 'success', 'to' => 'n5'], ['from' => 'n5', 'port' => 'next', 'to' => 'n6'],
        ]])]);
        app(ChatbotPage::class)->link('Page Uno', $flow);
        $this->app->instance(\App\Services\Chatbot\ChatbotSheet::class, new class extends \App\Services\Chatbot\ChatbotSheet {
            public function append(string $spreadsheetId, string $tab, array $row): ?string { return null; }
        });

        return $flow;
    }

    // ---- Amendment 1: manual reply ----

    public function test_S_074_R1_a_manual_reply_inside_the_window_posts_the_exact_body_and_logs_it_as_manual(): void
    {
        Http::fake([self::SEND => Http::response(['success' => true])]);
        $this->in('7001', 'Hi', '2026-10-19 11:00:00');
        $this->actingAs($ceo = $this->user());

        $res = $this->postJson('/chatbot/inbox/send', ['page_id' => '1001', 'psid' => '7001', 'text' => "Hello po!\nHow can I help?", 'image_url' => 'https://img.example.test/p.jpg'])
            ->assertOk()->json();

        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::SEND && $r->hasHeader('access-token', self::TOKEN) && $r->data() === ['psid' => '7001',
            'data' => ['version' => 'v2', 'content' => ['messages' => [['type' => 'text', 'text' => "Hello po!\nHow can I help?"], ['type' => 'image', 'url' => 'https://img.example.test/p.jpg']]]]]);
        $reply = ChatbotReply::sole();
        $this->assertSame(['manual', 'sent', $ceo->id, 1001, 7001], [$reply->kind, $reply->status, $reply->user_id, $reply->page_id, $reply->psid]);
        $this->assertSame(['out', 'CEO User', 'sent'], [$res['item']['side'], $res['item']['by'], $res['item']['status']]);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($res));
        $thread = $this->getJson('/chatbot/inbox/thread?page_id=1001&psid=7001')->json('items');
        $this->assertSame('CEO User', last($thread)['by']);
        $row = $this->getJson('/chatbot/replies/data?date=2026-10-19')->assertOk()->json('rows.0');
        $this->assertSame(['manual', "Manual by CEO User: Hello po!\nHow can I help? · [image] https://img.example.test/p.jpg"], [$row['kind'], $row['summary']]);
    }

    public function test_S_074_R2_outside_24_hours_or_a_bad_image_nothing_is_sent(): void
    {
        Http::fake();
        $this->in('7001', 'Hi', '2026-10-18 11:59:00'); // 24 oras at 1 minuto na
        $this->in('7002', 'Hi', '2026-10-19 11:00:00');
        $this->actingAs($this->user());

        $this->postJson('/chatbot/inbox/send', ['page_id' => '1001', 'psid' => '7001', 'text' => 'Hello'])->assertStatus(422)
            ->assertJson(['error' => "Outside Messenger's 24-hour window: the customer must message first."]);
        $this->assertFalse($this->getJson('/chatbot/inbox/panel?page_id=1001&psid=7001')->json('can_reply_window'));
        $this->postJson('/chatbot/inbox/send', ['page_id' => '1001', 'psid' => '7009', 'text' => 'Hello'])->assertStatus(422); // walang chat
        foreach ([['text' => 'x', 'image_url' => 'javascript:alert(1)'], ['text' => 'x', 'image_url' => 'ftp://x/y.jpg'], ['text' => '', 'image_url' => ''], ['text' => str_repeat('a', 2001)]] as $bad) {
            $this->postJson('/chatbot/inbox/send', ['page_id' => '1001', 'psid' => '7002'] + $bad)->assertStatus(422);
        }
        Http::assertNothingSent();
        $this->assertSame(0, ChatbotReply::count());
    }

    public function test_S_074_R3_only_allowed_roles_send_the_ceo_sets_them_and_sends_are_throttled(): void
    {
        Http::fake([self::SEND => Http::response(['success' => true])]);
        $this->in('7001', 'Hi', '2026-10-19 11:00:00');
        $this->navLink(['Marketing']);
        $mkt = $this->user('Marketing', 'mkt@example.test');

        $this->actingAs($mkt);
        $this->assertFalse($this->getJson('/chatbot/inbox/panel?page_id=1001&psid=7001')->json('can_send'));
        $this->postJson('/chatbot/inbox/send', ['page_id' => '1001', 'psid' => '7001', 'text' => 'Hi'])->assertForbidden();
        $this->postJson('/chatbot/inbox/roles', ['roles' => ['Marketing']])->assertForbidden();
        $this->postJson('/chatbot/inbox/pause', ['page_id' => '1001', 'psid' => '7001', 'paused' => true])->assertForbidden();

        $this->actingAs($this->user());
        $this->postJson('/chatbot/inbox/roles', ['roles' => ['Marketing', ' ', 'Marketing', 'Data Encoder']])->assertOk()->assertJson(['roles' => ['CEO', 'Marketing', 'Data Encoder']]);
        // Nasa reply_roles pero walang Chatblast access: 403 pa rin.
        $this->actingAs($this->user('Data Encoder', 'enc@example.test'));
        $this->postJson('/chatbot/inbox/send', ['page_id' => '1001', 'psid' => '7001', 'text' => 'Hi'])->assertForbidden();
        $this->postJson('/chatbot/inbox/pause', ['page_id' => '1001', 'psid' => '7001', 'paused' => true])->assertForbidden();

        $this->travel(61)->seconds(); // bagong minuto: ang 403 kanina ay bilang din sa throttle
        $this->actingAs($mkt);
        $this->assertTrue($this->getJson('/chatbot/inbox/panel?page_id=1001&psid=7001')->json('can_send'));
        for ($i = 1; $i <= 30; $i++) $this->postJson('/chatbot/inbox/send', ['page_id' => '1001', 'psid' => '7001', 'text' => 'Hi ' . $i])->assertOk();
        $this->postJson('/chatbot/inbox/send', ['page_id' => '1001', 'psid' => '7001', 'text' => 'One too many'])->assertStatus(429);
        $this->assertSame(30, ChatbotReply::where('kind', 'manual')->count());
    }

    public function test_S_074_R4_a_paused_customer_gets_no_bot_runs_until_unpaused(): void
    {
        $pausedAtSend = [];
        Http::fake([self::SEND => function () use (&$pausedAtSend) {
            $pausedAtSend[] = (new CustomerFields(1001, 7001))->get(CustomerFields::BOT_PAUSED);
            return Http::response(['success' => true]);
        }, 'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'AI answer']]]])]);
        $this->cbbFlow();
        app(ChatbotRunner::class)->handle($this->in('7001', 'hello', '2026-10-19 11:00:00')->id); // may marker na
        $this->actingAs($this->user());

        $this->postJson('/chatbot/inbox/send', ['page_id' => '1001', 'psid' => '7001', 'text' => 'A human here', 'pause' => true])->assertOk();
        $this->assertSame('1', end($pausedAtSend), 'naka-pause na bago ipadala, para walang takbo ng bot habang naghihintay sa Botcake');
        $this->assertTrue($this->getJson('/chatbot/inbox/panel?page_id=1001&psid=7001')->json('bot_paused'));
        $sent = ChatbotReply::count();

        $chat = $this->in('7001', 'price please', '2026-10-19 11:30:00');
        app(ChatbotRunner::class)->handle($chat->id);
        $this->assertSame($sent, ChatbotReply::count(), 'naka-pause: walang padala ang bot');
        $this->assertSame((string) $chat->id, (new CustomerFields(1001, 7001))->get(CustomerFields::LAST_CHAT), 'ang chat ay itinuring na tapos');
        $this->assertSame(['reason' => 'bot paused'], DB::table('chatbot_events')->where('chat_id', $chat->id)->where('type', 'skipped')->get()->map(fn ($e) => array_intersect_key(json_decode($e->detail, true), ['reason' => 1]))->first());

        $this->postJson('/chatbot/inbox/pause', ['page_id' => '1001', 'psid' => '7001', 'paused' => false])->assertOk();
        app(ChatbotRunner::class)->handle($this->in('7001', 'price again', '2026-10-19 11:40:00')->id);
        $this->assertGreaterThan($sent, ChatbotReply::where('kind', 'send')->count() + ChatbotReply::where('kind', 'manual')->count());
        $this->assertTrue(ChatbotReply::where('kind', 'send')->where('sent_at', '>=', '2026-10-19 11:40:00')->exists(), 'tumatakbo ulit ang bot');
    }

    // ---- Amendment 2: executed actions ----

    public function test_S_074_E1_a_run_writes_its_events_in_one_insert_without_the_prompt(): void
    {
        // Ang AI ay 3 segundo at ang sheet ay 2 segundo pa: ang bawat event ay may sariling oras sa usapan.
        Http::fake([self::SEND => Http::response(['success' => true]), 'api.openai.com/*' => function () {
            $this->travel(3)->seconds();
            return Http::response(['choices' => [['message' => ['content' => 'It is 499.']]]]);
        }]);
        $this->cbbFlow();
        $this->app->instance(\App\Services\Chatbot\ChatbotSheet::class, new class extends \App\Services\Chatbot\ChatbotSheet {
            public function append(string $spreadsheetId, string $tab, array $row): ?string { \Illuminate\Support\Carbon::setTestNow(now()->addSeconds(2)); return null; }
        });
        $chat = $this->in('7001', 'what is the price ' . str_repeat('x', 300), '2026-10-19 11:50:00');

        DB::enableQueryLog();
        app(ChatbotRunner::class)->handle($chat->id);
        $inserts = array_filter(DB::getQueryLog(), fn ($q) => str_starts_with($q['query'], 'insert into "chatbot_events"'));

        $this->assertCount(1, $inserts, 'isang insert kada takbo');
        $events = DB::table('chatbot_events')->where('chat_id', $chat->id)->orderBy('id')->get();
        $this->assertSame(['run_started', 'field', 'field', 'condition', 'ai', 'sheet', 'waiting', 'run_ended'], $events->pluck('type')->all());
        $this->assertSame([null, 'n2', 'n2', 'n3', 'n4', 'n5', 'n6', null], $events->pluck('node_id')->all());
        $this->assertStringNotContainsString('SECRET PROMPT', $events->pluck('detail')->implode(' '));
        $this->assertSame(1, $events->pluck('detail')->map(fn ($d) => json_decode($d, true)['run'] ?? null)->unique()->count());
        $this->assertSame(200, $events->pluck('detail')->flatMap(fn ($d) => array_map(fn ($v) => is_string($v) ? mb_strlen($v) : 0, json_decode($d, true)))->max(), 'ang value ay hanggang 200 character');

        $this->actingAs($this->user());
        $lines = collect($this->getJson('/chatbot/inbox/thread?page_id=1001&psid=7001&events=1')->assertOk()->json('items'));
        $this->assertSame([
            'Flow Main COD started (new)', 'Custom field value changed — CHAT STATUS was set to LOOP 1', 'Custom field value changed — ALL USER INPUT appended',
            'Condition n3 → row 1', 'AI step n4 → success (saved to GPT INPUT)', 'Sheet row n5 → written', 'Waiting for reply at step n6', 'Run ended (waiting for a reply)',
        ], $lines->where('side', 'event')->pluck('text')->values()->all());
        $this->assertSame(['in', 'run_started', 'field', 'field', 'condition', 'ai', 'out: It is 499.', 'sheet', 'waiting', 'run_ended', 'system', 'out: Anything else?'],
            $lines->map(fn ($x) => $x['side'] === 'event' ? $x['type'] : ($x['side'] === 'out' ? 'out: ' . $x['messages'][0]['text'] : $x['side']))->all(), 'ang mga event ay nasa oras nila, sa pagitan ng mga bubble');
        $this->assertSame([], collect($this->getJson('/chatbot/inbox/thread?page_id=1001&psid=7001')->json('items'))->where('side', 'event')->all());
    }

    public function test_S_074_E2_the_panel_lists_runs_errors_and_media(): void
    {
        Http::fake([self::SEND => Http::sequence()->push(['success' => true])->push(['success' => false, 'message' => 'outside window'])->push(['success' => true]),
            'api.openai.com/*' => Http::sequence()->push(['choices' => [['message' => ['content' => 'x']]]])->push(['error' => ['message' => 'no']], 500)]);
        $this->cbbFlow();
        app(ChatbotRunner::class)->handle($this->in('7001', 'price?', '2026-10-19 11:40:00')->id);
        app(ChatbotRunner::class)->handle($this->in('7001', '[Photo]', '2026-10-19 11:45:00', ['image_url' => 'https://scontent.xx.fbcdn.net/v/a.jpg'])->id);
        (new CustomerFields(1001, 7001))->clearAll();
        app(ChatbotRunner::class)->handle($this->in('7001', 'price again', '2026-10-19 11:50:00')->id);
        $this->actingAs($this->user());

        $p = $this->getJson('/chatbot/inbox/panel?page_id=1001&psid=7001')->assertOk()->json();

        $this->assertSame('Main COD', $p['runs'][0]['flow']);
        $this->assertSame(['n1', 'n2', 'n3', 'n4'], $p['runs'][0]['path']);
        $this->assertStringContainsString('AI', $p['runs'][0]['result']);
        $this->assertGreaterThanOrEqual(2, count($p['runs']));
        $errors = array_column($p['errors'], 'text');
        $this->assertContains('Send failed: Botcake said "outside window" (HTTP 200)', $errors);
        $this->assertContains('AI step n4 → failed: OpenAI refused (HTTP 500).', $errors);
        $this->assertSame(['https://scontent.xx.fbcdn.net/v/a.jpg'], array_column($p['media'], 'url'));
    }

    public function test_S_074_E3_human_and_bot_tabs_follow_the_pause_and_events_are_pruned_after_30_days(): void
    {
        Http::fake();
        $this->in('7001', 'paused one', '2026-10-19 10:00:00');
        $this->in('7002', 'bot one', '2026-10-19 10:05:00');
        (new CustomerFields(1001, 7001))->set(CustomerFields::BOT_PAUSED, '1');
        $this->actingAs($this->user());

        $this->assertSame(['7001'], array_column($this->getJson('/chatbot/inbox/conversations?page_id=1001&tab=human')->json('rows'), 'psid'));
        $this->assertSame(['7002'], array_column($this->getJson('/chatbot/inbox/conversations?page_id=1001&tab=bot')->json('rows'), 'psid'));
        $this->assertSame(['7002', '7001'], array_column($this->getJson('/chatbot/inbox/conversations?page_id=1001')->json('rows'), 'psid'));

        foreach ([31, 29] as $days) {
            DB::table('chatbot_events')->insert(['page_id' => 1001, 'psid' => 7001, 'type' => 'run_started', 'detail' => '{}', 'created_at' => now()->subDays($days)]);
        }
        Artisan::call('botcake:prune-chats');
        $this->assertSame([now()->subDays(29)->format('Y-m-d H:i:s')], DB::table('chatbot_events')->pluck('created_at')->map(fn ($t) => (string) $t)->all());

        // Ang event na mas luma sa 50 na naipakita ay hindi rin lumalabas sa polling.
        for ($i = 0; $i < 51; $i++) $this->in('7001', 'm' . $i, now()->subMinutes(100 - $i)->format('Y-m-d H:i:s'));
        $first = $this->getJson('/chatbot/inbox/thread?page_id=1001&psid=7001&events=1')->assertOk()->json();
        $this->assertSame([], collect($first['items'])->where('side', 'event')->values()->all());
        $this->assertSame([], $this->getJson('/chatbot/inbox/thread?page_id=1001&psid=7001&events=1&' . http_build_query(['after' => $first['newer']]))->json('items'));
    }
}
