<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\HandleGatewayEvent;
use App\Models\PageSetting;
use App\Services\Chatbot\GatewayProvider;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Ang Forbeast token kada page (forbeast.token): Test sa GET /v1/pages/{id}, saka ang sariling webhook secret ng page
 * (PUT /v1/pages/{id}/webhook), ang pirma ng /api/gateway/events ayon sa page, ang padala gamit ang token ng page, at ang
 * pagbura (DELETE). Fake na HTTP; walang tawag sa totoong Forbeast.
 */
class ForbeastPageTokenTest extends ChatblastTestCase
{
    private const GW = 'https://gw.example.test/api';
    private const PAGE = self::GW . '/v1/pages/1001';
    private const HOOK = self::PAGE . '/webhook';
    private const TOKEN_UNO = 'fbp_page_uno_token_a1b2c3d4e5f6';
    private const GLOBAL_SECRET = 'gw-global-secret-test-5t6y7u';
    private const PSID = '6012345678901234';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_22_100000_widen_source_on_botcake_psids.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_inbox_indexes.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_29_100000_create_botcake_send_requests_table.php',
        ]]);
        config([
            'app.url' => 'https://likha.example.test/',
            'services.gateway.url' => self::GW, 'services.gateway.key' => '',
            'services.gateway.webhook_secret' => self::GLOBAL_SECRET, 'services.gateway.timeout' => 10,
        ]);
        $this->page('Page Uno', '1001');
        $this->page('Page Dos', '1002');
        $this->actingAs($this->user());
    }

    private function okPage(): array
    {
        return [self::PAGE => Http::response(['id' => '1001', 'name' => 'Tech Flask', 'connected' => true, 'webhook' => null, 'can_send' => true]),
            self::HOOK => Http::response(['ok' => true])];
    }

    private function saveToken(string $token = self::TOKEN_UNO)
    {
        return $this->postJson('/chatblast/page-settings/cell', ['page_name' => 'Page Uno', 'provider' => 'forbeast', 'key' => 'token', 'value' => $token, 'is_secret' => true]);
    }

    private function setting(string $key, string $page = 'Page Uno'): ?string
    {
        return app(PageSettings::class)->get($page, 'forbeast', $key);
    }

    private function event(string $pageId, ?string $secret)
    {
        $body = json_encode(['kind' => 'event', 'event_id' => 1, 'page_id' => $pageId, 'psid' => self::PSID, 'type' => 'read', 'mid' => null, 'timestamp' => 1761037200123]);

        return $this->call('POST', '/api/gateway/events', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_GATEWAY_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $body, (string) $secret)], $body);
    }

    public function test_S_113_1_saving_a_token_tests_it_then_registers_the_webhook_with_a_stored_page_secret(): void
    {
        Http::fake($this->okPage());

        $res = $this->saveToken()->assertOk()->assertJson(['forbeast' => ['status' => 'ok', 'name' => 'Tech Flask', 'connected' => true]]);

        $secret = $this->setting('webhook_secret');
        $this->assertSame(64, strlen((string) $secret));
        $this->assertSame('ok · Forbeast connected (Tech Flask)', $this->setting('token_status'));
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'GET' && $r->url() === self::PAGE && $r->hasHeader('Authorization', 'Bearer ' . self::TOKEN_UNO));
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT' && $r->url() === self::HOOK && $r->hasHeader('Authorization', 'Bearer ' . self::TOKEN_UNO)
            && $r->data() === ['url' => 'https://likha.example.test/api/gateway/events', 'secret' => $secret]);
        $this->assertStringNotContainsString($secret, $res->getContent());
        $this->assertStringNotContainsString(self::TOKEN_UNO, $res->getContent());
        $this->assertTrue(PageSetting::where('provider', 'forbeast')->where('key', 'webhook_secret')->where('active', true)->sole()->is_secret);
    }

    public function test_S_113_2_a_bad_token_shows_the_error_and_registers_nothing(): void
    {
        Http::fake([self::PAGE => Http::sequence()->push(['error' => ['code' => 'unauthorized', 'message' => 'Invalid page token.']], 401)->push(['id' => '1001', 'name' => 'Tech Flask']),
            self::HOOK => Http::response(['error' => ['message' => 'Bad url.']], 422)]);

        $this->saveToken()->assertOk()->assertJson(['forbeast' => ['status' => 'invalid', 'connected' => false]]);

        $this->assertSame('invalid · Forbeast said "Invalid page token." (HTTP 401)', $this->setting('token_status'));
        $this->assertNull($this->setting('webhook_secret'));
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'PUT');

        // Tinanggap ang token pero hindi ang webhook: walang secret na naitala, at hindi ok ang status.
        $this->saveToken('fbp_page_uno_token_second_9k8j')->assertOk()->assertJson(['forbeast' => ['status' => 'error', 'connected' => false]]);
        $this->assertSame('error · Webhook not set: Forbeast said "Bad url." (HTTP 422)', $this->setting('token_status'));
        $this->assertNull($this->setting('webhook_secret'));
    }

    public function test_S_113_3_the_test_button_retests_and_reconnects_from_page_settings_and_from_the_chatbot_row(): void
    {
        Http::fake($this->okPage());
        app(PageSettings::class)->set('Page Uno', 'forbeast', 'token', self::TOKEN_UNO);

        $this->postJson('/chatblast/page-settings/forbeast-test', ['page_name' => 'page uno'])->assertOk()->assertJson(['forbeast' => ['status' => 'ok', 'connected' => true]]);
        $first = $this->setting('webhook_secret');
        $this->postJson('/chatbot/forbeast-token', ['page_name' => 'Page Uno'])->assertOk()->assertJson(['forbeast' => ['status' => 'ok']]);
        $this->assertNotSame($first, $this->setting('webhook_secret'));
        $this->postJson('/chatblast/page-settings/forbeast-test', ['page_name' => 'Page Dos'])->assertOk()->assertJson(['forbeast' => ['status' => 'skipped']]);
        Http::assertSentCount(4);
    }

    public function test_S_113_4_the_token_is_saved_from_the_chatbot_row_with_the_same_access_as_a_cell(): void
    {
        Http::fake($this->okPage());

        $this->postJson('/chatbot/forbeast-token', ['page_name' => 'Page Uno', 'token' => self::TOKEN_UNO])->assertOk()->assertJson(['forbeast' => ['status' => 'ok', 'connected' => true]]);
        $this->assertSame(self::TOKEN_UNO, $this->setting('token'));
        $this->postJson('/chatbot/forbeast-token', ['page_name' => 'No Such Page', 'token' => self::TOKEN_UNO])->assertStatus(422);

        $this->actingAs($this->user('Packer', 'packer@example.test'));
        $this->postJson('/chatbot/forbeast-token', ['page_name' => 'Page Uno', 'clear' => true])->assertStatus(403);
        $this->assertSame(self::TOKEN_UNO, $this->setting('token'));
    }

    public function test_S_113_5_inbound_is_verified_with_the_pages_own_secret_and_falls_back_to_the_global_one(): void
    {
        Queue::fake();
        app(PageSettings::class)->set('Page Uno', 'forbeast', 'webhook_secret', 'page-uno-own-secret', true, null, true);

        $this->event('1001', 'page-uno-own-secret')->assertOk();
        $this->event('1001', self::GLOBAL_SECRET)->assertStatus(401);
        $this->event('1002', self::GLOBAL_SECRET)->assertOk();
        $this->event('1002', 'page-uno-own-secret')->assertStatus(401);
        $this->event('9999', self::GLOBAL_SECRET)->assertStatus(401);
        $this->event('', self::GLOBAL_SECRET)->assertStatus(401); // walang page_id

        Queue::assertPushed(HandleGatewayEvent::class, 2);
    }

    public function test_S_113_6_a_send_uses_the_pages_token_and_otherwise_the_global_key(): void
    {
        Http::fake([self::GW . '/v1/pages/*/messages' => Http::response(['id' => 's1', 'status' => 'queued'], 202)]);
        config(['services.gateway.key' => 'gw_global_key_9z8x']);
        app(PageSettings::class)->set('Page Uno', 'forbeast', 'token', self::TOKEN_UNO);
        $msg = [['type' => 'text', 'text' => 'Hi']];

        $this->assertSame('sent', (new GatewayProvider())->send('Page Uno', '1001', self::PSID, $msg, 'likha-a')['status']);
        $this->assertSame('sent', (new GatewayProvider())->send('Page Dos', '1002', self::PSID, $msg, 'likha-b')['status']);
        config(['services.gateway.key' => '']);
        $this->assertSame('failed', (new GatewayProvider())->send('Page Dos', '1002', self::PSID, $msg, 'likha-c')['status']);

        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/1001/') && $r->hasHeader('Authorization', 'Bearer ' . self::TOKEN_UNO));
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/1002/') && $r->hasHeader('Authorization', 'Bearer gw_global_key_9z8x'));
    }

    public function test_S_113_7_replies_via_forbeast_needs_a_working_token_of_the_page(): void
    {
        Http::fake([self::PAGE => Http::sequence()->push(['error' => ['message' => 'Invalid page token.']], 401)
            ->push(['id' => '1001', 'name' => 'Tech Flask']), self::HOOK => Http::response(['ok' => true])]);
        $ready = fn () => collect($this->getJson('/chatbot/data')->json('rows'))->pluck('forbeast.ready', 'page')->all();

        $this->assertSame(['Page Dos' => false, 'Page Uno' => false], collect($ready())->sortKeys()->all());
        $this->postJson('/chatbot/provider', ['page_name' => 'Page Uno', 'provider' => 'gateway'])->assertStatus(422)->assertJson(['error' => 'Add the Forbeast token first: Page Uno']);
        $this->saveToken();
        $this->postJson('/chatbot/provider', ['page_name' => 'Page Uno', 'provider' => 'gateway'])->assertStatus(422);

        $this->saveToken('fbp_page_uno_token_second_9k8j');
        $this->assertTrue($ready()['Page Uno']);
        $this->postJson('/chatbot/provider', ['page_name' => 'Page Uno', 'provider' => 'gateway'])->assertOk();
        $this->postJson('/chatbot/provider', ['page_names' => ['Page Uno', 'Page Dos'], 'provider' => 'gateway'])->assertStatus(422)->assertJson(['error' => 'Add the Forbeast token first: Page Dos']);
        $this->postJson('/chatbot/provider', ['page_names' => ['Page Uno', 'Page Dos'], 'provider' => 'botcake'])->assertOk();

        // The global key is no proof that a page is set up (Alexa Angeles, 2026-10-23): Page Dos still needs its own token.
        config(['services.gateway.key' => 'gw_global_key_9z8x']);
        $this->postJson('/chatbot/provider', ['page_names' => ['Page Uno', 'Page Dos'], 'provider' => 'gateway'])->assertStatus(422)->assertJson(['error' => 'Add the Forbeast token first: Page Dos']);
        $this->assertFalse($ready()['Page Dos']);
        $this->get('/chatbot/pages')->assertOk()->assertSee('Add the Forbeast token first', false);
    }

    public function test_S_113_8_clearing_the_token_deletes_the_webhook_and_removes_both_keys(): void
    {
        Http::fake([self::HOOK => Http::sequence()->push(['ok' => true])->push(null, 204)] + $this->okPage());
        $this->saveToken();
        $this->assertNotNull($this->setting('webhook_secret'));

        $this->postJson('/chatblast/page-settings/cell', ['page_name' => 'Page Uno', 'provider' => 'forbeast', 'key' => 'token', 'value' => '', 'clear' => true])
            ->assertOk()->assertJson(['status' => 'cleared']);

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && $r->url() === self::HOOK && $r->hasHeader('Authorization', 'Bearer ' . self::TOKEN_UNO));
        $this->assertSame([null, null, null], [$this->setting('token'), $this->setting('webhook_secret'), $this->setting('token_status')]);
    }

    public function test_S_113_8b_clearing_still_removes_the_keys_when_forbeast_does_not_answer(): void
    {
        Http::fake([self::HOOK => Http::sequence()->push(['ok' => true])->push(['error' => 'down'], 500)] + $this->okPage());
        $this->saveToken();

        $this->postJson('/chatbot/forbeast-token', ['page_name' => 'Page Uno', 'clear' => true])->assertOk()->assertJson(['forbeast' => ['token' => null, 'connected' => false]]);

        $this->assertSame([null, null], [$this->setting('token'), $this->setting('webhook_secret')]);
    }

    public function test_S_113_9_secrets_are_never_rendered_only_the_last_four(): void
    {
        Http::fake($this->okPage());
        $this->saveToken();
        $secret = $this->setting('webhook_secret');

        $outputs = [
            $this->get('/chatblast/page-settings')->assertOk()->getContent(),
            $this->getJson('/chatblast/page-settings/grid')->assertOk()->getContent(),
            $this->getJson('/chatbot/data')->assertOk()->getContent(),
        ];
        foreach ($outputs as $out) {
            $this->assertStringNotContainsString(self::TOKEN_UNO, $out);
            $this->assertStringNotContainsString($secret, $out);
        }
        $this->assertSame('••••' . substr(self::TOKEN_UNO, -4), collect(json_decode($outputs[1], true)['rows'])->firstWhere('name', 'Page Uno')['cells']['forbeast.token']['value']);
        $this->assertSame('••••' . substr(self::TOKEN_UNO, -4), collect(json_decode($outputs[2], true)['rows'])->firstWhere('page', 'Page Uno')['forbeast']['token']);
        $this->assertNotContains('forbeast.webhook_secret', array_column(json_decode($outputs[1], true)['columns'], 'key'));
    }
}
