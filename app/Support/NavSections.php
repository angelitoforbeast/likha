<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * The sidebar's sections (spec 295): their order and icon live here, not in the table. A link's section is the one
 * stored in nav_links.section when it is one of these; otherwise the one its route_url has in the inventory below (a
 * link added later by a migration that does not know the column still lands in its place); otherwise More.
 */
class NavSections
{
    /** Section => Font Awesome icon, in the order of the business day. More is always last. */
    public const ICONS = [
        'Home' => 'fa-solid fa-house',
        'Orders' => 'fa-solid fa-inbox',
        'Shipping' => 'fa-solid fa-truck-fast',
        'Marketing' => 'fa-solid fa-bullhorn',
        'Chat' => 'fa-solid fa-comments',
        'AI' => 'fa-solid fa-wand-magic-sparkles',
        'Money' => 'fa-solid fa-wallet',
        'Settings' => 'fa-solid fa-gear',
        'More' => 'fa-solid fa-ellipsis',
    ];

    public const MORE = 'More';

    /** The spec's inventory, route_url => section. The migration 2026_11_27_100000 froze a copy of it. */
    public const BY_URL = [
        '/checklist' => 'Home',
        '/likha_order_import' => 'Orders', '/macro/gsheet/import' => 'Orders', '/encoder/summary' => 'Orders',
        '/encoder/checker_1' => 'Orders', '/encoder/pending-rate' => 'Orders', '/encoder/timetable' => 'Orders',
        '/data_encoder/mes-segregator' => 'Orders', '/jnt/address' => 'Orders',
        '/jnt_upload' => 'Shipping', '/jnt_upload_v2' => 'Shipping', '/encoded_vs_upload' => 'Shipping', '/jnt/stickers' => 'Shipping',
        '/jnt/checker' => 'Shipping', '/jnt/hold' => 'Shipping', '/jnt_rts' => 'Shipping', '/jnt/supply' => 'Shipping',
        '/pancake/retrieve2' => 'Shipping',
        '/ads_manager/report' => 'Marketing', '/ads_manager/cpp' => 'Marketing', '/ads_manager/daily-output' => 'Marketing',
        '/ads_manager/payment/upload' => 'Marketing', '/ads_manager/pancake-subscription-checker' => 'Marketing',
        '/conversation/tracker' => 'Marketing', '/owner/private' => 'Marketing', '/playbook' => 'Marketing',
        '/prompt-generator' => 'Marketing',
        '/chatbot' => 'Chat', '/chatblast' => 'Chat', '/chatblast/page-settings' => 'Chat', '/page-settings' => 'Chat',
        '/chatbot/botcake-accounts' => 'Chat', '/pancake/page-id-mapping' => 'Chat', '/chat' => 'Chat',
        '/checklist/ai-followups' => 'AI', '/astra' => 'AI', '/boardroom' => 'AI',
        '/finance' => 'Money', '/finance/supply' => 'Money',
        '/assign-roles' => 'Settings', '/owner/users' => 'Settings', '/owner/nav-settings' => 'Settings',
        '/owner/column-settings' => 'Settings', '/allowed-ips' => 'Settings', '/jnt/config' => 'Settings',
        '/jnt/fee-settings' => 'Settings', '/jnt/supply/excluded-pages' => 'Settings', '/queue-manager' => 'Settings',
        '/system-monitor/storage' => 'Settings', '/owner/access-report' => 'Settings',
    ];

    /** @return string[] */
    public static function names(): array
    {
        return array_keys(self::ICONS);
    }

    public static function isKnown(?string $section): bool
    {
        return $section !== null && isset(self::ICONS[$section]);
    }

    /** The section of a url from the inventory, More when it is not listed. */
    public static function forUrl(?string $url): string
    {
        return self::BY_URL['/' . trim((string) $url, '/')] ?? self::MORE;
    }

    /** The section a link is shown in. */
    public static function of(object $link): string
    {
        $stored = $link->section ?? null;

        return self::isKnown($stored) ? $stored : self::forUrl($link->route_url ?? null);
    }

    /**
     * The links grouped for the sidebar: only sections that hold a link, in section order; links keep their order.
     *
     * @return array<int, array{name: string, icon: string, links: Collection}>
     */
    public static function group(Collection $links): array
    {
        $bySection = $links->groupBy(fn ($link) => self::of($link));
        $out = [];
        foreach (self::ICONS as $name => $icon) {
            if ($bySection->has($name)) $out[] = ['name' => $name, 'icon' => $icon, 'links' => $bySection[$name]->values()];
        }

        return $out;
    }
}
