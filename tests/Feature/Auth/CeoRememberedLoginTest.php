<?php

namespace Tests\Feature\Auth;

use App\Listeners\RefuseRememberedLoginUnlessCeo;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Handoff 012: ang CEO lang ang may "remembered" login na 30 araw (D1, D2), at natatapos iyon sa Logout (D4).
 * Amendment 012-1 (A1): ang 30 araw ay binabantayan ng server sa pamamagitan ng pangalawang cookie.
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
        $this->freezeSecond();
        $user = $this->user($role);

        [$response, $sessionIdBefore] = $this->postLogin(['email' => $user->email, 'password' => self::PASSWORD]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($sessionIdBefore, session()->getId());

        $cookie = $response->getCookie($this->recallerName());
        $this->assertNotNull($cookie, 'Walang remember cookie sa login ng CEO.');
        $this->assertEqualsWithDelta(time() + self::THIRTY_DAYS, $cookie->getExpiresTime(), 120);

        // A1: pangalawang cookie na may user id at oras ng login, parehong tagal at parehong attributes.
        $since = $response->getCookie(self::SINCE_COOKIE);
        $this->assertNotNull($since, 'Walang pangalawang cookie sa login ng CEO.');
        $this->assertSame($user->id . '|' . now()->timestamp, $since->getValue());
        $this->assertEqualsWithDelta(time() + self::THIRTY_DAYS, $since->getExpiresTime(), 120);
        $this->assertTrue($since->isHttpOnly());
        $this->assertSame(
            [$cookie->isSecure(), $cookie->getSameSite(), $cookie->getPath(), $cookie->getDomain()],
            [$since->isSecure(), $since->getSameSite(), $since->getPath(), $since->getDomain()]
        );
    }

    #[DataProvider('ceoRoles')]
    public function test_remember_cookie_alone_signs_the_ceo_in_on_a_fresh_session(string $role): void
    {
        $user = $this->user($role);
        [$cookie, $since] = $this->loginAndTakeCookies($user);

        $response = $this->getWithRememberCookie($cookie, $since);

        $response->assertOk();
        $response->assertJsonStructure(['ip', 'xff']);
        $this->assertAuthenticatedAs($user);
    }

    /**
     * A1: mga pangalawang cookie na tinatanggap.
     * Bawat case: [paano ginagawa ang pangalawang cookie, ilang minuto ang lumipas bago ang remembered sign-in].
     */
    public static function validSinceCookies(): array
    {
        return [
            '29 araw na'                                => ['as issued', 29 * 24 * 60],
            'eksaktong 30 araw (hangganan)'             => ['as issued', 30 * 24 * 60],
            '60 segundo sa hinaharap, loob ng palugit'  => [fn (int $id, int $at) => $id . '|' . ($at + 60), 0],
            // Sadyang disenyo: hindi napapalitan ang remember token sa bawat login, kaya ang remember cookie ng
            // naunang login (40 araw na) ay tinatanggap kasama ang pangalawang cookie ng mas huling login (20 araw).
            'galing sa mas huling password login'       => ['later login', 20 * 24 * 60],
        ];
    }

    #[DataProvider('validSinceCookies')]
    public function test_remembered_sign_in_is_accepted_with_a_valid_second_cookie($since, int $minutesLater): void
    {
        $this->freezeSecond();
        $user = $this->user('CEO');
        [$cookie, $issued] = $this->loginAndTakeCookies($user);
        $loginAt = now()->timestamp;

        if ($since === 'later login') {
            // Pangalawang password login makalipas ang 20 araw, sa ibang browser; ang pangalawang cookie nito ang gagamitin.
            $this->travel(20)->days();
            $this->freshBrowser();
            [, $issued] = $this->loginAndTakeCookies($user);
        }

        $this->travel($minutesLater)->minutes();
        $response = $this->getWithRememberCookie(
            $cookie,
            $since instanceof \Closure ? $since($user->id, $loginAt) : $issued
        );

        $response->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    /**
     * A1: walang remembered sign-in kung walang wastong pangalawang cookie na hindi lalampas sa 30 araw.
     * Bawat case: [paano ginagawa ang pangalawang cookie mula sa (user id, oras ng login), ilang minuto ang lumipas].
     */
    public static function badSinceCookies(): array
    {
        return [
            'wala'                         => [null, 0],
            'binago ang ciphertext'        => ['tampered', 0],
            'para sa ibang user id'        => [fn (int $id, int $at) => ($id + 1) . '|' . $at, 0],
            'lampas 30 araw'               => ['as issued', 30 * 24 * 60 + 1],
            'oras na nasa hinaharap'       => [fn (int $id, int $at) => $id . '|' . ($at + 3600), 0],
            'hindi digits ang oras'        => [fn (int $id, int $at) => $id . '|' . $at . 'x', 0],
            'user id na may zero sa unahan' => [fn (int $id, int $at) => '0' . $id . '|' . $at, 0],
            'walang oras'                  => [fn (int $id, int $at) => (string) $id, 0],
            'array ang hugis (name[]=...)' => ['as array', 0],
        ];
    }

    #[DataProvider('badSinceCookies')]
    public function test_remembered_sign_in_is_refused_without_a_valid_second_cookie($since, int $minutesLater): void
    {
        $this->freezeSecond();
        $user = $this->user('CEO');
        [$login] = $this->postLogin(['email' => $user->email, 'password' => self::PASSWORD]);
        $cookie = $login->getCookie($this->recallerName())->getValue();
        $rawSince = $this->rawCookie($login, self::SINCE_COOKIE);
        $loginAt = now()->timestamp;
        $tokenBefore = $user->fresh()->remember_token;

        $this->travel($minutesLater)->minutes();
        $this->freshBrowser();
        if ($since === 'tampered') {
            // Isang character sa gitna ng ciphertext ang binago: hindi na ito made-decrypt ng framework.
            $middle = intdiv(strlen($rawSince), 2);
            $this->withUnencryptedCookie(
                self::SINCE_COOKIE,
                substr_replace($rawSince, $rawSince[$middle] === 'A' ? 'B' : 'A', $middle, 1)
            );
        } elseif ($since === 'as issued') {
            $this->withUnencryptedCookie(self::SINCE_COOKIE, $rawSince);
        } elseif ($since === 'as array') {
            // Ang tunay na cookie ng server, pero ipinadala bilang ceo_remember_since[]=...: nade-decrypt ang
            // laman, array ang dumarating sa listener. String lang ang tinatanggap ng withUnencryptedCookie.
            $this->unencryptedCookies[self::SINCE_COOKIE] = [$rawSince];
        } elseif ($since !== null) {
            $this->withCookie(self::SINCE_COOKIE, $since($user->id, $loginAt));
        }

        $response = $this->withCookie($this->recallerName(), $cookie)->get(self::PROTECTED_URL);

        $this->assertSentToLogin($response);
        $response->assertCookieExpired($this->recallerName());
        $response->assertCookieExpired(self::SINCE_COOKIE);
        $this->assertGuest();
        $this->assertNotSame($tokenBefore, $user->fresh()->remember_token);
    }

    public function test_logout_clears_the_remember_cookie_and_the_old_cookie_no_longer_signs_in(): void
    {
        $user = $this->user('CEO');
        [$cookie, $since] = $this->loginAndTakeCookies($user);
        $tokenBefore = $user->fresh()->remember_token;

        $response = $this
            ->withCookie(session()->getName(), session()->getId())
            ->withCookie($this->recallerName(), $cookie)
            ->withCookie(self::SINCE_COOKIE, $since)
            ->post('/logout');

        $response->assertRedirect('/login');
        $response->assertCookieExpired($this->recallerName());
        $response->assertCookieExpired(self::SINCE_COOKIE);
        $this->assertGuest();
        $this->assertNotSame($tokenBefore, $user->fresh()->remember_token);

        $this->assertSentToLogin($this->getWithRememberCookie($cookie, $since));
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
            // Hindi ito naibibigay ng login; token na inilagay sa kamay at mga cookie na ginawa sa kamay.
            $user = $this->user('Marketing');
            $user->forceFill(['remember_token' => Str::random(60)])->save();
            $cookie = $user->id . '|' . $user->remember_token . '|x';
            $since = $user->id . '|' . now()->timestamp;
        } else {
            $user = $this->user('CEO');
            [$cookie, $since] = $this->loginAndTakeCookies($user);
            $case === 'demoted'
                ? $user->employeeProfile()->update(['role' => 'Marketing'])
                : $user->employeeProfile()->delete();
        }
        $tokenBefore = $user->fresh()->remember_token;

        // Wasto ang pangalawang cookie, kaya ang role lang ang dahilan ng pagtanggi.
        $response = $this->getWithRememberCookie($cookie, $since);

        $this->assertSentToLogin($response);
        $response->assertCookieExpired($this->recallerName());
        $response->assertCookieExpired(self::SINCE_COOKIE);
        $this->assertGuest();
        $this->assertNotSame($tokenBefore, $user->fresh()->remember_token);

        // Ang lumang cookie ay patay na rin sa susunod na request.
        $this->assertSentToLogin($this->getWithRememberCookie($cookie, $since));
        $this->assertGuest();
    }

    /**
     * Fail closed: isinusulat ng guard ang login id sa session BAGO ang Login event. Kapag pumalya ang
     * pagbasa ng role (hal. DB error), hindi dapat maiwang naka-sign-in ang session na iyon.
     */
    public function test_remembered_sign_in_leaves_no_signed_in_session_when_the_role_check_fails(): void
    {
        $user = $this->user('CEO');
        [$cookie, $since] = $this->loginAndTakeCookies($user);

        // Panandaliang DB error sa pagbasa ng employee profile.
        Schema::rename('employee_profiles', 'employee_profiles_off');
        $this->getWithRememberCookie($cookie, $since)->assertStatus(500);
        Schema::rename('employee_profiles_off', 'employee_profiles');

        // Susunod na request ng parehong browser: session cookie lang, walang remember cookie.
        // Nililinis ang nasa memory para ang mabasa ay ang session na na-save ng pumalyang request.
        $sessionId = session()->getId();
        session()->flush();
        Auth::forgetGuards();
        Cookie::flushQueuedCookies();
        unset($this->defaultCookies[$this->recallerName()], $this->defaultCookies[self::SINCE_COOKIE]);

        $response = $this->withCookie(session()->getName(), $sessionId)->get(self::PROTECTED_URL);

        $this->assertSentToLogin($response);
        $this->assertGuest();
    }

    /**
     * A3: ang re-check ay nakakabit sa Login event nang tahasan (AppServiceProvider), hindi sa discovery o sa
     * naka-cache na events file: array na [class, method] ang tahasang rehistro, string na "Class@method" ang
     * galing sa discovery. Nauuna rin ito sa ibang Login listener, at isang beses lang nakakabit.
     */
    public function test_the_recheck_is_registered_explicitly_once_and_runs_before_other_login_listeners(): void
    {
        $raw = Event::getRawListeners();
        $login = $raw[Login::class] ?? [];

        $this->assertSame([RefuseRememberedLoginUnlessCeo::class, 'recheckRememberedLogin'], $login[0] ?? null);
        $this->assertSame(1, $this->timesAttached($login));
        // Kontrol: kasama sa listahan ang listener na galing sa discovery, kaya may saysay ang "nauuna".
        $this->assertGreaterThan(0, array_search('App\Listeners\CopyEverydayTasksOnLogin@handle', $login, true));

        $logout = $raw[Logout::class] ?? [];
        $this->assertContains([RefuseRememberedLoginUnlessCeo::class, 'forgetSinceCookie'], $logout);
        $this->assertSame(1, $this->timesAttached($logout));
    }

    /** Ilang listener sa listahan ang tumutukoy sa RefuseRememberedLoginUnlessCeo, anuman ang hugis. */
    private function timesAttached(array $listeners): int
    {
        return count(array_filter($listeners, function ($listener) {
            $target = is_array($listener) ? $listener[0] : $listener;
            $name = is_object($target) ? get_class($target) : (string) $target;

            return str_contains($name, 'RefuseRememberedLoginUnlessCeo');
        }));
    }
}
