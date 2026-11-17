<?php

namespace Tests\Feature\MetaAds;

use App\Services\MetaAds\AppConfig;
use App\Services\MetaAds\Connections;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Spec 281: ang Meta app (App ID, App Secret, Configuration ID) ay naitatakda sa /ads_manager/report (app_settings rows),
 * at ang .env ang fallback kapag walang row. CEO lang (gaya ng write mode).
 */
class MetaAdsAppSettingsTest extends MetaAdsTestCase
{
    private const NEW_SECRET = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const NEW_APP_ID = '123456789012345';
    private const NEW_CONFIG = '555666777';

    private function saveApp(array $data)
    {
        return $this->post('/ads_manager/meta/app', $data);
    }

    private function appRows(): array
    {
        return DB::table('app_settings')->where('key', 'like', 'meta_ads.app.%')->orderBy('key')->pluck('value', 'key')->all();
    }

    public function test_S_281_1_the_ceo_sees_the_meta_app_block_with_redirect_uri_app_domain_save_and_test(): void
    {
        $this->actingAs($this->user('CEO'));

        $page = $this->get('/ads_manager/report')->assertOk();

        $page->assertSee('id="meta-app-settings"', false)
            ->assertSee('Meta app')
            ->assertSee('App ID')->assertSee('value="999000"', false)
            ->assertSee('App Secret')->assertSee('Set')->assertSee('Replace')
            ->assertSee('Configuration ID')
            ->assertSee('Redirect URI')->assertSee('https://likha.test/ads_manager/meta/callback')
            ->assertSee('App Domain')->assertSee('data-copy="likha.test"', false)
            ->assertSee('Copy')
            ->assertSee('Save Meta app')->assertSee('Test');
    }

    public function test_S_281_1_on_a_site_with_no_meta_app_the_ceo_still_sees_the_block_with_secret_not_set(): void
    {
        config(['services.meta_ads.app_id' => null, 'services.meta_ads.app_secret' => null]);
        $this->actingAs($this->user('CEO'));

        $this->get('/ads_manager/report')->assertOk()
            ->assertSee('Meta app not set up yet')
            ->assertSee('id="meta-app-settings"', false)
            ->assertSee('Not set');
    }

    public function test_S_281_2_saved_values_win_for_the_dialog_the_code_exchange_and_appsecret_proof(): void
    {
        $this->actingAs($this->user('CEO'));
        $this->saveApp(['app_id' => self::NEW_APP_ID, 'app_secret' => self::NEW_SECRET, 'config_id' => self::NEW_CONFIG])
            ->assertRedirect('/ads_manager/report')->assertSessionHasNoErrors();

        $rows = $this->appRows();
        $this->assertSame(self::NEW_APP_ID, $rows['meta_ads.app.app_id']);
        $this->assertSame(self::NEW_CONFIG, $rows['meta_ads.app.config_id']);
        $this->assertNotSame(self::NEW_SECRET, $rows['meta_ads.app.app_secret']);
        $this->assertSame(self::NEW_SECRET, Crypt::decryptString($rows['meta_ads.app.app_secret']));

        // Connect: ang dialog ay may bagong client_id at config_id.
        $user = $this->user('Marketing', 'm@example.test');
        $this->actingAs($user);
        $url = (string) $this->get('/ads_manager/meta/connect')->headers->get('Location');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame(self::NEW_APP_ID, $q['client_id']);
        $this->assertSame(self::NEW_CONFIG, $q['config_id']);

        // Callback: ang code exchange, ang debug_token app token at ang appsecret_proof ay gumagamit ng bagong secret.
        $this->fakeGraph();
        $this->withSession(['meta_ads_state' => str_repeat('s', 40)])
            ->get('/ads_manager/meta/callback?code=abc&state=' . str_repeat('s', 40))->assertSessionHasNoErrors();

        $exchange = collect($this->graphRequests)->firstWhere('path', 'oauth/access_token');
        $this->assertSame([self::NEW_APP_ID, self::NEW_SECRET], [$exchange['query']['client_id'], $exchange['query']['client_secret']]);
        $this->assertSame(self::NEW_APP_ID . '|' . self::NEW_SECRET, collect($this->graphRequests)->firstWhere('path', 'debug_token')['token']);
        $me = collect($this->graphRequests)->firstWhere('path', 'me');
        $this->assertSame(hash_hmac('sha256', self::TOKEN_ANA, self::NEW_SECRET), $me['query']['appsecret_proof']);
        $this->assertFalse(collect($this->graphRequests)->contains(fn ($r) => str_contains($r['url'], 'app-secret-xyz')));
    }

    public function test_S_281_2_changing_the_app_id_warns_that_existing_connections_must_reconnect_and_keeps_them(): void
    {
        $ana = $this->user('Marketing', 'm@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111']);
        $this->actingAs($this->user('CEO'));

        $res = $this->saveApp(['app_id' => self::NEW_APP_ID, 'app_secret' => self::NEW_SECRET, 'config_id' => '']);

        $res->assertSessionHas('meta_app_warning', fn ($w) => str_contains($w, '1 existing Facebook connection') && str_contains($w, 'reconnect'));
        $this->assertNotNull(app(Connections::class)->get($ana->id));
        $this->get('/ads_manager/report')->assertSee('must reconnect');

        // Pareho ang App ID: walang babala.
        $this->saveApp(['app_id' => self::NEW_APP_ID, 'app_secret' => '', 'config_id' => '1'])->assertSessionMissing('meta_app_warning');
    }

    public static function badInputs(): array
    {
        return [
            'app id with letters'    => [['app_id' => '12ab34', 'app_secret' => self::NEW_SECRET, 'config_id' => ''], 'app_id', 'App ID: digits only'],
            'app id missing'         => [['app_id' => '', 'app_secret' => self::NEW_SECRET, 'config_id' => ''], 'app_id', 'App ID is required'],
            'secret too short'       => [['app_id' => '123', 'app_secret' => 'abc123', 'config_id' => ''], 'app_secret', 'App Secret: 32 hex characters (0-9, a-f)'],
            'secret not hex'         => [['app_id' => '123', 'app_secret' => str_repeat('g', 32), 'config_id' => ''], 'app_secret', 'App Secret: 32 hex characters (0-9, a-f)'],
            'config id with letters' => [['app_id' => '123', 'app_secret' => self::NEW_SECRET, 'config_id' => 'cfg-1'], 'config_id', 'Configuration ID: digits only'],
        ];
    }

    #[DataProvider('badInputs')]
    public function test_S_281_3_invalid_input_is_refused_with_a_clear_message_and_nothing_is_stored(array $data, string $field, string $message): void
    {
        $this->actingAs($this->user('CEO'));

        $this->saveApp($data)->assertRedirect('/ads_manager/report')->assertSessionHasErrors([$field => $message]);

        $this->assertSame([], $this->appRows());
        $this->assertSame(0, DB::table('upload_logs')->count());
    }

    public function test_S_281_3_no_secret_anywhere_and_an_empty_field_is_refused(): void
    {
        config(['services.meta_ads.app_id' => null, 'services.meta_ads.app_secret' => null]);
        $this->actingAs($this->user('CEO'));

        $this->saveApp(['app_id' => self::NEW_APP_ID, 'app_secret' => '', 'config_id' => ''])
            ->assertSessionHasErrors(['app_secret' => 'App Secret is required (none is saved yet)']);

        $this->assertSame([], $this->appRows());
    }

    public function test_S_281_3_an_empty_secret_field_keeps_the_stored_secret(): void
    {
        $this->actingAs($this->user('CEO'));
        $this->saveApp(['app_id' => self::NEW_APP_ID, 'app_secret' => self::NEW_SECRET, 'config_id' => '']);
        $stored = $this->appRows()['meta_ads.app.app_secret'];

        $this->saveApp(['app_id' => '777', 'app_secret' => '', 'config_id' => self::NEW_CONFIG])->assertSessionHasNoErrors();

        $rows = $this->appRows();
        $this->assertSame($stored, $rows['meta_ads.app.app_secret']);
        $this->assertSame(self::NEW_SECRET, AppConfig::appSecret());
        $this->assertSame(['777', self::NEW_CONFIG], [AppConfig::appId(), AppConfig::configId()]);
    }

    public function test_S_281_4_with_no_rows_the_values_come_from_env_and_an_unchanged_save_stores_nothing(): void
    {
        config(['services.meta_ads.config_id' => '42']);
        $this->assertSame(['999000', 'app-secret-xyz', '42'], [AppConfig::appId(), AppConfig::appSecret(), AppConfig::configId()]);

        $this->actingAs($this->user('CEO'));
        $this->saveApp(['app_id' => '999000', 'app_secret' => '', 'config_id' => '42'])->assertSessionHasNoErrors();

        $this->assertSame([], $this->appRows());
        $this->assertSame(['999000', 'app-secret-xyz', '42'], [AppConfig::appId(), AppConfig::appSecret(), AppConfig::configId()]);
    }

    public function test_S_281_4_a_saved_empty_configuration_id_turns_the_env_one_off(): void
    {
        config(['services.meta_ads.config_id' => '42']);
        $this->actingAs($this->user('CEO'));

        $this->saveApp(['app_id' => '999000', 'app_secret' => '', 'config_id' => ''])->assertSessionHasNoErrors();

        $this->assertSame(['meta_ads.app.config_id' => ''], $this->appRows());
        $this->assertNull(AppConfig::configId());
    }

    public function test_S_281_5_a_role_without_write_mode_gets_403_on_save_and_test_and_does_not_see_the_block(): void
    {
        $this->actingAs($this->user('Marketing', 'm@example.test'));
        Http::fake();

        $this->assertSame([403, 403], [
            $this->saveApp(['app_id' => self::NEW_APP_ID, 'app_secret' => self::NEW_SECRET, 'config_id' => ''])->status(),
            $this->post('/ads_manager/meta/app/test')->status(),
        ]);
        $this->get('/ads_manager/report')->assertOk()->assertSee('Connect Facebook')
            ->assertDontSee('meta-app-settings')->assertDontSee('Save Meta app');

        $this->assertSame([], $this->appRows());
        Http::assertNothingSent();
    }

    public function test_S_281_5_a_role_without_the_ads_link_gets_404_like_the_rest_of_the_card(): void
    {
        $this->actingAs($this->user('Data Encoder', 'de@example.test'));

        $this->assertSame([404, 404], [
            $this->saveApp(['app_id' => self::NEW_APP_ID, 'app_secret' => self::NEW_SECRET, 'config_id' => ''])->status(),
            $this->post('/ads_manager/meta/app/test')->status(),
        ]);
        $this->assertSame([], $this->appRows());
    }

    public function test_S_281_6_the_secret_never_appears_in_responses_the_page_logs_session_audit_or_error_messages(): void
    {
        $this->actingAs($this->user('CEO'));
        $logged = [];
        Log::listen(function ($e) use (&$logged) { $logged[] = $e->message . json_encode($e->context); });
        // Ang Graph ay sumasagot ng mali na may secret sa mensahe (hal. inuulit ang URL).
        Http::fake(fn () => Http::response(['error' => [
            'message' => 'Bad request https://graph.facebook.com/v25.0/oauth/access_token?client_secret=' . self::NEW_SECRET . ' secret ' . self::NEW_SECRET,
            'type' => 'OAuthException', 'code' => 1,
        ]], 400));

        $outputs = [];
        $save = $this->saveApp(['app_id' => self::NEW_APP_ID, 'app_secret' => self::NEW_SECRET, 'config_id' => '']);
        $outputs[] = $save->getContent();
        $outputs[] = json_encode(session()->all());
        $outputs[] = $this->get('/ads_manager/report')->getContent();
        $test = $this->post('/ads_manager/meta/app/test');
        $outputs[] = $test->getContent();
        $outputs[] = json_encode(session()->all());
        $outputs[] = $this->get('/ads_manager/report')->getContent();
        // Mali ang input: hindi rin naiiwan sa session ang secret (old input).
        $bad = $this->saveApp(['app_id' => 'x', 'app_secret' => self::NEW_SECRET, 'config_id' => '']);
        $outputs[] = $bad->getContent();
        $outputs[] = json_encode(session()->all());
        $outputs[] = $this->get('/ads_manager/report')->getContent();
        $outputs[] = implode("\n", $logged);
        $outputs[] = json_encode(DB::table('upload_logs')->get());
        $outputs[] = json_encode(DB::table('app_settings')->get());

        $this->assertStringContainsString('Meta app test failed', implode(' ', session('errors')?->get('meta_app') ?? []) . $outputs[5]);
        foreach ($outputs as $i => $out) {
            $this->assertStringNotContainsString(self::NEW_SECRET, (string) $out, "output #{$i}");
            $this->assertStringNotContainsString('app-secret-xyz', (string) $out, "output #{$i}");
        }
    }

    public function test_S_281_7_test_shows_ok_on_a_valid_pair(): void
    {
        $this->actingAs($this->user('CEO'));
        $this->saveApp(['app_id' => self::NEW_APP_ID, 'app_secret' => self::NEW_SECRET, 'config_id' => '']);
        $this->fakeGraph();

        $res = $this->post('/ads_manager/meta/app/test');

        $res->assertRedirect('/ads_manager/report')->assertSessionHasNoErrors()->assertSessionHas('status', fn ($s) => str_contains($s, 'Meta app test: OK'));
        $this->assertSame(['oauth/access_token'], $this->graphCalls);
        $q = $this->graphRequests[0]['query'];
        $this->assertSame(['client_credentials', self::NEW_APP_ID], [$q['grant_type'], $q['client_id']]);
        $this->get('/ads_manager/report')->assertSee('Meta app test: OK')->assertDontSee('EAAB-short-token');
    }

    public function test_S_281_7_test_shows_the_cleaned_error_on_a_refused_pair(): void
    {
        $this->actingAs($this->user('CEO'));
        Http::fake(function (Request $req) {
            return Http::response(['error' => ['message' => 'Error validating client secret.', 'type' => 'OAuthException', 'code' => 1]], 400);
        });

        $res = $this->post('/ads_manager/meta/app/test');

        $res->assertRedirect('/ads_manager/report')
            ->assertSessionHasErrors(['meta_app' => 'Meta app test failed: Graph error 1: Error validating client secret.']);
        $this->get('/ads_manager/report')->assertSee('Error validating client secret.');
    }

    public function test_S_281_8_each_save_writes_one_audit_line_with_who_and_the_changed_fields_never_the_secret(): void
    {
        $ceo = $this->user('CEO');
        $this->actingAs($ceo);

        $this->saveApp(['app_id' => self::NEW_APP_ID, 'app_secret' => self::NEW_SECRET, 'config_id' => '']);
        $this->saveApp(['app_id' => self::NEW_APP_ID, 'app_secret' => '', 'config_id' => self::NEW_CONFIG]);

        $lines = DB::table('upload_logs')->where('type', 'ads_manager_api_event')->orderBy('id')->get();
        $this->assertCount(2, $lines);
        $this->assertSame(['meta app saved: app_id, app_secret', 'meta app saved: config_id'], $lines->pluck('original_name')->all());
        $this->assertSame([$ceo->id, $ceo->id], $lines->pluck('user_id')->map(fn ($v) => (int) $v)->all());
        $this->assertNotNull($lines[0]->created_at);
        $this->assertSame(['app_id', 'app_secret'], json_decode($lines[0]->protected_details, true)['changed']);
        $this->assertSame(['app_id' => ['999000', self::NEW_APP_ID]], json_decode($lines[0]->protected_details, true)['values']);
        $this->assertStringNotContainsString(self::NEW_SECRET, json_encode($lines));
    }
}
