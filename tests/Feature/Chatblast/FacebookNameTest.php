<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\FacebookName;
use App\Services\Chatbot\ProblemsPanel;
use App\Services\MetaAds\Connections;
use App\Services\PageSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Spec 208: ang tunay na pangalan ng page sa Facebook (mula sa gateway probe, o sa Meta Ads connection na nakakabasa ng
 * page), at ang babala kapag iba ito sa pangalan ng row o kapag may ibang row na may parehong page ID. Fake na HTTP lang.
 */
class FacebookNameTest extends ChatblastTestCase
{
    private const GW = 'https://gw.example.test/api';
    private const ID = '1085660977960440';
    private const REAL = 'Alaga Natural Collective';
    private const DUP = '4RO Alaga Natural Collective';
    private const FB_TOKEN = 'fbp_alaga_gateway_token_k8j7';
    private const META_TOKEN = 'EAAmeta-user-token-first-5d4c';
    private const META_TOKEN_2 = 'EAAmeta-user-token-second-9h8g';
    private const NOT_READABLE = ['error' => ['message' => '(#100) Object does not exist, cannot be loaded due to missing permission or reviewable feature', 'type' => 'GraphMethodException', 'code' => 100]];
    private $ceo;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_10_20_100000_create_botcake_chats_table.php']]);
        config(['app.url' => 'https://app.example.test/', 'services.gateway.url' => self::GW, 'services.gateway.key' => '',
            'services.meta_ads.app_secret' => 'meta-app-secret-test', 'services.meta_ads.graph_version' => 'v25.0']);
        $this->travelTo('2026-10-31 10:00:00');
        $this->actingAs($this->ceo = $this->user());
    }

    private function settings(): PageSettings
    {
        return app(PageSettings::class);
    }

    /** Ang dalawang row ng live case: parehong Botcake page ID, ang tunay ay may Forbeast token. */
    private function alaga(): void
    {
        $s = $this->settings();
        $this->page(self::REAL, self::ID);
        $this->page(self::DUP, self::ID);
        $s->set(self::REAL, 'forbeast', 'token', self::FB_TOKEN, true);
        $s->set(self::REAL, 'forbeast', 'token_status', 'ok · Forbeast connected', false, null, true);
        $s->set(self::REAL, 'forbeast', 'webhook_secret', str_repeat('s', 64), true, null, true);
    }

    private function meta(int $userId, string $token, string $status = 'ok'): void
    {
        app(Connections::class)->save($userId, $token, ['fb_user_name' => 'Ads User ' . $userId, 'ad_accounts' => [], 'status' => $status]);
    }

    private function botcakeOk(): array
    {
        return self::setupOkFakes() + ['botcake.io/*' => Http::response(['success' => true, 'data' => []])];
    }

    private function graphCalls(): array
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'graph.facebook.com'))->map(fn ($p) => $p[0])->values()->all();
    }

    private function row(string $page): array
    {
        return collect($this->getJson('/chatbot/data')->assertOk()->json('rows'))->firstWhere('page', $page);
    }

    /** Ang helper ng Check at ng Connections (facebook-lines, mula sa rendered HTML) sa Node. */
    private function lines(?array $facebook, string $page, string $pageId = self::ID): array
    {
        $html = $this->get('/chatbot/pages')->assertOk()->getContent();
        $this->assertSame(1, preg_match('~// facebook-lines:start(.*?)// facebook-lines:end~s', $html, $m), 'the helper is on the page');
        $this->assertSame(1, preg_match('~// fb-link:start(.*?)// fb-link:end~s', $html, $link));
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) $this->markTestSkipped('Node is not installed.');
        $base = tempnam(sys_get_temp_dir(), 'fbn');
        $file = $base . '.mjs';
        file_put_contents($file, $link[1] . $m[1] . "\nconsole.log(JSON.stringify(facebookLines(" . json_encode($facebook) . ', ' . json_encode($page) . ', ' . json_encode($pageId) . ')));');
        $p = new Process([$node, $file]);
        $p->run();
        @unlink($file);
        @unlink($base);
        $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());

        return json_decode($p->getOutput(), true);
    }

    public function test_S_208_1_the_gateway_test_stores_the_facebook_name_and_the_duplicate_row_shows_it(): void
    {
        $this->alaga();
        $this->meta(1, self::META_TOKEN); // ang gateway ang nauuna: walang tawag sa Graph
        Http::fake([self::GW . '/v1/pages/' . self::ID => Http::response(['id' => self::ID, 'name' => self::REAL, 'webhook' => ['url' => 'https://app.example.test/api/gateway/events']])]);

        $this->postJson('/chatbot/connections/forbeast-test', ['page_name' => self::REAL])->assertOk()->assertJsonPath('status', 'ok');

        $s = $this->settings();
        $this->assertSame(self::REAL, $s->get(self::REAL, 'facebook', 'name'));
        $this->assertSame('2026-10-31 10:00:00 · forbeast · ' . self::ID, $s->get(self::REAL, 'facebook', 'name_checked'));
        $this->assertSame([], $this->graphCalls());

        $dup = $this->row(self::DUP)['facebook'];
        $this->assertSame(['name' => self::REAL, 'differs' => true, 'same_id' => [self::REAL], 'match' => self::REAL], array_intersect_key($dup, array_flip(['name', 'differs', 'same_id', 'match'])));
        $this->assertFalse($this->row(self::REAL)['facebook']['differs']);

        $shown = $this->lines($dup, self::DUP);
        $this->assertSame(['Facebook name: ' . self::REAL, 'Same page ID as ' . self::REAL . ': ' . self::REAL . ' matches the Facebook name'], $shown['warnings']);
        $this->assertSame(['Same page ID as ' . self::DUP . ': this row matches the Facebook name'], $this->lines($this->row(self::REAL)['facebook'], self::REAL)['warnings']);
        // Ang Connections ng doble: ang parehong babala.
        $this->assertSame(self::REAL, $this->getJson('/chatbot/connections?page_name=' . rawurlencode(self::DUP))->assertOk()->json('facebook.name'));
    }

    public function test_S_208_2_without_a_gateway_token_a_meta_ads_connection_that_can_read_the_page_gives_the_name(): void
    {
        $this->page('Page Dos', '2002');
        $this->meta(1, self::META_TOKEN, 'expired'); // expired: hindi sinusubukan
        $this->meta(2, self::META_TOKEN_2);          // hindi nakakabasa ng page
        $this->meta(3, 'EAAmeta-user-token-third-1a2b');
        $this->meta(4, 'EAAmeta-user-token-never-0z0z'); // tumigil na sa ikatlo
        Http::fake($this->botcakeOk() + [
            'graph.facebook.com/*' => fn (HttpRequest $r) => str_contains((string) $r->header('Authorization')[0], 'third')
                ? Http::response(['name' => 'Page Dos Shop', 'id' => '2002']) : Http::response(self::NOT_READABLE, 400),
        ]);

        $this->postJson('/chatbot/connections/botcake-test', ['page_name' => 'Page Dos'])->assertOk();

        $this->assertSame('Page Dos Shop', $this->settings()->get('Page Dos', 'facebook', 'name'));
        $this->assertSame('2026-10-31 10:00:00 · meta-ads · 2002', $this->settings()->get('Page Dos', 'facebook', 'name_checked'));
        $calls = $this->graphCalls();
        $this->assertCount(2, $calls, 'the second and third connection, one call each');
        foreach ($calls as $c) {
            $this->assertStringStartsWith('https://graph.facebook.com/v25.0/2002?', $c->url());
            $this->assertSame('name', $c->data()['fields'] ?? null);
            $this->assertNotEmpty($c->data()['appsecret_proof'] ?? null);
            $this->assertStringNotContainsString('EAAmeta', $c->url(), 'the token is in the header, not the URL');
        }
        $body = $this->getJson('/chatbot/connections?page_name=Page%20Dos')->assertOk()->getContent() . $this->getJson('/chatbot/data')->getContent();
        foreach ([self::META_TOKEN, self::META_TOKEN_2, 'EAAmeta-user-token-third-1a2b'] as $t) $this->assertStringNotContainsString($t, $body);

        // "+ Page" na may page ID: kinukuha rin ang pangalan.
        $this->postJson('/chatbot/pages', ['page_name' => 'Page Tres', 'page_id' => '3003', 'token' => self::TOKEN])->assertOk();
        $this->assertSame('2026-10-31 10:00:00 · meta-ads · 3003', $this->settings()->get('Page Tres', 'facebook', 'name_checked'));
    }

    public function test_S_208_3_when_no_source_can_read_it_the_name_is_unknown_and_the_last_known_name_is_kept(): void
    {
        $this->page('Page Dos', '2002');
        $this->page('Page Uno', '1001');
        $this->meta(2, self::META_TOKEN_2);
        $this->meta(3, self::META_TOKEN);
        app(FacebookName::class)->remember('Page Dos', '2002', 'Page Dos Old Name', 'meta-ads');
        Http::fake($this->botcakeOk() + ['graph.facebook.com/*' => Http::response(self::NOT_READABLE, 400)]);

        foreach (['Page Dos', 'Page Uno'] as $page) $this->postJson('/chatbot/connections/botcake-test', ['page_name' => $page])->assertOk();

        $s = $this->settings();
        $this->assertSame('Page Dos Old Name', $s->get('Page Dos', 'facebook', 'name'), 'the last known name is kept');
        $this->assertSame('2026-10-31 10:00:00 · unknown · 2002', $s->get('Page Dos', 'facebook', 'name_checked'));
        $this->assertNull($s->get('Page Uno', 'facebook', 'name'));
        $this->assertSame('2026-10-31 10:00:00 · unknown · 1001', $s->get('Page Uno', 'facebook', 'name_checked'));
        $this->assertCount(4, $this->graphCalls(), 'each connection once per page');

        $uno = $this->getJson('/chatbot/connections?page_name=Page%20Uno')->assertOk()->json('facebook');
        $shown = $this->lines($uno, 'Page Uno', '1001');
        $this->assertSame('Facebook name: unknown', $shown['line']);
        $this->assertSame('https://www.facebook.com/messages/t/1001', $shown['link']['href'], 'with the link to the page on Facebook');
        $dos = $this->lines($this->getJson('/chatbot/connections?page_name=Page%20Dos')->json('facebook'), 'Page Dos', '2002');
        $this->assertSame('Facebook name: Page Dos Old Name (last known; could not read it at 2026-10-31 10:00:00)', $dos['line']);
    }

    public function test_S_208_3_a_name_read_for_another_page_id_is_not_kept(): void
    {
        $this->page('Page Dos', '2002');
        app(FacebookName::class)->remember('Page Dos', '9999', 'Another Page', 'meta-ads');
        Http::fake($this->botcakeOk());

        $this->assertNull($this->row('Page Dos')['facebook']['name'], 'the ID changed since: not this page\'s name');
        $this->postJson('/chatbot/connections/botcake-test', ['page_name' => 'Page Dos'])->assertOk();
        $this->assertNull($this->settings()->get('Page Dos', 'facebook', 'name'));
    }

    public function test_S_208_4_names_equal_but_for_case_and_spaces_give_no_warning(): void
    {
        $this->page(self::REAL, self::ID);
        app(FacebookName::class)->remember(self::REAL, self::ID, "  ALAGA  natural\t Collective ", 'forbeast');

        $f = $this->row(self::REAL)['facebook'];
        $this->assertFalse($f['differs']);
        $this->assertSame([], $this->lines($f, self::REAL)['warnings']);

        // Ibang salita (hindi lang laki ng titik o espasyo): may babala.
        app(FacebookName::class)->remember(self::REAL, self::ID, 'Alaga Naturals Collective', 'forbeast');
        $this->assertTrue($this->row(self::REAL)['facebook']['differs']);
    }

    public function test_S_208_5_two_rows_with_one_page_id_both_warn_the_banner_counts_the_pair_once_and_the_match_is_marked(): void
    {
        $this->page('Refined Market', '490249500844090');
        $this->page('Refined Optics', '490249500844090');
        $this->page('Page Uno', '1001');
        Http::fake();

        // Hindi pa alam ang pangalan sa Facebook.
        $optics = $this->row('Refined Optics')['facebook'];
        $this->assertSame(['Same page ID as Refined Market: Facebook name: unknown'], $this->lines($optics, 'Refined Optics', '490249500844090')['warnings']);

        app(FacebookName::class)->remember('Refined Market', '490249500844090', 'Refined Market', 'meta-ads');
        $market = $this->row('Refined Market')['facebook'];
        $optics = $this->row('Refined Optics')['facebook'];
        $this->assertSame(['Refined Optics'], $market['same_id']);
        $this->assertSame(['Refined Market'], $optics['same_id']);
        $this->assertSame('Refined Market', $market['match']);
        $this->assertSame('Refined Market', $optics['match']);
        $this->assertSame([], $this->row('Page Uno')['facebook']['same_id']);
        $this->assertSame(['Same page ID as Refined Optics: this row matches the Facebook name'], $this->lines($market, 'Refined Market', '490249500844090')['warnings']);
        $this->assertSame(['Facebook name: Refined Market', 'Same page ID as Refined Market: Refined Market matches the Facebook name'], $this->lines($optics, 'Refined Optics', '490249500844090')['warnings']);
        $this->assertSame(['Refined Market'], $this->getJson('/chatbot/connections?page_name=Refined%20Optics')->assertOk()->json('facebook.same_id'));

        \Illuminate\Support\Facades\Cache::forget(ProblemsPanel::CACHE); // ang banner ay naka-cache nang 60 s (ang page sa itaas ay nag-render na nito)
        $panel = app(ProblemsPanel::class)->panel();
        $lines = collect($panel['pages'])->flatMap(fn ($p) => array_map(fn ($l) => [$p['page'], $l['level'], $l['text']], $p['lines']))->filter(fn ($l) => str_contains($l[2], 'Same page ID'))->values()->all();
        $this->assertSame([['Refined Market', 'orange', 'Same page ID 490249500844090 as Refined Optics; Refined Market matches the Facebook name']], $lines, 'the pair once');
        $this->assertSame(1, $panel['urgent']);
        $html = $this->get('/chatbot/pages')->assertOk()->getContent();
        $this->assertStringContainsString('Same page ID 490249500844090 as Refined Optics; Refined Market matches the Facebook name', $html);
        Http::assertNothingSent();
    }

    public function test_S_208_6_loading_the_pages_and_the_data_calls_no_gateway_and_no_graph(): void
    {
        $this->alaga();
        $this->page('Page Dos', '2002');
        $this->meta(1, self::META_TOKEN);
        Http::fake();

        $this->get('/chatbot/pages')->assertOk();
        $this->getJson('/chatbot/data')->assertOk();
        $this->getJson('/chatbot/connections?page_name=Page%20Dos')->assertOk();

        Http::assertNothingSent();
    }

    public function test_S_208_7_the_nightly_run_looks_up_each_listed_page_once_records_failures_and_keeps_going(): void
    {
        $s = $this->settings();
        $this->alaga();                         // Forbeast: ang retest na ang nagbibigay ng pangalan
        $this->page('Page Aa Boom', '1111');    // isang hindi inaasahang mali: tuloy pa rin ang iba
        $this->page('Page Dos', '2002');        // Graph 200
        $this->page('Page Tres', '3003');       // Graph 400
        $this->page('Page Cuatro', '4004');     // walang sagot ang Graph
        $s->set('Page Gw', 'botcake', 'page_id', '5005'); // gateway lang (walang Botcake token), nasa listahan pa rin
        $s->set('Page Gw', 'forbeast', 'token', 'fbp_gateway_only_token_5h5h', true);
        $this->meta(1, self::META_TOKEN);
        $this->app->bind(FacebookName::class, fn ($app) => new class($app->make(PageSettings::class), $app->make(\App\Services\Chatbot\ForbeastPageSetup::class), $app->make(Connections::class), $app->make(\App\Services\MetaAds\Graph::class)) extends FacebookName {
            public function lookup(string $page, array $skip = [], ?\DateTimeInterface $since = null): ?array
            {
                if ($page === 'Page Aa Boom') throw new \RuntimeException('boom');
                return parent::lookup($page, $skip, $since);
            }
        });
        Log::spy();
        $timeouts = 0; // ang tawag na walang sagot ay hindi naitatala ng Http::recorded
        Http::fake($this->botcakeOk() + [
            self::GW . '/v1/pages/' . self::ID => Http::response(['id' => self::ID, 'name' => self::REAL, 'webhook' => ['url' => 'https://app.example.test/api/gateway/events']]),
            self::GW . '/v1/pages/5005' => Http::response(['id' => '5005', 'name' => 'Page Gw', 'webhook' => null]),
            'graph.facebook.com/v25.0/2002*' => Http::response(['name' => 'Page Dos Shop', 'id' => '2002']),
            'graph.facebook.com/v25.0/3003*' => Http::response(self::NOT_READABLE, 400),
            'graph.facebook.com/v25.0/4004*' => function () use (&$timeouts) {
                $timeouts++;
                throw new ConnectionException('timed out');
            },
            'graph.facebook.com/*' => Http::response(self::NOT_READABLE, 400),
        ]);

        $this->artisan('chatbot:connections-check')->assertExitCode(0);

        $checked = fn ($p) => $s->get($p, 'facebook', 'name_checked');
        $this->assertSame('2026-10-31 10:00:00 · forbeast · ' . self::ID, $checked(self::REAL));
        $this->assertSame(self::REAL, $s->get(self::REAL, 'facebook', 'name'));
        $this->assertSame('2026-10-31 10:00:00 · forbeast · ' . self::ID, $checked(self::DUP), 'the duplicate reuses the name read this run');
        $this->assertSame('2026-10-31 10:00:00 · meta-ads · 2002', $checked('Page Dos'));
        $this->assertSame('2026-10-31 10:00:00 · unknown · 3003', $checked('Page Tres'));
        $this->assertSame('2026-10-31 10:00:00 · unknown · 4004', $checked('Page Cuatro'));
        $this->assertSame('2026-10-31 10:00:00 · forbeast · 5005', $checked('Page Gw'));
        $this->assertNull($checked('Page Aa Boom'));
        $urls = array_map(fn ($c) => strtok($c->url(), '?'), $this->graphCalls());
        sort($urls);
        // Isa kada page na walang gateway name; ang doble ay kumuha ng pangalang nabasa ng Forbeast ngayong run (walang tawag).
        $this->assertSame(['https://graph.facebook.com/v25.0/2002', 'https://graph.facebook.com/v25.0/3003'], $urls);
        $this->assertSame(1, $timeouts, 'Page Cuatro: one call, no retry');
        Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx = []) => $msg === 'chatbot:connections-check'
            && ($ctx['facebook_names'] ?? null) === 4 && ($ctx['facebook_unknown'] ?? null) === 2 && ($ctx['errors'] ?? null) === 1
            && !str_contains(json_encode($ctx), 'EAAmeta'));
    }

    public function test_S_208_1_the_gateway_save_stores_the_name_and_a_failed_gateway_test_tries_the_others_once(): void
    {
        $this->page(self::REAL, self::ID);
        $this->meta(1, self::META_TOKEN);
        $gw = 0;
        Http::fake([
            self::GW . '/v1/pages/' . self::ID . '/webhook' => Http::response(['ok' => true]),
            self::GW . '/v1/pages/' . self::ID => function () use (&$gw) {
                $gw++;
                return $gw <= 2 ? Http::response(['id' => self::ID, 'name' => self::REAL, 'webhook' => null]) : Http::response(['error' => 'revoked'], 401);
            },
            'graph.facebook.com/*' => Http::response(['name' => 'Alaga Natural Collective (Graph)', 'id' => self::ID]),
        ]);

        // Save: ang probe (bago i-save) at ang GET ng connect (ang unang dalawang sagot); ang pangalan ay mula sa gateway, walang Graph.
        $this->postJson('/chatbot/connections/forbeast', ['page_name' => self::REAL, 'page_id' => self::ID, 'token' => self::FB_TOKEN])->assertOk();
        $this->assertSame('2026-10-31 10:00:00 · forbeast · ' . self::ID, $this->settings()->get(self::REAL, 'facebook', 'name_checked'));
        $this->assertSame([], $this->graphCalls());

        // Test na hindi nabasa ng gateway: isang probe lang ng gateway, saka ang Meta Ads.
        $before = $gw;
        $this->travelTo('2026-10-31 10:05:00');
        $this->postJson('/chatbot/connections/forbeast-test', ['page_name' => self::REAL])->assertOk()->assertJsonPath('status', 'invalid');
        $this->assertSame(1, $gw - $before, 'the gateway is not probed again');
        $this->assertCount(1, $this->graphCalls());
        $this->assertSame('2026-10-31 10:05:00 · meta-ads · ' . self::ID, $this->settings()->get(self::REAL, 'facebook', 'name_checked'));
    }

    public function test_S_208_2_a_rate_limited_connection_is_tried_once_without_waiting(): void
    {
        \Illuminate\Support\Sleep::fake();
        $this->page('Page Dos', '2002');
        $this->meta(1, self::META_TOKEN);
        Http::fake($this->botcakeOk() + ['graph.facebook.com/*' => Http::response(['error' => ['message' => 'User request limit reached', 'code' => 17]], 400)]);

        $this->postJson('/chatbot/connections/botcake-test', ['page_name' => 'Page Dos'])->assertOk();

        $this->assertCount(1, $this->graphCalls());
        \Illuminate\Support\Sleep::assertNeverSlept();
        $this->assertSame('2026-10-31 10:00:00 · unknown · 2002', $this->settings()->get('Page Dos', 'facebook', 'name_checked'));
    }

    public function test_S_208_5_a_name_from_outside_is_shown_as_text(): void
    {
        $this->page('Refined Market', '490249500844090');
        $this->page('Refined Optics', '490249500844090');
        app(FacebookName::class)->remember('Refined Market', '490249500844090', '<img src=x onerror=alert(1)>', 'meta-ads');

        $html = $this->get('/chatbot/pages')->assertOk()->getContent();
        $this->assertStringNotContainsString('<img src=x onerror', $html);
        $this->assertStringContainsString('no row matches the Facebook name &quot;&lt;img src=x onerror=alert(1)&gt;&quot;', $html);
    }

    public function test_S_208_8_plus_page_with_a_used_id_names_the_owner_and_the_facebook_name(): void
    {
        $this->page(self::REAL, self::ID);
        Http::fake();
        $this->postJson('/chatbot/pages', ['page_name' => 'New Row', 'page_id' => self::ID, 'token' => self::TOKEN])
            ->assertStatus(422)->assertJsonPath('error', 'Botcake ID ' . self::ID . ' is already used by ' . self::REAL . '.');

        app(FacebookName::class)->remember(self::REAL, self::ID, 'Alaga Natural Collective PH', 'forbeast');
        $this->postJson('/chatbot/pages', ['page_name' => 'New Row', 'page_id' => self::ID, 'token' => self::TOKEN])
            ->assertStatus(422)->assertJsonPath('error', 'Botcake ID ' . self::ID . ' is already used by ' . self::REAL . '. Facebook name: Alaga Natural Collective PH.');
        Http::assertNothingSent();
    }
}
