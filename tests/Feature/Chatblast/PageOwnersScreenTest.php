<?php

namespace Tests\Feature\Chatblast;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * /chatbot/owners: ang CEO lang ang nakakakita kung aling site ang may-ari ng bawat page (mula sa Forbeast, pinepeke rito)
 * at ang nagta-take, naglilipat, humihingi, tumatanggap, tumatanggi at nagka-cancel. Ang take, move, ask at accept ay
 * kailangan ng sariling password ng CEO (dito lang sinusuri) at ng phrase na nasa screen.
 */
class PageOwnersScreenTest extends ChatblastTestCase
{
    private const API = 'https://forbeast.example.test/api/v1/site';
    private const ACTOR = 'CEO User ceo@example.test';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_10_20_100000_create_botcake_chats_table.php']]);
        config(['services.gateway.url' => 'https://forbeast.example.test/api', 'services.forbeast_site.key' => 'fbs_test_key', 'services.forbeast_site.secret' => 'site-secret']);
    }

    /** Ang Forbeast (fake): ang $first ay nauuna sa mga default (ang unang tugma ang sagot). */
    private function forbeast(array $first = []): void
    {
        Http::fake($first + [
            self::API . '/pages' => Http::response(['site' => 'likha', 'pages' => [
                ['meta_page_id' => '1001', 'name' => 'Page Uno', 'picture_url' => null, 'owner' => 'likha', 'connected_at' => '2026-10-01T00:00:00Z', 'pending' => [
                    ['id' => 41, 'direction' => 'pull', 'from' => 'likha', 'to' => 'incepxion', 'requested_by' => 'Ben <b>Cruz</b> ben@incepxion.test', 'requested_at' => '2026-10-27T01:00:00Z'],
                ]],
                ['meta_page_id' => '2002', 'name' => 'Page Dos', 'picture_url' => 'https://img.example.test/2.jpg', 'owner' => 'incepxion', 'connected_at' => '2026-10-02T00:00:00Z', 'pending' => [
                    ['id' => 42, 'direction' => 'pull', 'from' => 'incepxion', 'to' => 'likha', 'requested_by' => self::ACTOR, 'requested_at' => '2026-10-27T02:00:00Z'],
                ]],
                ['meta_page_id' => '3003', 'name' => 'Page Tres', 'picture_url' => null, 'owner' => null, 'connected_at' => '2026-10-03T00:00:00Z', 'pending' => []],
            ]]),
            self::API . '/transfers?page=1001' => Http::response(['transfers' => [
                ['id' => 40, 'direction' => 'push', 'from' => 'likha', 'to' => 'incepxion', 'status' => 'declined', 'requested_by' => 'Ana Reyes ana@likha.test',
                    'requested_at' => '2026-10-20T01:00:00Z', 'steps' => [['step' => 'declined', 'status' => 'done', 'actor' => 'Ben Cruz ben@incepxion.test', 'at' => '2026-10-20T02:00:00Z']]],
            ]]),
            self::API . '/pages/*/take' => Http::response(['transfer_id' => 50], 202),
            self::API . '/pages/*/transfers' => Http::response(['id' => 51], 201),
            self::API . '/transfers/*' => Http::response(['id' => 42], 200),
        ]);
    }

    private function navLink(string $url, array $roles): void
    {
        Artisan::call('migrate', ['--path' => 'database/migrations/2026_05_18_140000_create_nav_links_and_visibility_tables.php', '--force' => true]);
        $id = DB::table('nav_links')->insertGetId(['key' => 'chatblast', 'label' => 'Chatblast', 'route_url' => $url, 'icon' => 'fa-solid fa-link',
            'active_pattern' => 'chatblast*', 'is_active' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($roles as $role) DB::table('nav_link_role_visibility')->insert(['nav_link_id' => $id, 'role' => $role, 'is_visible' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<int, HttpRequest> ang mga sulat (POST) na naipadala sa Forbeast */
    private function writes(): array
    {
        return array_values(array_map(fn ($pair) => $pair[0], array_filter(Http::recorded()->all(), fn ($pair) => $pair[0]->method() === 'POST')));
    }

    public function test_S_164_1_the_ceo_sees_every_page_with_its_owner_pending_requests_and_the_actions_that_fit(): void
    {
        $this->forbeast();
        $this->actingAs($this->user());
        $html = $this->get('/chatbot/owners')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-cb-nav="Owners"[^>]*aria-current="page"/', $html);
        foreach (['1001' => 'This site (Likha)', '2002' => 'Incepxion', '3003' => 'Unowned'] as $id => $owner) {
            $this->assertMatchesRegularExpression('#data-page="' . $id . '"[\s\S]*?data-owner[^>]*>\s*' . preg_quote($owner, '#') . '\s*<#', $html);
        }
        $this->assertStringContainsString('Ben &lt;b&gt;Cruz&lt;/b&gt; ben@incepxion.test', $html, 'Forbeast text is escaped');
        $this->assertStringContainsString('asks to move it to Incepxion', $html);
        $this->assertStringContainsString('This site asked to move it to Likha', $html);
        preg_match_all('/data-action="([a-z]+)" data-target="(\d+)"/', $html, $m, PREG_SET_ORDER);
        $actions = array_map(fn ($x) => $x[1] . ':' . $x[2], $m);
        sort($actions);
        $this->assertSame(['accept:41', 'ask:2002', 'cancel:42', 'decline:41', 'move:1001', 'take:3003'], $actions);
        $this->assertStringContainsString('TAKE Page Tres TO LIKHA', $html);
        $this->assertStringContainsString('TRANSFER Page Uno TO INCEPXION', $html);
        $this->assertStringContainsString('TRANSFER Page Dos TO LIKHA', $html);
        $this->assertSame([], $this->writes());

        // The history of one page, from GET site/transfers.
        $history = $this->get('/chatbot/owners?history=1001')->assertOk()->getContent();
        $this->assertStringContainsString('Ana Reyes ana@likha.test', $history);
        $this->assertMatchesRegularExpression('/declined[\s\S]*Ben Cruz ben@incepxion.test/', $history);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::API . '/transfers?page=1001' && $r->hasHeader('Authorization', 'Bearer fbs_test_key'));
    }

    public function test_S_164_1_other_roles_get_403_and_no_owners_tab(): void
    {
        $this->forbeast();
        $this->navLink('/chatblast', ['Encoder']);
        $this->actingAs($this->user('Encoder', 'enc@example.test'));

        $this->get('/chatbot/owners')->assertForbidden();
        $this->post('/chatbot/owners/take', ['target' => '3003', 'password' => 'secret-password', 'phrase' => 'TAKE Page Tres TO LIKHA'])->assertForbidden();
        $this->assertStringNotContainsString('data-cb-nav="Owners"', $this->get('/chatbot/pages')->assertOk()->getContent());
        Http::assertNothingSent();
    }

    public function test_S_164_1_a_site_without_its_key_says_it_is_not_linked(): void
    {
        $this->forbeast();
        config(['services.forbeast_site.key' => '']);
        $this->actingAs($this->user());

        $this->get('/chatbot/owners')->assertOk()->assertSee('This site is not linked to Forbeast yet')->assertSee('FORBEAST_SITE_KEY');
        Http::assertNothingSent();
    }

    public static function actions(): array
    {
        $ok = 'secret-password';
        return [
            'take, wrong password' => ['take', '3003', 'nope', 'TAKE Page Tres TO LIKHA', null, 'Wrong password'],
            'take, wrong phrase' => ['take', '3003', $ok, 'TAKE Page Uno TO LIKHA', null, 'The phrase does not match'],
            'take' => ['take', '3003', $ok, ' TAKE  Page Tres TO LIKHA ', ['/pages/3003/take', 'TAKE Page Tres TO LIKHA'], null],
            'take an owned page' => ['take', '1001', $ok, 'TAKE Page Uno TO LIKHA', null, 'cannot be taken'],
            'move, wrong password' => ['move', '1001', 'nope', 'TRANSFER Page Uno TO INCEPXION', null, 'Wrong password'],
            'move' => ['move', '1001', $ok, 'TRANSFER Page Uno TO INCEPXION', ['/pages/1001/transfers', 'TRANSFER Page Uno TO INCEPXION'], null],
            'move a page owned there' => ['move', '2002', $ok, 'TRANSFER Page Dos TO INCEPXION', null, 'not owned by this site'],
            'ask, wrong phrase' => ['ask', '2002', $ok, 'TRANSFER Page Dos TO INCEPXION', null, 'The phrase does not match'],
            'ask' => ['ask', '2002', $ok, 'TRANSFER Page Dos TO LIKHA', ['/pages/2002/transfers', 'TRANSFER Page Dos TO LIKHA'], null],
            'accept, wrong password' => ['accept', '41', 'nope', 'TRANSFER Page Uno TO INCEPXION', null, 'Wrong password'],
            'accept' => ['accept', '41', $ok, 'TRANSFER Page Uno TO INCEPXION', ['/transfers/41/accept', 'TRANSFER Page Uno TO INCEPXION'], null],
            'accept our own request' => ['accept', '42', $ok, 'TRANSFER Page Dos TO LIKHA', null, 'Only the other site'],
            'decline' => ['decline', '41', '', '', ['/transfers/41/decline', null], null],
            'decline our own request' => ['decline', '42', '', '', null, 'Only the other site'],
            'cancel' => ['cancel', '42', '', '', ['/transfers/42/cancel', null], null],
            'cancel their request' => ['cancel', '41', '', '', null, 'Only the site that asked'],
            'unknown request' => ['cancel', '99', '', '', null, 'no longer pending'],
        ];
    }

    #[DataProvider('actions')]
    public function test_S_164_2_each_action_needs_the_right_password_phrase_and_side_before_forbeast_is_called(string $action, string $target, string $password, string $phrase, ?array $sent, ?string $error): void
    {
        $this->forbeast();
        $this->actingAs($this->user());
        $res = $this->post('/chatbot/owners/' . $action, ['target' => $target, 'password' => $password, 'phrase' => $phrase])->assertRedirect('/chatbot/owners');

        $writes = $this->writes();
        if ($sent === null) {
            $res->assertSessionHas('owners_error', fn ($e) => str_contains($e, $error));
            $this->assertSame([], $writes, 'nothing is sent to Forbeast');
            if ($error === 'Wrong password') Http::assertNothingSent();
            return;
        }
        $res->assertSessionHas('owners_status');
        $this->assertCount(1, $writes);
        $this->assertSame(self::API . $sent[0], $writes[0]->url());
        $this->assertSame(['actor' => self::ACTOR] + ($sent[1] !== null ? ['phrase' => $sent[1]] : []), $writes[0]->data());
        $this->assertTrue($writes[0]->hasHeader('Authorization', 'Bearer fbs_test_key'));
        $this->assertStringNotContainsString('secret-password', json_encode($writes[0]->data()), 'the password never leaves this site');
    }

    public function test_S_164_2_a_refusal_from_forbeast_is_shown_and_writes_are_limited_to_10_a_minute(): void
    {
        $this->forbeast([self::API . '/pages' => Http::response(['site' => 'likha', 'pages' => [['meta_page_id' => '3003', 'name' => 'Page Tres', 'owner' => null, 'pending' => []]]]),
            self::API . '/pages/3003/take' => Http::response(['error' => 'already_owned', 'message' => 'The page already has an owner.'], 409)]);
        $this->actingAs($this->user());
        $form = ['target' => '3003', 'password' => 'secret-password', 'phrase' => 'TAKE Page Tres TO LIKHA'];

        $this->post('/chatbot/owners/take', $form)->assertSessionHas('owners_error', 'Forbeast said "The page already has an owner." (HTTP 409).');
        for ($i = 2; $i <= 10; $i++) $this->post('/chatbot/owners/take', $form)->assertRedirect();
        $this->post('/chatbot/owners/take', $form)->assertStatus(429);
    }

    /** @return array<string, array{0: int, 1: string, 2: string}> */
    public static function refusals(): array
    {
        return [
            'unknown_page' => [404, 'unknown_page', 'Forbeast does not know this page for this site. Reload the list. (HTTP 404)'],
            'unknown_transfer' => [404, 'unknown_transfer', 'Forbeast has no such request for this site. It may be gone already; reload the list. (HTTP 404)'],
            'not_owned' => [409, 'not_owned', 'This site does not own this page any more. Reload the list. (HTTP 409)'],
            'not_set_up' => [409, 'not_set_up', 'Forbeast has not finished setting up this site or page, so nothing was changed. (HTTP 409)'],
            'too_many_pending' => [409, 'too_many_pending', 'This page already has 5 pending requests from this site. Cancel one first. (HTTP 409)'],
            'invalid_request' => [422, 'invalid_request', 'Forbeast did not accept the request. Reload the page and try again. (HTTP 422)'],
            'too_many_requests' => [429, 'too_many_requests', 'Too many requests to Forbeast. Wait a minute and try again. (HTTP 429)'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_S_164_10_each_forbeast_refusal_code_is_shown_as_a_plain_message(int $status, string $code, string $shown): void
    {
        $this->forbeast([self::API . '/pages/3003/take' => Http::response(['error' => $code, 'message' => 'raw ' . $code . ' text'], $status)]);
        $this->actingAs($this->user());

        $this->post('/chatbot/owners/take', ['target' => '3003', 'password' => 'secret-password', 'phrase' => 'TAKE Page Tres TO LIKHA'])
            ->assertSessionHas('owners_error', $shown);
    }

    public function test_S_164_13_a_page_held_here_for_a_transfer_says_its_follow_ups_are_paused_since_when(): void
    {
        $this->forbeast();
        $s = app(\App\Services\PageSettings::class);
        $s->set('Page Uno', 'botcake', 'page_id', '1001', false, null, true);
        $s->set('Page Dos', 'botcake', 'page_id', '2002', false, null, true);
        $this->travelTo('2026-10-27 09:00:00');
        $s->set('Page Uno', 'transfer', 'hold', '41', false, null, true);
        $this->travelTo('2026-10-29 09:00:00');
        $this->actingAs($this->user());
        $html = $this->get('/chatbot/owners')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#data-page="1001"(?:(?!data-page=)[\s\S])*?Follow-ups paused for transfer \#41 since Oct 27, 2026 9:00 AM#', $html);
        $this->assertSame(1, substr_count($html, 'Follow-ups paused'), 'only the held page');
    }

    public function test_S_164_8_the_screen_is_cards_that_fit_a_phone(): void
    {
        $this->forbeast();
        $this->actingAs($this->user());
        $html = $this->get('/chatbot/owners')->assertOk()->getContent();

        $this->assertStringContainsString('name="viewport" content="width=device-width', $html);
        $this->assertStringNotContainsString('<table', $html, 'no wide table: one card per page');
        $this->assertMatchesRegularExpression('/data-page="1001"[^>]*class="[^"]*\bmin-w-0\b/', $html);
        preg_match_all('/<input[^>]*name="(password|confirm_text)"[^>]*>/', $html, $inputs);
        foreach ($inputs[0] as $input) $this->assertStringContainsString('w-full', $input);
    }

    public static function marketingRoles(): array
    {
        return ['Marketing' => ['Marketing'], 'Marketing - OIC' => ['Marketing - OIC'], 'extra spaces' => [' Marketing  -  OIC ']];
    }

    #[DataProvider('marketingRoles')]
    public function test_S_170_1_marketing_sees_the_owners_link_and_the_screen(string $role): void
    {
        $this->forbeast();
        $this->navLink('/chatblast', [preg_replace('/\s+/u', ' ', trim($role))]);
        $this->actingAs($this->user($role, 'mkt@example.test'));

        $this->assertMatchesRegularExpression('/data-cb-nav="Owners"[^>]*aria-current="page"/', $this->get('/chatbot/owners')->assertOk()->getContent());
        $this->assertStringContainsString('data-cb-nav="Owners"', $this->get('/chatbot/pages')->assertOk()->getContent());
    }

    public function test_S_170_2_marketing_sees_only_unowned_and_this_sites_pages_and_no_requests(): void
    {
        $this->forbeast();
        $this->navLink('/chatblast', ['Marketing']);
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        $html = $this->get('/chatbot/owners')->assertOk()->getContent();

        $this->assertStringContainsString('data-page="3003"', $html);
        $this->assertStringContainsString('data-page="1001"', $html);
        foreach (['data-page="2002"', 'Page Dos', 'Incepxion', 'TRANSFER', 'ben@incepxion.test', self::ACTOR, 'data-group="other"'] as $absent) {
            $this->assertStringNotContainsString($absent, $html);
        }
        preg_match_all('/data-action="([a-z]+)" data-target="(\d+)"/', $html, $m, PREG_SET_ORDER);
        $this->assertSame(['take:3003'], array_values(array_unique(array_map(fn ($x) => $x[1] . ':' . $x[2], $m))));

        // History: only for the pages marketing can see; another site's page is not asked of Forbeast.
        $this->assertStringNotContainsString('data-history', $this->get('/chatbot/owners?history=2002')->assertOk()->getContent());
        $this->assertStringNotContainsString('data-history', $this->get('/chatbot/owners?history=01001')->assertOk()->getContent());
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/transfers?page=2002') || str_contains($r->url(), '/transfers?page=01001'));
        $this->assertStringContainsString('data-history="1001"', $this->get('/chatbot/owners?history=1001')->assertOk()->getContent());
    }

    public static function marketingTakes(): array
    {
        return [
            'claim' => ['secret-password', 'TAKE Page Tres TO LIKHA', true, null],
            'wrong password' => ['nope', 'TAKE Page Tres TO LIKHA', false, 'Wrong password'],
            'wrong phrase' => ['secret-password', 'TAKE Page Uno TO LIKHA', false, 'The phrase does not match'],
            'a page this site owns' => ['secret-password', 'TAKE Page Uno TO LIKHA', false, 'cannot be taken', '1001'],
            'a page the other site owns' => ['secret-password', 'TAKE Page Dos TO LIKHA', false, 'cannot be taken', '2002'],
        ];
    }

    #[DataProvider('marketingTakes')]
    public function test_S_170_3_marketing_claims_with_their_own_password_and_the_phrase(string $password, string $phrase, bool $sent, ?string $error, string $target = '3003'): void
    {
        $this->forbeast();
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        $res = $this->post('/chatbot/owners/take', ['target' => $target, 'password' => $password, 'confirm_text' => $phrase])->assertRedirect('/chatbot/owners');

        if (!$sent) {
            $res->assertSessionHas('owners_error', fn ($e) => str_contains($e, $error));
            $this->assertSame([], $this->writes());
            return;
        }
        $res->assertSessionHas('owners_status');
        $writes = $this->writes();
        $this->assertCount(1, $writes);
        $this->assertSame(self::API . '/pages/3003/take', $writes[0]->url());
        $this->assertSame(['actor' => 'Marketing User mkt@example.test', 'phrase' => 'TAKE Page Tres TO LIKHA'], $writes[0]->data());
    }

    public static function ceoOnly(): array
    {
        return ['move' => ['move', '1001', 'TRANSFER Page Uno TO INCEPXION'], 'ask' => ['ask', '2002', 'TRANSFER Page Dos TO LIKHA'],
            'accept' => ['accept', '41', 'TRANSFER Page Uno TO INCEPXION'], 'decline' => ['decline', '41', ''], 'cancel' => ['cancel', '42', '']];
    }

    #[DataProvider('ceoOnly')]
    public function test_S_170_4_marketing_cannot_move_ask_accept_decline_or_cancel(string $action, string $target, string $phrase): void
    {
        $this->forbeast();
        $this->actingAs($this->user('Marketing - OIC', 'oic@example.test'));

        $this->post('/chatbot/owners/' . $action, ['target' => $target, 'password' => 'secret-password', 'confirm_text' => $phrase])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_S_170_5_available_pages_come_first_then_this_site_then_the_other_site_one_per_row(): void
    {
        $this->forbeast();
        $this->actingAs($this->user());
        $html = $this->get('/chatbot/owners')->assertOk()->getContent();

        $order = array_map(fn (string $needle) => strpos($html, $needle), ['data-group="available"', 'data-page="3003"', 'data-group="site"', 'data-page="1001"', 'data-group="other"', 'data-page="2002"']);
        $this->assertNotContains(false, $order);
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order, 'Available, then On Likha, then On Incepxion');
        $this->assertStringContainsString('Available to claim', $html);
        $this->assertStringContainsString('On Likha', $html);
        $this->assertStringContainsString('On Incepxion', $html);
        $this->assertStringNotContainsString('grid-cols-2', $html, 'one page per row');
        $this->assertMatchesRegularExpression('#data-action="take" data-target="3003"[^>]*>\s*<summary[^>]*>\s*Claim\s*<#', $html);
    }

    public function test_S_170_5_an_empty_available_group_says_there_is_nothing_to_claim(): void
    {
        $this->forbeast([self::API . '/pages' => Http::response(['site' => 'likha', 'pages' => [['meta_page_id' => '1001', 'name' => 'Page Uno', 'owner' => 'likha', 'pending' => []]]])]);
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));

        $this->assertMatchesRegularExpression('#data-group="available"(?:(?!data-group=)[\s\S])*?No pages to claim right now#', $this->get('/chatbot/owners')->assertOk()->getContent());
    }

    public function test_S_170_6_the_confirm_form_has_a_hidden_username_then_the_password_then_the_phrase(): void
    {
        $this->forbeast();
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        $html = $this->get('/chatbot/owners')->assertOk()->getContent();

        $this->assertSame(1, preg_match('#data-action="take" data-target="3003"[\s\S]*?</form>#', $html, $form));
        $this->assertMatchesRegularExpression('#<input(?=[^>]*autocomplete="username")(?=[^>]*value="mkt@example.test")(?=[^>]*\bhidden\b)[^>]*>[\s\S]*type="password"[\s\S]*<input(?=[^>]*name="confirm_text")(?=[^>]*autocomplete="off")[^>]*>#', $form[0]);
        $this->assertStringNotContainsString('name="phrase"', $form[0]);
        // The phrase is case-sensitive: a phone keyboard must not switch it to capitals.
        $this->assertMatchesRegularExpression('#<input(?=[^>]*name="confirm_text")(?=[^>]*autocapitalize="off")(?=[^>]*autocorrect="off")[^>]*>#', $form[0]);
    }

    private const UNOWNED = ['site' => 'likha', 'pages' => [['meta_page_id' => '3003', 'name' => 'Page Tres', 'owner' => null, 'pending' => []]]];
    private const CLAIM = ['target' => '3003', 'password' => 'secret-password', 'confirm_text' => 'TAKE Page Tres TO LIKHA'];

    /** Ang card ng isang page sa HTML. */
    private function card(string $html, string $id): string
    {
        // Possessive (*+): walang backtracking, kaya hindi umaabot sa limit ng PCRE sa mahabang page.
        $this->assertSame(1, preg_match('#<li data-page="' . $id . '"(?:(?!<li data-page=)[\s\S])*+#', $html, $m), 'card ' . $id);

        return $m[0];
    }

    public function test_S_170_9_after_a_claim_the_page_shows_claiming_without_its_claim_button_and_the_screen_refreshes(): void
    {
        $this->forbeast([self::API . '/pages' => Http::response(self::UNOWNED), self::API . '/transfers?page=3003' => Http::response(['transfers' => [
            ['id' => 50, 'direction' => 'take', 'to' => 'likha', 'status' => 'running', 'steps' => []]]])]);
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));

        $this->post('/chatbot/owners/take', self::CLAIM)->assertSessionHas('owners_status');
        $html = $this->get('/chatbot/owners')->assertOk()->getContent();
        $card = $this->card($html, '3003');

        $this->assertMatchesRegularExpression('#data-claiming="50"[^>]*>\s*Claiming…\s*\#50\s*<#', $card);
        $this->assertStringNotContainsString('data-action="take"', $card);
        $this->assertStringContainsString('data-owners-refresh', $html);
    }

    public function test_S_170_10_when_forbeast_reports_this_site_as_owner_the_card_moves_there_with_the_claimed_note(): void
    {
        $this->forbeast([self::API . '/pages' => Http::sequence()->push(self::UNOWNED)
            ->push(['site' => 'likha', 'pages' => [['meta_page_id' => '3003', 'name' => 'Page Tres', 'owner' => 'likha', 'pending' => []]]])]);
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));

        $this->post('/chatbot/owners/take', self::CLAIM)->assertSessionHas('owners_status');
        $html = $this->get('/chatbot/owners')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#data-group="site"(?:(?!data-group=)[\s\S])*?data-page="3003"#', $html);
        $this->assertMatchesRegularExpression('#data-claimed[^>]*>\s*Claimed — the bot is Off until you set it up and turn it on#', $this->card($html, '3003'));
        $this->assertStringNotContainsString('data-claiming', $html);
        $this->assertStringNotContainsString('data-owners-refresh', $html, 'nothing in progress: no refresh');
    }

    public function test_S_170_11_a_failed_take_shows_forbeasts_error_in_red_and_brings_the_claim_button_back(): void
    {
        $this->forbeast([self::API . '/pages' => Http::response(self::UNOWNED), self::API . '/transfers?page=3003' => Http::response(['transfers' => [
            ['id' => 50, 'direction' => 'take', 'to' => 'likha', 'status' => 'stuck', 'steps' => [
                ['step' => 'adopt', 'status' => 'failed', 'error' => 'Likha did not answer <adopt> (HTTP 500)']]]]])]);
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));

        $this->post('/chatbot/owners/take', self::CLAIM)->assertSessionHas('owners_status');
        $card = $this->card($this->get('/chatbot/owners')->assertOk()->getContent(), '3003');

        $this->assertMatchesRegularExpression('#data-claim-error[^>]*text-red[^>]*>[^<]*Likha did not answer &lt;adopt&gt; \(HTTP 500\)#', $card);
        $this->assertStringContainsString('data-action="take" data-target="3003"', $card);
        $this->assertStringNotContainsString('data-claiming', $card);
    }

    public static function doubles(): array
    {
        return ['claim' => ['Marketing', 'take', self::CLAIM, 'This page is already being claimed'],
            'move' => ['CEO', 'move', ['target' => '1001', 'password' => 'secret-password', 'confirm_text' => 'TRANSFER Page Uno TO INCEPXION'], 'A request for this page is already in progress']];
    }

    #[DataProvider('doubles')]
    public function test_S_170_12_a_second_request_for_a_page_in_progress_is_refused_without_calling_forbeast(string $role, string $action, array $form, string $error): void
    {
        $this->forbeast();
        $this->actingAs($role === 'CEO' ? $this->user() : $this->user($role, 'mkt@example.test'));

        $this->post('/chatbot/owners/' . $action, $form)->assertSessionHas('owners_status');
        $calls = count(Http::recorded());
        $this->post('/chatbot/owners/' . $action, $form)->assertRedirect('/chatbot/owners')->assertSessionHas('owners_error', fn ($e) => str_contains($e, $error));

        $this->assertCount($calls, Http::recorded(), 'nothing asked of Forbeast');
        $this->assertCount(1, $this->writes());
    }
}
