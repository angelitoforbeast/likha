<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Auth;

/**
 * Handoff 012 (D3): ang sign-in na galing sa remember cookie (hindi sa password) ay para lang sa account
 * na CEO pa rin sa oras na iyon. Kung hindi na CEO (pinalitan ang role, binura ang employee profile),
 * tinatanggihan: logout agad, kaya nabubura ang cookie at napapalitan ang remember token.
 *
 * Tumatakbo ito sa loob mismo ng guard (Login event), kaya walang route o middleware na makakalaktaw.
 */
class RefuseRememberedLoginUnlessCeo
{
    public function handle(Login $event): void
    {
        $guard = Auth::guard($event->guard);

        // Ang password login ng CEO ay may remember=true rin sa event; viaRemember() lang ang nagsasabing cookie ang pinanggalingan.
        if ($guard->viaRemember() && ! self::isCeo($event->user)) {
            $guard->logout();
        }
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
