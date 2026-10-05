<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

/**
 * Handoff 012 (D3): ang sign-in na galing sa remember cookie (hindi sa password) ay para lang sa account
 * na CEO pa rin sa oras na iyon. Kung hindi na CEO (pinalitan ang role, binura ang employee profile),
 * tinatanggihan: logout agad, kaya nabubura ang cookie at napapalitan ang remember token.
 *
 * Amendment 012-1 (A1): kailangan din ng pangalawang cookie na inilabas sa password login ng CEO (user id at
 * oras ng login). Tinatanggap lang ang remembered sign-in kung nandoon ito, na-decrypt ng framework, para sa
 * parehong user id, at hindi lalampas sa 30 araw. Server ang nagbibilang ng 30 araw, hindi ang browser.
 *
 * Tumatakbo ito sa loob mismo ng guard (Login event), kaya walang route o middleware na makakalaktaw.
 *
 * Amendment 012-1 (A3): tahasang nakarehistro ang dalawang method sa AppServiceProvider::register(). Sadyang
 * HINDI `handle*` o `__invoke` ang mga pangalan, para hindi rin sila makuha ng event discovery (dobleng takbo)
 * at para hindi umasa ang re-check sa naka-cache na events file.
 */
class RefuseRememberedLoginUnlessCeo
{
    /** Pangalawang cookie ng CEO: "user id|unix timestamp ng password login", naka-encrypt gaya ng ibang cookie. */
    public const SINCE_COOKIE = 'ceo_remember_since';

    /** Tagal ng remembered login, sa minuto: 30 araw mula sa password login. */
    public const REMEMBER_MINUTES = 60 * 24 * 30;

    /** Palugit (segundo) sa oras na nasa hinaharap; lampas dito, hindi galing sa server na ito ang oras. */
    private const FUTURE_TOLERANCE_SECONDS = 300;

    /** Login event: re-check ng sign-in na galing sa remember cookie. */
    public function recheckRememberedLogin(Login $event): void
    {
        $guard = Auth::guard($event->guard);

        // Ang password login ng CEO ay may remember=true rin sa event; viaRemember() lang ang nagsasabing cookie ang pinanggalingan.
        if (! $guard->viaRemember()) {
            return;
        }

        try {
            $accepted = self::isCeo($event->user)
                && self::isFreshFor($event->user->getAuthIdentifier(), request()->cookie(self::SINCE_COOKIE));
        } catch (\Throwable $e) {
            // Fail closed: naisulat na ng guard ang login id sa session bago ang event na ito. Kapag pumalya
            // ang pagbasa ng role (hal. DB error), logout muna bago ibalik ang error, para hindi maiwang
            // naka-sign-in ang session nang walang re-check.
            $guard->logout();

            throw $e;
        }

        if (! $accepted) {
            $guard->logout();
            // Laging binubura ang pangalawang cookie sa pagtanggi, kahit wala ito sa request.
            Cookie::queue(Cookie::forget(self::SINCE_COOKIE));
        }
    }

    /**
     * Logout event: binubura rin ang pangalawang cookie, sa bawat daan ng logout (controller, pagtanggi,
     * fail closed). Kapag wala ito sa request (ibang role), walang idinadagdag sa response.
     */
    public function forgetSinceCookie(Logout $event): void
    {
        if (request()->cookies->has(self::SINCE_COOKIE)) {
            Cookie::queue(Cookie::forget(self::SINCE_COOKIE));
        }
    }

    /**
     * Wasto ba ang pangalawang cookie para sa user na ito? Mahigpit ang basa: eksaktong "digits|digits",
     * parehong user id (bilang string), at edad na 0 hanggang 30 araw. Ang cookie na hindi na-decrypt ay null.
     */
    private static function isFreshFor($userId, $cookie): bool
    {
        if (! is_string($cookie) || ! preg_match('/\A(\d{1,20})\|(\d{1,10})\z/', $cookie, $m)) {
            return false;
        }

        if ($m[1] !== (string) $userId) {
            return false;
        }

        $age = now()->timestamp - (int) $m[2];

        return $age >= -self::FUTURE_TOLERANCE_SECONDS && $age <= self::REMEMBER_MINUTES * 60;
    }

    /**
     * Iisang CEO check para sa remembered login.
     * Pareho ng basa sa OwnerPrivateController::getNormalizedRole(): trim, pinagsamang spaces, hindi case-sensitive.
     * Walang employee profile o walang role = hindi CEO.
     */
    public static function isCeo($user): bool
    {
        $raw  = $user?->employeeProfile?->role ?? '';
        $norm = preg_replace('/\s+/u', ' ', trim((string) $raw));

        return (bool) preg_match('/^ceo$/iu', $norm);
    }
}
