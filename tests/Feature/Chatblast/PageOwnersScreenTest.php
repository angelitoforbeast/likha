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
            self::API . '/transfers/*' => Http::response([], 202),
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
        $this->navLink('/chatblast', ['Marketing']);
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));

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

    public function test_S_164_8_the_screen_is_cards_that_fit_a_phone(): void
    {
        $this->forbeast();
        $this->actingAs($this->user());
        $html = $this->get('/chatbot/owners')->assertOk()->getContent();

        $this->assertStringContainsString('name="viewport" content="width=device-width', $html);
        $this->assertStringNotContainsString('<table', $html, 'no wide table: one card per page');
        $this->assertMatchesRegularExpression('/data-page="1001"[^>]*class="[^"]*\bmin-w-0\b/', $html);
        preg_match_all('/<input[^>]*name="(password|phrase)"[^>]*>/', $html, $inputs);
        foreach ($inputs[0] as $input) $this->assertStringContainsString('w-full', $input);
    }
}
