<?php

namespace Tests\Feature\MetaAds;

use App\Services\MetaAds\Connections;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;

/** Facebook Login for Business: ang Connect ay may config_id (META_ADS_CONFIG_ID) sa halip na scope. */
class MetaAdsLoginConfigTest extends MetaAdsTestCase
{
    private const CODE_TOKEN = 'EAAB-lfb-code-token-SECRET-9012';

    private function dialogQuery(): array
    {
        $this->actingAs($this->user('Marketing', 'm@example.test'));
        $res = $this->get('/ads_manager/meta/connect');
        $res->assertRedirect();
        $url = (string) $res->headers->get('Location');
        $this->assertStringStartsWith('https://www.facebook.com/v25.0/dialog/oauth?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        return $q;
    }

    public function test_S_159_1_with_a_config_id_the_dialog_sends_config_id_and_no_scope(): void
    {
        config(['services.meta_ads.config_id' => '995680132834288']);

        $q = $this->dialogQuery();

        $this->assertSame(['client_id', 'config_id', 'redirect_uri', 'response_type', 'state'], collect($q)->keys()->sort()->values()->all());
        $this->assertSame('995680132834288', $q['config_id']);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame('999000', $q['client_id']);
        $this->assertSame('https://likha.test/ads_manager/meta/callback', $q['redirect_uri']);
        $this->assertSame(session('meta_ads_state'), $q['state']);
    }

    public static function emptyConfigIds(): array
    {
        return ['unset' => [null], 'blank' => ['  ']];
    }

    #[DataProvider('emptyConfigIds')]
    public function test_S_159_2_without_a_config_id_the_dialog_is_as_before(?string $configId): void
    {
        config(['services.meta_ads.config_id' => $configId]);

        $q = $this->dialogQuery();

        $this->assertSame(['client_id', 'redirect_uri', 'response_type', 'scope', 'state'], collect($q)->keys()->sort()->values()->all());
        $this->assertSame('ads_read', $q['scope']);
        $this->assertSame('code', $q['response_type']);
    }

    public function test_S_159_3_with_a_config_id_and_no_app_secret_the_card_still_offers_connect(): void
    {
        config(['services.meta_ads.config_id' => '995680132834288']);
        $this->actingAs($this->user('Marketing', 'm@example.test'));

        $this->get('/ads_manager/report')->assertOk()->assertSee('Connect Facebook')->assertDontSee('Meta app not set up yet');
    }

    /** Ang fb_exchange_token ay sinasagot ng error (gaya ng Login for Business token); ang debug_token ay may sariling expiry. */
    private function failLongLivedExchange(int $expiresAt): void
    {
        Http::fake(function (Request $req) use ($expiresAt) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
            $path = (string) parse_url($req->url(), PHP_URL_PATH);
            if (str_ends_with($path, 'oauth/access_token')) {
                return ($q['grant_type'] ?? '') === 'fb_exchange_token'
                    ? Http::response(['error' => ['message' => 'Invalid OAuth access token', 'type' => 'OAuthException', 'code' => 190]], 400)
                    : Http::response(['access_token' => self::CODE_TOKEN, 'token_type' => 'bearer']);
            }
            if (str_ends_with($path, 'debug_token')) {
                return Http::response(['data' => ['user_id' => 'fb-ana', 'is_valid' => true, 'expires_at' => $expiresAt]]);
            }

            return null;
        });
    }

    public static function exchangeOutcomes(): array
    {
        return [
            'long-lived exchange works' => [false, MetaAdsTestCase::TOKEN_ANA, 'fb_exchange_token', 60],
            'long-lived exchange fails' => [true, self::CODE_TOKEN, 'code', 90],
        ];
    }

    #[DataProvider('exchangeOutcomes')]
    public function test_S_159_3_callback_keeps_a_token_whether_the_long_lived_exchange_works_or_fails(bool $fail, string $token, string $from, int $days): void
    {
        config(['services.meta_ads.config_id' => '995680132834288']);
        $user = $this->user('Marketing', 'm@example.test');
        $this->actingAs($user);
        if ($fail) $this->failLongLivedExchange(now()->addDays(90)->timestamp);
        $this->fakeGraph();
        $logged = [];
        Log::listen(function ($e) use (&$logged) { $logged[] = $e->message . json_encode($e->context); });

        $res = $this->withSession(['meta_ads_state' => str_repeat('s', 40)])
            ->get('/ads_manager/meta/callback?code=abc&state=' . str_repeat('s', 40));
        $res->assertRedirect('/ads_manager/report')->assertSessionHasNoErrors();
        $page = $this->get('/ads_manager/report');

        $c = app(Connections::class)->get($user->id);
        $this->assertSame($token, app(Connections::class)->token($c));
        $this->assertSame($from, $c['token_from']);
        $this->assertEqualsWithDelta(now()->addDays($days)->timestamp, $c['expires_at'], 5);
        $this->assertSame(['111', '222'], array_column($c['ad_accounts'], 'id'));
        $this->assertSame($token, collect($this->graphRequests)->firstWhere('path', 'debug_token')['query']['input_token']);

        // S-159.4: ang token ay wala sa log, sa sagot, sa page, sa upload_logs at sa hindi naka-encrypt na bahagi ng row.
        $raw = json_decode((string) DB::table('app_settings')->where('key', 'meta_ads.connection.' . $user->id)->value('value'), true);
        unset($raw['token']);
        foreach ([$res->getContent(), $page->getContent(), implode("\n", $logged), json_encode(DB::table('upload_logs')->get()), json_encode($raw)] as $out) {
            $this->assertStringNotContainsString($token, $out);
            $this->assertStringNotContainsString(substr($token, -4), $out);
            $this->assertStringNotContainsString('app-secret-xyz', $out);
        }
    }

    public function test_S_159_3_without_a_config_id_a_failed_long_lived_exchange_still_fails_the_connect(): void
    {
        $user = $this->user('Marketing', 'm@example.test');
        $this->actingAs($user);
        $this->failLongLivedExchange(now()->addDays(90)->timestamp);
        $this->fakeGraph();

        $this->withSession(['meta_ads_state' => str_repeat('s', 40)])
            ->get('/ads_manager/meta/callback?code=abc&state=' . str_repeat('s', 40))
            ->assertRedirect('/ads_manager/report')->assertSessionHasErrors('meta');

        $this->assertNull(app(Connections::class)->get($user->id));
    }
}
