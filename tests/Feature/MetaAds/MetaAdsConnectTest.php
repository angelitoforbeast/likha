<?php

namespace Tests\Feature\MetaAds;

use App\Services\MetaAds\Connections;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;

/** Connect / callback / disconnect ng Facebook (Meta Ads) sa Likha user, at kung sino ang puwede. */
class MetaAdsConnectTest extends MetaAdsTestCase
{
    public function test_S_154_1_connect_redirects_to_the_oauth_dialog_with_ads_read_and_a_state(): void
    {
        $this->actingAs($this->user('Marketing', 'm@example.test'));

        $res = $this->get('/ads_manager/meta/connect');

        $res->assertRedirect();
        $url = $res->headers->get('Location');
        $this->assertStringStartsWith('https://www.facebook.com/v25.0/dialog/oauth?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('ads_read', $q['scope']);
        $this->assertSame('999000', $q['client_id']);
        $this->assertSame('https://likha.test/ads_manager/meta/callback', $q['redirect_uri']);
        $this->assertGreaterThanOrEqual(32, strlen($q['state']));
        $this->assertSame($q['state'], session('meta_ads_state'));
    }

    public function test_S_154_1_callback_exchanges_for_a_long_lived_token_and_stores_it_encrypted_with_expiry_and_accounts(): void
    {
        $user = $this->user('Marketing', 'm@example.test');
        $this->actingAs($user);
        $this->fakeGraph();

        $res = $this->withSession(['meta_ads_state' => str_repeat('s', 40)])
            ->get('/ads_manager/meta/callback?code=abc&state=' . str_repeat('s', 40));

        $res->assertRedirect('/ads_manager/report');
        $c = app(Connections::class)->get($user->id);
        $this->assertSame('Ana Facebook', $c['fb_user_name']);
        $this->assertSame('fb-ana', $c['fb_user_id']);
        $this->assertSame(['111', '222'], array_column($c['ad_accounts'], 'id'));
        $this->assertEqualsWithDelta(now()->addDays(60)->timestamp, $c['expires_at'], 5);
        $this->assertSame(self::TOKEN_ANA, app(Connections::class)->token($c));

        $raw = (string) DB::table('app_settings')->where('key', 'meta_ads.connection.' . $user->id)->value('value');
        $this->assertStringNotContainsString(self::TOKEN_ANA, $raw);
        $this->assertSame(self::TOKEN_ANA, Crypt::decryptString(json_decode($raw, true)['token']));
        $this->assertSame('connect', DB::table('upload_logs')->where('type', 'ads_manager_api_event')->value('original_name'));
    }

    public static function badStates(): array
    {
        return ['missing state' => [null], 'wrong state' => ['not-the-state']];
    }

    #[DataProvider('badStates')]
    public function test_S_154_1_callback_with_a_bad_or_missing_state_is_refused_and_stores_nothing(?string $state): void
    {
        $this->actingAs($this->user('Marketing', 'm@example.test'));
        $this->fakeGraph();

        $res = $this->withSession(['meta_ads_state' => str_repeat('s', 40)])
            ->get('/ads_manager/meta/callback?code=abc' . ($state === null ? '' : '&state=' . $state));

        $res->assertRedirect('/ads_manager/report');
        $res->assertSessionHasErrors('meta');
        $this->assertSame([], $this->graphCalls);
        $this->assertFalse(DB::table('app_settings')->where('key', 'like', 'meta_ads.connection.%')->exists());
    }

    public function test_S_154_2_without_an_app_id_the_card_and_connect_say_meta_app_not_set_up_yet(): void
    {
        config(['services.meta_ads.app_id' => null]);
        $this->actingAs($this->user('Marketing', 'm@example.test'));

        $this->get('/ads_manager/report')->assertOk()->assertSee('Meta app not set up yet');
        $this->get('/ads_manager/meta/connect')->assertRedirect('/ads_manager/report')
            ->assertSessionHasErrors(['meta' => 'Meta app not set up yet']);
    }

    public function test_S_154_8_the_token_never_appears_in_logs_responses_or_the_page(): void
    {
        $user = $this->user('Marketing', 'm@example.test');
        $this->actingAs($user);
        $this->fakeGraph();
        $logged = [];
        Log::listen(function ($e) use (&$logged) { $logged[] = $e->message . json_encode($e->context); });

        $callback = $this->withSession(['meta_ads_state' => str_repeat('s', 40)])
            ->get('/ads_manager/meta/callback?code=abc&state=' . str_repeat('s', 40));
        $page = $this->get('/ads_manager/report');

        $page->assertOk()->assertSee('Ana Facebook');
        foreach ([$callback->getContent(), $page->getContent(), implode("\n", $logged), json_encode(DB::table('upload_logs')->get())] as $out) {
            $this->assertStringNotContainsString(self::TOKEN_ANA, $out);
            $this->assertStringNotContainsString(substr(self::TOKEN_ANA, -4), $out);
        }
    }

    public function test_S_154_8_a_paging_next_link_to_another_host_is_not_followed_with_the_token(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['data' => [['id' => 'act_1']], 'paging' => ['next' => 'https://attacker.example/steal?after=p2']]),
        ]);

        $rows = app(\App\Services\MetaAds\Graph::class)->all('me/adaccounts', ['fields' => 'id'], self::TOKEN_ANA);

        $this->assertSame([['id' => 'act_1']], $rows);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'attacker.example'));
    }

    public function test_S_154_8_a_failed_exchange_shows_and_logs_no_secret_or_code(): void
    {
        $this->actingAs($this->user('Marketing', 'm@example.test'));
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException(
            'cURL error 28 for https://graph.facebook.com/v25.0/oauth/access_token?client_id=999000&client_secret=app-secret-xyz&code=secret-code-123'));
        $logged = [];
        Log::listen(function ($e) use (&$logged) { $logged[] = $e->message . json_encode($e->context); });

        $res = $this->withSession(['meta_ads_state' => str_repeat('s', 40)])
            ->get('/ads_manager/meta/callback?code=secret-code-123&state=' . str_repeat('s', 40));

        $shown = implode(' ', session('errors')->get('meta')) . implode("\n", $logged) . $res->getContent();
        $this->assertStringContainsString('Facebook connect failed', $shown);
        foreach (['app-secret-xyz', 'secret-code-123'] as $secret) $this->assertStringNotContainsString($secret, $shown);
    }

    public function test_S_154_10_disconnect_removes_the_token_row_and_logs_it(): void
    {
        $user = $this->user('Marketing', 'm@example.test');
        $other = $this->user('Marketing - OIC', 'o@example.test');
        $this->connect($user, self::TOKEN_ANA, ['111']);
        $this->connect($other, self::TOKEN_BEN, ['222']);
        $this->actingAs($user);

        $this->post('/ads_manager/meta/disconnect')->assertRedirect('/ads_manager/report');

        $this->assertFalse(DB::table('app_settings')->where('key', 'meta_ads.connection.' . $user->id)->exists());
        $this->assertNotNull(app(Connections::class)->get($other->id));
        $this->assertSame(['disconnect', $user->id], [
            DB::table('upload_logs')->where('type', 'ads_manager_api_event')->value('original_name'),
            (int) DB::table('upload_logs')->where('type', 'ads_manager_api_event')->value('user_id'),
        ]);
    }

    public function test_S_154_9_a_role_without_the_ads_link_gets_404_on_connect_disconnect_compare_and_sync(): void
    {
        $this->actingAs($this->user('Data Encoder', 'de@example.test'));

        $this->assertSame([404, 404, 404, 404, 404], [
            $this->get('/ads_manager/meta/connect')->status(),
            $this->get('/ads_manager/meta/callback?code=a&state=b')->status(),
            $this->post('/ads_manager/meta/disconnect')->status(),
            $this->post('/ads_manager/meta/compare', ['from' => '2026-10-20', 'to' => '2026-10-21'])->status(),
            $this->post('/ads_manager/meta/sync')->status(),
        ]);
        $this->get('/ads_manager/report')->assertOk()->assertDontSee('Connect Facebook');
    }
}
