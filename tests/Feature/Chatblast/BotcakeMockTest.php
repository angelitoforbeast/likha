<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\BotcakePsid;
use App\Models\ChatbotFlow;
use App\Models\ChatbotReply;
use App\Models\PageSetting;
use App\Services\BotcakeClient;
use App\Services\BotcakeFlowNames;
use App\Services\BotcakeMock;
use App\Services\Chatbot\ChatbotPage;
use App\Services\ChatblastSetupCheck;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/**
 * Ang pekeng Botcake ng mga test page (spec 086): sa loob ng BotcakeClient, walang HTTP, para lang sa page na may
 * botcake.mock = 1 at page_id na nagsisimula sa 99; ang bagal at ang error rate; ang buong chat → flow → sagot; at ang
 * botcake:stress-page.
 */
class BotcakeMockTest extends ChatblastTestCase
{
    private const MOCK_ID = '990000000000001';
    private const PAGE = 'ZZ STRESS TEST';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_25_100000_create_chatbot_waits_table.php',
        ]]);
        config(['services.chatbot.replies_enabled' => true, 'services.openai.key' => 'sk-test-not-real']);
        BotcakeMock::$roll = null;
    }

    private function mockPage(array $extra = []): void
    {
        $s = app(PageSettings::class);
        $s->set(self::PAGE, 'botcake', 'page_id', self::MOCK_ID);
        $s->set(self::PAGE, 'botcake', 'token', 'mock');
        $s->set(self::PAGE, 'botcake', 'mock', '1', false, null, true);
        foreach ($extra as $k => $v) $s->set(self::PAGE, 'botcake', $k, $v, false, null, true);
        BotcakeMock::forget();
    }

    private function client(): BotcakeClient
    {
        return app(BotcakeClient::class)->shared(sha1('mock'));
    }

    public function test_S_086_1_a_mock_page_is_answered_in_process_with_botcakes_shapes(): void
    {
        Http::fake();
        $this->mockPage();
        $base = '/pages/' . self::MOCK_ID;

        $this->assertSame([200, true], [($r = $this->client()->sendContent(self::MOCK_ID, '9911112222333344', [['type' => 'text', 'text' => 'Hi']], 'mock'))['http'], $r['body']['success']]);
        $this->assertSame(200, $this->client()->call('POST', $base . '/customer/9911112222333344/customer_fields', ['data' => [['id' => '990002', 'value' => 'Hello po']]], 'mock')['http']);
        $read = $this->client()->call('GET', $base . '/customer/9911112222333344/customer_fields', [], 'mock')['body'];
        $this->assertSame([['id' => '990002', 'value' => 'Hello po']], $read['data']);
        $this->assertSame(true, $this->client()->call('POST', $base . '/flows/send_flow', ['psid' => '9911112222333344', 'flow_id' => 990001], 'mock', true)['body']['success']);
        $this->client()->call('POST', $base . '/flows/send_flow', ['psid' => '9911112222333345', 'flow_id' => 990001], 'mock', true);
        $this->assertSame('found', $this->client()->customerInfo(self::MOCK_ID, '9911112222333344', 'mock'));
        $this->assertSame([], $this->client()->customers(self::MOCK_ID, 'mock', 1));
        $this->assertSame(['ok' => true, 'fields' => [['id' => '990002', 'name' => 'message']]], $this->client()->tokenAndFields(self::MOCK_ID, 'mock'));

        [$flows] = app(BotcakeFlowNames::class)->readFlows(self::PAGE, $this->clock->now() + 60);
        $this->assertSame(['990001' => ['name' => 'UPDATE SHIPPING STATUS 2026', 'removed' => false]], $flows);
        [$templates] = app(BotcakeFlowNames::class)->readTemplates(self::PAGE, $this->clock->now() + 60);
        $this->assertSame([['name' => 'post_order', 'approved' => true, 'body' => "May update po sa order nyo:\n{{1}}"]], $templates);
        $this->assertSame(['sent' => 2, 'delivery' => 2], app(ChatblastSetupCheck::class)->statistics(self::PAGE, '990001', $this->clock->now() + 60));
        $this->assertSame(404, $this->client()->call('GET', $base . '/something_else', [], 'mock')['http']);

        // The whole setup check passes against it, and nothing went over HTTP.
        $this->assertTrue(app(ChatblastSetupCheck::class)->run(self::PAGE)['ok']);
        Http::assertNothingSent();
        $counts = BotcakeMock::counts(self::MOCK_ID);
        $this->assertSame([1, 2, 2], [$counts['send_content'], $counts['send_flow'], $counts['customer_fields']]);
        $this->assertGreaterThanOrEqual(1, $counts['other']);
    }

    public function test_S_086_2_a_real_page_id_with_the_flag_still_calls_botcake(): void
    {
        Http::fake(['botcake.io/*' => Http::response(['success' => true])]);
        $s = app(PageSettings::class);
        $s->set('Page Uno', 'botcake', 'page_id', '1001');
        $s->set('Page Uno', 'botcake', 'mock', '1', false, null, true);
        BotcakeMock::forget();

        $this->client()->sendContent('1001', '7001', [['type' => 'text', 'text' => 'Hi']], 'tok');

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'botcake.io/api/public_api/v1/pages/1001/flows/send_content'));
        $this->assertSame([], BotcakeMock::counts('1001'));
        // The page can't set the flag itself.
        $this->actingAs($this->user());
        $this->postJson('/chatblast/page-settings/cell', ['page_name' => self::PAGE, 'provider' => 'botcake', 'key' => 'mock', 'value' => '1'])->assertStatus(422);
        // Nor remove it (that would lock the stress page out of its own cleanup).
        $flag = PageSetting::where('page_name', 'Page Uno')->where('key', 'mock')->where('active', true)->first();
        $this->post('/chatblast/page-settings/' . $flag->id . '/deactivate')->assertSessionHasErrors('value');
        $this->assertTrue($flag->fresh()->active);
    }

    public function test_S_086_3_the_latency_setting_is_applied_and_capped(): void
    {
        Http::fake();
        $this->mockPage(['mock_latency_ms' => '1200']);
        $this->client()->sendContent(self::MOCK_ID, '9911112222333344', [['type' => 'text', 'text' => 'Hi']], 'mock');
        $this->assertContains(1.2, $this->clock->sleeps);

        $this->mockPage(['mock_latency_ms' => '99999']);
        $this->clock->sleeps = [];
        $this->client()->customerInfo(self::MOCK_ID, '9911112222333344', 'mock');
        $this->assertContains(5.0, $this->clock->sleeps, 'max 5000 ms');

        app(PageSettings::class)->set(self::PAGE, 'botcake', 'mock_latency_ms', '400', false, null, true);
        BotcakeMock::forget();
        $this->clock->sleeps = [];
        $this->client()->customerInfo(self::MOCK_ID, '9911112222333344', 'mock');
        $this->assertContains(0.4, $this->clock->sleeps, 'the default');
    }

    /** Start → Send "Hi {{first_name}}" (no failed port: a failed send stops the run). */
    private function flowPage(array $nodes = null, array $edges = null): ChatbotFlow
    {
        $graph = \App\Services\Chatbot\FlowGraph::clean(['nodes' => $nodes ?? [['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Hi {{first_name}}']]]]],
            'edges' => $edges ?? [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]]);
        $flow = ChatbotFlow::create(['name' => 'Stress flow', 'graph' => $graph]);
        \Illuminate\Support\Facades\DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);
        app(ChatbotPage::class)->link(self::PAGE, $flow);

        return $flow;
    }

    public function test_S_086_4_the_error_rate_gives_a_500_and_a_failed_reply(): void
    {
        Http::fake();
        $this->mockPage(['mock_error_rate' => '0.5']);
        $this->flowPage();
        BotcakeMock::$roll = fn () => 0.1; // below 0.5: this send fails
        $chat = BotcakeChat::create(['page_id' => self::MOCK_ID, 'page_name' => self::PAGE, 'psid' => '9911112222333344', 'name' => 'Test Customer', 'message' => 'Hi', 'payload' => [], 'received_at' => now()]);

        app(\App\Services\Chatbot\ChatbotRunner::class)->handle($chat->id);

        $reply = ChatbotReply::where('chat_id', $chat->id)->first();
        $this->assertSame(['failed', 500], [$reply->status, $reply->http]);
        Http::assertNothingSent();
        $this->assertSame(1, BotcakeMock::counts(self::MOCK_ID)['error'] ?? 0);
    }

    public function test_S_086_5_a_chat_goes_through_the_endpoint_and_the_flow_to_a_sent_reply_with_ai(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'Opo, available pa po!']]]])]);
        $this->mockPage();
        app(PageSettings::class)->set(self::PAGE, 'botcake', 'chat.key', 'stress-key-1234567890abcdef', false, null, true);
        $this->flowPage([['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'ai', 'config' => ['system' => 'Be nice', 'send' => true, 'history' => 0]]],
            [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]);
        BotcakePsid::create(['page' => self::PAGE, 'psid' => '9911112222333344', 'fb_name' => 'Stress 1', 'status' => 'available', 'source' => 'stress_test']);

        $this->postJson('/api/botcake/chat', ['page_id' => self::MOCK_ID, 'psid' => '9911112222333344', 'name' => 'Ana Stress', 'message' => 'Available pa po?'],
            ['X-Chat-Key' => 'stress-key-1234567890abcdef'])->assertOk();

        $chat = BotcakeChat::where('page_id', self::MOCK_ID)->firstOrFail();
        app(\App\Services\Chatbot\ChatbotRunner::class)->handle($chat->id); // the job (sync queue may have run it already: once only)
        $reply = ChatbotReply::where('chat_id', $chat->id)->firstOrFail();
        $this->assertSame(['sent', [['type' => 'text', 'text' => 'Opo, available pa po!']]], [$reply->status, $reply->content]);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'botcake.io'));
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'api.openai.com'));
    }

    public function test_S_086_6_stress_page_seeds_copies_the_flow_prints_stats_and_cleans_up_and_never_touches_a_real_page(): void
    {
        Http::fake();
        $key = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stress-key-' . uniqid() . '.txt';
        $this->assertSame(0, Artisan::call('botcake:stress-page', ['--seed' => 3, '--key-file' => $key]));
        $out = Artisan::output();
        $s = app(PageSettings::class);
        $this->assertSame([self::MOCK_ID, 'mock', '1'], [$s->get(self::PAGE, 'botcake', 'page_id'), $s->get(self::PAGE, 'botcake', 'token'), $s->get(self::PAGE, 'botcake', 'mock')]);
        $stored = trim((string) file_get_contents($key));
        $this->assertSame($stored, $s->get(self::PAGE, 'botcake', 'chat.key'));
        $this->assertGreaterThanOrEqual(32, strlen($stored));
        $this->assertStringNotContainsString($stored, $out, 'the key is never printed');
        $psids = BotcakePsid::where('page', self::PAGE)->where('source', 'stress_test')->pluck('psid');
        $this->assertCount(3, $psids);
        $this->assertSame(['9900000000000000', '9900000000000001', '9900000000000002'], $psids->sort()->values()->all(), 'in order: the PSIDs the k6 scripts send');

        // --flow-from copies the chatbot settings and turns the page On.
        $this->page('Page Uno', '1001');
        $flow = ChatbotFlow::create(['name' => 'Main Flow (from CBB)', 'graph' => \App\Services\Chatbot\FlowGraph::blank()]);
        app(ChatbotPage::class)->link('Page Uno', $flow);
        $s->set('Page Uno', 'chatbot', 'prompt_1', 'You are a sales bot');
        $this->assertSame(0, Artisan::call('botcake:stress-page', ['--flow-from' => 'Page Uno']));
        $this->assertSame([(string) $flow->id, 'You are a sales bot', '1'], [$s->get(self::PAGE, 'chatbot', 'flow_id'), $s->get(self::PAGE, 'chatbot', 'prompt_1'), $s->get(self::PAGE, 'chatbot', 'active')]);

        // --stats
        BotcakeChat::create(['page_id' => self::MOCK_ID, 'page_name' => self::PAGE, 'psid' => $psids[0], 'name' => 'x', 'message' => 'Hi', 'payload' => [], 'received_at' => now()->subSeconds(3)]);
        $chat = BotcakeChat::where('page_id', self::MOCK_ID)->first();
        ChatbotReply::create(['page_id' => (int) self::MOCK_ID, 'psid' => (int) $psids[0], 'chat_id' => $chat->id, 'kind' => 'send', 'status' => 'sent', 'content' => [], 'sent_at' => now()]);
        $this->assertSame(0, Artisan::call('botcake:stress-page', ['--stats' => true]));
        $stats = Artisan::output();
        foreach (['Chats in', 'Flow runs', 'Replies sent', 'Replies failed', 'AI ok', 'Mock calls', 'Jobs waiting (chatbot)', 'Chat to first reply'] as $label) $this->assertStringContainsString($label, $stats, $label);
        $this->assertMatchesRegularExpression('/Chats in\s+1/', $stats);
        $this->assertMatchesRegularExpression('/avg 3\.0 s, p95 3\.0 s/', $stats);

        // --cleanup removes every row of the test page and nothing of the real one.
        $real = PageSetting::where('page_name', 'Page Uno')->count();
        $this->assertSame(0, Artisan::call('botcake:stress-page', ['--cleanup' => true]));
        $this->assertSame(0, PageSetting::where('page_name', self::PAGE)->count());
        $this->assertSame([0, 0, 0], [BotcakeChat::where('page_id', self::MOCK_ID)->count(), BotcakePsid::where('page', self::PAGE)->count(), ChatbotReply::where('page_id', (int) self::MOCK_ID)->count()]);
        $this->assertSame($real, PageSetting::where('page_name', 'Page Uno')->count());
        $this->assertStringContainsString('botcake_chats', Artisan::output());

        // Never on a real page: a page ID outside 99…, or a page name that has a real Botcake ID.
        foreach (['--seed' => 1, '--flow-from' => 'Page Uno', '--stats' => true, '--cleanup' => true] as $opt => $v) {
            $this->assertSame(1, Artisan::call('botcake:stress-page', [$opt => $v, '--page-id' => '1001']), $opt);
            $this->assertSame(1, Artisan::call('botcake:stress-page', [$opt => $v, '--page' => 'Page Uno']), $opt . ' on a real page name');
        }
        $this->assertSame('1001', $s->get('Page Uno', 'botcake', 'page_id'));
        @unlink($key);
    }

    public function test_S_086_7_a_real_page_without_an_active_page_id_is_never_touched_and_cleanup_keeps_other_pages(): void
    {
        Http::fake();
        $s = app(PageSettings::class);
        // A real page whose Botcake ID was cleared on /page-settings (the row is history now), and a second test page.
        $s->set('Real Shop', 'botcake', 'page_id', '1234567');
        $s->deactivate(PageSetting::where('page_name', 'Real Shop')->where('key', 'page_id')->first());
        $s->set('Real Shop', 'botcake', 'flow.shipping', '5501');
        \App\Models\BotcakeMessage::create(['page_name' => 'Real Shop', 'waybill' => 'JT1', 'kind' => 'in_transit', 'status' => 'sent', 'is_test' => false]);
        $before = [PageSetting::where('page_name', 'Real Shop')->count(), \App\Models\BotcakeMessage::where('page_name', 'Real Shop')->count()];
        foreach (['--cleanup' => true, '--seed' => 1, '--stats' => true, '--latency' => 100] as $opt => $v) {
            $this->assertSame(1, Artisan::call('botcake:stress-page', [$opt => $v, '--page' => 'Real Shop', '--key-file' => sys_get_temp_dir() . '/x.txt']), $opt);
        }
        $this->assertSame($before, [PageSetting::where('page_name', 'Real Shop')->count(), \App\Models\BotcakeMessage::where('page_name', 'Real Shop')->count()]);

        // Cleanup of the default test page leaves another test page and a real page alone.
        $key = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stress-key-' . uniqid() . '.txt';
        Artisan::call('botcake:stress-page', ['--seed' => 1, '--key-file' => $key]);
        Artisan::call('botcake:stress-page', ['--seed' => 1, '--key-file' => $key, '--page' => 'ZZ OTHER TEST', '--page-id' => '990000000000002']);
        foreach ([[self::PAGE, self::MOCK_ID], ['ZZ OTHER TEST', '990000000000002'], ['Page Uno', '1001']] as [$p, $id]) {
            ChatbotReply::create(['page_id' => (int) $id, 'psid' => 1, 'kind' => 'send', 'status' => 'sent', 'content' => [], 'sent_at' => now()]);
            \Illuminate\Support\Facades\DB::table('chatbot_events')->insert(['page_id' => (int) $id, 'psid' => 1, 'type' => 'run_started', 'detail' => '{}', 'created_at' => now()]);
            BotcakePsid::create(['page' => $p, 'psid' => '1', 'fb_name' => 'x', 'status' => 'verified', 'source' => 'chat_verify']);
        }
        Artisan::call('botcake:stress-page', ['--cleanup' => true]);
        $this->assertSame([0, 1, 1], [ChatbotReply::where('page_id', (int) self::MOCK_ID)->count(), ChatbotReply::where('page_id', 990000000000002)->count(), ChatbotReply::where('page_id', 1001)->count()]);
        $this->assertSame([1, 1], [\Illuminate\Support\Facades\DB::table('chatbot_events')->where('page_id', 990000000000002)->count(), BotcakePsid::where('page', 'Page Uno')->count()]);
        $this->assertGreaterThanOrEqual(4, PageSetting::where('page_name', 'ZZ OTHER TEST')->where('active', true)->count(), 'the other test page keeps its settings');
        @unlink($key);
    }

    public function test_S_086_8_latency_and_error_rate_are_set_from_the_command_and_the_flag_lifecycle(): void
    {
        Http::fake(['botcake.io/*' => Http::response(['success' => true])]);
        $key = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stress-key-' . uniqid() . '.txt';
        Artisan::call('botcake:stress-page', ['--seed' => 0, '--key-file' => $key]);
        $this->assertSame(0, Artisan::call('botcake:stress-page', ['--latency' => 1500, '--error-rate' => 0.2]));
        $this->assertSame(['latency' => 1500, 'error' => 0.2], BotcakeMock::pages()[self::MOCK_ID]);
        $this->assertSame(1, Artisan::call('botcake:stress-page', ['--latency' => 6000]), 'over 5000 ms');
        $this->assertSame(1, Artisan::call('botcake:stress-page', ['--error-rate' => 0.6]), 'over 0.5');

        // Without the flag, a 99… page calls the real host; removing the flag takes effect at once.
        $s = app(PageSettings::class);
        $s->deactivate(PageSetting::where('page_name', self::PAGE)->where('key', 'mock')->where('active', true)->first());
        $this->client()->sendContent(self::MOCK_ID, '9900000000000000', [['type' => 'text', 'text' => 'Hi']], 'mock');
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'pages/' . self::MOCK_ID . '/flows/send_content'));
        $this->assertSame(1, Artisan::call('botcake:stress-page', ['--cleanup' => true]), 'no longer a test page: refused');
        @unlink($key);
    }

    public function test_S_086_9_stats_count_ai_results_and_replies(): void
    {
        Http::fake();
        $key = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stress-key-' . uniqid() . '.txt';
        Artisan::call('botcake:stress-page', ['--seed' => 1, '--key-file' => $key]);
        $ev = fn (string $result) => \Illuminate\Support\Facades\DB::table('chatbot_events')->insert(['page_id' => (int) self::MOCK_ID, 'psid' => 1, 'type' => 'ai',
            'detail' => json_encode(['result' => $result, 'model' => 'gpt-4o-mini']), 'created_at' => now()]);
        $ev('success'); $ev('success'); $ev('failed');
        ChatbotReply::create(['page_id' => (int) self::MOCK_ID, 'psid' => 1, 'kind' => 'send', 'status' => 'failed', 'content' => [], 'sent_at' => now()]);

        Artisan::call('botcake:stress-page', ['--stats' => true]);
        $out = Artisan::output();

        $this->assertMatchesRegularExpression('/AI ok\s+2\R/', $out);
        $this->assertMatchesRegularExpression('/AI failed\s+1\R/', $out);
        $this->assertMatchesRegularExpression('/Replies failed\s+1\R/', $out);
        @unlink($key);
    }
}
