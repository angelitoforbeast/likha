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

    /** Botcake fake: ang token test (custom_fields), ang Default Reply na nakikita ngayon, at ang pagsulat ng block. */
    private function botcake(array $defaultReply = ['success' => true, 'data' => []], bool $tokenOk = true): void
    {
        $this->fake['reply'] = $defaultReply;
        $this->fake['token_ok'] = $tokenOk;
        Http::fake([
            self::BC . '/custom_fields*' => fn () => $this->fake['token_ok'] ? Http::response(['success' => true, 'data' => [['id' => 7701, 'name' => 'message']]]) : Http::response(['success' => false], 401),
            self::BC . '/default_reply/content' => Http::response(['success' => true]),
            self::BC . '/default_reply*' => fn (HttpRequest $r) => Http::response($r->method() === 'GET' ? $this->fake['reply'] : ['success' => true]),
        ]);
    }

    private function forbeast(string $name = 'Page Uno', string $id = '1001', ?string $hook = null): void
    {
        $this->fake['page'] = ['id' => $id, 'name' => $name, 'connected' => true, 'webhook' => $hook ? ['url' => $hook, 'set_at' => '2026-10-24'] : null, 'can_send' => true];
        Http::fake([
            self::GW . '/v1/pages/1001' => fn () => Http::response($this->fake['page']),
            self::GW . '/v1/pages/1001/webhook' => Http::response(['ok' => true]),
        ]);
    }
    private function sentTo(string $part, string $method = null): array
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), $part) && ($method === null || $r->method() === $method))->values()->all();
    }

    private function jsonApiReply(string $url): array
    {
        return ['success' => true, 'data' => ['blocks' => [['cards' => [['plugin_id' => 'json_api', 'config' => ['method' => 'post', 'url' => $url]]]]]]];
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
        $this->assertCount(0, $this->sentTo('/default_reply/content'));

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
        $this->botcake($this->jsonApiReply('https://incepxion.pro/api/botcake/chat'));

        $this->postJson('/chatbot/connections/botcake', ['page_name' => 'Page Uno', 'page_id' => '1001', 'token' => self::NEW_BC])
            ->assertStatus(409)->assertJson(['status' => 'confirm', 'host' => 'incepxion.pro', 'message' => 'Connected to incepxion.pro now. Move it to this site?']);
        $this->assertSame(self::TOKEN, $this->settings()->get('Page Uno', 'botcake', 'token'));
        $this->assertCount(0, $this->sentTo('/default_reply/content'));

        $this->postJson('/chatbot/connections/botcake', ['page_name' => 'Page Uno', 'page_id' => '1001', 'token' => self::NEW_BC, 'confirm' => true])
            ->assertOk()->assertJson(['status' => 'ok']);
        $this->assertCount(1, $this->sentTo('/default_reply/content'));

        // Ang sariling site ay hindi tinatanong.
        $this->botcake($this->jsonApiReply('https://app.example.test/api/botcake/chat'));
        $this->postJson('/chatbot/connections/botcake', ['page_name' => 'Page Uno', 'page_id' => '1001'])->assertOk();

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

    public function test_S_148_13_the_new_routes_need_the_page_edit_access_and_a_listed_page(): void
    {
        Http::fake();
        $this->actingAs($this->user('Data Encoder', 'enc@example.test'));
        $this->getJson('/chatbot/connections?page_name=Page%20Uno')->assertForbidden();
        foreach (['botcake', 'forbeast', 'chat-block'] as $path) {
            $this->postJson('/chatbot/connections/' . $path, ['page_name' => 'Page Uno', 'page_id' => '1001', 'token' => self::NEW_BC])->assertForbidden();
        }
        $this->actingAs($this->ceo);
        $this->getJson('/chatbot/connections?page_name=Not%20Listed')->assertStatus(422);
        Http::assertNothingSent();
        $this->assertSame(self::TOKEN, $this->settings()->get('Page Uno', 'botcake', 'token'));
    }
}
