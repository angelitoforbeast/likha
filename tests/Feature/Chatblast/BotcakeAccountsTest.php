<?php

namespace Tests\Feature\Chatblast;

use App\Models\PageSetting;
use App\Services\BotcakeAccounts;
use App\Services\PageSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Spec 272: /chatbot/botcake-accounts. Botcake is faked (Http::fake): list_access_token and GET /pages answer per account
 * token (the user-access-token header). Account tokens are JWT-shaped strings built here at run time, never written out.
 */
class BotcakeAccountsTest extends ChatblastTestCase
{
    private const URL = '/chatbot/botcake-accounts';

    private const LINK_MIGRATION = 'database/migrations/2026_11_26_100000_add_botcake_accounts_nav_link.php';

    /** @var array<string, array{list: mixed, pages: mixed}> account token => Botcake's answers */
    private array $bc = [];

    /** @var array<int, string> every log line with its context */
    private array $logs = [];

    private string $tokA;

    private string $tokA2;

    private string $tokB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-13 09:00:00');
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_11_12_100000_add_source_to_page_settings.php',
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_05_18_140000_create_nav_links_and_visibility_tables.php',
            'database/migrations/2026_10_18_300100_add_chatblast_nav_link.php',
            'database/migrations/2026_10_27_100000_add_chatbot_nav_link.php',
            self::LINK_MIGRATION,
        ], '--force' => true]);

        $this->tokA = self::jwt('11111111-aaaa-4bbb-8ccc-000000000001', 'first');
        $this->tokA2 = self::jwt('11111111-aaaa-4bbb-8ccc-000000000001', 'second');
        $this->tokB = self::jwt('22222222-aaaa-4bbb-8ccc-000000000002', 'other');
        $this->bc[$this->tokA] = $this->account(['1001' => 'pt-1001-botcake', '1002' => 'pt-1002-botcake', '1003' => 'pt-1003-botcake', '1004' => 'pt-1004-botcake']);
        $this->bc[$this->tokA2] = $this->bc[$this->tokA];
        $this->bc[$this->tokB] = $this->account(['2001' => 'pt-2001-botcake']);

        Http::fake(function (HttpRequest $r) {
            $tok = $r->header('user-access-token')[0] ?? '';
            $acct = $this->bc[$tok] ?? null;
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            if ($path === '/api/public_api/v1/integration_page/list_access_token') {
                if ($acct === null) {
                    return Http::response(['success' => false, 'message' => 'Invalid access token'], 401);
                }
                $a = $acct['list'];

                return $a instanceof \Closure ? $a($r) : (is_array($a) ? Http::response($a) : $a);
            }
            if ($path === '/api/public_api/v1/pages' && $r->method() === 'GET') {
                if ($acct === null) {
                    return Http::response(['success' => false, 'message' => 'Invalid access token'], 401);
                }
                $a = $acct['pages'];
                if (! is_array($a)) {
                    return $a instanceof \Closure ? $a($r) : $a;
                }
                parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
                $at = (int) ($q['cursor'] ?? 0);
                $next = $at + 2 < count($a) ? (string) ($at + 2) : null; // two per call, to follow the cursor

                return Http::response(['data' => array_slice($a, $at, 2), 'meta' => ['next_cursor' => $next], 'success' => true]);
            }

            return Http::response('not expected', 599);
        });

        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logs[] = $e->message.' '.json_encode($e->context);
        });
    }

    /** A JWT-shaped fake token: header and payload like Botcake's (user_id, timestamp), a fake signature. */
    private static function jwt(string $userId, string $salt): string
    {
        $part = fn (array $a) => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');

        return $part(['alg' => 'HS256', 'typ' => 'JWT']).'.'.$part(['user_id' => $userId, 'timestamp' => 1763000000]).'.fake-signature-'.$salt;
    }

    /** Botcake's answers for an account: the page tokens and the pages (name Page <id>, role MANAGE). */
    private function account(array $pageTokens): array
    {
        return [
            'list' => array_map(fn ($id, $t) => ['page_id' => (string) $id, 'public_token' => $t], array_keys($pageTokens), $pageTokens),
            'pages' => array_map(fn ($id) => ['name' => 'Botcake Page '.$id, 'page_id' => (string) $id, 'platform' => 'facebook', 'role_in_page' => 'MANAGE', 'marketing_message_supported' => true], array_keys($pageTokens)),
        ];
    }

    private function add(string $label, string $token)
    {
        return $this->post(self::URL, ['label' => $label, 'access_token' => $token]);
    }

    private function rows(string $like = '%')
    {
        return PageSetting::where('page_name', BotcakeAccounts::PAGE)->where('provider', BotcakeAccounts::PROVIDER)->where('key', 'like', $like)->orderBy('id')->get();
    }

    private function accounts(): array
    {
        return app(BotcakeAccounts::class)->all();
    }

    /** Every account token of this test, so a response, the session or a log line can be checked for none of them. */
    private function assertNoToken(string $text, string $where): void
    {
        foreach ([$this->tokA, $this->tokA2, $this->tokB] as $t) {
            $this->assertStringNotContainsString($t, $text, 'An account token leaked into '.$where);
            $this->assertStringNotContainsString(explode('.', $t)[2], $text, 'Part of an account token leaked into '.$where);
        }
        $this->assertDoesNotMatchRegularExpression('/eyJ[A-Za-z0-9_-]{10,}/', $text, 'A JWT-looking string leaked into '.$where);
    }

    private function link(string $role, bool $visible = true): void
    {
        $id = DB::table('nav_links')->where('key', 'botcake_accounts')->value('id');
        DB::table('nav_link_role_visibility')->updateOrInsert(['nav_link_id' => $id, 'role' => $role], ['is_visible' => $visible, 'created_at' => now(), 'updated_at' => now()]);
    }

    // ---- S1: add an account ----

    public function test_s1_an_accepted_token_is_saved_encrypted_with_its_label_who_when_botcake_user_and_page_count(): void
    {
        $ceo = $this->user('CEO');
        $this->actingAs($ceo);

        $res = $this->add('Marketing A – Botcake', $this->tokA)->assertRedirect(self::URL);
        $html = $this->followRedirects($res)->assertOk()->assertSee('Saved: Marketing A – Botcake (4 pages)')->getContent();
        $this->assertNoToken($html, 'the page after saving');

        $secret = $this->rows('%.token')->sole();
        $this->assertTrue($secret->is_secret);
        $this->assertSame($this->tokA, Crypt::decryptString($secret->getRawOriginal('value')));
        $this->assertSame($ceo->id, $secret->created_by);

        $a = array_values($this->accounts());
        $this->assertCount(1, $a);
        $this->assertSame(['Marketing A – Botcake', '11111111-aaaa-4bbb-8ccc-000000000001', $ceo->id], [$a[0]['label'], $a[0]['user_id'], $a[0]['added_by']]);
        $this->assertTrue($a[0]['check']['ok']);
        $this->assertSame(4, $a[0]['check']['pages']);
        $this->assertNotNull($a[0]['added_at']);

        // The calls: list_access_token as a POST without an id in the path and with an empty body, then GET /pages
        // following the cursor (4 pages, 2 per call), the token in the user-access-token header only.
        $calls = Http::recorded()->map(fn ($p) => [$p[0]->method(), $p[0]->url(), $p[0]->body()])->all();
        $this->assertSame(['POST', 'https://botcake.io/api/public_api/v1/integration_page/list_access_token', ''], $calls[0]);
        $this->assertSame(['GET', 'https://botcake.io/api/public_api/v1/pages'], array_slice($calls[1], 0, 2));
        $this->assertSame(['GET', 'https://botcake.io/api/public_api/v1/pages?cursor=2'], array_slice($calls[2], 0, 2));
        $this->assertCount(3, $calls);
        Http::assertSent(fn (HttpRequest $r) => ($r->header('user-access-token')[0] ?? null) === $this->tokA);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), $this->tokA) || str_contains($r->body(), $this->tokA));

        $list = $this->get(self::URL)->assertOk()->assertSee('Marketing A – Botcake')->assertSee('CEO User')->assertSee('2026-11-13 09:00')->getContent();
        $this->assertStringContainsString('data-ba-check="ok"', $list);
        $this->assertNoToken($list, 'the list');
    }

    public function test_s1_a_refused_token_saves_nothing_and_shows_botcakes_message_in_a_red_box(): void
    {
        $this->actingAs($this->user('CEO'));
        $unknown = self::jwt('33333333-aaaa-4bbb-8ccc-000000000003', 'unknown');

        $html = $this->followRedirects($this->add('Marketing C', $unknown))->assertOk()->getContent();
        $this->assertStringContainsString('data-ba-form-error', $html);
        $this->assertStringContainsString('Botcake: Invalid access token', $html);
        $this->assertStringContainsString('Nothing was saved.', $html);
        $this->assertStringContainsString('value="Marketing C"', $html); // the label comes back, the token does not
        $this->assertStringNotContainsString($unknown, $html);
        $this->assertCount(0, $this->rows());

        // 200 with success:false and no message, or a body that is not a list: "Botcake did not accept this token".
        $this->bc[$unknown] = ['list' => Http::response(['success' => false]), 'pages' => []];
        $this->followRedirects($this->add('Marketing C', $unknown))->assertSee(BotcakeAccounts::REFUSED);
        $this->bc[$unknown] = ['list' => [], 'pages' => Http::response(['success' => false], 403)];
        $this->followRedirects($this->add('Marketing C', $unknown))->assertSee(BotcakeAccounts::REFUSED);
        $this->assertCount(0, $this->rows());
    }

    public function test_s1_a_network_error_or_rate_limit_is_shown_as_such_and_saves_nothing(): void
    {
        $this->actingAs($this->user('CEO'));
        $this->bc[$this->tokA]['list'] = fn () => Http::failedConnection('cURL error 28: Operation timed out');
        $this->followRedirects($this->add('Marketing A', $this->tokA))->assertSee('Could not reach Botcake (network error or timeout)');

        $this->bc[$this->tokA]['list'] = Http::response(['message' => 'Too many requests'], 429);
        $this->followRedirects($this->add('Marketing A', $this->tokA))->assertSee('Botcake is limiting requests right now (HTTP 429)');

        $this->bc[$this->tokA] = $this->account(['1001' => 'pt']);
        $this->bc[$this->tokA]['pages'] = Http::response('upstream down', 502);
        $this->followRedirects($this->add('Marketing A', $this->tokA))->assertSee('Botcake had an error (HTTP 502)');

        $this->assertCount(0, $this->rows());
        // Not retried: one list_access_token call per Save (and GET /pages once on the last).
        $this->assertCount(4, Http::recorded());
    }

    public function test_s1_a_token_of_the_same_botcake_user_replaces_the_saved_one_and_says_updated(): void
    {
        $this->actingAs($this->user('CEO'));
        $this->add('Marketing A', $this->tokA);
        $first = array_key_first($this->accounts());
        $this->travel(5)->minutes();

        $this->followRedirects($this->add('Marketing A (new token)', $this->tokA2))->assertSee('Updated: Marketing A (new token) (4 pages)');

        $accounts = $this->accounts();
        $this->assertSame([$first], array_keys($accounts));
        $this->assertSame('Marketing A (new token)', $accounts[$first]['label']);
        $active = $this->rows('%.token')->where('active', true);
        $this->assertCount(1, $active);
        $this->assertSame($this->tokA2, Crypt::decryptString($active->first()->getRawOriginal('value')));
        $this->assertCount(2, $this->rows('%.token')); // the old one is history

        // A different Botcake user is a second account.
        $this->add('Marketing B', $this->tokB);
        $this->assertCount(2, $this->accounts());
    }

    public function test_s1_bad_input_is_refused_before_any_call(): void
    {
        $this->actingAs($this->user('CEO'));
        $this->followRedirects($this->add('', $this->tokA))->assertSee('Give the account a label');
        $this->followRedirects($this->add('Marketing A', ''))->assertSee('Paste the Access Token.');
        $this->followRedirects($this->add('Marketing A', 'two words'))->assertSee('Paste the token on one line');
        $this->followRedirects($this->add(str_repeat('x', 81), $this->tokA))->assertSee('Label: 80 characters at most.');
        Http::assertNothingSent();
        $this->assertCount(0, $this->rows());
    }

    // ---- S2: the token is never shown again ----

    public function test_s2_the_token_is_in_no_response_session_or_log_line_even_when_botcake_echoes_it(): void
    {
        $this->actingAs($this->user('CEO'));
        $bodies = [];
        $bodies[] = $this->followRedirects($this->add('Marketing A', $this->tokA))->getContent();
        $id = array_key_first($this->accounts());
        $bodies[] = $this->get(self::URL)->getContent();
        $bodies[] = $this->get(self::URL.'/'.$id.'/pages')->getContent();
        $bodies[] = $this->followRedirects($this->post(self::URL.'/'.$id.'/update-tokens'))->getContent();
        $bodies[] = $this->getJson(self::URL)->getContent();

        // Botcake refusing with the token inside its message: shown and logged redacted.
        $this->bc[$this->tokB]['list'] = Http::response(['success' => false, 'message' => 'Token '.$this->tokB.' is expired'], 401);
        $bodies[] = $this->followRedirects($this->add('Marketing B', $this->tokB))->assertSee('Token [redacted] is expired')->getContent();
        // A failed POST: the session holds no token (old input keeps only the label).
        $this->assertNoToken(serialize(session()->all()), 'the session');

        // The daily check with a refused token logs the reason, redacted.
        $this->bc[$this->tokA]['list'] = Http::response(['message' => 'Token '.$this->tokA.' revoked'], 401);
        Artisan::call('botcake:accounts-check');

        foreach ($bodies as $i => $b) {
            $this->assertNoToken((string) $b, 'response '.$i);
        }
        $this->assertNotEmpty($this->logs);
        $this->assertNoToken(implode("\n", $this->logs), 'the log');
        $this->assertStringContainsString('[redacted]', implode("\n", $this->logs));
        $this->assertSame('x [redacted] y', BotcakeAccounts::redact('x '.$this->tokB.' y'));
    }

    public function test_s2_the_access_token_field_is_never_flashed_on_an_exception(): void
    {
        $this->assertContains('access_token', (fn () => $this->dontFlash)->call(app(\Illuminate\Contracts\Debug\ExceptionHandler::class)));
    }

    // ---- S3, S4: account list and pages ----

    /** Likha pages: A matches, B differs, C has no token, D is in Likha with a page id outside the account. */
    private function likhaPages(): void
    {
        $s = app(PageSettings::class);
        $s->set('Page Alpha', 'botcake', 'page_id', '1001');
        $s->set('Page Alpha', 'botcake', 'token', 'pt-1001-botcake');
        $s->set('Page Bravo', 'botcake', 'page_id', '1002');
        $s->set('Page Bravo', 'botcake', 'token', 'pt-1002-old-likha');
        $s->set('Page Bravo', 'botcake', 'flow.shipping', '5501');
        $s->set('Page Charlie', 'botcake', 'page_id', '1003');
        $s->set('Page Delta', 'botcake', 'page_id', '9999');
        $s->set('Page Delta', 'botcake', 'token', 'pt-9999-likha');
    }

    public function test_s4_view_pages_lists_every_page_with_its_likha_status_sorted_and_counted(): void
    {
        $this->likhaPages();
        $this->actingAs($this->user('CEO'));
        $this->add('Marketing A', $this->tokA);
        $id = array_key_first($this->accounts());

        $html = $this->get(self::URL.'/'.$id.'/pages')->assertOk()->getContent();
        $this->assertStringContainsString('4 pages: 3 in Likha (1 match, 1 differs, 1 with no token in Likha), 1 not in Likha', $html);
        preg_match_all('/data-ba-page="(\d+)" data-ba-page-status="([a-z_]+)"/', $html, $m, PREG_SET_ORDER);
        $this->assertSame([['1002', 'differs'], ['1003', 'missing'], ['1001', 'match'], ['1004', 'none']], array_map(fn ($x) => [$x[1], $x[2]], $m));
        $this->assertStringContainsString('In Likha as <span class="font-medium">Page Bravo</span>', $html);
        $this->assertStringContainsString('token differs', $html);
        $this->assertStringContainsString('Likha has no token', $html);
        $this->assertStringContainsString('token matches', $html);
        $this->assertStringContainsString('Not in Likha', $html);
        $this->assertStringContainsString('Botcake Page 1004', $html);
        $this->assertStringContainsString('MANAGE', $html);
        // No page token leaves the server either.
        foreach (['pt-1001-botcake', 'pt-1002-botcake', 'pt-1002-old-likha', 'pt-9999-likha'] as $t) {
            $this->assertStringNotContainsString($t, $html);
        }
        $this->assertNoToken($html, 'the pages view');
    }

    public function test_s4_the_count_line_for_an_account_with_one_page_in_likha(): void
    {
        $s = app(PageSettings::class);
        $s->set('Page Alpha', 'botcake', 'page_id', '1001');
        $s->set('Page Alpha', 'botcake', 'token', 'pt-1001-botcake');
        $this->actingAs($this->user('CEO'));
        $this->add('Marketing A', $this->tokA);

        $this->get(self::URL.'/'.array_key_first($this->accounts()).'/pages')->assertSee('4 pages: 1 in Likha (1 match), 3 not in Likha');
    }

    public function test_s4_page_names_from_botcake_are_escaped(): void
    {
        $this->bc[$this->tokA]['pages'][0]['name'] = '<script>alert(1)</script>';
        $this->actingAs($this->user('CEO'));
        $this->add('Marketing A', $this->tokA);

        $html = $this->get(self::URL.'/'.array_key_first($this->accounts()).'/pages')->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    // ---- S5: update tokens in Likha ----

    public function test_s5_update_tokens_writes_only_differing_or_missing_pages_already_in_likha_and_nothing_else(): void
    {
        $this->likhaPages();
        $user = $this->user('CEO');
        $this->actingAs($user);
        $this->add('Marketing A', $this->tokA);
        $id = array_key_first($this->accounts());
        $before = PageSetting::where('page_name', '!=', BotcakeAccounts::PAGE)->orderBy('id')->get()->map(fn ($r) => $r->only(['id', 'page_name', 'provider', 'key', 'active']))->all();

        $this->followRedirects($this->post(self::URL.'/'.$id.'/update-tokens'))->assertOk()->assertSee('Marketing A: Updated 2 pages: Page Bravo, Page Charlie');

        $s = app(PageSettings::class);
        $this->assertSame('pt-1002-botcake', $s->get('Page Bravo', 'botcake', 'token'));
        $this->assertSame('pt-1003-botcake', $s->get('Page Charlie', 'botcake', 'token'));
        $this->assertSame('pt-1001-botcake', $s->get('Page Alpha', 'botcake', 'token'));
        $this->assertSame('pt-9999-likha', $s->get('Page Delta', 'botcake', 'token'));

        // Exactly two new rows (botcake.token of Bravo and Charlie), encrypted, by the user, with the account as the
        // source; Bravo's old token is history; no page was added and no other row changed.
        $after = PageSetting::where('page_name', '!=', BotcakeAccounts::PAGE)->orderBy('id')->get();
        $new = $after->whereNotIn('id', array_column($before, 'id'))->values();
        $this->assertSame([['Page Bravo', 'botcake', 'token'], ['Page Charlie', 'botcake', 'token']], $new->map(fn ($r) => [$r->page_name, $r->provider, $r->key])->all());
        foreach ($new as $r) {
            $this->assertTrue($r->is_secret);
            $this->assertSame($user->id, $r->created_by);
            $this->assertSame('botcake account: Marketing A', $r->source);
        }
        $changed = collect($before)->filter(fn ($b) => $after->firstWhere('id', $b['id'])->active !== $b['active'])->pluck('key', 'page_name')->all();
        $this->assertSame(['Page Bravo' => 'token'], $changed);
        $this->assertSame(['Page Alpha', 'Page Bravo', 'Page Charlie', 'Page Delta'], $after->pluck('page_name')->unique()->sort()->values()->all());

        // Again: everything matches.
        $this->followRedirects($this->post(self::URL.'/'.$id.'/update-tokens'))->assertSee('Marketing A: Nothing to update: every page in Likha matches.');
        $this->assertCount($after->count(), PageSetting::where('page_name', '!=', BotcakeAccounts::PAGE)->get());
    }

    public function test_s5_a_botcake_failure_writes_nothing_and_shows_the_reason(): void
    {
        $this->likhaPages();
        $this->actingAs($this->user('CEO'));
        $this->add('Marketing A', $this->tokA);
        $id = array_key_first($this->accounts());
        $count = PageSetting::where('page_name', '!=', BotcakeAccounts::PAGE)->count();

        $this->bc[$this->tokA]['list'] = Http::response(['success' => false, 'message' => 'Token expired'], 401);
        $html = $this->followRedirects($this->post(self::URL.'/'.$id.'/update-tokens'))->assertSee('Marketing A: nothing was updated. Botcake: Token expired')->getContent();
        $this->assertStringContainsString('data-ba-error', $html);
        $this->assertStringContainsString('data-ba-check="failed"', $html); // a refused token also shows red in the list

        $this->bc[$this->tokA]['list'] = fn () => Http::failedConnection();
        $this->followRedirects($this->post(self::URL.'/'.$id.'/update-tokens'))->assertSee('Could not reach Botcake');

        $this->assertSame($count, PageSetting::where('page_name', '!=', BotcakeAccounts::PAGE)->count());
        $this->assertSame('pt-1002-old-likha', app(PageSettings::class)->get('Page Bravo', 'botcake', 'token'));
    }

    // ---- S3: remove ----

    public function test_s3_remove_deletes_the_stored_token_and_closes_the_accounts_rows_keeping_history(): void
    {
        $this->actingAs($this->user('CEO'));
        $this->add('Marketing A', $this->tokA);
        $this->add('Marketing B', $this->tokB);
        [$a, $b] = array_keys($this->accounts());

        $this->followRedirects($this->post(self::URL.'/'.$a.'/remove'))->assertOk()->assertSee('Removed: Marketing A. Its token is deleted.');

        $this->assertSame([$b], array_keys($this->accounts()));
        $rows = $this->rows('a'.$a.'.%');
        $this->assertCount(3, $rows); // token, info, check: kept as history
        $this->assertTrue($rows->every(fn ($r) => ! $r->active && $r->replaced_at !== null));
        $this->assertNull($rows->firstWhere('key', 'a'.$a.'.token')->getRawOriginal('value'));
        $this->assertNotNull($this->rows('a'.$b.'.token')->sole()->getRawOriginal('value'));
        $html = $this->get(self::URL)->getContent();
        $this->assertStringNotContainsString('data-ba-account="'.$a.'"', $html);
        $this->assertStringContainsString('data-ba-account="'.$b.'"', $html);

        $this->post(self::URL.'/'.$a.'/remove')->assertNotFound();
        $this->post(self::URL.'/not-an-id/remove')->assertNotFound();
    }

    public function test_s3_the_generic_page_settings_deactivate_does_not_touch_account_rows(): void
    {
        $this->actingAs($this->user('CEO'));
        $this->add('Marketing A', $this->tokA);
        $row = $this->rows('%.token')->sole();

        $this->post('/chatblast/page-settings/'.$row->id.'/deactivate');
        $this->assertTrue($row->fresh()->active);
    }

    // ---- S6: access by nav settings ----

    public function test_s6_the_migration_adds_the_link_next_to_the_chatbot_link_visible_to_the_ceo_only(): void
    {
        $link = DB::table('nav_links')->where('key', 'botcake_accounts')->first();
        $this->assertNotNull($link);
        $this->assertSame(['Botcake Accounts', '/chatbot/botcake-accounts', 'chatbot/botcake-accounts*'], [$link->label, $link->route_url, $link->active_pattern]);
        $this->assertTrue((bool) $link->is_active);
        $this->assertSame((int) DB::table('nav_links')->where('key', 'chatbot')->value('sort_order'), (int) $link->sort_order);
        $this->assertSame(['CEO' => true], DB::table('nav_link_role_visibility')->where('nav_link_id', $link->id)->pluck('is_visible', 'role')->map(fn ($v) => (bool) $v)->all());

        $migration = require base_path(self::LINK_MIGRATION);
        $migration->up();
        $this->assertSame(1, DB::table('nav_links')->where('key', 'botcake_accounts')->count());
        $migration->down();
        $this->assertNull(DB::table('nav_links')->where('key', 'botcake_accounts')->first());
        $this->assertSame(0, DB::table('nav_link_role_visibility')->where('nav_link_id', $link->id)->count());
        $migration->up();
        $this->assertNotNull(DB::table('nav_links')->where('key', 'botcake_accounts')->first());
    }

    /** @return array<string, array{0: string, 1: array}> */
    public static function actions(): array
    {
        return [
            'page' => ['GET', []],
            'add' => ['POST', ['label' => 'x', 'access_token' => 'not-a-token-but-long-enough']],
            'view pages' => ['GET', ['id']],
            'update tokens' => ['POST', ['id', 'update-tokens']],
            'remove' => ['POST', ['id', 'remove']],
        ];
    }

    private function hit(string $method, array $what, string $id)
    {
        $url = self::URL.(isset($what[0]) && $what[0] === 'id' ? '/'.$id.(isset($what[1]) ? '/'.$what[1] : '/pages') : '');

        return $method === 'GET' ? $this->get($url) : $this->post($url, isset($what['label']) ? $what : []);
    }

    #[DataProvider('actions')]
    public function test_s6_a_role_without_the_link_checked_gets_403_on_the_page_and_every_action(string $method, array $what): void
    {
        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        $this->add('Marketing A', $this->tokA);
        $id = array_key_first($this->accounts());
        $sent = count(Http::recorded());

        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        $this->hit($method, $what, $id)->assertForbidden();
        $this->link('Marketing', false); // a row that is unchecked is still closed
        $this->hit($method, $what, $id)->assertForbidden();

        $this->assertCount($sent, Http::recorded()); // nothing reached Botcake
        $this->assertSame([$id], array_keys($this->accounts()));
    }

    public function test_s6_a_role_with_the_link_checked_opens_the_page_and_the_ceo_always_does(): void
    {
        $mkt = $this->user('Marketing', 'mkt@example.test');
        $this->link('Marketing');
        $this->actingAs($mkt)->get(self::URL)->assertOk()->assertSee('Botcake Accounts')->assertSee('How to get your Access Token');
        $this->followRedirects($this->add('Marketing A', $this->tokA))->assertSee('Saved: Marketing A');
        $id = array_key_first($this->accounts());
        $this->get(self::URL.'/'.$id.'/pages')->assertOk();
        $this->post(self::URL.'/'.$id.'/update-tokens')->assertRedirect(self::URL);

        // The CEO opens it even with no row for the role.
        DB::table('nav_link_role_visibility')->delete();
        $this->actingAs($this->user('CEO', 'ceo2@example.test'))->get(self::URL)->assertOk();
        $this->actingAs($mkt)->get(self::URL)->assertForbidden();
    }

    public function test_s6_guests_are_sent_to_login(): void
    {
        $this->get(self::URL)->assertRedirect();
        $this->post(self::URL, ['label' => 'x', 'access_token' => $this->tokA])->assertRedirect();
        Http::assertNothingSent();
    }

    // ---- S7: the daily check ----

    public function test_s7_the_daily_command_updates_each_accounts_check_and_writes_no_page_token(): void
    {
        $this->likhaPages();
        $this->actingAs($this->user('CEO'));
        $this->add('Marketing A', $this->tokA);
        $this->add('Marketing B', $this->tokB);
        [$a, $b] = array_keys($this->accounts());
        $tokens = PageSetting::where('provider', 'botcake')->where('key', 'token')->count();

        $this->travel(1)->days();
        $this->bc[$this->tokA] = $this->account(['1001' => 'pt-1001-botcake', '1002' => 'pt-1002-botcake', '1003' => 'pt-1003-botcake', '1004' => 'x', '1005' => 'y']);
        $this->bc[$this->tokB]['list'] = Http::response(['success' => false, 'message' => 'Token was reset'], 401);

        $this->assertSame(0, Artisan::call('botcake:accounts-check'));
        $this->assertStringContainsString('1 OK, 1 failed', Artisan::output());

        $accounts = $this->accounts();
        $this->assertSame([true, 5, null], [$accounts[$a]['check']['ok'], $accounts[$a]['check']['pages'], $accounts[$a]['check']['reason']]);
        $this->assertSame([false, 1, 'Botcake: Token was reset'], [$accounts[$b]['check']['ok'], $accounts[$b]['check']['pages'], $accounts[$b]['check']['reason']]);
        $this->assertStringStartsWith('2026-11-14', $accounts[$a]['check']['at']);
        $this->assertSame($tokens, PageSetting::where('provider', 'botcake')->where('key', 'token')->count());
        $this->assertSame('pt-1002-old-likha', app(PageSettings::class)->get('Page Bravo', 'botcake', 'token'));
        // Only list_access_token was called by the command.
        $this->assertTrue(Http::recorded()->slice(-2)->every(fn ($p) => str_ends_with($p[0]->url(), '/integration_page/list_access_token')));

        $html = $this->get(self::URL)->getContent();
        $this->assertMatchesRegularExpression('/data-ba-account="'.$b.'" class="bg-red-50"/', $html);
        $this->assertStringContainsString('Botcake: Token was reset', $html);
    }

    public function test_s7_the_check_is_scheduled_once_a_day_early_morning_manila(): void
    {
        Artisan::call('schedule:list'); // loads routes/console.php
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'botcake:accounts-check'));
        $this->assertNotNull($event);
        $this->assertSame('10 5 * * *', $event->expression);
        $this->assertSame('Asia/Manila', $event->timezone);
    }

    // ---- S8: how to get the token ----

    public function test_s8_the_folded_help_has_the_four_steps(): void
    {
        $html = $this->actingAs($this->user('CEO'))->get(self::URL)->assertOk()->getContent();
        $this->assertStringContainsString('<details class="mt-3 text-sm text-slate-600" data-ba-help>', $html);
        $this->assertStringContainsString('click the copy icon only', $html);
        $this->assertStringContainsString('type="password" name="access_token"', $html);
    }
}
