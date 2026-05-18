<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NavLink extends Model
{
    protected $fillable = [
        'key', 'label', 'route_url', 'icon', 'active_pattern', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function roleVisibility(): HasMany
    {
        return $this->hasMany(NavLinkRoleVisibility::class);
    }

    /**
     * Get the ordered list of nav links visible to a given role. Used by the
     * layout to render the top nav. Returns Eloquent Collection of NavLink models.
     */
    public static function visibleFor(?string $role)
    {
        if (!$role) return collect();

        return static::query()
            ->where('is_active', true)
            ->whereHas('roleVisibility', function ($q) use ($role) {
                $q->where('role', $role)->where('is_visible', true);
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Canonical default catalog for nav links. Mirrors the seed in migration
     * 2026_05_18_140000_create_nav_links_and_visibility_tables.php — kept here
     * so the Reset button (NavSettingsController::reset) can re-apply defaults
     * without re-running the migration. Edit BOTH places if adding a new link
     * to be auto-seeded, OR move new links via /owner/nav-settings UI only.
     *
     * `visible` lists roles that get is_visible=true by default. All other
     * (catalog) roles get is_visible=false. `sort_order` derived from array
     * position (1, 2, 3...) — clean sequential.
     */
    public static function defaultData(): array
    {
        return [
            ['key'=>'ads_payment',           'label'=>'Ad Payment',         'route_url'=>'/ads_manager/payment/upload',           'icon'=>'fa-solid fa-credit-card',     'active_pattern'=>'ads_manager/payment*',           'visible'=>['Marketing','Marketing - OIC','CEO']],
            ['key'=>'ads',                   'label'=>'Ads',                'route_url'=>'/ads_manager/report',                   'icon'=>'fa-solid fa-bullhorn',         'active_pattern'=>'ads_manager/report*',            'visible'=>['Marketing','Marketing - OIC','CEO']],
            ['key'=>'marketing_summary',     'label'=>'Marketing Summary',  'route_url'=>'/owner/private',                        'icon'=>'fa-solid fa-chart-pie',        'active_pattern'=>'owner/private*',                 'visible'=>['Marketing','Marketing - OIC','CEO']],
            ['key'=>'likha',                 'label'=>'Likha',              'route_url'=>'/likha_order_import',                   'icon'=>'fa-solid fa-store',            'active_pattern'=>'likha_order_import*',            'visible'=>['Marketing','Marketing - OIC','CEO']],
            ['key'=>'conv_tracker',          'label'=>'Conv Tracker',       'route_url'=>'/conversation/tracker',                 'icon'=>'fa-solid fa-comments',         'active_pattern'=>'conversation/tracker*',          'visible'=>['Marketing','Marketing - OIC','CEO']],
            ['key'=>'macro',                 'label'=>'Macro',              'route_url'=>'/macro/gsheet/import',                  'icon'=>'fa-solid fa-table',            'active_pattern'=>'macro/gsheet/*',                 'visible'=>['Marketing','Marketing - OIC','CEO','Data Encoder - OIC']],
            ['key'=>'waybill',               'label'=>'Waybill',            'route_url'=>'/jnt_upload',                           'icon'=>'fa-solid fa-receipt',          'active_pattern'=>'jnt_upload*',                    'visible'=>['Marketing','Marketing - OIC','CEO']],
            ['key'=>'cpp',                   'label'=>'CPP',                'route_url'=>'/ads_manager/cpp',                      'icon'=>'fa-solid fa-chart-line',       'active_pattern'=>'ads_manager/cpp*',               'visible'=>['Marketing','Marketing - OIC','CEO']],
            ['key'=>'rts',                   'label'=>'RTS',                'route_url'=>'/jnt_rts',                              'icon'=>'fa-solid fa-rotate-left',      'active_pattern'=>'jnt_rts*',                       'visible'=>['Marketing','Marketing - OIC','CEO']],
            ['key'=>'supply',                'label'=>'Supply',             'route_url'=>'/jnt/supply',                           'icon'=>'fa-solid fa-boxes-stacking',   'active_pattern'=>'jnt/supply*',                    'visible'=>['Marketing','Marketing - OIC','CEO']],
            ['key'=>'jnt_checker',           'label'=>'JNT Checker',        'route_url'=>'/jnt/checker',                          'icon'=>'fa-solid fa-circle-check',     'active_pattern'=>'jnt/checker*',                   'visible'=>['Marketing','Marketing - OIC','CEO']],
            ['key'=>'tally_sticker',         'label'=>'Tally Sticker',      'route_url'=>'/encoded_vs_upload',                    'icon'=>'fa-solid fa-layer-group',      'active_pattern'=>'encoded_vs_upload*',             'visible'=>['Marketing','Marketing - OIC','CEO']],
            ['key'=>'tally_sticker_2',       'label'=>'Tally Sticker 2',    'route_url'=>'/jnt/stickers',                         'icon'=>'fa-solid fa-tags',             'active_pattern'=>'jnt/stickers*',                  'visible'=>['Marketing','Marketing - OIC','CEO']],
            ['key'=>'checker_1',             'label'=>'Checker 1',          'route_url'=>'/encoder/checker_1',                    'icon'=>'fa-solid fa-magnifying-glass', 'active_pattern'=>'encoder/checker_1*',             'visible'=>['Marketing','Marketing - OIC','CEO','Data Encoder','Data Encoder - OIC']],
            ['key'=>'order_summary',         'label'=>'Order Summary',      'route_url'=>'/encoder/summary',                      'icon'=>'fa-solid fa-clipboard-list',   'active_pattern'=>'encoder/summary*',               'visible'=>['Marketing','Marketing - OIC','CEO']],
            ['key'=>'purchases',             'label'=>'Purchases',          'route_url'=>'/ads_manager/pancake-subscription-checker', 'icon'=>'fa-solid fa-bag-shopping',  'active_pattern'=>'ads_manager/pancake-subscription-checker*', 'visible'=>['Marketing','Marketing - OIC','CEO']],
            ['key'=>'hold',                  'label'=>'Hold',               'route_url'=>'/jnt/hold',                             'icon'=>'fa-solid fa-pause',            'active_pattern'=>'jnt/hold*',                      'visible'=>['Marketing','Marketing - OIC','CEO']],
            ['key'=>'retrieve',              'label'=>'Retrieve',           'route_url'=>'/pancake/retrieve2',                    'icon'=>'fa-solid fa-download',         'active_pattern'=>'pancake/retrieve2*',             'visible'=>['Marketing','Marketing - OIC','CEO']],
            ['key'=>'pancake_id',            'label'=>'Pancake ID',         'route_url'=>'/pancake/page-id-mapping',              'icon'=>'fa-solid fa-id-badge',         'active_pattern'=>'pancake/page-id-mapping*',       'visible'=>['Marketing','Marketing - OIC','CEO']],
            ['key'=>'mes_seg',               'label'=>'MES SEG',            'route_url'=>'/data_encoder/mes-segregator',          'icon'=>'fa-solid fa-scissors',         'active_pattern'=>'data_encoder/mes-segregator*',   'visible'=>['Data Encoder','Data Encoder - OIC']],
            ['key'=>'address_search',        'label'=>'Address Search',     'route_url'=>'/jnt/address',                          'icon'=>'fa-solid fa-location-dot',     'active_pattern'=>'jnt/address*',                   'visible'=>['Data Encoder','Data Encoder - OIC']],
            ['key'=>'roles',                 'label'=>'Roles',              'route_url'=>'/assign-roles',                         'icon'=>'fa-solid fa-user-gear',        'active_pattern'=>'assign-roles*',                  'visible'=>['CEO']],
            ['key'=>'finance',               'label'=>'Finance',            'route_url'=>'/finance',                              'icon'=>'fa-solid fa-wallet',           'active_pattern'=>'finance*',                       'visible'=>['CEO']],
            ['key'=>'waybill_v2',            'label'=>'Waybill V2',         'route_url'=>'/jnt_upload_v2',                        'icon'=>'fa-solid fa-layer-group',      'active_pattern'=>'jnt_upload_v2*',                 'visible'=>['Marketing - OIC','CEO']],
            ['key'=>'queue',                 'label'=>'Queue',              'route_url'=>'/queue-manager',                        'icon'=>'fa-solid fa-list-check',       'active_pattern'=>'queue-manager*',                 'visible'=>['Marketing - OIC','CEO']],
            ['key'=>'ip',                    'label'=>'IP',                 'route_url'=>'/allowed-ips',                          'icon'=>'fa-solid fa-network-wired',    'active_pattern'=>'allowed-ips*',                   'visible'=>['CEO','Marketing - OIC','Marketing','Data Encoder','Data Encoder - OIC']],
            ['key'=>'pending_rate',          'label'=>'Pending Rate',       'route_url'=>'/encoder/pending-rate',                 'icon'=>'fa-solid fa-hourglass-half',   'active_pattern'=>'encoder/pending-rate*',          'visible'=>['Data Encoder - OIC']],
            // JNT config pages — all CEO-only, hidden from top nav (use the JNT Config hub instead)
            ['key'=>'jnt_accounts',          'label'=>'JNT Accounts',       'route_url'=>'/jnt/accounts',                         'icon'=>'fa-solid fa-building',         'active_pattern'=>'jnt/accounts',                   'visible'=>[]],
            ['key'=>'jnt_acct_mapping',      'label'=>'JNT Acct Mapping',   'route_url'=>'/jnt/accounts/mapping',                 'icon'=>'fa-solid fa-diagram-project',  'active_pattern'=>'jnt/accounts/mapping',           'visible'=>[]],
            ['key'=>'jnt_sender_name',       'label'=>'JNT Sender',         'route_url'=>'/jnt/sender-name',                      'icon'=>'fa-solid fa-signature',        'active_pattern'=>'jnt/sender-name*',               'visible'=>[]],
            ['key'=>'jnt_item_sender',       'label'=>'JNT Item Sender',    'route_url'=>'/jnt/item-sender-name',                 'icon'=>'fa-solid fa-tag',              'active_pattern'=>'jnt/item-sender-name*',          'visible'=>[]],
            ['key'=>'jnt_item_types',        'label'=>'JNT Item Types',     'route_url'=>'/jnt/item-types',                       'icon'=>'fa-solid fa-boxes-packing',    'active_pattern'=>'jnt/item-types*',                'visible'=>[]],
            ['key'=>'jnt_orders',            'label'=>'JNT Orders',         'route_url'=>'/jnt/orders',                           'icon'=>'fa-solid fa-truck-fast',       'active_pattern'=>'jnt/orders*',                    'visible'=>[]],
            ['key'=>'jnt_waybills_print',    'label'=>'Waybills Print',     'route_url'=>'/jnt/waybills/print',                   'icon'=>'fa-solid fa-print',            'active_pattern'=>'jnt/waybills*',                  'visible'=>[]],
            ['key'=>'jnt_fee_settings',      'label'=>'JNT Fee Settings',   'route_url'=>'/jnt/fee-settings',                     'icon'=>'fa-solid fa-coins',            'active_pattern'=>'jnt/fee-settings*',              'visible'=>['CEO']],
            ['key'=>'jnt_supply_excluded',   'label'=>'Supply Excluded',    'route_url'=>'/jnt/supply/excluded-pages',            'icon'=>'fa-solid fa-ban',              'active_pattern'=>'jnt/supply/excluded-pages*',     'visible'=>['CEO']],
            ['key'=>'jnt_config',            'label'=>'JNT Config',         'route_url'=>'/jnt/config',                           'icon'=>'fa-solid fa-sliders',          'active_pattern'=>'jnt/config*',                    'visible'=>['CEO']],
            // CEO admin tools
            ['key'=>'nav_settings',          'label'=>'Nav Settings',       'route_url'=>'/owner/nav-settings',                   'icon'=>'fa-solid fa-bars-staggered',   'active_pattern'=>'owner/nav-settings*',            'visible'=>['CEO']],
            ['key'=>'column_settings',       'label'=>'Column Settings',    'route_url'=>'/owner/column-settings',                'icon'=>'fa-solid fa-table-columns',    'active_pattern'=>'owner/column-settings*',         'visible'=>['CEO']],
        ];
    }
}
