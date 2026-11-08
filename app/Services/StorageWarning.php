<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Who may see the server storage, and the banner on every `<x-layout>` page when the disk of `/` is 80 % used or more.
 *
 * The disk filled up once and took Likha down for hours without anyone seeing it coming; the banner makes the
 * CEO and the marketing OIC see it in time.
 */
class StorageWarning
{
    public const AMBER_PERCENT = 80;

    public const RED_PERCENT = 90;

    /** Long enough that a page view almost never touches the disk functions, short enough to follow a cleanup. */
    private const CACHE_SECONDS = 60;

    private const CACHE_KEY = 'storage_warning_disk';

    /** CEO or Marketing - OIC by the actual role (not the CEO's "view as"), normalized like checkCEO. */
    public static function canSee(?User $user): bool
    {
        $raw  = $user?->employeeProfile?->role ?? '';
        $norm = preg_replace('/\s+/u', ' ', trim((string) $raw));

        return preg_match('/^(ceo|marketing - oic)$/iu', $norm) === 1;
    }

    /**
     * The banner for this user, or null: other roles, under 80 %, or the disk could not be read. It is on every
     * page, so it never throws and only the cached disk numbers feed it (no directory scan, no database query).
     *
     * @return array{percent:int, free_gb:string, tone:string, text:string}|null
     */
    public static function banner(?User $user): ?array
    {
        try {
            if (!self::canSee($user)) {
                return null;
            }

            // A failed read is cached as false, so a broken mount is not retried on every page view.
            $disk = Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => app(ServerStorage::class)->disk() ?? false);
            if (!is_array($disk) || $disk['percent'] < self::AMBER_PERCENT) {
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
}
