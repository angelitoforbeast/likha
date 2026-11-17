<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The one page-access rule (spec 294), in order: the CEO is allowed; a path on the open list (config/access.php) is
 * allowed; a path a nav link covers (its active_pattern, else its route_url as a prefix; the longest match wins) is
 * allowed when that link is visible for the role (nav_link_role_visibility, the role trimmed and its spaces
 * collapsed as NavAccess does); a path no link covers is `unmapped`.
 *
 * The mode, the nav map and the table checks are read at most once per request and cached for 60 seconds.
 */
class AccessRule
{
    public const ALLOW = 'allow';
    public const WOULD_BLOCK = 'would_block';
    public const UNMAPPED = 'unmapped';

    public const RECORD = 'record';
    public const ENFORCE = 'enforce';

    private const CACHE_KEY = 'access_rule:state:v1';
    private const CACHE_SECONDS = 60;

    /** @var array{mode: string, links: array<int, array>, records: bool}|null this request's copy */
    private ?array $state = null;

    public static function normalizeRole(?string $role): string
    {
        return preg_replace('/\s+/u', ' ', trim((string) $role));
    }

    public static function isCeo(?string $role): bool
    {
        return strtoupper(trim((string) $role)) === 'CEO';
    }

    /** `record` (default) or `enforce`, from app_settings `access.mode`. */
    public function mode(): string
    {
        return $this->state()['mode'];
    }

    public function isOpen(string $path): bool
    {
        $path = $this->normalizePath($path);
        foreach ((array) config('access.open', []) as $pattern) {
            if ($path === $pattern || Str::is($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The nav link that covers a path (longest match), or null. Links with the same match length are merged:
     * visible when any of them is visible, as NavAccess allows when any listed link is checked.
     *
     * @return array{label: string, roles: string[]}|null
     */
    public function linkFor(string $path): ?array
    {
        $path = $this->normalizePath($path);
        $best = null;
        $bestLength = -1;
        foreach ($this->state()['links'] as $link) {
            $length = $this->matchLength($link, $path);
            if ($length === null || $length < $bestLength) {
                continue;
            }
            if ($length > $bestLength) {
                $best = ['label' => $link['label'], 'roles' => $link['roles']];
                $bestLength = $length;
            } else {
                $best['roles'] = array_values(array_unique(array_merge($best['roles'], $link['roles'])));
            }
        }

        return $best;
    }

    /** One of ALLOW, WOULD_BLOCK, UNMAPPED. */
    public function decide(?string $role, string $path): string
    {
        if (self::isCeo($role) || $this->isOpen($path)) {
            return self::ALLOW;
        }

        $link = $this->linkFor($path);
        if ($link === null) {
            return self::UNMAPPED;
        }

        $role = mb_strtolower(self::normalizeRole($role));

        return $role !== '' && in_array($role, $link['roles'], true) ? self::ALLOW : self::WOULD_BLOCK;
    }

    /** Counts one would-block or unmapped view: one row per user, pattern, decision and Manila day. */
    public function record(int $userId, ?string $role, string $pathPattern, string $decision): void
    {
        if (!$this->state()['records']) {
            return;
        }

        $now = now();
        DB::table('access_records')->upsert(
            [[
                'user_id'      => $userId,
                'role'         => mb_substr(self::normalizeRole($role), 0, 100),
                'path_pattern' => mb_substr($pathPattern, 0, 255),
                'decision'     => $decision,
                'day'          => $now->copy()->setTimezone('Asia/Manila')->toDateString(),
                'hits'         => 1,
                'last_at'      => $now,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]],
            ['user_id', 'path_pattern', 'decision', 'day'],
            ['hits' => DB::raw('access_records.hits + 1'), 'last_at' => $now, 'updated_at' => $now]
        );
    }

    /** Drops the cached mode and nav map (after a mode or nav change, or in tests). */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function state(): array
    {
        return $this->state ??= Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => $this->load());
    }

    private function load(): array
    {
        $mode = self::RECORD;
        if (Schema::hasTable('app_settings')) {
            // pluck, not value(): without "limit 1" this read never shares its SQL text with the other app_settings
            // reads (some tests tell a page's own queries apart by their SQL text).
            $value = DB::table('app_settings')->where('key', 'access.mode')->pluck('value')->first();
            if (strtolower(trim((string) $value)) === self::ENFORCE) {
                $mode = self::ENFORCE;
            }
        }

        $links = [];
        if (Schema::hasTable('nav_links') && Schema::hasTable('nav_link_role_visibility')) {
            $rows = DB::table('nav_links as l')
                ->leftJoin('nav_link_role_visibility as v', function ($j) {
                    $j->on('v.nav_link_id', '=', 'l.id')->where('v.is_visible', true);
                })
                ->where('l.is_active', true)
                ->orderBy('l.id')
                ->get(['l.id', 'l.label', 'l.route_url', 'l.active_pattern', 'v.role']);
            foreach ($rows as $row) {
                $links[$row->id] ??= [
                    'label'   => (string) $row->label,
                    'pattern' => ltrim(trim((string) $row->active_pattern), '/'),
                    'url'     => trim(trim((string) $row->route_url), '/'),
                    'roles'   => [],
                ];
                if ($row->role !== null) {
                    $links[$row->id]['roles'][] = mb_strtolower(self::normalizeRole($row->role));
                }
            }
        }

        return ['mode' => $mode, 'links' => array_values($links), 'records' => Schema::hasTable('access_records')];
    }

    /** How specific the link's match is (longer wins), or null when it does not cover the path. */
    private function matchLength(array $link, string $path): ?int
    {
        if ($link['pattern'] !== '') {
            return Str::is($link['pattern'], $path) ? strlen(rtrim($link['pattern'], '*')) : null;
        }
        if ($link['url'] === '') {
            return $path === '/' ? 0 : null;
        }

        // route_url as a prefix, on whole path segments (so /jnt does not cover /jnt_upload).
        return $path === $link['url'] || str_starts_with($path, $link['url'] . '/') ? strlen($link['url']) : null;
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path, '/');

        return $path === '' ? '/' : $path;
    }
}
