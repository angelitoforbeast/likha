<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Handoff 012: ang CEO lang ang may "remembered" login na 30 araw (D1, D2), at natatapos iyon sa Logout (D4).
 */
class CeoRememberedLoginTest extends AuthTestCase
{
    private const THIRTY_DAYS = 30 * 24 * 60 * 60;

    /** Ang role ay binabasa gaya ng getNormalizedRole(): trim, hindi case-sensitive. */
    public static function ceoRoles(): array
    {
        return [
            'CEO'                  => ['CEO'],
            'may space at maliit'  => [' ceo '],
        ];
    }

    #[DataProvider('ceoRoles')]
    public function test_ceo_login_sets_a_remember_cookie_that_lasts_30_days(string $role): void
    {
        $user = $this->user($role);

        [$response, $sessionIdBefore] = $this->postLogin(['email' => $user->email, 'password' => self::PASSWORD]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($sessionIdBefore, session()->getId());

        $cookie = $response->getCookie($this->recallerName());
        $this->assertNotNull($cookie, 'Walang remember cookie sa login ng CEO.');
        $this->assertEqualsWithDelta(time() + self::THIRTY_DAYS, $cookie->getExpiresTime(), 120);
    }

    #[DataProvider('ceoRoles')]
    public function test_remember_cookie_alone_signs_the_ceo_in_on_a_fresh_session(string $role): void
    {
        $user = $this->user($role);
        $cookie = $this->loginAndTakeRememberCookie($user);

        $response = $this->getWithRememberCookie($cookie);

        $response->assertOk();
        $response->assertJsonStructure(['ip', 'xff']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_logout_clears_the_remember_cookie_and_the_old_cookie_no_longer_signs_in(): void
    {
        $user = $this->user('CEO');
        $cookie = $this->loginAndTakeRememberCookie($user);
        $tokenBefore = $user->fresh()->remember_token;

        $response = $this
            ->withCookie(session()->getName(), session()->getId())
            ->withCookie($this->recallerName(), $cookie)
            ->post('/logout');

        $response->assertRedirect('/login');
        $response->assertCookieExpired($this->recallerName());
        $this->assertGuest();
        $this->assertNotSame($tokenBefore, $user->fresh()->remember_token);

        $this->assertSentToLogin($this->getWithRememberCookie($cookie));
        $this->assertGuest();
    }

    /** D3: ang sign-in na galing sa cookie ay para lang sa account na CEO pa rin sa oras na iyon. */
    public static function accountsThatAreNotCeoAnymore(): array
    {
        return [
            'pinalitan ang role pagkatapos mag-login' => ['demoted'],
            'binura ang employee profile'             => ['profile deleted'],
            'Marketing na may hawak na valid cookie'  => ['never ceo'],
        ];
    }

    #[DataProvider('accountsThatAreNotCeoAnymore')]
    public function test_remembered_sign_in_is_refused_when_the_account_is_not_ceo(string $case): void
    {
        if ($case === 'never ceo') {
            // Hindi ito naibibigay ng login; token na inilagay sa kamay at cookie na ginawa sa kamay.
            $user = $this->user('Marketing');
            $user->forceFill(['remember_token' => Str::random(60)])->save();
            $cookie = $user->id . '|' . $user->remember_token . '|x';
        } else {
            $user = $this->user('CEO');
            $cookie = $this->loginAndTakeRememberCookie($user);
            $case === 'demoted'
                ? $user->employeeProfile()->update(['role' => 'Marketing'])
                : $user->employeeProfile()->delete();
        }
        $tokenBefore = $user->fresh()->remember_token;

        $response = $this->getWithRememberCookie($cookie);

        $this->assertSentToLogin($response);
        $response->assertCookieExpired($this->recallerName());
        $this->assertGuest();
        $this->assertNotSame($tokenBefore, $user->fresh()->remember_token);

        // Ang lumang cookie ay patay na rin sa susunod na request.
        $this->assertSentToLogin($this->getWithRememberCookie($cookie));
        $this->assertGuest();
    }

    /** Password login; ibinabalik ang halaga ng remember cookie na ibinigay ng server. */
    private function loginAndTakeRememberCookie(User $user): string
    {
        [$response] = $this->postLogin(['email' => $user->email, 'password' => self::PASSWORD]);

        $cookie = $response->getCookie($this->recallerName());
        $this->assertNotNull($cookie, 'Walang remember cookie sa login ng CEO.');

        return $cookie->getValue();
    }
}
