<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\MarkChatbotReplied;
use App\Jobs\RunChatbotFlow;
use App\Models\BotcakeChat;
use App\Models\BotcakePsid;
use App\Models\ChatbotFlow;
use App\Models\PageSetting;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotProvider;
use App\Services\Chatbot\FlowGraph;
use App\Services\Chatbot\ForbeastWatch;
use App\Services\Chatbot\GatewayComments;
use App\Services\Chatbot\GatewayProvider;
use App\Services\Chatbot\Gateways;
use App\Services\Chatbot\GatewayTyping;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Dalawang gateway (forbeast at incepxionbot): ang page ay sumasagot sa isa lang (chatbot.gateway), tumatanggap mula sa
 * dalawa nang isang beses, at kusang lumilipat sa kabila kapag pumalya ang aktibo. Fake na HTTP; walang totoong gateway.
 */
class GatewayPerPageTest extends ChatblastTestCase
{
    private const GW = 'https://gw.example.test';
    private const IXB = 'https://ixb.example.test';
    private const GW_SECRET = 'gw-webhook-secret-test-9o8i7u';
    private const IXB_SECRET = 'ixb-webhook-secret-test-5t4r3e';
    private const PSID = '6012345678901234';
    /** Ang sagot ng health ng bawat gateway: [http, connected]. */
    private array $health = [self::GW => [200, true], self::IXB => [200, true]];
    /** Ang HTTP ng padala ng bawat gateway. */
    private array $send = [self::GW => 200, self::IXB => 200];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/0001_01_01_000002_create_jobs_table.php',
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_22_100000_widen_source_on_botcake_psids.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_inbox_indexes.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_29_100000_create_botcake_send_requests_table.php',
            'database/migrations/2026_11_09_100000_add_via_to_chatbot_replies.php',
            'database/migrations/2026_11_13_100000_widen_via_on_chatbot_replies.php',
        ]]);
        config([
            'services.chatbot.replies_enabled' => true,
            'services.gateway.url' => self::GW, 'services.gateway.key' => 'gw_global_key', 'services.gateway.webhook_secret' => self::GW_SECRET,
            'services.incepxionbot.url' => self::IXB, 'services.incepxionbot.key' => 'ixb_global_key', 'services.incepxionbot.webhook_secret' => self::IXB_SECRET,
            'services.botcake.chat_ips' => ['203.171.22.6'], 'services.botcake.chat_ip_mode' => 'log',
        ]);
        $s = app(PageSettings::class);
        $this->page('Page Uno', '1001');
        $this->connect('forbeast', 'fbp_uno_token');
        $this->connect('incepxionbot', 'ixb_uno_token');
        $s->set('Page Uno', 'chatbot', 'provider', 'gateway');
        PageSetting::where('provider', 'chatbot')->where('key', 'provider')->update(['created_at' => now()->subHours(3)]);
        BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Ana Cruz', 'psid' => self::PSID, 'status' => 'available', 'source' => 'api']);
        Http::fake(function (HttpRequest $r) {
            $base = str_starts_with($r->url(), self::IXB) ? self::IXB : self::GW;
            if ($r->method() === 'GET' && str_ends_with($r->url(), '/v1/pages/1001')) {
                [$http, $ok] = $this->health[$base];
                return Http::response(['connected' => $ok, 'can_send' => $ok, 'name' => 'Page Uno', 'id' => '1001',
                    'webhook' => ['url' => Gateways::eventsUrl($base === self::IXB ? 'incepxionbot' : 'forbeast')]], $http);
            }
            if (str_ends_with($r->url(), '/messages')) return Http::response(['ok' => $this->send[$base] < 300], $this->send[$base]);

            return Http::response(['ok' => true]);
        });
    }

    private function connect(string $gateway, string $token, string $page = 'Page Uno'): void
    {
        $s = app(PageSettings::class);
        $s->set($page, $gateway, 'token', $token, true, null, true);
        $s->set($page, $gateway, 'token_status', 'ok · connected', false, null, true);
        $s->set($page, $gateway, 'webhook_secret', $gateway === 'forbeast' ? self::GW_SECRET : self::IXB_SECRET, true, null, true);
    }

    private function useGateway(string $gateway, ?int $userId = null): void
    {
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'gateway', $gateway, false, $userId, true);
    }

    private function greeting(): ChatbotFlow
    {
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start'],
            ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'You said: {{last_input}}']]]],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]])]);
        app(ChatbotPage::class)->link('Page Uno', $flow);

        return $flow;
    }

    /** Isang event sa /api/gateway/events (forbeast) o /api/gateway/events/incepxionbot, pinirmahan ng $secret. */
    private function event(string $path, string $secret, array $e = [])
    {
        $body = json_encode($e + ['kind' => 'event', 'event_id' => 18342, 'page_id' => '1001', 'psid' => self::PSID, 'type' => 'message',
            'mid' => 'm_AbC1', 'timestamp' => 1761037200123, 'text' => 'Available pa po?', 'attachments' => [], 'is_echo' => false]);

        return $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_GATEWAY_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $body, $secret)], $body);
    }

    /** @return array<int, string> ang mga URL na tinawag (method + url) */
    private function calls(): array
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0]->method() . ' ' . $p[0]->url())->all();
    }

    private function sendNow(): array
    {
        return (new GatewayProvider())->send('Page Uno', '1001', self::PSID, [['type' => 'text', 'text' => 'Hi']], ChatbotProvider::ref());
    }

    /** @return array<int, array> ang mga auto-switch event ng page, luma muna */
    private function events(): array
    {
        return PageSetting::where('provider', 'chatbot')->where('key', 'auto_switch')->orderBy('id')->get()
            ->map(fn ($s) => json_decode((string) $s->plainValue(), true))->all();
    }

    private function watch(): void
    {
        $this->artisan('chatbot:forbeast-watch')->assertSuccessful();
    }

    public function test_S_194_1_without_a_second_gateway_a_page_uses_forbeast_and_the_second_path_is_closed(): void
    {
        Queue::fake();
        config(['services.incepxionbot.url' => '', 'services.incepxionbot.webhook_secret' => '']);

        $this->assertSame(['forbeast', ['forbeast']], [Gateways::active('Page Uno'), Gateways::setUpList()]);
        $this->assertSame('sent', $this->sendNow()['status']);
        $this->assertSame(['POST ' . self::GW . '/v1/pages/1001/messages'], $this->calls());
        $this->event('/api/gateway/events/incepxionbot', self::IXB_SECRET)->assertStatus(503);
        $this->assertSame(0, BotcakeChat::count());
    }

    public function test_S_194_2_a_page_on_incepxionbot_sends_types_and_replies_to_comments_only_there_with_its_token(): void
    {
        $this->useGateway('incepxionbot');

        $provider = ChatbotProvider::for('Page Uno');
        $sent = $provider->send('Page Uno', '1001', self::PSID, [['type' => 'text', 'text' => 'Hi']], ChatbotProvider::ref());
        GatewayTyping::start('Page Uno', '1001', self::PSID);
        app(GatewayComments::class)->reply('Page Uno', '1001', '1001_77', 'Salamat po!');

        $this->assertSame('sent', $sent['status']);
        $this->assertSame([
            'POST ' . self::IXB . '/v1/pages/1001/messages', 'POST ' . self::IXB . '/v1/pages/1001/typing', 'POST ' . self::IXB . '/v1/pages/1001/comments/1001_77/reply',
        ], $this->calls());
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer ixb_uno_token'));
        Http::assertNotSent(fn (HttpRequest $r) => str_starts_with($r->url(), self::GW) || $r->hasHeader('Authorization', 'Bearer fbp_uno_token'));
        $this->assertSame('incepxionbot', ChatbotProvider::viaOf($provider));
    }

    public function test_S_194_2b_a_flow_reply_records_the_gateway_that_sent_it(): void
    {
        $this->greeting();
        $this->useGateway('incepxionbot');
        $chat = BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => self::PSID, 'message' => 'Hi', 'payload' => ['source' => 'gateway'], 'received_at' => now()]);

        app(\App\Services\Chatbot\ChatbotRunner::class)->handle($chat->id);

        $this->assertSame(['sent', 'incepxionbot'], [\App\Models\ChatbotReply::sole()->status, \App\Models\ChatbotReply::sole()->via]);
        $this->assertSame(['Incepxionbot', 'incepxionbot'], [ChatbotProvider::viaLabel('incepxionbot'), ChatbotProvider::via('Page Uno', $chat)]);
    }

    public function test_S_194_3b_page_values_site_calls_and_echoes_know_the_second_gateway(): void
    {
        Queue::fake();
        config(['services.incepxionbot.site_key' => 'ixb_site_key', 'services.incepxionbot.site_secret' => 'ixb-site-secret', 'services.incepxionbot.app_id' => '99887766']);
        $signed = function (string $method, string $url, string $body, string $secret) {
            $ts = time();
            return $this->call($method, $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_GATEWAY_TIMESTAMP' => (string) $ts,
                'HTTP_X_GATEWAY_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret), 'HTTP_X_FORBEAST_USER' => 'ixb user'], $body);
        };

        // Ang page values: ang secret ng page sa incepxionbot ay tinatanggap; ang ibang secret ay hindi.
        $signed('GET', '/api/gateway/pages/1001/values', '', self::IXB_SECRET)->assertOk();
        $signed('GET', '/api/gateway/pages/1001/values', '', 'not-a-secret')->assertStatus(401);
        // Ang site: ang site secret ng incepxionbot; ang adopt ay isinusulat ang token sa incepxionbot at doon sasagot.
        $body = json_encode(['name' => 'Page Siete', 'token' => 'ixb_siete_token', 'mode' => 'take', 'actor' => 'Owner']);
        $signed('POST', '/api/gateway/site/pages/7007/adopt', $body, 'ixb-site-secret')->assertOk();
        $this->assertSame(['ixb_siete_token', '', 'incepxionbot'], [app(PageSettings::class)->get('Page Siete', 'incepxionbot', 'token'),
            (string) app(PageSettings::class)->get('Page Siete', 'forbeast', 'token'), Gateways::active('Page Siete')]);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT' && $r->url() === self::IXB . '/v1/pages/7007/webhook'
            && $r['url'] === rtrim((string) config('app.url'), '/') . '/api/gateway/events/incepxionbot');
        // Ang echo ng Meta app ng incepxionbot.
        $this->assertSame(['incepxionbot', 'Bot (Incepxionbot)'], [\App\Services\Chatbot\ChatEchoes::sender('99887766'), \App\Services\Chatbot\ChatEchoes::label('incepxionbot')]);
    }

    public function test_S_194_3_the_same_message_from_both_gateways_is_processed_once(): void
    {
        Queue::fake([RunChatbotFlow::class, MarkChatbotReplied::class]);
        $this->greeting();

        $this->event('/api/gateway/events/incepxionbot', self::IXB_SECRET, ['event_id' => 'ixb-1'])->assertOk();
        $this->event('/api/gateway/events', self::GW_SECRET)->assertOk();
        // Walang mid (referral): ang parehong oras ng Meta, magkaibang event id ng dalawang gateway.
        $ref = ['type' => 'referral', 'mid' => null, 'text' => null, 'referral' => ['ad_id' => '6045246247433'], 'timestamp' => 1761037299999];
        $this->event('/api/gateway/events', self::GW_SECRET, $ref + ['event_id' => 500])->assertOk();
        $this->event('/api/gateway/events/incepxionbot', self::IXB_SECRET, $ref + ['event_id' => 500])->assertOk();

        $chats = BotcakeChat::orderBy('id')->get();
        $this->assertCount(2, $chats);
        $this->assertSame(['m_AbC1', 'incepxionbot'], [$chats[0]->message_id, $chats[0]->payload['gateway'] ?? null]);
        $this->assertSame(['gw-event-500', null], [$chats[1]->message_id, $chats[1]->payload['gateway'] ?? null]);
        Queue::assertPushed(RunChatbotFlow::class, 2);
    }

    public function test_S_194_4_a_stalled_gateway_moves_the_page_to_the_other_one_and_back_after_30_healthy_minutes(): void
    {
        Queue::fake();
        $this->watch(); // ang health ng dalawa: maayos
        $this->send[self::GW] = 500;
        for ($i = 0; $i < 3; $i++) $this->sendNow();

        $this->assertSame(['gateway', 'incepxionbot'], [ChatbotProvider::name('Page Uno'), Gateways::active('Page Uno')]);
        $e = $this->events()[0];
        $this->assertSame(['switched', 'forbeast', 'incepxionbot', 'no_answer', 'forbeast'], [$e['result'], $e['from'], $e['to'], $e['condition'], $e['chosen']]);

        $this->send[self::GW] = 200;
        for ($i = 0; $i <= 29; $i++) {
            if ($i > 0) $this->travel(1)->minutes();
            $this->watch();
        }
        $this->assertSame('incepxionbot', Gateways::active('Page Uno'), 'not before 30 minutes');
        $this->travel(1)->minutes();
        $this->watch();

        $this->assertSame(['gateway', 'forbeast'], [ChatbotProvider::name('Page Uno'), Gateways::active('Page Uno')]);
        $back = $this->events()[1];
        $this->assertSame(['switched_back', 'incepxionbot', 'forbeast'], [$back['result'], $back['from'], $back['to']]);
    }

    public function test_S_194_4b_a_manual_choice_after_the_switch_is_never_moved_back(): void
    {
        Queue::fake();
        // Pumalya ang dalawa: Botcake, at ang pili ng page (forbeast) ang babalikan.
        $this->health[self::IXB] = [500, true];
        $this->watch();
        $this->send[self::GW] = 500;
        for ($i = 0; $i < 3; $i++) $this->sendNow();
        $this->assertSame('botcake', ChatbotProvider::name('Page Uno'));
        // Ang tao ang pumili ng gateway pagkatapos ng lipat.
        $this->useGateway('incepxionbot', $this->user()->id);
        $this->send[self::GW] = 200;
        $this->health[self::IXB] = [200, true];

        for ($i = 0; $i <= 31; $i++) {
            $this->travel(1)->minutes();
            $this->watch();
        }

        $this->assertSame(['botcake', 'incepxionbot'], [ChatbotProvider::name('Page Uno'), Gateways::active('Page Uno')]);
        $this->assertCount(1, $this->events());
    }

    public function test_S_194_5_when_both_gateways_stall_the_page_goes_to_botcake(): void
    {
        Queue::fake();
        $this->health[self::IXB] = [500, true];
        $this->watch();
        $this->send[self::GW] = 500;
        for ($i = 0; $i < 3; $i++) $this->sendNow();

        $this->assertSame('botcake', ChatbotProvider::name('Page Uno'));
        $e = $this->events()[0];
        $this->assertSame(['switched', 'gateway', 'botcake', 'forbeast'], [$e['result'], $e['from'], $e['to'], $e['gateway']]);
    }

    public function test_S_194_6_an_event_signed_with_the_other_gateways_secret_is_401(): void
    {
        Queue::fake();

        $this->event('/api/gateway/events/incepxionbot', self::GW_SECRET)->assertStatus(401);
        $this->event('/api/gateway/events', self::IXB_SECRET)->assertStatus(401);
        $this->event('/api/gateway/events/other', self::IXB_SECRET)->assertStatus(404);

        Queue::assertNothingPushed();
    }

    public function test_S_194_7_a_page_without_a_working_token_on_the_other_gateway_is_not_moved_there(): void
    {
        Queue::fake();
        app(PageSettings::class)->set('Page Uno', 'incepxionbot', 'token_status', 'invalid · Forbeast said "bad token" (HTTP 401)', false, null, true);
        $this->watch();
        $this->send[self::GW] = 500;
        for ($i = 0; $i < 3; $i++) $this->sendNow();

        $this->assertSame('botcake', ChatbotProvider::name('Page Uno'));
        $this->assertSame('forbeast', Gateways::active('Page Uno'));
    }

    public function test_S_194_8_the_pages_screen_shows_and_sets_the_gateway(): void
    {
        Queue::fake();
        $this->actingAs($this->user());
        $this->page('Page Dos', '2002');
        $this->connect('forbeast', 'fbp_dos_token', 'Page Dos');
        $row = fn (string $p = 'Page Uno') => collect($this->getJson('/chatbot/data')->assertOk()->json('rows'))->firstWhere('page', $p);

        $this->assertSame(['forbeast', ['forbeast', 'incepxionbot']], [$row()['gateway'], $row()['gateways']]);
        $this->assertSame(['forbeast'], $row('Page Dos')['gateways']);
        $this->postJson('/chatbot/gateway', ['page_name' => 'page uno', 'gateway' => 'incepxionbot'])->assertOk()->assertJson(['gateway' => 'incepxionbot']);
        $this->assertSame('incepxionbot', $row()['gateway']);
        $this->postJson('/chatbot/gateway', ['page_name' => 'Page Dos', 'gateway' => 'incepxionbot'])->assertStatus(422);
        $this->postJson('/chatbot/gateway', ['page_name' => 'Page Uno', 'gateway' => 'pancake'])->assertStatus(422);
        $this->assertSame('forbeast', Gateways::active('Page Dos'));
        $this->assertNotNull(PageSetting::where('provider', 'chatbot')->where('key', 'gateway')->where('active', true)->value('created_by'));
        $this->get('/chatbot/pages')->assertOk()->assertSee('data-gateway-url', false);
        // Ang Connections: may card ang incepxionbot, at ang Test nito ay sa sariling URL at token.
        $card = $this->getJson('/chatbot/connections?page_name=Page%20Uno')->assertOk()->json('incepxionbot');
        $this->assertSame(['••••oken', true], [$card['token'], $card['connected']]);
        $this->assertContains('Incepxionbot', $row()['connected']);
        $this->postJson('/chatbot/connections/incepxionbot-test', ['page_name' => 'Page Uno'])->assertOk()->assertJson(['status' => 'ok']);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::IXB . '/v1/pages/1001' && $r->hasHeader('Authorization', 'Bearer ixb_uno_token'));
    }

    public function test_S_194_9_incepxion_with_only_incepxionbot_uses_it_as_its_gateway(): void
    {
        Queue::fake();
        config(['services.gateway.url' => '', 'services.gateway.key' => '', 'services.gateway.webhook_secret' => '']);
        $this->actingAs($this->user());

        $this->assertSame(['incepxionbot', ['incepxionbot']], [Gateways::active('Page Uno'), Gateways::setUpList()]);
        $this->postJson('/chatbot/provider', ['page_name' => 'Page Uno', 'provider' => 'gateway'])->assertOk();
        $this->assertSame('sent', $this->sendNow()['status']);
        $this->assertSame(['POST ' . self::IXB . '/v1/pages/1001/messages'], $this->calls());
        $this->event('/api/gateway/events/incepxionbot', self::IXB_SECRET)->assertOk();
        $this->event('/api/gateway/events', self::IXB_SECRET)->assertStatus(401);
    }
}
