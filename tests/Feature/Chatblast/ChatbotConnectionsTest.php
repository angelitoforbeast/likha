<?php

namespace Tests\Feature\Chatblast;

use App\Models\PageSetting;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Ang Connections popup ng /chatbot/pages: Botcake at Forbeast (Page ID at token) ng isang page. Ang token ay sinusubukan
 * bago i-save, ang ibang site na nakakabit na ay kailangan ng confirm, at ang Botcake ay kusang nagkakaroon ng chat block.
 * Fake na HTTP; walang tawag sa totoong Botcake o Forbeast.
 */
class ChatbotConnectionsTest extends ChatblastTestCase
{
    private const GW = 'https://gw.example.test/api';
    private const BC = 'https://botcake.io/api/public_api/v1/pages/1001';
    private const NEW_BC = 'tok-botcake-new-test-9z8y7x';
    private const NEW_FB = 'fbp_page_uno_token_q1w2e3r4';
    private $ceo;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_10_20_100000_create_botcake_chats_table.php']]);
        config(['app.url' => 'https://app.example.test/', 'services.gateway.url' => self::GW, 'services.gateway.key' => '']);
        $this->page('Page Uno', '1001');
        $this->actingAs($this->ceo = $this->user());
    }

    private function settings(): PageSettings
    {
        return app(PageSettings::class);
    }

    /** Ang sagot ng fake (napapalitan sa gitna ng test: ang unang Http::fake ang tumatama). */
    private array $fake = [];

    /**
     * Botcake fake, sa hugis ng totoong sagot: ang token test (custom_fields), ang laman ng Default Reply
     * (GET /default_reply/content: walang success key) at ang settings nito (GET /default_reply), at ang pagsulat.
     */
    private function botcake(?array $content = null, bool $tokenOk = true): void
    {
        $this->fake['content'] = $content ?? $this->content(null);
        $this->fake['token_ok'] = $tokenOk;
        Http::fake([
            self::BC . '/custom_fields*' => fn () => $this->fake['token_ok'] ? Http::response(['success' => true, 'data' => [['id' => 7701, 'name' => 'message']]]) : Http::response(['success' => false], 401),
            self::BC . '/default_reply/content' => fn (HttpRequest $r) => $r->method() === 'GET' ? Http::response($this->fake['content'], $this->fake['reply_status'] ?? 200) : Http::response(['success' => true]),
            self::BC . '/default_reply' => fn (HttpRequest $r) => Http::response($r->method() === 'GET'
                ? ['success' => true, 'settings' => ['is_published' => $this->fake['published'] ?? true, 'unlimit_default_reply' => true], 'page_id' => '1001', 'assistants' => []]
                : ['success' => true]),
            // Ang mga keyword (walang text, flag at flow id lang) at ang pangalan ng mga flow, sa hugis ng totoong sagot.
            self::BC . '/keywords' => fn () => Http::response($this->fake['keywords'] ?? ['data' => [], 'success' => true], $this->fake['keywords_status'] ?? 200),
            self::BC . '/flows' => fn () => Http::response(['data' => ['flows' => [['id' => 424398467, 'name' => 'ON DELIVERY SCRIPT ', 'is_removed' => false, 'parent_id' => null],
                ['id' => 1415244560, 'name' => 'Old Promo', 'is_removed' => false, 'parent_id' => null]]], 'success' => true]),
        ]);
    }

    /** Ang laman ng Default Reply: walang block (null), o isang JSON API card papunta sa $url na may X-Chat-Key. */
    private function content(?string $url, string $key = 'kept-chat-key-123'): array
    {
        $cards = $url === null ? [] : [['plugin_id' => 'json_api', 'config' => ['method' => 'post', 'url' => $url], 'headers' => [['key' => 'X-Chat-Key', 'value' => $key], ['key' => '', 'value' => '']]]];

        return ['name' => 'Default reply', 'status' => 'published', 'blocks' => $url === null ? [] : [['key' => 'chat_to_site', 'cards' => $cards]]];
    }

    private function forbeast(string $name = 'Page Uno', string $id = '1001', ?string $hook = null): void
    {
        $this->fake['page'] = ['id' => $id, 'name' => $name, 'connected' => true, 'webhook' => $hook ? ['url' => $hook, 'set_at' => '2026-10-24'] : null, 'can_send' => true];
        Http::fake([
            self::GW . '/v1/pages/1001' => fn () => Http::response($this->fake['page']),
            self::GW . '/v1/pages/1001/webhook' => Http::response(['ok' => true]),
        ]);
    }
    private function sentTo(string $part, ?string $method = null): array
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), $part) && ($method === null || $r->method() === $method))->values()->all();
    }

    private function navLink(string $url, array $roles): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('nav_links')) {
            Artisan::call('migrate', ['--path' => 'database/migrations/2026_05_18_140000_create_nav_links_and_visibility_tables.php', '--force' => true]);
        }
        $id = \Illuminate\Support\Facades\DB::table('nav_links')->insertGetId([
            'key' => 'chatblast', 'label' => 'Chatblast', 'route_url' => $url, 'icon' => 'fa-solid fa-link', 'active_pattern' => 'chatblast*',
            'is_active' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($roles as $role) {
            \Illuminate\Support\Facades\DB::table('nav_link_role_visibility')->insert(['nav_link_id' => $id, 'role' => $role, 'is_visible' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Ang Changes ng page: [field, by] ng bawat linya. */
    private function changed(): array
    {
        return array_map(fn ($e) => [$e['field'], $e['by']], $this->getJson('/chatbot/changes?page=Page%20Uno')->assertOk()->json('entries'));
    }

    public function test_S_148_1_the_popup_data_has_both_cards_with_only_the_last_4_of_each_token(): void
    {
        Http::fake();
        $this->settings()->set('Page Uno', 'forbeast', 'token', self::NEW_FB, true);

        $res = $this->getJson('/chatbot/connections?page_name=Page%20Uno')->assertOk()
            ->assertJson(['page' => 'Page Uno', 'botcake' => ['page_id' => '1001', 'token' => '••••' . substr(self::TOKEN, -4)],
                'forbeast' => ['page_id' => '1001', 'token' => '••••e3r4', 'connected' => false]]);

        $this->assertStringNotContainsString(self::TOKEN, $res->getContent());
        $this->assertStringNotContainsString(self::NEW_FB, $res->getContent());
    }

    public function test_S_148_2_a_valid_botcake_token_is_tested_saved_and_the_chat_block_turned_on_with_the_same_key(): void
    {
        $this->botcake();
        $this->settings()->set('Page Uno', 'botcake', 'chat.key', 'kept-chat-key-123', true, null, true);

        $res = $this->postJson('/chatbot/connections/botcake', ['page_name' => 'Page Uno', 'page_id' => '1001', 'token' => self::NEW_BC])
            ->assertOk()->assertJson(['status' => 'ok', 'chat_block' => ['ok' => true], 'connections' => ['botcake' => ['connected' => true]]]);

        $this->assertSame(self::NEW_BC, $this->settings()->get('Page Uno', 'botcake', 'token'));
        $this->assertSame(auth()->id(), PageSetting::where('provider', 'botcake')->where('key', 'token')->where('active', true)->value('created_by'));
        $this->assertStringStartsWith('ok', (string) $this->settings()->get('Page Uno', 'botcake', 'token_status'));
        $this->assertSame('kept-chat-key-123', $this->settings()->get('Page Uno', 'botcake', 'chat.key'));
        $this->assertSame('1', $this->settings()->get('Page Uno', 'chatbot', 'active'), 'ang block ay nag-o-On din ng bot');
        $write = $this->sentTo('/default_reply/content', 'POST');
        $this->assertCount(1, $write);
        $this->assertSame('kept-chat-key-123', $write[0][0]->data()['blocks'][0]['cards'][0]['headers'][0]['value']);
        $this->assertSame(['update' => ['is_published' => true, 'unlimit_default_reply' => true]], $this->sentTo('/default_reply', 'POST')[1][0]->data());
        $this->assertStringNotContainsString(self::NEW_BC, $res->getContent());
    }

    public function test_S_148_3_a_token_that_does_not_match_the_page_is_red_and_not_saved(): void
    {
        // Botcake: ang token ay hindi gumagana sa Page ID na ito.
        $this->botcake(tokenOk: false);
        $this->postJson('/chatbot/connections/botcake', ['page_name' => 'Page Uno', 'page_id' => '1001', 'token' => self::NEW_BC])
            ->assertStatus(422)->assertJson(['status' => 'invalid']);
        $this->assertSame(self::TOKEN, $this->settings()->get('Page Uno', 'botcake', 'token'));
        $this->assertCount(0, $this->sentTo('/default_reply/content', 'POST'));

        // Forbeast: ibang pangalan ng page, o ibang page id.
        foreach ([['Tech Flask', '1001'], ['Page Uno', '9999']] as [$name, $id]) {
            $this->forbeast($name, $id);
            $this->postJson('/chatbot/connections/forbeast', ['page_name' => 'Page Uno', 'page_id' => '1001', 'token' => self::NEW_FB])
                ->assertStatus(422)->assertJson(['status' => 'mismatch', 'name' => $name]);
            $this->assertNull($this->settings()->get('Page Uno', 'forbeast', 'token'));
            $this->assertCount(0, $this->sentTo('/webhook'));
        }
    }

    public function test_S_148_4_a_forbeast_token_connects_and_the_page_shows_forbeast_connected(): void
    {
        $this->forbeast('  page   UNO ');

        $res = $this->postJson('/chatbot/connections/forbeast', ['page_name' => 'Page Uno', 'page_id' => '1001', 'token' => self::NEW_FB])
            ->assertOk()->assertJson(['status' => 'ok', 'connections' => ['forbeast' => ['connected' => true]]]);

        $this->assertSame(self::NEW_FB, $this->settings()->get('Page Uno', 'forbeast', 'token'));
        $this->assertSame('https://app.example.test/api/gateway/events', $this->sentTo('/webhook', 'PUT')[0][0]->data()['url']);
        $this->assertStringNotContainsString(self::NEW_FB, $res->getContent());
        $row = collect($this->getJson('/chatbot/data')->json('rows'))->firstWhere('page', 'Page Uno');
        $this->assertContains('Forbeast', $row['connected']);
    }

    public function test_S_148_5_a_page_pointing_at_another_site_needs_a_confirm_before_it_moves(): void
    {
        $this->botcake($this->content('https://incepxion.pro/api/botcake/chat'));

        $this->postJson('/chatbot/connections/botcake', ['page_name' => 'Page Uno', 'page_id' => '1001', 'token' => self::NEW_BC])
            ->assertStatus(409)->assertJson(['status' => 'confirm', 'host' => 'incepxion.pro', 'message' => 'Connected to incepxion.pro now. Move it to this site?']);
        $this->assertSame(self::TOKEN, $this->settings()->get('Page Uno', 'botcake', 'token'));
        $this->assertCount(0, $this->sentTo('/default_reply/content', 'POST'));

        $this->postJson('/chatbot/connections/botcake', ['page_name' => 'Page Uno', 'page_id' => '1001', 'token' => self::NEW_BC, 'confirm' => true])
            ->assertOk()->assertJson(['status' => 'ok']);
        $this->assertCount(1, $this->sentTo('/default_reply/content', 'POST'));

        // Ang sariling site ay hindi tinatanong (kahit luma ang key).
        $this->fake['content'] = $this->content('https://app.example.test/api/botcake/chat', 'old-key');
        $this->postJson('/chatbot/connections/botcake', ['page_name' => 'Page Uno', 'page_id' => '1001'])->assertOk();

        // Hindi masuri ang Default Reply (404): tinatanong din, at ang Fix chat block ay may parehong tanong.
        $this->fake['reply_status'] = 404;
        $this->postJson('/chatbot/connections/botcake', ['page_name' => 'Page Uno', 'page_id' => '1001'])->assertStatus(409)->assertJson(['status' => 'confirm', 'host' => null]);
        $this->fake['reply_status'] = 200;
        $this->fake['content'] = $this->content('https://incepxion.pro/api/botcake/chat');
        $before = count($this->sentTo('/default_reply/content', 'POST'));
        $this->postJson('/chatbot/connections/chat-block', ['page_name' => 'Page Uno'])->assertStatus(409)->assertJson(['host' => 'incepxion.pro']);
        $this->assertCount($before, $this->sentTo('/default_reply/content', 'POST'));

        // Forbeast: ang webhook ng page ay nasa ibang site.
        $this->forbeast(hook: 'https://incepxion.pro/api/gateway/events');
        $this->postJson('/chatbot/connections/forbeast', ['page_name' => 'Page Uno', 'page_id' => '1001', 'token' => self::NEW_FB])
            ->assertStatus(409)->assertJson(['status' => 'confirm', 'host' => 'incepxion.pro']);
        $this->assertNull($this->settings()->get('Page Uno', 'forbeast', 'token'));
        $this->postJson('/chatbot/connections/forbeast', ['page_name' => 'Page Uno', 'page_id' => '1001', 'token' => self::NEW_FB, 'confirm' => true])->assertOk();
    }

    public function test_S_148_6_fix_chat_block_re_applies_with_the_same_key(): void
    {
        $this->botcake();
        $this->settings()->set('Page Uno', 'botcake', 'chat.key', 'kept-chat-key-123', true, null, true);

        $this->postJson('/chatbot/connections/chat-block', ['page_name' => 'Page Uno'])->assertOk()->assertJson(['chat_block' => ['ok' => true]]);
        $this->postJson('/chatbot/connections/chat-block', ['page_name' => 'Page Uno'])->assertOk();

        $writes = $this->sentTo('/default_reply/content', 'POST');
        $this->assertCount(2, $writes);
        foreach ($writes as [$req]) $this->assertSame('kept-chat-key-123', $req->data()['blocks'][0]['cards'][0]['headers'][0]['value']);
        $this->assertSame('kept-chat-key-123', $this->settings()->get('Page Uno', 'botcake', 'chat.key'));
    }

    public function test_S_148_7_the_row_has_a_connections_popup_instead_of_the_forbeast_buttons_and_page_settings_links_to_it(): void
    {
        Http::fake();
        $html = $this->get('/chatbot/pages')->assertOk()->getContent();
        foreach (['id="conn"', 'data-connections-url', 'Fix chat block', "This replaces the page's Default Reply in Botcake.", 'id="flow-box"', 'id="flow-q"', 'Check all', 'Uncheck all', "'Connected'"] as $want) {
            $this->assertStringContainsString($want, $html, $want);
        }
        foreach (['forbeastBox', 'Paste the Forbeast token', "'New token'", "'Add token'", 'Clear the Forbeast token', 'data-forbeast-token-url'] as $gone) {
            $this->assertStringNotContainsString($gone, $html, $gone);
        }

        $settings = $this->get('/chatblast/page-settings')->assertOk()->getContent();
        $this->assertStringContainsString('data-connections-url="' . route('chatbot.pages.index') . '"', $settings);
        $this->assertStringContainsString('Forbeast: set up in Chatbot pages', $settings);
        $this->assertStringNotContainsString('Test Forbeast', $settings);
    }

    public function test_S_148_10_a_flow_picked_in_the_popup_is_linked_and_changes_shows_who_changed_it(): void
    {
        Http::fake();
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_10_23_100000_create_chatbot_tables.php']]);
        foreach (['Main Flow', 'Encode Order'] as $name) \App\Models\ChatbotFlow::forceCreate(['name' => $name]);

        // Ang popup ay sumusulat sa parehong cell path ng FLOW (nakatali ayon sa id).
        foreach (['Main Flow', 'Encode Order'] as $name) $this->postJson('/chatbot/cell', ['page_name' => 'Page Uno', 'key' => 'flow', 'value' => $name])->assertOk();

        $this->assertSame((string) \App\Models\ChatbotFlow::where('name', 'Encode Order')->value('id'), $this->settings()->get('Page Uno', 'chatbot', 'flow_id'));
        $entries = $this->getJson('/chatbot/changes?field=flow')->assertOk()->json('entries');
        $this->assertSame(['Main Flow', 'Encode Order'], [$entries[0]['old'], $entries[0]['new']]);
        $this->assertSame($this->ceo->name, $entries[0]['by']);
    }

    public function test_S_148_9_connected_lists_only_complete_connections_and_check_gives_the_reason_for_the_rest(): void
    {
        Http::fake();
        $s = $this->settings();
        $this->page('Page Dos', '2002');
        $this->page('Page Tres', '3003');
        $this->page('Page Cuatro', '4004');
        $botcake = function (string $page) use ($s) {
            $s->set($page, 'botcake', 'token_status', 'ok 2026-10-25 08:00', false, null, true);
            $s->set($page, 'botcake', 'chat.key', 'key-' . $page, true, null, true);
        };
        $forbeast = function (string $page, string $status = 'ok · Forbeast connected') use ($s) {
            $s->set($page, 'forbeast', 'token', self::NEW_FB, true);
            $s->set($page, 'forbeast', 'token_status', $status, false, null, true);
            $s->set($page, 'forbeast', 'webhook_secret', str_repeat('s', 64), true, null, true);
        };
        $botcake('Page Uno');
        $forbeast('Page Dos');
        $botcake('Page Tres');
        $forbeast('Page Tres');
        // Page Cuatro: pumalya ang Botcake token test at ang Forbeast token.
        $s->set('Page Cuatro', 'botcake', 'token_status', 'invalid 2026-10-25 08:00', false, null, true);
        $s->set('Page Cuatro', 'botcake', 'chat.key', 'key-4', true, null, true);
        $forbeast('Page Cuatro', 'invalid · Forbeast refused it (HTTP 401)');

        $rows = collect($this->getJson('/chatbot/data')->assertOk()->json('rows'))->keyBy('page');
        $this->assertSame(['Botcake'], $rows['Page Uno']['connected']);
        $this->assertSame(['Forbeast'], $rows['Page Dos']['connected']);
        $this->assertSame(['Botcake', 'Forbeast'], $rows['Page Tres']['connected']);
        $this->assertSame([], $rows['Page Cuatro']['connected']);

        $reasons = array_column(app(\App\Services\Chatbot\PageContentCheck::class)->setup('Page Cuatro'), 'reason');
        $this->assertContains('Botcake: token not working', $reasons);
        $this->assertContains('Forbeast: token not working', $reasons);
        $this->assertNotContains('Botcake: token not working', array_column(app(\App\Services\Chatbot\PageContentCheck::class)->setup('Page Uno'), 'reason'));
    }

    public function test_S_148_12_the_nightly_check_retests_tokens_and_re_applies_a_missing_chat_block_only_on_active_botcake_pages(): void
    {
        $s = $this->settings();
        foreach (['Page Dos' => '2002', 'Page Tres' => '3003', 'Page Cuatro' => '4004', 'Page Cinco' => '5005', 'Page Seis' => '6006', 'Page Siete' => '7007'] as $page => $id) $this->page($page, $id);
        $s->set('Page Siete', 'botcake', 'chat.key', 'key-7', true, null, true);
        foreach (['Page Uno', 'Page Tres', 'Page Cuatro', 'Page Cinco', 'Page Siete'] as $page) $s->set($page, 'chatbot', 'active', '1', false, null, true);
        // Page Seis: walang ACTIVE row (hindi in-On dito): walang block. Page Cinco: ang Default Reply ay nasa ibang site.
        $s->set('Page Dos', 'chatbot', 'active', '0', false, null, true); // Off: walang block
        $s->set('Page Tres', 'chatbot', 'provider', 'gateway', false, null, true); // Forbeast: walang block
        $s->set('Page Cuatro', 'botcake', 'chat.key', 'key-4', true, null, true); // nasa Default Reply pa ang block
        $s->set('Page Uno', 'forbeast', 'token', self::NEW_FB, true);
        $s->set('Page Uno', 'forbeast', 'webhook_secret', str_repeat('s', 64), true, null, true);
        Http::fake([
            'botcake.io/*/pages/3003/custom_fields*' => Http::response(['success' => false], 401),
            // Page Uno: walang block; Page Cuatro: ang block namin (tamang key); Page Cinco: ibang site; Page Siete: luma ang key.
            'botcake.io/*/pages/1001/default_reply/content' => fn (HttpRequest $r) => Http::response($r->method() === 'GET' ? $this->content(null) : ['success' => true]),
            'botcake.io/*/pages/4004/default_reply/content' => Http::response($this->content('https://app.example.test/api/botcake/chat', 'key-4')),
            'botcake.io/*/pages/5005/default_reply/content' => Http::response($this->content('https://incepxion.pro/api/botcake/chat')),
            'botcake.io/*/pages/7007/default_reply/content' => fn (HttpRequest $r) => Http::response($r->method() === 'GET' ? $this->content('https://app.example.test/api/botcake/chat', 'old-key') : ['success' => true]),
            'botcake.io/*/default_reply' => Http::response(['success' => true, 'settings' => ['is_published' => true], 'page_id' => '0', 'assistants' => []]),
            'botcake.io/*' => Http::response(['success' => true, 'data' => []]),
            self::GW . '/v1/pages/1001' => Http::response(['id' => '1001', 'name' => 'Page Uno', 'webhook' => ['url' => 'https://app.example.test/api/gateway/events']]),
        ]);
        \Illuminate\Support\Facades\Log::spy();

        $this->artisan('chatbot:connections-check')->assertExitCode(0);

        $blocks = collect($this->sentTo('/default_reply/content', 'POST'))->map(fn ($r) => $r[0]->url())->sort()->values()->all();
        $this->assertSame(['https://botcake.io/api/public_api/v1/pages/1001/default_reply/content', 'https://botcake.io/api/public_api/v1/pages/7007/default_reply/content'], $blocks);
        $this->assertSame('key-7', $s->get('Page Siete', 'botcake', 'chat.key'), 'luma ang key sa Botcake: isinulat ulit ang block, parehong key');
        $this->assertNotSame('', (string) $s->get('Page Uno', 'botcake', 'chat.key'));
        $this->assertStringStartsWith('ok', (string) $s->get('Page Uno', 'botcake', 'token_status'));
        $this->assertStringStartsWith('invalid', (string) $s->get('Page Tres', 'botcake', 'token_status'));
        $this->assertStringStartsWith('ok · Forbeast connected (Page Uno) · ', (string) $s->get('Page Uno', 'forbeast', 'token_status'));
        // Sa Changes: ang gabi-gabing test at ang block na ibinalik, ng "system".
        $changed = $this->changed();
        foreach (['Botcake test', 'Botcake chat block', 'Forbeast test'] as $field) $this->assertContains([$field, 'system'], $changed, $field);
        $this->assertSame(str_repeat('s', 64), $s->get('Page Uno', 'forbeast', 'webhook_secret'), 'ang secret ay hindi pinapalitan gabi-gabi');
        $this->assertCount(0, $this->sentTo('/webhook'));
        \Illuminate\Support\Facades\Log::shouldHaveReceived('info')->withArgs(function ($msg, $ctx = []) {
            $text = $msg . json_encode($ctx);
            return $msg === 'chatbot:connections-check' && !str_contains($text, self::TOKEN) && !str_contains($text, self::NEW_FB) && ($ctx['blocks'] ?? null) === 2;
        })->once();
    }

    public function test_S_148_13_the_setup_routes_are_for_ceo_marketing_oic_and_marketing_only(): void
    {
        Http::fake();
        // May /chatblast pero ibang role: 403 sa popup, On/Off at + Page. Walang /chatblast: 403 din.
        $this->navLink('/chatblast', ['Data Encoder']);
        foreach ([$this->user('Data Encoder', 'enc@example.test'), $this->user('Finance', 'fin@example.test')] as $user) {
            $this->actingAs($user);
            $this->getJson('/chatbot/connections?page_name=Page%20Uno')->assertForbidden();
            foreach (['botcake', 'forbeast', 'chat-block', 'botcake-test', 'forbeast-test', 'forbeast-clear'] as $path) {
                $this->postJson('/chatbot/connections/' . $path, ['page_name' => 'Page Uno', 'page_id' => '1001', 'token' => self::NEW_BC])->assertForbidden();
            }
            $this->postJson('/chatbot/active', ['page_name' => 'Page Uno', 'on' => false])->assertForbidden();
            $this->postJson('/chatbot/pages', ['page_name' => 'Page Seis', 'page_id' => '6006', 'token' => self::NEW_BC])->assertForbidden();
        }
        $this->actingAs($this->ceo);
        $this->getJson('/chatbot/connections?page_name=Not%20Listed')->assertStatus(422);
        Http::assertNothingSent();
        $this->assertSame(self::TOKEN, $this->settings()->get('Page Uno', 'botcake', 'token'));
        $this->assertNull($this->settings()->get('Page Seis', 'botcake', 'page_id'));
    }

    public function test_S_148_16_marketing_oic_and_marketing_can_use_on_off_add_page_and_the_popup(): void
    {
        $this->botcake($this->content('https://app.example.test/api/botcake/chat'));
        foreach ([['Marketing - OIC', 'oic@example.test', 'Page Seis', '6006'], ['Marketing', 'mkt@example.test', 'Page Siete', '7007']] as [$role, $email, $newPage, $newId]) {
            $this->actingAs($this->user($role, $email));
            $this->postJson('/chatbot/active', ['page_name' => 'Page Uno', 'on' => false])->assertOk()->assertJson(['active' => false]);
            $this->postJson('/chatbot/active', ['page_name' => 'Page Uno', 'on' => true])->assertOk()->assertJson(['active' => true]);
            Http::fake(['botcake.io/*/pages/' . $newId . '/*' => Http::response(['success' => true, 'data' => []])]);
            $this->postJson('/chatbot/pages', ['page_name' => $newPage, 'page_id' => $newId, 'token' => self::NEW_BC])->assertOk()->assertJson(['page' => $newPage]);
            $this->getJson('/chatbot/connections?page_name=Page%20Uno')->assertOk();
            $this->postJson('/chatbot/connections/botcake', ['page_name' => 'Page Uno', 'page_id' => '1001', 'token' => self::NEW_BC])->assertOk();
            $this->postJson('/chatbot/connections/botcake-test', ['page_name' => 'Page Uno'])->assertOk();
            $this->postJson('/chatbot/connections/chat-block', ['page_name' => 'Page Uno'])->assertOk();
        }
    }

    public function test_S_148_17_test_re_tests_the_saved_token_and_updates_the_last_test_time(): void
    {
        $s = $this->settings();
        $s->set('Page Uno', 'botcake', 'chat.key', 'kept-chat-key-123', true, null, true);
        $s->set('Page Uno', 'forbeast', 'token', self::NEW_FB, true);
        $s->set('Page Uno', 'forbeast', 'webhook_secret', str_repeat('s', 64), true, null, true);
        $this->botcake($this->content('https://app.example.test/api/botcake/chat'));
        $this->forbeast(hook: 'https://app.example.test/api/gateway/events');

        $this->travelTo('2026-10-25 08:00:00');
        $this->postJson('/chatbot/connections/botcake-test', ['page_name' => 'Page Uno'])->assertOk()
            ->assertJson(['status' => 'ok', 'chat_block' => 'on', 'connections' => ['botcake' => ['tested_at' => '2026-10-25 08:00', 'connected' => true]]]);
        $this->postJson('/chatbot/connections/forbeast-test', ['page_name' => 'Page Uno'])->assertOk()
            ->assertJson(['status' => 'ok', 'connections' => ['forbeast' => ['tested_at' => '2026-10-25 08:00', 'connected' => true]]]);
        $this->assertCount(0, $this->sentTo('/default_reply/content', 'POST'), 'Test lang: walang isinusulat sa Botcake');
        $this->assertCount(0, $this->sentTo('/webhook', 'PUT'), 'Test lang: walang bagong webhook');

        // Ang block ay wala na sa Default Reply: makikita sa susunod na Test, at hindi na Connected ang Botcake.
        $this->travelTo('2026-10-25 09:30:00');
        $this->fake['content'] = $this->content(null);
        $this->postJson('/chatbot/connections/botcake-test', ['page_name' => 'Page Uno'])->assertOk()
            ->assertJson(['status' => 'ok', 'chat_block' => 'missing', 'connections' => ['botcake' => ['tested_at' => '2026-10-25 09:30', 'connected' => false]]]);
        $info = $this->getJson('/chatbot/connections?page_name=Page%20Uno')->assertOk()->json();
        $this->assertSame('2026-10-25 09:30', $info['botcake']['tested_at']);
        $this->assertStringStartsWith('missing', $info['botcake']['chat_block_status']);
        $this->assertContains('Botcake: no chat block', array_column(app(\App\Services\Chatbot\PageContentCheck::class)->setup('Page Uno'), 'reason'));
    }

    public function test_S_148_18_every_setup_action_shows_in_changes_with_the_user_and_never_a_token(): void
    {
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_10_23_100000_create_chatbot_tables.php']]);
        \App\Models\ChatbotFlow::forceCreate(['name' => 'Main Flow']);
        $this->navLink('/chatblast', ['Marketing']); // ang Bot at FLOW ay sa /chatblast access pa rin
        $mkt = $this->user('Marketing', 'mkt@example.test');
        $this->actingAs($mkt);
        $this->botcake($this->content('https://app.example.test/api/botcake/chat'));
        $this->forbeast(hook: 'https://app.example.test/api/gateway/events');
        $step = 0;
        $do = function (string $url, array $body) use (&$step) {
            $this->travelTo(now()->addMinutes(++$step)); // bawat kilos ay sariling oras
            return $this->postJson($url, ['page_name' => 'Page Uno'] + $body)->assertSuccessful();
        };

        $do('/chatbot/active', ['on' => false]);
        $do('/chatbot/active', ['on' => true]);
        $do('/chatbot/connections/botcake', ['page_id' => '1001', 'token' => self::NEW_BC]);
        $do('/chatbot/connections/botcake-test', []);
        $do('/chatbot/connections/chat-block', []);
        $do('/chatbot/connections/forbeast', ['page_id' => '1001', 'token' => self::NEW_FB]);
        $do('/chatbot/connections/forbeast-test', []);
        $do('/chatbot/provider', ['provider' => 'gateway']);
        $do('/chatbot/provider', ['provider' => 'botcake']);
        $do('/chatbot/cell', ['key' => 'flow', 'value' => 'Main Flow']);
        $do('/chatbot/connections/forbeast-clear', []);

        $res = $this->getJson('/chatbot/changes?page=Page%20Uno')->assertOk();
        $changed = array_map(fn ($e) => [$e['field'], $e['by']], $res->json('entries'));
        foreach (['ACTIVE', 'Botcake token', 'Botcake test', 'Botcake chat block', 'Forbeast token', 'Forbeast test', 'Provider', 'FLOW'] as $field) {
            $this->assertContains([$field, $mkt->name], $changed, $field);
        }
        $entries = collect($res->json('entries'));
        $this->assertSame(2, $entries->where('field', 'ACTIVE')->where('by', $mkt->name)->count(), 'On at Off');
        $this->assertSame(['(hidden)', '(none)'], $entries->where('field', 'Forbeast token')->pluck('new')->sort()->values()->all(), 'na-save at na-clear');
        $this->assertGreaterThanOrEqual(2, $entries->where('field', 'Botcake chat block')->count(), 'ang kusang block at ang Fix chat block');
        foreach ([self::TOKEN, self::NEW_BC, self::NEW_FB, 'kept-chat-key-123'] as $secret) $this->assertStringNotContainsString($secret, $res->getContent());
        $labels = ['Botcake ID', 'Botcake token', 'Botcake test', 'Botcake chat block', 'Forbeast token', 'Forbeast test'];
        $this->assertSame($labels, array_values(array_intersect(array_column($res->json('fields'), 'label'), $labels)));
    }
    public static function replies(): array
    {
        $card = fn (string $url, string $key = 'kept-chat-key-123') => ['plugin_id' => 'json_api', 'config' => ['method' => 'post', 'url' => $url], 'headers' => [['key' => 'X-Chat-Key', 'value' => $key]]];

        return [
            'ours then another site' => [['name' => 'Default reply', 'blocks' => [['cards' => [$card('https://app.example.test/api/botcake/chat'), $card('https://incepxion.pro/api/botcake/chat')]]]], 'incepxion.pro'],
            'success false' => [['success' => false, 'message' => 'Page not found'], null],
            'no blocks key' => [['name' => 'Default reply', 'status' => 'published'], null],
            'a card with no host' => [['name' => 'Default reply', 'blocks' => [['cards' => [$card('/api/botcake/chat')]]]], null],
        ];
    }

    /** @dataProvider replies */
    public function test_S_148_19_the_popup_asks_on_another_site_or_an_unreadable_default_reply_and_writes_nothing(array $content, ?string $host): void
    {
        $this->botcake($content);
        $this->settings()->set('Page Uno', 'botcake', 'chat.key', 'kept-chat-key-123', true, null, true);

        $this->postJson('/chatbot/connections/botcake', ['page_name' => 'Page Uno', 'page_id' => '1001', 'token' => self::NEW_BC])
            ->assertStatus(409)->assertJson(['status' => 'confirm', 'host' => $host]);
        $this->postJson('/chatbot/connections/chat-block', ['page_name' => 'Page Uno'])->assertStatus(409)->assertJson(['host' => $host]);
        $this->assertCount(0, $this->sentTo('/default_reply/content', 'POST'));
        $this->assertSame(self::TOKEN, $this->settings()->get('Page Uno', 'botcake', 'token'));
    }

    public function test_S_148_20_on_and_add_page_ask_before_replacing_another_sites_default_reply(): void
    {
        $this->botcake($this->content('https://incepxion.pro/api/botcake/chat'));
        $this->settings()->set('Page Uno', 'chatbot', 'active', '0', false, null, true);

        $this->postJson('/chatbot/active', ['page_name' => 'Page Uno', 'on' => true])->assertStatus(409)
            ->assertJson(['status' => 'confirm', 'host' => 'incepxion.pro', 'active' => false]);
        $this->postJson('/chatbot/pages', ['page_name' => 'Page Seis', 'page_id' => '1001', 'token' => self::NEW_BC])->assertStatus(422); // ang ID ay sa Page Uno
        Http::fake(['botcake.io/*/pages/6006/default_reply/content' => Http::response($this->content('https://incepxion.pro/api/botcake/chat'))]);
        $this->postJson('/chatbot/pages', ['page_name' => 'Page Seis', 'page_id' => '6006', 'token' => self::NEW_BC])->assertStatus(409)->assertJson(['host' => 'incepxion.pro']);
        $this->assertNull($this->settings()->get('Page Seis', 'botcake', 'page_id'), 'walang naisulat bago ang confirm');
        $this->assertCount(0, $this->sentTo('/default_reply/content', 'POST'));

        $this->postJson('/chatbot/active', ['page_name' => 'Page Uno', 'on' => true, 'confirm' => true])->assertOk()->assertJson(['active' => true]);
        $this->assertCount(1, $this->sentTo('/default_reply/content', 'POST'));
        $this->assertStringStartsWith('applied', (string) $this->settings()->get('Page Uno', 'botcake', 'chat_block'));
        $this->postJson('/chatbot/active', ['page_name' => 'Page Uno', 'on' => false])->assertOk();
        $this->assertStringStartsWith('off', (string) $this->settings()->get('Page Uno', 'botcake', 'chat_block'));
    }

    public function test_S_148_21_the_old_forbeast_token_route_has_the_same_access_and_logs_the_user(): void
    {
        $this->forbeast(hook: 'https://app.example.test/api/gateway/events');
        $this->navLink('/chatblast', ['Data Encoder', 'Marketing']);
        $this->actingAs($this->user('Data Encoder', 'enc@example.test'));
        $this->postJson('/chatbot/forbeast-token', ['page_name' => 'Page Uno', 'token' => self::NEW_FB])->assertForbidden();
        $this->postJson('/chatbot/forbeast-token', ['page_name' => 'Page Uno', 'clear' => true])->assertForbidden();
        Http::assertNothingSent();

        $mkt = $this->user('Marketing', 'mkt@example.test');
        $this->actingAs($mkt);
        $this->postJson('/chatbot/forbeast-token', ['page_name' => 'Page Uno', 'token' => self::NEW_FB])->assertOk();
        $this->assertSame($mkt->id, PageSetting::where('provider', 'forbeast')->where('key', 'token_status')->where('active', true)->value('created_by'));
        $this->assertSame([['Forbeast token', $mkt->name, '(hidden)']], array_map(fn ($e) => [$e['field'], $e['by'], $e['new']],
            $this->getJson('/chatbot/changes?field=forbeast.token')->assertOk()->json('entries')));
        $this->assertSame([$mkt->name], array_column($this->getJson('/chatbot/changes?field=forbeast.token_status')->assertOk()->json('entries'), 'by'));
    }

    /** Ang /keywords ng Botcake: isang naka-on (flow ON DELIVERY SCRIPT), at mga naka-off. */
    private function keywords(bool $oneOn): void
    {
        $this->fake['keywords'] = ['data' => [['id' => 1003861036, 'is_activated' => $oneOn, 'flow_id' => 424398467],
            ['id' => 3837339, 'is_activated' => false, 'flow_id' => 1415244560], ['id' => 1003864376, 'is_activated' => false, 'flow_id' => null]], 'success' => true];
    }

    public function test_S_153_4_an_active_keyword_marks_the_page_not_working_yet_and_the_token_stays_ok(): void
    {
        $this->settings()->set('Page Uno', 'botcake', 'chat.key', 'kept-chat-key-123', true, null, true);
        $this->botcake($this->content('https://app.example.test/api/botcake/chat'));
        $this->keywords(true);

        $this->postJson('/chatbot/connections/botcake-test', ['page_name' => 'Page Uno'])->assertOk()
            ->assertJson(['status' => 'ok', 'chat_block' => 'on', 'keywords' => 'on', 'keywords_note' => 'ON DELIVERY SCRIPT']);

        $this->assertStringStartsWith('ok', (string) $this->settings()->get('Page Uno', 'botcake', 'token_status'));
        $stamp = PageSetting::where('provider', 'botcake')->where('key', 'keywords')->where('active', true)->first();
        $this->assertMatchesRegularExpression('/^on \d{4}-\d\d-\d\d \d\d:\d\d:\d\d · ON DELIVERY SCRIPT$/', $stamp->plainValue());
        $this->assertSame($this->ceo->id, $stamp->created_by);
        $this->assertStringStartsWith('on', (string) $this->getJson('/chatbot/connections?page_name=Page%20Uno')->json('botcake.keywords_status'));
        $this->assertCount(0, $this->sentTo('/keywords', 'POST'), 'basa lang: walang isinusulat sa Botcake');
    }

    public function test_S_153_5_only_inactive_keywords_give_none(): void
    {
        $this->settings()->set('Page Uno', 'botcake', 'chat.key', 'kept-chat-key-123', true, null, true);
        $this->botcake($this->content('https://app.example.test/api/botcake/chat'));
        $this->keywords(false);

        $this->postJson('/chatbot/connections/botcake-test', ['page_name' => 'Page Uno'])->assertOk()
            ->assertJson(['status' => 'ok', 'keywords' => 'none', 'keywords_note' => null]);
        $this->assertStringStartsWith('none', (string) $this->settings()->get('Page Uno', 'botcake', 'keywords'));
    }

    public static function unreadableKeywords(): array
    {
        return [
            'HTTP 500' => [['message' => 'down'], 500],
            'success false' => [['success' => false], 200],
        ];
    }

    /** @dataProvider unreadableKeywords */
    public function test_S_153_6_unreadable_keywords_are_unknown_with_a_note_and_the_chat_block_result_is_unchanged(array $body, int $http): void
    {
        $this->settings()->set('Page Uno', 'botcake', 'chat.key', 'kept-chat-key-123', true, null, true);
        $this->botcake($this->content('https://app.example.test/api/botcake/chat'));
        $this->fake['keywords'] = $body;
        $this->fake['keywords_status'] = $http;

        $this->postJson('/chatbot/connections/botcake-test', ['page_name' => 'Page Uno'])->assertOk()
            ->assertJson(['status' => 'ok', 'chat_block' => 'on', 'keywords' => 'unknown', 'keywords_note' => 'could not read the keywords']);
        $this->assertStringStartsWith('unknown', (string) $this->settings()->get('Page Uno', 'botcake', 'keywords'));
        $this->assertTrue(app(\App\Services\Chatbot\ChatbotConnections::class)->botcakeConnected('Page Uno'));
    }

    public function test_S_153_7_the_test_after_saving_a_token_also_checks_the_keywords(): void
    {
        $this->botcake();
        $this->keywords(true);

        $this->postJson('/chatbot/connections/botcake', ['page_name' => 'Page Uno', 'page_id' => '1001', 'token' => self::NEW_BC])->assertOk()
            ->assertJson(['status' => 'ok', 'chat_block' => ['ok' => true], 'keywords' => 'on', 'keywords_note' => 'ON DELIVERY SCRIPT']);
        $this->assertStringStartsWith('on', (string) $this->settings()->get('Page Uno', 'botcake', 'keywords'));
    }

    public function test_S_153_9_the_keyword_check_has_the_tests_access_and_shows_in_changes_with_the_user(): void
    {
        $this->botcake($this->content('https://app.example.test/api/botcake/chat'));
        $this->keywords(true);
        $this->navLink('/chatblast', ['Data Encoder', 'Marketing']); // ang Changes ay sa /chatblast access; ang Test ay sa setup roles lang
        $this->actingAs($this->user('Data Encoder', 'enc@example.test'));
        $this->postJson('/chatbot/connections/botcake-test', ['page_name' => 'Page Uno'])->assertForbidden();
        $this->assertCount(0, $this->sentTo('/keywords'));

        $mkt = $this->user('Marketing', 'mkt@example.test');
        $this->actingAs($mkt);
        $this->postJson('/chatbot/connections/botcake-test', ['page_name' => 'Page Uno'])->assertOk()->assertJson(['keywords' => 'on']);
        $this->assertContains(['Botcake keywords', $mkt->name], $this->changed());
    }
}
