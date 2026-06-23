<?php

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Auth;

/**
 * Single source of truth para sa AI Checker / AI Fix access.
 *
 * Allowed = default roles (CEO / Marketing / Marketing - OIC)
 *           O nasa extra allowlist (per-user, set sa /encoder/checker_1/ai-checker/access).
 *
 * Allowlist naka-store sa app_settings['ai_checker_allowed_user_ids'] (JSON array ng user IDs).
 */
class AiCheckerAccess
{
    private const KEY = 'ai_checker_allowed_user_ids';

    /** Default na roles na laging pwede. */
    public static function roleAllowed(?string $role): bool
    {
        $r = preg_replace('/\s+/u', ' ', trim((string) $role));
        return (bool) preg_match('/^(ceo|marketing|marketing\s*[-–—]\s*oic)$/iu', $r);
    }

    /** Extra na pinayagang user IDs (bukod sa default roles). */
    public static function allowedUserIds(): array
    {
        $raw = AppSetting::get(self::KEY, '[]');
        $arr = json_decode((string) $raw, true);
        if (!is_array($arr)) return [];
        return array_values(array_unique(array_map('intval', $arr)));
    }

    /** I-save ang extra allowlist (user IDs). */
    public static function setAllowedUserIds(array $ids): void
    {
        $clean = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            fn ($v) => $v > 0
        )));
        AppSetting::set(self::KEY, json_encode($clean));
    }

    /**
     * Pwede ba ang kasalukuyang user sa AI Checker?
     * Role-based (honors CEO "View as <role>") O nasa extra allowlist (actual user).
     */
    public static function allows(): bool
    {
        $u = Auth::user();
        if (!$u) return false;

        $actualRole = $u->employeeProfile?->role ?? null;
        $viewAs     = ($actualRole === 'CEO') ? session('nav_view_as_role') : null;
        $effective  = $viewAs ?: $actualRole;

        if (self::roleAllowed($effective)) return true;

        return in_array((int) $u->id, self::allowedUserIds(), true);
    }
}
