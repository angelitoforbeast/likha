<?php

namespace App\Support;

use App\Models\NavLink;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What the sidebar and the Ctrl+K palette show (spec 295): the links a role sees today (NavLink::visibleFor, with the
 * one Chatblast link of ChatblastNav::mainLinks), grouped by NavSections, and the person's pins among them.
 */
class NavMenu
{
    public const MAX_PINS = 6;

    /** Links shown to a role, in nav order. Empty for no role, and when the nav tables cannot be read. */
    public static function links(?string $role): Collection
    {
        if (!$role) return collect();
        try {
            return ChatblastNav::mainLinks(NavLink::visibleFor($role));
        } catch (\Throwable $e) {
            return collect();
        }
    }

    /**
     * The user's pins that are among $links, in their order, at most six. A pin to a link the user can no longer see
     * is dropped here, silently.
     *
     * @return int[]
     */
    public static function pins(?User $user, Collection $links): array
    {
        $saved = $user?->nav_pins;
        if (!is_array($saved)) return [];
        $visible = $links->pluck('id')->map(fn ($id) => (int) $id)->flip();

        return collect($saved)->map(fn ($id) => (int) $id)->filter(fn ($id) => $visible->has($id))
            ->unique()->take(self::MAX_PINS)->values()->all();
    }

    /** One entry per link for the palette (and the pinned group, which the browser draws). */
    public static function entries(Collection $links): array
    {
        return $links->map(fn ($link) => [
            'id' => (int) $link->id,
            'label' => (string) $link->label,
            'section' => NavSections::of($link),
            'url' => (string) $link->route_url,
            'icon' => $link->icon ?: 'fa-solid fa-link',
        ])->values()->all();
    }
}
