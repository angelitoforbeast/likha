<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Ang nav ng Chatblast app (To send, History, Messages, Sequences, Chats, Page settings) at ang iisang Chatblast link sa main
 * nav ng Likha. Ang access ay galing sa /owner/nav-settings (NavAccess): /chatblast para sa unang lima, at ang link ng page
 * settings sa luma o bagong path nito.
 */
class ChatblastNav
{
    /** Ang route_url ng page settings sa nav-settings: ang luma (bago lumipat sa Chatblast) at ang bago. */
    public const PAGE_SETTINGS = ['/page-settings', '/chatblast/page-settings'];

    public static function allowsPageSettings(?string $role): bool
    {
        return NavAccess::allows($role, self::PAGE_SETTINGS);
    }

    /**
     * Ang mga item na mabubuksan ng role, may `active` ayon sa kasalukuyang request.
     *
     * @return array<int, array{label: string, url: string, active: bool}>
     */
    public static function items(?string $role): array
    {
        $items = [];
        if (NavAccess::allows($role, '/chatblast')) {
            $items[] = ['label' => 'To send', 'url' => '/chatblast', 'active' => request()->is('chatblast', 'chatblast/messages/*')];
            $items[] = ['label' => 'History', 'url' => '/chatblast/history', 'active' => request()->is('chatblast/history')];
            $items[] = ['label' => 'Messages', 'url' => '/chatblast/campaigns', 'active' => request()->is('chatblast/campaigns*')];
            $items[] = ['label' => 'Sequences', 'url' => '/chatblast/sequence', 'active' => request()->is('chatblast/sequence*')];
            $items[] = ['label' => 'Chats', 'url' => '/chatblast/chats', 'active' => request()->is('chatblast/chats*')];
        }
        if (self::allowsPageSettings($role)) {
            $items[] = ['label' => 'Page settings', 'url' => '/chatblast/page-settings', 'active' => request()->is('chatblast/page-settings*')];
        }

        return $items;
    }

    /**
     * Ang main nav ng Likha na iisa na ang Chatblast at Page Settings: kapag nakikita ang Chatblast link, tinatanggal
     * ang page settings; kapag page settings lang, ito ang nagiging "Chatblast" link papunta sa page settings (ang
     * /chatblast ay 404 sa role na iyon). Sa memory lang: walang isinusulat sa nav_links.
     */
    public static function mainLinks(Collection $links): Collection
    {
        $path = fn ($link) => '/' . ltrim((string) $link->route_url, '/');
        $isPageSettings = fn ($link) => in_array($path($link), self::PAGE_SETTINGS, true);
        $hasChatblast = $links->contains(fn ($link) => $path($link) === '/chatblast');

        $shown = false;
        return $links->filter(function ($link) use ($isPageSettings, $hasChatblast, &$shown) {
            if (!$isPageSettings($link)) return true;
            if ($hasChatblast || $shown) return false;
            return $shown = true;
        })->map(function ($link) use ($isPageSettings) {
            if (!$isPageSettings($link)) return $link;
            $link = clone $link;
            $link->label = 'Chatblast';
            $link->route_url = '/chatblast/page-settings';
            $link->active_pattern = 'chatblast*';
            return $link;
        })->values();
    }
}
