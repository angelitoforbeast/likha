<?php

namespace Tests\Feature\MetaAds;

use App\Models\User;
use App\Services\MetaAds\Connections;
use App\Services\MetaAds\Sync;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Spec 287: one Likha login keeps several Facebook connections (one per Facebook account, each from its own browser
 * profile). The Graph is faked; no call leaves the test.
 */
class MetaAdsManyConnectionsTest extends MetaAdsTestCase
{
    private const DAY = '2026-10-20';
    private const STATE = 'ssssssssssssssssssssssssssssssssssssssss';

    /** @var array{id: string, name: string, token: string, accounts: list<string>} the Facebook account the fake signs in as */
    private array $as = ['id' => '', 'name' => '', 'token' => '', 'accounts' => []];
    private bool $faked = false;

    /** Runs the connect callback as if the browser were signed in to Facebook as $fbId. */
    private function connectAs(string $fbId, string $name, string $token, array $accounts)
    {
        $this->as = ['id' => $fbId, 'name' => $name, 'token' => $token, 'accounts' => $accounts];
        if (!$this->faked) {
            $this->faked = true; // Http::fake callbacks stack and the first one answers, so it reads $this->as each time
            Http::fake(function (Request $req) {
                $path = trim(preg_replace('#^/v25\.0#', '', (string) parse_url($req->url(), PHP_URL_PATH)), '/');
                parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
                $this->graphCalls[] = $path;

                return match ($path) {
                    'oauth/access_token' => Http::response(['access_token' => ($q['grant_type'] ?? '') === 'fb_exchange_token' ? $this->as['token'] : 'EAAB-short', 'expires_in' => 5184000]),
                    'debug_token'        => Http::response(['data' => ['user_id' => $this->as['id'], 'expires_at' => now()->addDays(60)->timestamp]]),
                    'me'                 => Http::response(['id' => $this->as['id'], 'name' => $this->as['name']]),
                    'me/adaccounts'      => Http::response(['data' => array_map(fn ($id) => ['id' => 'act_' . $id, 'account_id' => $id, 'name' => 'Likha ' . $id, 'account_status' => 1], $this->as['accounts'])]),
                    default              => Http::response(['error' => ['message' => 'unexpected ' . $path, 'code' => 100]], 400),
                };
            });
        }

        return $this->withSession(['meta_ads_state' => self::STATE])->get('/ads_manager/meta/callback?code=abc&state=' . self::STATE);
    }

    private function keys(): array
    {
        return DB::table('app_settings')->where('key', 'like', 'meta_ads.connection.%')->orderBy('key')->pluck('key')->all();
    }

    /** One row of the connections table, up to its </tr>. */
    private static function row(string $html, string $ref): string
    {
        $start = strpos($html, 'data-connection="' . $ref . '"');

        return $start === false ? '' : substr($html, $start, strpos($html, '</tr>', $start) - $start);
    }

    /** A connection saved the way it was before 287: one row per Likha user, key meta_ads.connection.<user id>. */
    private function legacy(User $user, string $token, string $fbId, array $accounts): void
    {
        DB::table('app_settings')->insert(['key' => 'meta_ads.connection.' . $user->id, 'value' => json_encode([
            'status' => 'ok', 'connected_at' => now()->subDays(10)->toIso8601String(), 'last_sync_at' => null, 'last_error' => null,
            'fb_user_id' => $fbId, 'fb_user_name' => 'Old FB', 'expires_at' => now()->addDays(40)->timestamp,
            'ad_accounts' => array_map(fn ($id) => ['id' => $id, 'name' => 'Account ' . $id, 'status' => 1], $accounts),
            'token' => Crypt::encryptString($token),
        ]), 'created_at' => now(), 'updated_at' => now()]);
    }

    // ---- FB-1 ----

    public function test_FB_1_two_facebook_accounts_on_one_login_both_stay_connected_and_both_show(): void
    {
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->actingAs($ana);

        $this->connectAs('fb-a', 'Ana A', self::TOKEN_ANA, ['111'])->assertRedirect('/ads_manager/report')->assertSessionHasNoErrors();
        $this->connectAs('fb-b', 'Ana B', self::TOKEN_BEN, ['222'])->assertRedirect('/ads_manager/report')->assertSessionHasNoErrors();

        $this->assertSame(["meta_ads.connection.{$ana->id}.fb-a", "meta_ads.connection.{$ana->id}.fb-b"], $this->keys());
        $mine = app(Connections::class)->of($ana->id);
        $this->assertSame(['Ana A', 'Ana B'], array_values(array_column($mine, 'fb_user_name')));
        $this->assertSame([self::TOKEN_ANA, self::TOKEN_BEN], array_values(array_map(fn ($c) => app(Connections::class)->token($c), $mine)));

        $html = $this->get('/ads_manager/report')->assertOk()->getContent();
        foreach (["{$ana->id}.fb-a" => 'Ana A', "{$ana->id}.fb-b" => 'Ana B'] as $ref => $name) {
            $row = self::row($html, $ref);
            $this->assertStringContainsString('Facebook: ' . $name, $row);
            $this->assertStringContainsString('OK', $row);
            $this->assertStringContainsString(now()->addDays(60)->timezone('Asia/Manila')->format('M j, Y'), $row);
            $this->assertStringContainsString('name="ref" value="' . $ref . '"', $row);
            $this->assertStringContainsString('>Disconnect</button>', $row);
        }
        $this->assertStringContainsString('Connect another Facebook', $html);
        foreach ([self::TOKEN_ANA, self::TOKEN_BEN] as $t) $this->assertStringNotContainsString($t, $html);
    }

    public function test_FB_1_connecting_the_same_facebook_account_again_refreshes_its_one_row(): void
    {
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->actingAs($ana);
        $this->connectAs('fb-a', 'Ana A', self::TOKEN_ANA, ['111']);
        $this->connectAs('fb-b', 'Ana B', self::TOKEN_BEN, ['222']);
        app(Connections::class)->update("{$ana->id}.fb-a", ['status' => 'expired']);

        $this->connectAs('fb-a', 'Ana A renamed', 'EAAB-ana-new-token-9999', ['111', '333']);

        $this->assertSame(["meta_ads.connection.{$ana->id}.fb-a", "meta_ads.connection.{$ana->id}.fb-b"], $this->keys());
        $a = app(Connections::class)->find("{$ana->id}.fb-a");
        $this->assertSame(['ok', 'Ana A renamed', ['111', '333']], [$a['status'], $a['fb_user_name'], array_column($a['ad_accounts'], 'id')]);
        $this->assertSame('EAAB-ana-new-token-9999', app(Connections::class)->token($a));
        $this->assertSame(self::TOKEN_BEN, app(Connections::class)->token(app(Connections::class)->find("{$ana->id}.fb-b")));
    }

    public function test_FB_1_disconnect_removes_only_that_facebook_connection_and_logs_which(): void
    {
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111'], ['fb_user_id' => 'fb-a', 'fb_user_name' => 'Ana A']);
        $this->connect($ana, self::TOKEN_BEN, ['222'], ['fb_user_id' => 'fb-b', 'fb_user_name' => 'Ana B']);
        $this->actingAs($ana);

        $this->post('/ads_manager/meta/disconnect', ['ref' => "{$ana->id}.fb-a"])->assertRedirect('/ads_manager/report')->assertSessionHasNoErrors();

        $this->assertSame(["meta_ads.connection.{$ana->id}.fb-b"], $this->keys());
        $log = DB::table('upload_logs')->where('type', 'ads_manager_api_event')->first();
        $this->assertSame(['disconnect', $ana->id], [$log->original_name, (int) $log->user_id]);
        $this->assertSame(['fb_user_id' => 'fb-a', 'fb_user_name' => 'Ana A'], json_decode($log->protected_details, true));
    }

    public function test_FB_1_another_users_connection_cannot_be_disconnected_and_a_bare_disconnect_with_several_is_refused(): void
    {
        $ana = $this->user('Marketing', 'ana@example.test');
        $ben = $this->user('CEO', 'ben@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111'], ['fb_user_id' => 'fb-a']);
        $this->connect($ana, self::TOKEN_BEN, ['222'], ['fb_user_id' => 'fb-b']);
        $before = $this->keys();

        // Even the CEO removes only his own connections.
        $this->actingAs($ben)->post('/ads_manager/meta/disconnect', ['ref' => "{$ana->id}.fb-a"])->assertNotFound();
        $this->actingAs($ben)->post('/ads_manager/meta/disconnect', ['ref' => 'not-a-ref'])->assertNotFound();
        $html = $this->actingAs($ben)->get('/ads_manager/report')->assertOk()->getContent();
        $this->assertStringNotContainsString('name="ref"', $html);

        $this->actingAs($ana)->post('/ads_manager/meta/disconnect')->assertRedirect('/ads_manager/report')->assertSessionHasErrors('meta');
        $this->assertSame($before, $this->keys());
    }

    // ---- FB-2 ----

    public function test_FB_2_sync_reads_through_every_connection_and_an_account_shared_by_two_is_synced_once(): void
    {
        Sync::setWriteMode(true);
        Sync::setAccounts(['111', '222', '333']);
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111', '333'], ['fb_user_id' => 'fb-a', 'fb_user_name' => 'Ana A']);
        $this->connect($ana, self::TOKEN_BEN, ['222', '333'], ['fb_user_id' => 'fb-b', 'fb_user_name' => 'Ana B']);
        $this->fakeGraph(
            insights: [
                '111' => [self::insight('111', 'a1', self::DAY, 10)],
                '222' => [self::insight('222', 'a2', self::DAY, 20)],
                '333' => [self::insight('333', 'a3', self::DAY, 30)],
            ],
            ads: ['111' => [self::adDetail('a1')], '222' => [self::adDetail('a2')], '333' => [self::adDetail('a3')]],
        );
        $this->actingAs($ana);

        $this->post('/ads_manager/meta/sync', ['from' => self::DAY, 'to' => self::DAY])->assertRedirect('/ads_manager/report');

        $this->assertSame(['a1', 'a2', 'a3'], array_column($this->reportRows(), 'ad_id'));
        $this->assertSame(1, count(array_filter($this->graphCalls, fn ($p) => $p === 'act_333/insights')));
        $byPath = collect($this->graphRequests)->where('path', 'act_222/insights')->first();
        $this->assertSame(self::TOKEN_BEN, $byPath['token']);
        foreach (app(Connections::class)->of($ana->id) as $c) $this->assertNotNull($c['last_sync_at'], $c['ref']);
        $this->assertSame('done', DB::table('upload_logs')->where('type', 'ads_manager_api')->value('status'));
    }

    public function test_FB_2_the_account_list_and_ad_accounts_health_use_every_connection_once_per_account(): void
    {
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111', '333'], ['fb_user_id' => 'fb-a', 'fb_user_name' => 'Ana A']);
        $this->connect($ana, self::TOKEN_BEN, ['222', '333'], ['fb_user_id' => 'fb-b', 'fb_user_name' => 'Ana B']);

        $accounts = app(Connections::class)->accounts();
        $ids = array_map('strval', array_keys($accounts));
        sort($ids);
        $this->assertSame(['111', '222', '333'], $ids);
        $this->assertSame(['Ana A', 'Ana B'], $accounts['333']['who']);
        $this->assertSame(['111', '222', '333'], Sync::current());

        $acc = fn ($id) => ['id' => 'act_' . $id, 'account_id' => $id, 'name' => 'Likha ' . $id, 'account_status' => 1, 'disable_reason' => 0,
            'currency' => 'PHP', 'balance' => '0', 'amount_spent' => '0', 'spend_cap' => '0'];
        Http::fake(function (Request $req) use ($acc) {
            $token = trim(str_replace('Bearer', '', $req->header('Authorization')[0] ?? ''));
            $this->graphCalls[] = 'me/adaccounts:' . $token;

            return Http::response(['data' => $token === self::TOKEN_ANA ? [$acc('111'), $acc('333')] : [$acc('222'), $acc('333')]]);
        });
        $html = $this->actingAs($this->user('CEO', 'ceo@example.test'))->get('/ads_manager/meta/ad-accounts')->assertOk()->getContent();

        foreach (['111', '222'] as $id) $this->assertSame(1, substr_count($html, '<tr data-account="' . $id . '"'));
        $this->assertSame(1, substr_count($html, '<tr data-account="333"'));
        $start = strpos($html, '<tr data-account="333"');
        $this->assertStringContainsString('Ana A, Ana B', substr($html, $start, strpos($html, '</tr>', $start) - $start));
        $this->assertContains('me/adaccounts:' . self::TOKEN_ANA, $this->graphCalls);
        $this->assertContains('me/adaccounts:' . self::TOKEN_BEN, $this->graphCalls);
    }

    // ---- FB-3 ----

    public function test_FB_3_a_connection_saved_before_287_keeps_working_without_a_reconnect(): void
    {
        Sync::setWriteMode(true);
        Sync::setAccounts(['111']);
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->legacy($ana, self::TOKEN_ANA, 'fb-old', ['111']);
        $this->fakeGraph(insights: ['111' => [self::insight('111', 'a1', self::DAY, 10)]], ads: ['111' => [self::adDetail('a1')]]);
        $this->actingAs($ana);

        $c = app(Connections::class)->get($ana->id);
        $this->assertSame([(string) $ana->id, $ana->id, 'Old FB'], [$c['ref'], $c['user_id'], $c['fb_user_name']]);
        $row = self::row($this->get('/ads_manager/report')->assertOk()->getContent(), (string) $ana->id);
        $this->assertStringContainsString('Facebook: Old FB', $row);
        $this->assertStringContainsString('name="ref" value="' . $ana->id . '"', $row);

        $this->post('/ads_manager/meta/sync', ['from' => self::DAY, 'to' => self::DAY])->assertRedirect('/ads_manager/report');
        $this->assertSame(['a1'], array_column($this->reportRows(), 'ad_id'));
        $this->assertSame(self::TOKEN_ANA, collect($this->graphRequests)->firstWhere('path', 'act_111/insights')['token']);
        $this->assertNotNull(app(Connections::class)->find((string) $ana->id)['last_sync_at']);

        $this->post('/ads_manager/meta/disconnect', ['ref' => (string) $ana->id])->assertSessionHasNoErrors();
        $this->assertSame([], $this->keys());
    }

    public function test_FB_3_reconnecting_the_same_account_turns_the_old_row_into_its_new_row_and_another_account_keeps_it(): void
    {
        $ana = $this->user('Marketing', 'ana@example.test');
        $ben = $this->user('Marketing - OIC', 'ben@example.test');
        $this->legacy($ana, self::TOKEN_ANA, 'fb-old', ['111']);
        $this->legacy($ben, self::TOKEN_BEN, 'fb-ben', ['222']);

        $this->actingAs($ben)->connectAs('fb-ben2', 'Ben second', 'EAAB-ben-second-0000', ['444']);
        $this->assertSame(["meta_ads.connection.{$ben->id}", "meta_ads.connection.{$ben->id}.fb-ben2"],
            array_values(array_filter($this->keys(), fn ($k) => str_starts_with($k, "meta_ads.connection.{$ben->id}"))));

        $this->actingAs($ana)->connectAs('fb-old', 'Old FB', 'EAAB-ana-fresh-1111', ['111']);
        $this->assertSame(["meta_ads.connection.{$ana->id}.fb-old"],
            array_values(array_filter($this->keys(), fn ($k) => str_starts_with($k, "meta_ads.connection.{$ana->id}"))));
        $this->assertSame('EAAB-ana-fresh-1111', app(Connections::class)->token(app(Connections::class)->get($ana->id)));
        $this->assertCount(3, app(Connections::class)->all());
    }

    // ---- FB-4 ----

    public function test_FB_4_an_expired_token_marks_only_its_own_row(): void
    {
        Sync::setWriteMode(true);
        Sync::setAccounts(['111', '222']);
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111'], ['fb_user_id' => 'fb-a', 'fb_user_name' => 'Ana A']);
        $this->connect($ana, self::TOKEN_BEN, ['222'], ['fb_user_id' => 'fb-b', 'fb_user_name' => 'Ana B']);
        $this->fakeGraph(
            insights: ['111' => [self::insight('111', 'a1', self::DAY, 10)]],
            ads: ['111' => [self::adDetail('a1')]],
            errors: [self::TOKEN_BEN => 190],
        );
        $this->actingAs($ana);

        $this->post('/ads_manager/meta/sync', ['from' => self::DAY, 'to' => self::DAY])->assertRedirect('/ads_manager/report');

        $c = app(Connections::class);
        $this->assertSame(['ok', 'expired'], [$c->find("{$ana->id}.fb-a")['status'], $c->find("{$ana->id}.fb-b")['status']]);
        $this->assertSame(['a1'], array_column($this->reportRows(), 'ad_id'));

        $html = $this->get('/ads_manager/report')->assertOk()->getContent();
        $a = self::row($html, "{$ana->id}.fb-a");
        $b = self::row($html, "{$ana->id}.fb-b");
        $this->assertStringContainsString('OK', $a);
        $this->assertStringNotContainsString('Reconnect', $a);
        $this->assertStringContainsString('Token expired', $b);
        $this->assertStringContainsString('Reconnect from the browser signed in as Ana B', $b);
        $this->assertStringContainsString('>Disconnect</button>', $b);
    }
}
