<?php

namespace Tests\Feature\Chatblast;

use App\Models\PageSetting;
use App\Services\Chatbot\ChatbotProvider;
use App\Services\Chatbot\ForbeastPageSetup;
use App\Services\Chatbot\Gateways;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Iisang "Replies via" sa /chatbot/pages: Botcake, o Bot N (isang address ng gateway). Ang pili ay isinusulat sa iisang
 * save: chatbot.provider at chatbot.gateway_app, lahat o wala, may user ang bawat row. Fake na HTTP.
 */
class RepliesViaChoiceTest extends ChatblastTestCase
{
    private const GW = 'https://gw.example.test/api';
    private const APP2 = 'https://incepxion.example.test/api';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake(fn () => Http::response(['ok' => true]));
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
        ]]);
        config([
            'services.gateway.url' => self::GW, 'services.gateway.key' => 'gw_global_key', 'services.gateway.webhook_secret' => 'gw-secret-test-1q2w3e',
            'services.gateway.urls' => 'incepxion=' . self::APP2,
            'services.incepxionbot.url' => '',
        ]);
        $s = app(PageSettings::class);
        foreach (['Page Uno' => '1001', 'Page Dos' => '1002'] as $page => $id) {
            $this->page($page, $id);
            $s->set($page, 'forbeast', 'token', 'fbp_' . $id . '_token', true, null, true);
            $s->set($page, 'forbeast', 'token_status', 'ok · connected', false, null, true);
            $s->set($page, 'forbeast', 'webhook_secret', 'gw-secret-test-1q2w3e', true, null, true);
        }
    }

    private function active(string $page, string $key): ?PageSetting
    {
        return app(PageSettings::class)->pageQuery($page)->where('provider', 'chatbot')->where('key', $key)->where('active', true)->first();
    }

    public function test_S_225_1_1_bot_2_sets_the_provider_and_the_address_in_one_save_with_the_user(): void
    {
        $user = $this->user();
        $this->actingAs($user);

        $this->postJson('/chatbot/provider', ['page_name' => 'Page Uno', 'provider' => 'gateway', 'gateway_app' => 'incepxion'])
            ->assertOk()->assertJson(['provider' => 'gateway', 'gateway_app' => 'incepxion']);

        $this->assertSame(['gateway', 'incepxion'], [ChatbotProvider::name('Page Uno'), Gateways::route('Page Uno')]);
        $this->assertSame([$user->id, $user->id], [(int) $this->active('Page Uno', 'provider')->created_by, (int) $this->active('Page Uno', 'gateway_app')->created_by]);

        // Bot 1 is the default address, written as a change too.
        $this->postJson('/chatbot/provider', ['page_name' => 'Page Uno', 'provider' => 'gateway', 'gateway_app' => 'forbeast'])->assertOk();
        $this->assertSame('forbeast', Gateways::route('Page Uno'));
        $this->assertSame(2, app(PageSettings::class)->pageQuery('Page Uno')->where('provider', 'chatbot')->where('key', 'gateway_app')->count(), 'the old address is history');
    }

    public function test_S_225_1_2_botcake_keeps_the_address(): void
    {
        $this->actingAs($this->user());
        $this->postJson('/chatbot/provider', ['page_name' => 'Page Uno', 'provider' => 'gateway', 'gateway_app' => 'incepxion'])->assertOk();

        $this->postJson('/chatbot/provider', ['page_name' => 'Page Uno', 'provider' => 'botcake', 'gateway_app' => 'forbeast'])
            ->assertOk()->assertJson(['provider' => 'botcake']);

        $this->assertSame(['botcake', 'incepxion'], [ChatbotProvider::name('Page Uno'), Gateways::route('Page Uno')]);
    }

    public function test_S_225_1_3_a_bad_address_writes_nothing_for_any_page(): void
    {
        $this->actingAs($this->user());

        $this->postJson('/chatbot/provider', ['page_names' => ['Page Uno', 'Page Dos'], 'provider' => 'gateway', 'gateway_app' => 'nowhere'])->assertStatus(422);
        $this->postJson('/chatbot/provider', ['page_names' => ['Page Uno', 'Not Listed'], 'provider' => 'gateway', 'gateway_app' => 'incepxion'])->assertStatus(422);

        $this->assertNull($this->active('Page Uno', 'provider'));
        $this->assertNull($this->active('Page Uno', 'gateway_app'));
        $this->assertNull($this->active('Page Dos', 'gateway_app'));
    }

    public function test_S_225_1_4_the_bulk_apply_sets_both_for_every_selected_page(): void
    {
        $this->actingAs($this->user());

        $this->postJson('/chatbot/provider', ['page_names' => ['Page Uno', 'page dos'], 'provider' => 'gateway', 'gateway_app' => 'incepxion'])
            ->assertOk()->assertJson(['pages' => ['Page Uno', 'Page Dos']]);

        foreach (['Page Uno', 'Page Dos'] as $page) $this->assertSame(['gateway', 'incepxion'], [ChatbotProvider::name($page), Gateways::route($page)]);
    }

    public function test_S_225_1_5_with_one_address_the_choice_is_botcake_or_forbeast_as_before(): void
    {
        $this->actingAs($this->user());
        config(['services.gateway.urls' => '']);

        $this->postJson('/chatbot/provider', ['page_name' => 'Page Uno', 'provider' => 'gateway'])->assertOk()->assertJsonMissing(['gateway_app' => 'forbeast']);
        $this->assertSame('gateway', ChatbotProvider::name('Page Uno'));
        $this->assertNull($this->active('Page Uno', 'gateway_app'), 'no address written');

        $this->postJson('/chatbot/provider', ['page_name' => 'Page Uno', 'provider' => 'gateway', 'gateway_app' => 'incepxion'])->assertStatus(422);
    }

    public function test_S_225_1_6_the_screen_has_one_replies_via_select_and_no_app_select(): void
    {
        $this->actingAs($this->user());

        $html = $this->get('/chatbot/pages')->assertOk()->getContent();

        $this->assertStringContainsString('function repliesViaSelect(', $html);
        $this->assertStringNotContainsString('function gatewayAppSelect(', $html);
        $this->assertStringNotContainsString("'App: '", $html);
        $this->assertStringContainsString("'Bot ' + (i + 1) + ' (' + gatewayApps[k] + ')'", $html, 'Bot N (<address>) labels');
        $this->assertStringContainsString('gateway_app: wantApp', $html, 'the bulk apply sends the address too');
        $this->assertStringContainsString("const routeOk = v => !r.route_apps || r.route_apps[v.split(':')[1]] === 'ok';", $html, 'Bot N only where the page has a working token for that app');
        $this->assertStringContainsString("bot: {label: 'Bot', values: r => [botLabel(r)]},", $html, 'the Bot filter lists Bot N per address, as Replies via names it');
    }

    public function test_bot_n_is_offered_and_saved_only_where_the_page_has_a_working_token_for_that_app(): void
    {
        $this->actingAs($this->user());
        // The Forbeast page check (GET /v1/pages/{id}) now names the page's token per Meta app.
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(fn () => Http::response(['id' => '1001', 'name' => 'Page Uno', 'connected' => true, 'can_send' => true,
            'webhook' => ['url' => Gateways::eventsUrl('forbeast')], 'apps' => ['forbeast' => 'reconnect_needed', 'incepxion' => 'ok', 'Bad Key!' => 'ok', 'other' => 'weird']]));
        app(ForbeastPageSetup::class)->retest('Page Uno');
        $this->assertSame(['forbeast' => 'reconnect_needed', 'incepxion' => 'ok', 'other' => null], app(ForbeastPageSetup::class)->apps('Page Uno'));

        $row = collect($this->getJson('/chatbot/data')->assertOk()->json('rows'))->firstWhere('page', 'Page Uno');
        $this->assertSame('ok', $row['route_apps']['incepxion']);

        // Bot 1 (Forbeast): the page's token there needs a reconnect, so it is refused; Bot 2 is saved.
        $this->postJson('/chatbot/provider', ['page_name' => 'Page Uno', 'provider' => 'gateway', 'gateway_app' => 'forbeast'])
            ->assertStatus(422)->assertJsonFragment(['error' => 'Forbeast is not connected for: Page Uno']);
        $this->postJson('/chatbot/provider', ['page_name' => 'Page Uno', 'provider' => 'gateway', 'gateway_app' => 'incepxion'])->assertOk();
        // A page never checked against the new Forbeast (no apps saved): as before.
        $this->postJson('/chatbot/provider', ['page_name' => 'Page Dos', 'provider' => 'gateway', 'gateway_app' => 'forbeast'])->assertOk();
    }

    public function test_connecting_a_page_that_has_a_token_only_for_bot_2_sets_its_webhook_and_address_there(): void
    {
        // A page claimed after it was connected through the Incepxion app only: Bot 1's address refuses its webhook (409).
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            self::GW . '/v1/pages/1001/webhook' => Http::response(['error' => ['code' => 'app_not_connected']], 409),
            self::APP2 . '/v1/pages/1001/webhook' => Http::response(['ok' => true]),
            '*' => Http::response(['id' => '1001', 'name' => 'Page Uno', 'connected' => true, 'apps' => ['forbeast' => null, 'incepxion' => 'ok']]),
        ]);

        $res = app(ForbeastPageSetup::class)->connect('Page Uno');

        $this->assertSame('ok', $res['status']);
        $this->assertSame('incepxion', Gateways::route('Page Uno'));
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r->url() === self::APP2 . '/v1/pages/1001/webhook');
        Http::assertNotSent(fn ($r) => $r->method() === 'PUT' && $r->url() === self::GW . '/v1/pages/1001/webhook');
    }
}
