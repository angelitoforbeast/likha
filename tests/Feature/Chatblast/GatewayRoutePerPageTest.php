<?php

namespace Tests\Feature\Chatblast;

use App\Models\PageSetting;
use App\Services\Chatbot\ChatbotProvider;
use App\Services\Chatbot\ForbeastPageSetup;
use App\Services\Chatbot\GatewayComments;
use App\Services\Chatbot\GatewayProvider;
use App\Services\Chatbot\Gateways;
use App\Services\Chatbot\GatewayTyping;
use App\Services\PageSettings;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * Ang address ng gateway kada page (chatbot.gateway_app): iisang gateway (parehong key at token ng page) na sumasagot sa
 * higit sa isang address, at ang Meta app ay ang sa address na tinawag. Kasama ang 409 app_not_connected at ang detalye
 * ng "Gateway did not answer". Fake na HTTP; walang totoong gateway.
 */
class GatewayRoutePerPageTest extends ChatblastTestCase
{
    private const GW = 'https://gw.example.test/api';
    private const APP2 = 'https://incepxion.example.test/api';
    private const PSID = '6012345678901234';
    /** Ang sagot ng padala kada address: [http, body]; null = walang sagot (ConnectException). */
    private array $send = [self::GW => [200, ['ok' => true]], self::APP2 => [200, ['ok' => true]]];
    /** Ang HTTP ng health (GET /v1/pages/{id}) kada address. */
    private array $health = [self::GW => 200, self::APP2 => 200];
    private ?ConnectException $drop = null;
    /** Ang Guzzle options ng bawat tawag (para sa IPv4). */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/0001_01_01_000002_create_jobs_table.php',
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_29_100000_create_botcake_send_requests_table.php',
            'database/migrations/2026_11_09_100000_add_via_to_chatbot_replies.php',
            'database/migrations/2026_11_13_100000_widen_via_on_chatbot_replies.php',
        ]]);
        config([
            'services.chatbot.replies_enabled' => true,
            'services.gateway.url' => self::GW, 'services.gateway.key' => 'gw_global_key', 'services.gateway.webhook_secret' => 'gw-secret-test-1q2w3e',
            'services.gateway.urls' => 'incepxion=' . self::APP2 . '/',
            'services.incepxionbot.url' => '',
        ]);
        $s = app(PageSettings::class);
        $this->page('Page Uno', '1001');
        $s->set('Page Uno', 'forbeast', 'token', 'fbp_uno_token', true, null, true);
        $s->set('Page Uno', 'forbeast', 'token_status', 'ok · connected', false, null, true);
        $s->set('Page Uno', 'forbeast', 'webhook_secret', 'gw-secret-test-1q2w3e', true, null, true);
        $s->set('Page Uno', 'chatbot', 'provider', 'gateway');
        PageSetting::where('provider', 'chatbot')->where('key', 'provider')->update(['created_at' => now()->subHours(3)]);

        Http::globalMiddleware(fn ($handler) => function ($request, array $options) use ($handler) {
            $this->options[] = $options;
            return $handler($request, $options);
        });
        Http::fake(function (HttpRequest $r) {
            $base = str_starts_with($r->url(), self::APP2) ? self::APP2 : self::GW;
            if (str_ends_with($r->url(), '/messages')) {
                if ($this->drop !== null) return Create::rejectionFor($this->drop);
                return Http::response($this->send[$base][1], $this->send[$base][0]);
            }
            if ($r->method() === 'GET' && str_ends_with($r->url(), '/v1/pages/1001')) {
                return Http::response(['connected' => true, 'can_send' => true, 'name' => 'Page Uno', 'id' => '1001',
                    'webhook' => ['url' => Gateways::eventsUrl('forbeast')]], $this->health[$base]);
            }

            return Http::response(['ok' => true]);
        });
    }

    private function route(?string $key, ?int $userId = null): void
    {
        if ($key === null) {
            PageSetting::where('provider', 'chatbot')->where('key', 'gateway_app')->where('active', true)->get()->each(fn ($s) => app(PageSettings::class)->deactivate($s));
            return;
        }
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'gateway_app', $key, false, $userId, true);
    }

    private function sendNow(): array
    {
        return (new GatewayProvider())->send('Page Uno', '1001', self::PSID, [['type' => 'text', 'text' => 'Hi po, secret text']], ChatbotProvider::ref());
    }

    /** @return array<int, string> ang mga tinawag (method + url) */
    private function calls(): array
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0]->method() . ' ' . $p[0]->url())->all();
    }

    public function test_S_223_1_the_addresses_come_from_config_and_without_a_list_nothing_changes(): void
    {
        $this->assertSame(['forbeast' => self::GW, 'incepxion' => self::APP2], Gateways::routes());

        config(['services.gateway.urls' => ' forbeast=https://other.example.test, incepxion=https://incepxion.example.test/api ,  Bad Key=https://x.test  ftp=ftp://x.test  two=https://two.example.test/v2/ broken ']);
        $this->assertSame(['forbeast' => self::GW, 'incepxion' => self::APP2, 'two' => 'https://two.example.test/v2'], Gateways::routes(), 'forbeast is always GATEWAY_URL; bad entries are skipped');

        config(['services.gateway.urls' => '']);
        $this->assertSame(['forbeast' => self::GW], Gateways::routes());
        $this->route('incepxion');
        $this->assertSame('sent', $this->sendNow()['status']);
        $this->assertSame(['POST ' . self::GW . '/v1/pages/1001/messages'], $this->calls(), 'no list: the setting is unknown, today\'s address');
    }

    public function test_S_223_2_a_page_on_incepxion_makes_every_page_call_there_and_a_page_without_the_setting_uses_the_default(): void
    {
        $this->assertSame('sent', $this->sendNow()['status']);
        $this->assertSame(['POST ' . self::GW . '/v1/pages/1001/messages'], $this->calls(), 'no setting: the default address');

        $before = count(Http::recorded());
        $this->route('incepxion');
        config(['services.gateway.typing' => true]);
        $this->assertSame('sent', $this->sendNow()['status']);
        GatewayTyping::start('Page Uno', '1001', self::PSID);
        app(GatewayComments::class)->reply('Page Uno', '1001', '1001_77', 'Salamat po!');
        app(GatewayComments::class)->privateReply('Page Uno', '1001', '1001_78', 'Hello po');
        app(\App\Services\Chatbot\ForbeastWatch::class)->health('Page Uno');
        app(ForbeastPageSetup::class)->retest('Page Uno');

        $this->assertSame([
            'POST ' . self::APP2 . '/v1/pages/1001/messages', 'POST ' . self::APP2 . '/v1/pages/1001/typing',
            'POST ' . self::APP2 . '/v1/pages/1001/comments/1001_77/reply', 'POST ' . self::APP2 . '/v1/pages/1001/comments/1001_78/private-reply',
            'GET ' . self::APP2 . '/v1/pages/1001', 'GET ' . self::APP2 . '/v1/pages/1001',
        ], array_slice($this->calls(), $before));
        Http::assertNotSent(fn (HttpRequest $r) => str_starts_with($r->url(), self::APP2) && !$r->hasHeader('Authorization', 'Bearer fbp_uno_token'));
        $this->assertSame([self::GW, 'forbeast'], [Gateways::url('forbeast'), Gateways::active('Page Uno')], 'site calls and the gateway choice keep the default');
    }

    public function test_S_223_2b_an_unknown_key_uses_the_default_and_warns_once(): void
    {
        Log::spy();
        $this->route('nowhere');

        $this->sendNow();
        $this->sendNow();

        $this->assertSame(['POST ' . self::GW . '/v1/pages/1001/messages', 'POST ' . self::GW . '/v1/pages/1001/messages'], $this->calls());
        Log::shouldHaveReceived('warning')->with('gateway route: unknown address key, using the default', \Mockery::on(fn ($c) => ($c['key'] ?? null) === 'nowhere'))->once();
    }

    public function test_S_223_2c_changing_the_setting_takes_effect_on_the_next_call(): void
    {
        $this->route('incepxion');
        $this->sendNow();
        $this->route('forbeast');
        $this->sendNow();
        $this->route(null);
        $this->sendNow();

        $this->assertSame(['POST ' . self::APP2 . '/v1/pages/1001/messages', 'POST ' . self::GW . '/v1/pages/1001/messages', 'POST ' . self::GW . '/v1/pages/1001/messages'], $this->calls());
    }

    public function test_S_223_3_a_409_app_not_connected_is_an_answer_with_a_clear_reason_and_no_retry(): void
    {
        Queue::fake();
        $this->route('incepxion');
        $this->send[self::APP2] = [409, ['error' => ['code' => 'app_not_connected', 'message' => 'The page has no token for this app.']]];

        $o = $this->sendNow();
        $this->assertSame(['failed', 409, 'This page is not connected to the incepxion Meta app on the gateway (HTTP 409)'], [$o['status'], $o['http'], $o['error']]);
        $this->assertSame(['POST ' . self::APP2 . '/v1/pages/1001/messages'], $this->calls(), 'one call, never another address');

        $this->sendNow();
        $this->sendNow();
        $this->assertSame('gateway', ChatbotProvider::name('Page Uno'), 'three of them do not trip "no answer"');
        $this->assertSame(0, PageSetting::where('provider', 'chatbot')->where('key', 'auto_switch')->count());

        // The same reading for the sequence pool, and for the plain error shape.
        $this->assertSame('This page is not connected to the forbeast Meta app on the gateway (HTTP 409)',
            GatewayProvider::outcome(['http' => 409, 'body' => ['error' => 'app_not_connected']], 'k', 'forbeast')['error']);
        $other = GatewayProvider::outcome(['http' => 409, 'body' => ['error' => 'busy']], 'k');
        $this->assertSame(['failed', 409], [$other['status'], $other['http']]);
        $this->assertStringStartsWith('Gateway ', $other['error'], 'other 409s as before');
    }

    public function test_S_223_4_the_pages_screen_shows_and_sets_the_address_only_with_more_than_one(): void
    {
        Queue::fake();
        $user = $this->user();
        $this->actingAs($user);
        $data = fn () => $this->getJson('/chatbot/data')->assertOk();
        $row = fn () => collect($data()->json('rows'))->firstWhere('page', 'Page Uno');

        $this->assertSame(['forbeast' => 'Forbeast', 'incepxion' => 'Incepxion'], $data()->json('gateway_apps'));
        $this->assertSame('forbeast', $row()['gateway_app']);
        $this->postJson('/chatbot/gateway-app', ['page_name' => 'page uno', 'gateway_app' => 'incepxion'])->assertOk()->assertJson(['gateway_app' => 'incepxion']);
        $this->assertSame('incepxion', $row()['gateway_app']);
        $this->assertSame($user->id, (int) PageSetting::where('provider', 'chatbot')->where('key', 'gateway_app')->where('active', true)->value('created_by'));
        $this->postJson('/chatbot/gateway-app', ['page_name' => 'Page Uno', 'gateway_app' => 'nowhere'])->assertStatus(422);
        $this->postJson('/chatbot/gateway-app', ['page_name' => 'Not Listed', 'gateway_app' => 'forbeast'])->assertStatus(422);
        $this->assertSame('incepxion', Gateways::route('Page Uno'));
        $this->get('/chatbot/pages')->assertOk()->assertSee('data-gateway-app-url', false);

        config(['services.gateway.urls' => '']);
        $this->assertNull($data()->json('gateway_apps'), 'one address: nothing to choose, and the data is as before');
        $this->assertArrayNotHasKey('gateway_app', $row());
        $this->postJson('/chatbot/gateway-app', ['page_name' => 'Page Uno', 'gateway_app' => 'forbeast'])->assertStatus(422);
    }

    public function test_S_223_5_the_move_to_botcake_and_back_keeps_the_address(): void
    {
        Queue::fake();
        $this->route('incepxion');
        $this->artisan('chatbot:forbeast-watch')->assertSuccessful();
        $this->send[self::APP2] = [500, ['ok' => false]];
        for ($i = 0; $i < 3; $i++) $this->sendNow();
        $this->assertSame('botcake', ChatbotProvider::name('Page Uno'));

        $this->send[self::APP2] = [200, ['ok' => true]];
        for ($i = 0; $i <= 30; $i++) {
            $this->travel(1)->minutes();
            $this->artisan('chatbot:forbeast-watch')->assertSuccessful();
        }
        $this->assertSame('gateway', ChatbotProvider::name('Page Uno'));
        $this->assertSame('incepxion', Gateways::route('Page Uno'));

        $before = count(Http::recorded());
        $this->sendNow();
        $this->assertSame(['POST ' . self::APP2 . '/v1/pages/1001/messages'], array_slice($this->calls(), $before));
    }

    public function test_S_223_6_a_send_without_an_answer_records_the_curl_error_and_times_never_the_token_or_text(): void
    {
        Log::spy();
        $this->drop = new ConnectException('cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for ' . self::GW . '/v1/pages/1001/messages',
            new \GuzzleHttp\Psr7\Request('POST', self::GW . '/v1/pages/1001/messages'), null,
            ['errno' => 28, 'error' => 'Operation timed out after 10001 milliseconds with 0 bytes received', 'connect_time' => 0.012, 'total_time' => 10.001]);

        $o = $this->sendNow();

        $this->assertSame('Gateway did not answer (HTTP 0) · cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received · connect 0.01 s, total 10.00 s', $o['error']);
        Log::shouldHaveReceived('warning')->with('gateway send: no answer', \Mockery::on(function ($c) {
            $flat = json_encode($c);
            return $c['curl_errno'] === 28 && $c['host'] === 'gw.example.test' && $c['route'] === 'forbeast' && $c['total_time'] === 10.001
                && !str_contains($flat, 'fbp_uno_token') && !str_contains($flat, 'secret text') && !str_contains($flat, 'gw_global_key');
        }))->once();

        // Only the message (no handler context): the cURL part of it, without the URL.
        $this->drop = new ConnectException('cURL error 6: Could not resolve host: gw.example.test (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for ' . self::GW . '/v1/pages/1001/messages',
            new \GuzzleHttp\Psr7\Request('POST', self::GW . '/v1/pages/1001/messages'));
        $this->assertSame('Gateway did not answer (HTTP 0) · cURL error 6: Could not resolve host: gw.example.test', $this->sendNow()['error']);
    }

    public function test_S_223_6b_force_ipv4_makes_the_gateway_calls_ipv4_only(): void
    {
        config(['services.gateway.typing' => true]);
        $this->sendNow();
        $this->assertArrayNotHasKey('force_ip_resolve', $this->options[0], 'off by default');

        config(['services.gateway.force_ipv4' => true]);
        $this->options = [];
        \Illuminate\Support\Facades\Cache::flush();
        $this->sendNow();
        GatewayTyping::start('Page Uno', '1001', self::PSID);
        app(GatewayComments::class)->reply('Page Uno', '1001', '1001_77', 'Salamat po!');
        app(\App\Services\Chatbot\ForbeastWatch::class)->health('Page Uno');

        $this->assertCount(4, $this->options);
        $this->assertSame(['v4', 'v4', 'v4', 'v4'], array_map(fn ($o) => $o['force_ip_resolve'] ?? null, $this->options));
    }
}
