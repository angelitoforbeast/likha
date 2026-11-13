<?php

namespace App\Services;

use App\Http\Controllers\NavSettingsController;
use App\Models\User;
use App\Support\NavAccess;
use Illuminate\Support\Facades\Cache;

/**
 * Who may see the server storage, and the banner on every `<x-layout>` page when the disk of `/` is 80 % used or more.
 *
 * The disk filled up once and took Likha down for hours without anyone seeing it coming; the banner makes the people
 * who watch storage see it in time. Who they are is the "Storage" link in /owner/nav-settings, like every other page.
 */
class StorageWarning
{
    public const AMBER_PERCENT = 80;

    public const RED_PERCENT = 90;

    /** The Storage link's route_url in nav settings. */
    public const URL = '/system-monitor/storage';

    /** Long enough that a page view almost never touches the disk functions, short enough to follow a cleanup. */
    private const CACHE_SECONDS = 60;

    private const CACHE_KEY = 'storage_warning_disk';

    private const ACCESS_CACHE_KEY = 'storage_warning_access:';

    /** The Storage link is checked for this user's actual role (not the CEO's "view as"); CEO always. */
    public static function canSee(?User $user): bool
    {
        return NavAccess::allows(self::role($user), self::URL);
    }

    /**
     * The banner for this user, or null: under 80 %, the disk could not be read, or the role may not open the Storage
     * view. It is on every page, so it never throws, the disk is checked first (no query at all while under 80 %), and
     * both the disk numbers and the role's access answer come from a 60 s cache.
     *
     * @return array{percent:int, free_gb:string, tone:string, text:string}|null
     */
    public static function banner(?User $user): ?array
    {
        try {
            // A failed read is cached as false, so a broken mount is not retried on every page view.
            $disk = Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => app(ServerStorage::class)->disk() ?? false);
            if (!is_array($disk) || $disk['percent'] < self::AMBER_PERCENT) {
                return null;
            }

            $role = self::role($user);
            if (!Cache::remember(self::ACCESS_CACHE_KEY . md5($role), self::CACHE_SECONDS, fn () => NavAccess::allows($role, self::URL))) {
                return null;
            }

            // Rounded down, so the shown number never reaches the next threshold before the colour does.
            $percent = (int) floor($disk['percent']);
            $freeGb  = number_format($disk['free'] / 1024 ** 3, 1);

            return [
                'percent' => $percent,
                'free_gb' => $freeGb,
                'tone'    => $disk['percent'] >= self::RED_PERCENT ? 'red' : 'amber',
                'text'    => "Server storage is at {$percent}% ({$freeGb} GB free).",
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Drop the cached access answers, so a change saved in nav settings shows on the next page view rather than up to
     * 60 s later. Only the nav settings roles can have a checked row, so only their answers can change.
     */
    public static function forgetAccess(): void
    {
        foreach (NavSettingsController::ROLES as $role) {
            Cache::forget(self::ACCESS_CACHE_KEY . md5($role));
        }
    }

    /** The actual role, with spaces normalized as NavAccess does, so one cache entry serves every spelling of it. */
    private static function role(?User $user): string
    {
        return (string) preg_replace('/\s+/u', ' ', trim((string) ($user?->employeeProfile?->role ?? '')));
    }
}
