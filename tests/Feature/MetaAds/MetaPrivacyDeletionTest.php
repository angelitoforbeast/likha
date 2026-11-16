<?php

namespace Tests\Feature\MetaAds;

use App\Services\MetaAds\AppConfig;
use App\Services\MetaAds\Connections;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Spec 282: ang public na Privacy Policy (/privacy) at ang Data Deletion Callback ng Meta app
 * (POST /meta/data-deletion + status page), at ang company name / contact email sa "Meta app" block (CEO).
 */
class MetaPrivacyDeletionTest extends MetaAdsTestCase
{
    /** Ang App Secret sa MetaAdsTestCase (config services.meta_ads.app_secret). */
    private const SECRET = 'app-secret-xyz';
    private const FB_ID  = '9988776655443322';

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    /** Isang signed_request gaya ng ipinapadala ng Meta: base64url(sig).base64url(payload), sig = HMAC-SHA256 ng payload. */
    private static function signed(array $payload, string $secret = self::SECRET): string
    {
        $p = self::b64(json_encode($payload));

        return self::b64(hash_hmac('sha256', $p, $secret, true)) . '.' . $p;
    }

    private static function payload(string $userId = self::FB_ID, string $algorithm = 'HMAC-SHA256'): array
    {
        return ['algorithm' => $algorithm, 'expires' => 1893456000, 'issued_at' => 1763280000, 'user_id' => $userId];
    }

    private function deletionRows(): array
    {
        return DB::table('app_settings')->where('key', 'like', 'meta_ads.deletion.%')->pluck('value', 'key')->all();
    }

    private function connectionKeys(): array
    {
        return DB::table('app_settings')->where('key', 'like', Connections::PREFIX . '%')->orderBy('key')->pluck('key')->all();
    }

    // ── Story 1: public /privacy ──────────────────────────────────────────

    public function test_S_282_1_anyone_logged_out_sees_the_policy_with_the_saved_company_name_and_email(): void
    {
        AppConfig::savePrivacy('Forbes Trading Corp', 'privacy@forbes.example');

        $this->assertGuest();
        $this->get('/privacy')->assertOk()
            ->assertSee('Privacy Policy')
            ->assertSee('Forbes Trading Corp')
            ->assertSee('privacy@forbes.example')
            ->assertSee('app-scoped Facebook ID')
            ->assertSee('ad accounts you can access')
            ->assertSee('Ads reports and insights')
            ->assertSee('stored encrypted')
            ->assertSee('internal ads reporting')
            ->assertSee('Only the staff of Forbes Trading Corp')
            ->assertSee('Until you disconnect or ask for deletion')
            ->assertSee('no personal data')
            ->assertSee('Disconnect my Facebook')
            ->assertSee('Remove the app in your Facebook settings');
    }

    public function test_S_282_1_with_nothing_saved_the_page_uses_the_app_name_and_has_no_email_line(): void
    {
        config(['app.name' => 'Likha Site Name']);

        $this->get('/privacy')->assertOk()
            ->assertSee('Likha Site Name')
            ->assertDontSee('mailto:')
            ->assertDontSee('Email:');
    }

    // ── Story 2: company name and contact email in the Meta app block ────

    public function test_S_282_2_the_ceo_saves_company_name_and_contact_email_and_the_policy_shows_them(): void
    {
        $this->actingAs($this->user('CEO'));

        $this->post('/ads_manager/meta/app', ['app_id' => '999000', 'app_secret' => '', 'config_id' => '',
            'company_name' => 'Forbes Trading Corp', 'contact_email' => 'privacy@forbes.example'])
            ->assertRedirect('/ads_manager/report')->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Meta app saved: company_name, contact_email');

        $this->assertSame('Forbes Trading Corp', DB::table('app_settings')->where('key', 'meta_ads.app.company_name')->value('value'));
        $this->assertSame('privacy@forbes.example', DB::table('app_settings')->where('key', 'meta_ads.app.contact_email')->value('value'));
        $audit = DB::table('upload_logs')->where('type', 'ads_manager_api_event')->orderByDesc('id')->first();
        $this->assertSame('meta app saved: company_name, contact_email', $audit->original_name);

        $this->get('/ads_manager/report')->assertOk()
            ->assertSee('Company name (optional)')->assertSee('value="Forbes Trading Corp"', false)
            ->assertSee('Contact email (optional)')->assertSee('value="privacy@forbes.example"', false);

        auth()->logout();
        $this->get('/privacy')->assertOk()->assertSee('Forbes Trading Corp')->assertSee('privacy@forbes.example');

        // Blangko: bumabalik sa app name at walang email line.
        config(['app.name' => 'Likha Site Name']);
        $this->actingAs($this->user('CEO', 'ceo2@example.test'));
        $this->post('/ads_manager/meta/app', ['app_id' => '999000', 'app_secret' => '', 'config_id' => '', 'company_name' => '', 'contact_email' => ''])
            ->assertSessionHasNoErrors();
        auth()->logout();
        $this->get('/privacy')->assertOk()->assertSee('Likha Site Name')->assertDontSee('Forbes Trading Corp')->assertDontSee('mailto:');
    }

    public function test_S_282_2_an_invalid_email_is_refused_and_nothing_is_stored(): void
    {
        $this->actingAs($this->user('CEO'));

        $this->post('/ads_manager/meta/app', ['app_id' => '999000', 'app_secret' => '', 'config_id' => '',
            'company_name' => 'Forbes Trading Corp', 'contact_email' => 'not-an-email'])
            ->assertRedirect('/ads_manager/report')
            ->assertSessionHasErrors(['contact_email' => 'Contact email: enter a valid email address']);

        $this->assertSame([], DB::table('app_settings')->where('key', 'like', 'meta_ads.app.%')->pluck('value', 'key')->all());
        $this->get('/ads_manager/report')->assertSee('Contact email: enter a valid email address');
    }

    public function test_S_282_2_other_roles_get_403_or_404_like_the_other_meta_app_fields(): void
    {
        $data = ['app_id' => '999000', 'app_secret' => '', 'config_id' => '', 'company_name' => 'X Corp', 'contact_email' => 'x@example.test'];

        $this->actingAs($this->user('Marketing', 'm@example.test'));
        $this->post('/ads_manager/meta/app', $data)->assertStatus(403);
        $this->get('/ads_manager/report')->assertOk()->assertDontSee('Company name (optional)');

        $this->actingAs($this->user('Data Encoder', 'de@example.test'));
        $this->post('/ads_manager/meta/app', $data)->assertStatus(404);

        $this->assertSame([], DB::table('app_settings')->where('key', 'like', 'meta_ads.app.%')->pluck('value', 'key')->all());
    }

    // ── Story 3: valid signed_request ────────────────────────────────────

    public function test_S_282_3_a_valid_signed_request_deletes_the_matching_connections_and_answers_with_url_and_code(): void
    {
        $ana = $this->user('Marketing', 'ana@example.test');
        $ben = $this->user('Marketing - OIC', 'ben@example.test');
        $cel = $this->user('CEO', 'cel@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111'], ['fb_user_id' => self::FB_ID]);
        $this->connect($ben, self::TOKEN_BEN, ['222'], ['fb_user_id' => self::FB_ID]);
        $this->connect($cel, 'EAAB-cel-token', ['333'], ['fb_user_id' => '1111222233334444']);

        $res = $this->post('/meta/data-deletion', ['signed_request' => self::signed(self::payload())])->assertOk();

        $code = $res->json('confirmation_code');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{20}$/', $code);
        $this->assertSame(['url' => 'https://likha.test/meta/data-deletion/' . $code, 'confirmation_code' => $code], $res->json());
        $this->assertSame([Connections::PREFIX . $cel->id], $this->connectionKeys());

        $row = json_decode($this->deletionRows()['meta_ads.deletion.' . $code], true);
        $this->assertSame(2, $row['count']);
        $this->assertSame(hash('sha256', self::FB_ID), $row['fb_user_sha256']);
        $this->assertNotEmpty($row['deleted_at']);

        $this->get('/meta/data-deletion/' . $code)->assertOk()
            ->assertSee('Your Facebook data was deleted on ' . now()->format('F j, Y'))
            ->assertDontSee('Nothing was stored');
        $this->get('/meta/data-deletion/0123456789abcdef0123')->assertNotFound()->assertSee('Not found')->assertDontSee('was deleted');
    }

    public function test_S_282_3_the_callback_is_csrf_exempt_unlike_the_other_posts(): void
    {
        // Sa tests ay laging lusot ang CSRF (runningUnitTests), kaya ang middleware mismo ang sinusubok, naka-off ang shortcut.
        // Ang withMiddleware() ng bootstrap/app.php ay tumatakbo kapag na-resolve ang HTTP kernel.
        app(\Illuminate\Contracts\Http\Kernel::class);
        $mw =new class(app(), app('encrypter')) extends ValidateCsrfToken {
            protected function runningUnitTests() { return false; }
        };
        $handle = function (string $uri) use ($mw) {
            $req = Request::create($uri, 'POST', ['signed_request' => 'x']);
            $req->setLaravelSession(app('session.store'));

            return $mw->handle($req, fn () => response('passed'))->getContent();
        };

        $this->assertSame('passed', $handle('/meta/data-deletion'));
        $this->expectException(TokenMismatchException::class);
        $handle('/ads_manager/meta/app');
    }

    // ── Story 4: anything wrong → 400, nothing deleted ───────────────────

    public static function badRequests(): array
    {
        $payload = self::payload();

        return [
            'bad signature'          => [self::signed($payload, 'some-other-secret')],
            'wrong algorithm'        => [self::signed(self::payload(algorithm: 'HMAC-SHA1'))],
            'no algorithm'           => [self::signed(['user_id' => self::FB_ID])],
            'tampered payload'       => [explode('.', self::signed($payload))[0] . '.' . self::b64(json_encode(self::payload('1111222233334444')))],
            'no dot'                 => ['justonepart'],
            'three parts'            => [self::signed($payload) . '.extra'],
            'not base64'             => ['!!!.###'],
            'payload not json'       => [self::b64(hash_hmac('sha256', self::b64('not json'), self::SECRET, true)) . '.' . self::b64('not json')],
            'no user_id'             => [self::signed(['algorithm' => 'HMAC-SHA256'])],
            'empty user_id'          => [self::signed(self::payload(''))],
            'empty string'           => [''],
            'missing signed_request' => [null],
        ];
    }

    #[DataProvider('badRequests')]
    public function test_S_282_4_a_bad_request_answers_400_without_detail_and_deletes_nothing(?string $signed): void
    {
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111'], ['fb_user_id' => self::FB_ID]);

        $res = $this->post('/meta/data-deletion', $signed === null ? [] : ['signed_request' => $signed]);

        $res->assertStatus(400);
        $this->assertSame(['error' => 'invalid request'], $res->json());
        $this->assertSame([Connections::PREFIX . $ana->id], $this->connectionKeys());
        $this->assertSame([], $this->deletionRows());
    }

    public function test_S_282_4_with_no_app_secret_a_correctly_shaped_request_answers_400(): void
    {
        config(['services.meta_ads.app_secret' => null]);
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111'], ['fb_user_id' => self::FB_ID]);

        // Pinirmahan ng dating secret, at ng walang laman na secret: parehong tinatanggihan.
        foreach ([self::SECRET, ''] as $secret) {
            $this->post('/meta/data-deletion', ['signed_request' => self::signed(self::payload(), $secret)])->assertStatus(400);
        }
        $this->assertSame([Connections::PREFIX . $ana->id], $this->connectionKeys());
        $this->assertSame([], $this->deletionRows());
    }

    public function test_S_282_4_a_saved_app_secret_is_the_one_that_signs(): void
    {
        AppConfig::save('999000', 'a1b2c3d4e5f60718293a4b5c6d7e8f90', null);
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111'], ['fb_user_id' => self::FB_ID]);

        $this->post('/meta/data-deletion', ['signed_request' => self::signed(self::payload())])->assertStatus(400);
        $this->assertCount(1, $this->connectionKeys());

        $this->post('/meta/data-deletion', ['signed_request' => self::signed(self::payload(), 'a1b2c3d4e5f60718293a4b5c6d7e8f90')])->assertOk();
        $this->assertSame([], $this->connectionKeys());
    }

    // ── Story 5: no connection for that Facebook user ────────────────────

    public function test_S_282_5_a_valid_request_for_someone_with_no_connection_still_gets_a_code_and_a_deleted_status(): void
    {
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111'], ['fb_user_id' => '1111222233334444']);

        $res = $this->post('/meta/data-deletion', ['signed_request' => self::signed(self::payload())])->assertOk();
        $code = $res->json('confirmation_code');
        $this->assertSame('https://likha.test/meta/data-deletion/' . $code, $res->json('url'));

        $this->assertSame([Connections::PREFIX . $ana->id], $this->connectionKeys());
        $this->assertSame(0, json_decode($this->deletionRows()['meta_ads.deletion.' . $code], true)['count']);

        $this->get('/meta/data-deletion/' . $code)->assertOk()
            ->assertSee('Your Facebook data was deleted on ' . now()->format('F j, Y'))
            ->assertSee('Nothing was stored for this Facebook account.');
    }

    // ── Story 6: token, secret, Facebook ID never shown or logged ────────

    public function test_S_282_6_the_token_the_app_secret_and_the_facebook_id_never_appear_in_any_response_page_or_log(): void
    {
        $logged = [];
        Log::listen(function ($e) use (&$logged) { $logged[] = $e->message . json_encode($e->context); });
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111'], ['fb_user_id' => self::FB_ID, 'fb_user_name' => 'Ana Facebook']);
        AppConfig::savePrivacy('Forbes Trading Corp', 'privacy@forbes.example');

        $outputs = [];
        $outputs[] = $this->get('/privacy')->getContent();
        $outputs[] = $this->post('/meta/data-deletion', ['signed_request' => self::signed(self::payload(), 'wrong')])->getContent();
        $outputs[] = $this->post('/meta/data-deletion', ['signed_request' => self::signed(self::payload(algorithm: 'none'))])->getContent();
        $ok = $this->post('/meta/data-deletion', ['signed_request' => self::signed(self::payload())]);
        $outputs[] = $ok->getContent();
        $outputs[] = $this->get('/meta/data-deletion/' . $ok->json('confirmation_code'))->getContent();
        $outputs[] = $this->get('/meta/data-deletion/0123456789abcdef0123')->getContent();
        $this->actingAs($this->user('CEO'));
        $outputs[] = $this->get('/ads_manager/report')->getContent();
        $outputs[] = implode("\n", $logged);
        $outputs[] = json_encode(DB::table('upload_logs')->get());
        $outputs[] = json_encode(DB::table('app_settings')->get());

        $this->assertStringContainsString('confirmation_code', $outputs[3]);
        foreach ($outputs as $i => $out) {
            foreach ([self::TOKEN_ANA, self::SECRET, self::FB_ID, 'Ana Facebook'] as $secret) {
                $this->assertStringNotContainsString($secret, (string) $out, "output #{$i}");
            }
        }
    }

    // ── Story 7: the two URLs in the Meta app block ──────────────────────

    public function test_S_282_7_the_meta_app_block_shows_the_privacy_and_data_deletion_urls_with_copy_buttons(): void
    {
        $this->actingAs($this->user('CEO'));

        $this->get('/ads_manager/report')->assertOk()
            ->assertSee('Privacy Policy URL')
            ->assertSee('data-copy="https://likha.test/privacy"', false)
            ->assertSee('Data Deletion Callback URL')
            ->assertSee('data-copy="https://likha.test/meta/data-deletion"', false);
    }

    public function test_S_282_7_other_roles_do_not_see_the_urls(): void
    {
        $this->actingAs($this->user('Marketing', 'm@example.test'));

        $this->get('/ads_manager/report')->assertOk()
            ->assertDontSee('Data Deletion Callback URL')
            ->assertDontSee('https://likha.test/meta/data-deletion');
    }
}
