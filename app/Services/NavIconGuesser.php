<?php

namespace App\Services;

/**
 * Auto-suggest a Font Awesome icon based on a route URL.
 *
 * Used when adding a new nav link via /owner/nav-settings so the admin
 * doesn't have to manually pick an icon. Scans the URL for keywords sa
 * priority order — first match wins. Falls back to a generic link icon.
 *
 * To extend: add new keyword → icon entries sa MAP. Order matters — more
 * specific keywords should appear BEFORE more generic ones (e.g., 'waybill'
 * before 'jnt' since waybills are more specific).
 */
class NavIconGuesser
{
    /**
     * Keyword → FA icon class. Scanned in order; first hit wins. Lowercase
     * comparison against the full normalized URL (slashes → spaces, removed
     * leading slash).
     */
    private const MAP = [
        // Most specific first
        'waybills/files'   => 'fa-solid fa-folder-open',
        'waybills/print'   => 'fa-solid fa-print',
        'waybills'         => 'fa-solid fa-receipt',
        'fee-settings'     => 'fa-solid fa-coins',
        'fee'              => 'fa-solid fa-coins',
        'accounts/mapping' => 'fa-solid fa-diagram-project',
        'page-mapping'     => 'fa-solid fa-diagram-project',
        'mapping'          => 'fa-solid fa-diagram-project',
        'sender'           => 'fa-solid fa-signature',
        'item-types'       => 'fa-solid fa-boxes-packing',
        'item-sender'      => 'fa-solid fa-tag',
        'item'             => 'fa-solid fa-box',
        'excluded'         => 'fa-solid fa-ban',
        'supply'           => 'fa-solid fa-boxes-stacking',
        'orders'           => 'fa-solid fa-truck-fast',
        'order'            => 'fa-solid fa-clipboard-list',
        'shipments'        => 'fa-solid fa-truck',
        'shipment'         => 'fa-solid fa-truck',
        'jnt/checker'      => 'fa-solid fa-circle-check',
        'checker'          => 'fa-solid fa-circle-check',
        'sticker'          => 'fa-solid fa-tags',
        'tally'            => 'fa-solid fa-layer-group',
        'hold'             => 'fa-solid fa-pause',
        'address'          => 'fa-solid fa-location-dot',
        'rts'              => 'fa-solid fa-rotate-left',
        'jnt/config'       => 'fa-solid fa-sliders',
        'config'           => 'fa-solid fa-sliders',
        'jnt'              => 'fa-solid fa-truck',

        // Owner / column / nav settings
        'nav-settings'     => 'fa-solid fa-bars-staggered',
        'column-settings'  => 'fa-solid fa-table-columns',
        'private/daily'    => 'fa-solid fa-calendar-day',
        'private/breakdown'=> 'fa-solid fa-chart-area',
        'owner/private'    => 'fa-solid fa-chart-pie',
        'private'          => 'fa-solid fa-lock',
        'overall'          => 'fa-solid fa-chart-pie',
        'summary'          => 'fa-solid fa-clipboard-list',
        'report'           => 'fa-solid fa-bullhorn',
        'cpp'              => 'fa-solid fa-chart-line',
        'campaign'         => 'fa-solid fa-bullhorn',
        'payment'          => 'fa-solid fa-credit-card',
        'finance'          => 'fa-solid fa-wallet',

        // Ads-related
        'ads_manager'      => 'fa-solid fa-bullhorn',
        'ads-manager'      => 'fa-solid fa-bullhorn',
        'ads'              => 'fa-solid fa-bullhorn',
        'gpt'              => 'fa-solid fa-robot',
        'ai'               => 'fa-solid fa-robot',
        'generator'        => 'fa-solid fa-wand-magic-sparkles',

        // Conversation / messaging
        'conversation'     => 'fa-solid fa-comments',
        'message'          => 'fa-solid fa-comments',
        'chat'             => 'fa-solid fa-comments',

        // Encoder / data entry
        'mes-segregator'   => 'fa-solid fa-scissors',
        'mes'              => 'fa-solid fa-scissors',
        'encoder'          => 'fa-solid fa-keyboard',
        'pending'          => 'fa-solid fa-hourglass-half',
        'pending-rate'     => 'fa-solid fa-hourglass-half',

        // Tasks / queue
        'queue'            => 'fa-solid fa-list-check',
        'task'             => 'fa-solid fa-list-check',
        'tasks'            => 'fa-solid fa-list-check',
        'job'              => 'fa-solid fa-gear',
        'run'              => 'fa-solid fa-play',

        // Users / roles
        'role'             => 'fa-solid fa-user-gear',
        'roles'            => 'fa-solid fa-user-gear',
        'assign-roles'     => 'fa-solid fa-user-gear',
        'user'             => 'fa-solid fa-user',
        'users'            => 'fa-solid fa-users',
        'profile'          => 'fa-solid fa-id-card',
        'login'            => 'fa-solid fa-right-to-bracket',
        'logout'           => 'fa-solid fa-right-from-bracket',

        // Pancake (FB chat shop platform)
        'pancake/retrieve' => 'fa-solid fa-download',
        'pancake/page-id'  => 'fa-solid fa-id-badge',
        'pancake'          => 'fa-solid fa-fire',

        // Likha (sheet imports)
        'likha_order'      => 'fa-solid fa-store',
        'likha'            => 'fa-solid fa-store',

        // Generic but common
        'macro'            => 'fa-solid fa-table',
        'gsheet'           => 'fa-solid fa-table',
        'sheet'            => 'fa-solid fa-table',
        'import'           => 'fa-solid fa-file-import',
        'export'           => 'fa-solid fa-file-export',
        'upload'           => 'fa-solid fa-cloud-arrow-up',
        'download'         => 'fa-solid fa-cloud-arrow-down',
        'allowed-ips'      => 'fa-solid fa-network-wired',
        'ip'               => 'fa-solid fa-network-wired',
        'allowed'          => 'fa-solid fa-shield-check',
        'history'          => 'fa-solid fa-clock-rotate-left',
        'logs'             => 'fa-solid fa-scroll',
        'log'              => 'fa-solid fa-scroll',
        'checklist'        => 'fa-solid fa-list-check',
        'subscription'     => 'fa-solid fa-bag-shopping',
        'purchase'         => 'fa-solid fa-bag-shopping',
        'data_encoder'     => 'fa-solid fa-keyboard',
        'data-encoder'     => 'fa-solid fa-keyboard',
        'data'             => 'fa-solid fa-database',
        'setting'          => 'fa-solid fa-gear',
        'admin'            => 'fa-solid fa-user-shield',
        'dashboard'        => 'fa-solid fa-gauge',
        'home'             => 'fa-solid fa-house',
    ];

    /** Generic fallback when no keyword matches. */
    private const DEFAULT_ICON = 'fa-solid fa-link';

    /**
     * Guess the best FA icon for a route URL. Lowercases + normalizes the URL
     * (strips leading slash, collapses repeated slashes) before keyword scan.
     */
    public static function guess(string $url): string
    {
        $normalized = strtolower(trim($url, '/'));
        // Collapse any '-' or '_' runs into single separator so matches like
        // 'item-sender-name' hit 'item-sender' key.
        $haystack = '/' . preg_replace('/[\/\s]+/', '/', $normalized) . '/';

        foreach (self::MAP as $keyword => $icon) {
            // Try both raw and a "/" -bounded contains so 'jnt' doesn't match
            // 'adjnt' (made-up edge case) and 'item' isn't grabbed too greedily.
            if (str_contains($haystack, $keyword)) {
                return $icon;
            }
        }

        return self::DEFAULT_ICON;
    }
}
