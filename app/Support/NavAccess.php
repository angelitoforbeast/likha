<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pagpasok sa page ayon sa /owner/nav-settings: ang CEO ay laging pasok; ang ibang role ay pasok kapag
 * naka-check ang link ng page para sa role na iyon (aktibong nav link, is_visible). Kaya ang pag-check
 * sa nav-settings ay nagbubukas din ng page, hindi lang ng link sa menu.
 */
class NavAccess
{
    /** @param string|string[] $url ang route_url, o ilan (pasok kapag isa man ang naka-check) */
    public static function allows(?string $role, string|array $url): bool
    {
        $role = preg_replace('/\s+/u', ' ', trim((string) $role));
        if ($role === 'CEO') return true;
        if ($role === '' || !Schema::hasTable('nav_links') || !Schema::hasTable('nav_link_role_visibility')) return false;

        $urls = array_map(fn ($u) => '/' . ltrim($u, '/'), (array) $url);

        return DB::table('nav_links as l')
            ->join('nav_link_role_visibility as v', 'v.nav_link_id', '=', 'l.id')
            ->where('l.is_active', true)
            ->whereIn('l.route_url', array_merge($urls, array_map(fn ($u) => ltrim($u, '/'), $urls)))
            ->where('v.role', $role)
            ->where('v.is_visible', true)
            ->exists();
    }
}
