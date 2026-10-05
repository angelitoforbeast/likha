<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Pin ng kasalukuyang login / logout bago ang handoff 012 (CEO stays logged in):
 * walang remember cookie ang ibang role, pareho ang redirect, pareho ang failure.
 */
class LoginCharacterizationTest extends AuthTestCase
{
    public static function nonCeoLogins(): array
    {
        return [
            'Marketing'                        => ['Marketing', []],
            'Marketing - OIC'                  => ['Marketing - OIC', []],
            'Data Encoder'                     => ['Data Encoder', []],
            'Marketing na nag-post remember=1' => ['Marketing', ['remember' => '1']],
            'walang role'                      => ['', ['remember' => 'on']],
        ];
    }

    #[DataProvider('nonCeoLogins')]
    public function test_non_ceo_login_signs_in_regenerates_the_session_and_sets_no_remember_cookie(string $role, array $extra): void
    {
        $user = $this->user($role);

        [$response, $sessionIdBefore] = $this->postLogin(
            ['email' => $user->email, 'password' => self::PASSWORD] + $extra
        );

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($sessionIdBefore, session()->getId());
        $response->assertCookieMissing($this->recallerName());
        $this->assertNull($user->fresh()->remember_token);
    }

    public static function badLogins(): array
    {
        return [
            'maling password'   => [['email' => 'user@example.test', 'password' => 'wrong-password']],
            'hindi kilalang email' => [['email' => 'nobody@example.test', 'password' => self::PASSWORD]],
            'walang email'      => [['password' => self::PASSWORD]],
            'email na array'    => [['email' => ['nobody@example.test'], 'password' => self::PASSWORD]],
            'maling password + remember=1' => [['email' => 'user@example.test', 'password' => 'wrong-password', 'remember' => '1']],
        ];
    }

    #[DataProvider('badLogins')]
    public function test_failed_login_goes_back_with_the_email_error_and_sets_no_cookie(array $form): void
    {
        // CEO ang account: kahit CEO, walang cookie kapag bigo ang login.
        $this->user('CEO');

        [$response, $sessionIdBefore] = $this->postLogin($form);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors(['email' => 'Invalid credentials.']);
        $this->assertGuest();
        $this->assertSame($sessionIdBefore, session()->getId());
        $response->assertCookieMissing($this->recallerName());
    }

    public function test_logout_signs_out_and_redirects_to_login(): void
    {
        $user = $this->user('Marketing');

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect('/login');
        $this->assertGuest();
    }

    public static function forgedRememberCookies(): array
    {
        return [
            'tamang hugis, maling token' => [fn (int $id) => $id . '|' . str_repeat('w', 60) . '|x'],
            'gawa-gawang string'         => [fn (int $id) => 'not-a-remember-cookie'],
            'ibang user id'              => [fn (int $id) => ($id + 1) . '|' . str_repeat('w', 60) . '|x'],
        ];
    }

    #[DataProvider('forgedRememberCookies')]
    public function test_forged_remember_cookie_signs_nobody_in(\Closure $cookieFor): void
    {
        $user = $this->user('CEO');
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        $response = $this->getWithRememberCookie($cookieFor($user->id));

        $this->assertSentToLogin($response);
        $this->assertGuest();
    }
}
