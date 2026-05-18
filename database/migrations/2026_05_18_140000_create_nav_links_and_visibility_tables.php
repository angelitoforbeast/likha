<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Centralized nav management — replaces hardcoded @if($role) blocks sa
 * layout.blade.php with DB-driven render + CEO settings UI at /owner/nav-settings.
 *
 * Two tables:
 *   - nav_links: catalog of every nav item (key, label, url, icon, sort_order)
 *   - nav_link_role_visibility: which roles can see which links
 *
 * Migration also SEEDS the current state from layout.blade.php so existing
 * visibility is preserved on first run. CEO can then edit via the settings page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nav_links', function (Blueprint $t) {
            $t->id();
            $t->string('key', 80)->unique();                // 'ads_payment', 'jnt_accounts', etc.
            $t->string('label', 100);                       // 'Ad Payment'
            $t->string('route_url', 255);                   // '/ads_manager/payment/upload'
            $t->string('icon', 100)->nullable();            // 'fa-solid fa-credit-card'
            $t->string('active_pattern', 255)->nullable();  // 'ads_manager/payment*'
            $t->integer('sort_order')->default(0);
            $t->boolean('is_active')->default(true);        // soft-disable (kept for future use)
            $t->timestamps();
            $t->index('sort_order');
        });

        Schema::create('nav_link_role_visibility', function (Blueprint $t) {
            $t->id();
            $t->foreignId('nav_link_id')->constrained('nav_links')->onDelete('cascade');
            $t->string('role', 50);     // matches employee_profiles.role values
            $t->boolean('is_visible')->default(false);
            $t->timestamps();
            $t->unique(['nav_link_id', 'role']);
        });

        // ── Seed nav_links + visibility from current layout.blade.php ──
        // Roles used in seed:
        //   'CEO', 'Marketing - OIC', 'Marketing', 'Data Encoder', 'Data Encoder - OIC'
        // Each link's `visible` array lists roles that can see it BY DEFAULT
        // (mirrors yung hardcoded @if blocks sa layout). CEO can override anytime
        // via /owner/nav-settings.
        $now = now();
        $links = [
            // Group A — Marketing-tier shared (lines 50-128 of layout)
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

            // Group B — Data Encoder tier
            ['key'=>'mes_seg',               'label'=>'MES SEG',            'route_url'=>'/data_encoder/mes-segregator',          'icon'=>'fa-solid fa-scissors',         'active_pattern'=>'data_encoder/mes-segregator*',   'visible'=>['Data Encoder','Data Encoder - OIC']],
            ['key'=>'address_search',        'label'=>'Address Search',     'route_url'=>'/jnt/address',                          'icon'=>'fa-solid fa-location-dot',     'active_pattern'=>'jnt/address*',                   'visible'=>['Data Encoder','Data Encoder - OIC']],

            // Group C — CEO-only
            ['key'=>'roles',                 'label'=>'Roles',              'route_url'=>'/assign-roles',                         'icon'=>'fa-solid fa-user-gear',        'active_pattern'=>'assign-roles*',                  'visible'=>['CEO']],
            ['key'=>'finance',               'label'=>'Finance',            'route_url'=>'/finance',                              'icon'=>'fa-solid fa-wallet',           'active_pattern'=>'finance*',                       'visible'=>['CEO']],

            // Group D — Marketing-OIC + CEO
            ['key'=>'waybill_v2',            'label'=>'Waybill V2',         'route_url'=>'/jnt_upload_v2',                        'icon'=>'fa-solid fa-layer-group',      'active_pattern'=>'jnt_upload_v2*',                 'visible'=>['Marketing - OIC','CEO']],
            ['key'=>'queue',                 'label'=>'Queue',              'route_url'=>'/queue-manager',                        'icon'=>'fa-solid fa-list-check',       'active_pattern'=>'queue-manager*',                 'visible'=>['Marketing - OIC','CEO']],

            // Group E — All authenticated
            ['key'=>'ip',                    'label'=>'IP',                 'route_url'=>'/allowed-ips',                          'icon'=>'fa-solid fa-network-wired',    'active_pattern'=>'allowed-ips*',                   'visible'=>['CEO','Marketing - OIC','Marketing','Data Encoder','Data Encoder - OIC']],

            // Group F — Data Encoder - OIC extras
            ['key'=>'pending_rate',          'label'=>'Pending Rate',       'route_url'=>'/encoder/pending-rate',                 'icon'=>'fa-solid fa-hourglass-half',   'active_pattern'=>'encoder/pending-rate*',          'visible'=>['Data Encoder - OIC']],

            // Group G — NEW additions (JNT config pages currently NOT in nav — seeded as CEO-only by default per user spec)
            ['key'=>'jnt_accounts',          'label'=>'JNT Accounts',       'route_url'=>'/jnt/accounts',                         'icon'=>'fa-solid fa-building',         'active_pattern'=>'jnt/accounts',                   'visible'=>['CEO']],
            ['key'=>'jnt_acct_mapping',      'label'=>'JNT Acct Mapping',   'route_url'=>'/jnt/accounts/mapping',                 'icon'=>'fa-solid fa-diagram-project',  'active_pattern'=>'jnt/accounts/mapping',           'visible'=>['CEO']],
            ['key'=>'jnt_sender_name',       'label'=>'JNT Sender',         'route_url'=>'/jnt/sender-name',                      'icon'=>'fa-solid fa-signature',        'active_pattern'=>'jnt/sender-name*',               'visible'=>['CEO']],
            ['key'=>'jnt_item_sender',       'label'=>'JNT Item Sender',    'route_url'=>'/jnt/item-sender-name',                 'icon'=>'fa-solid fa-tag',              'active_pattern'=>'jnt/item-sender-name*',          'visible'=>['CEO']],
            ['key'=>'jnt_item_types',        'label'=>'JNT Item Types',     'route_url'=>'/jnt/item-types',                       'icon'=>'fa-solid fa-boxes-packing',    'active_pattern'=>'jnt/item-types*',                'visible'=>['CEO']],
            ['key'=>'jnt_orders',            'label'=>'JNT Orders',         'route_url'=>'/jnt/orders',                           'icon'=>'fa-solid fa-truck-fast',       'active_pattern'=>'jnt/orders*',                    'visible'=>['CEO']],
            ['key'=>'jnt_waybills_print',    'label'=>'Waybills Print',     'route_url'=>'/jnt/waybills/print',                   'icon'=>'fa-solid fa-print',            'active_pattern'=>'jnt/waybills*',                  'visible'=>['CEO']],
            ['key'=>'jnt_fee_settings',      'label'=>'JNT Fee Settings',   'route_url'=>'/jnt/fee-settings',                     'icon'=>'fa-solid fa-coins',            'active_pattern'=>'jnt/fee-settings*',              'visible'=>['CEO']],
            ['key'=>'jnt_supply_excluded',   'label'=>'Supply Excluded',    'route_url'=>'/jnt/supply/excluded-pages',            'icon'=>'fa-solid fa-ban',              'active_pattern'=>'jnt/supply/excluded-pages*',     'visible'=>['CEO']],

            // CEO admin tools
            ['key'=>'nav_settings',          'label'=>'Nav Settings',       'route_url'=>'/owner/nav-settings',                   'icon'=>'fa-solid fa-bars-staggered',   'active_pattern'=>'owner/nav-settings*',            'visible'=>['CEO']],
            ['key'=>'column_settings',       'label'=>'Column Settings',    'route_url'=>'/owner/column-settings',                'icon'=>'fa-solid fa-table-columns',    'active_pattern'=>'owner/column-settings*',         'visible'=>['CEO']],
        ];

        $sort = 1;
        foreach ($links as $l) {
            $visible = $l['visible'];
            unset($l['visible']);
            $l['sort_order'] = $sort++;
            $l['created_at'] = $now;
            $l['updated_at'] = $now;
            $linkId = DB::table('nav_links')->insertGetId($l);

            // Seed visibility for ALL known roles — false for those not in $visible.
            $allRoles = ['CEO', 'Marketing - OIC', 'Marketing', 'Data Encoder', 'Data Encoder - OIC'];
            foreach ($allRoles as $role) {
                DB::table('nav_link_role_visibility')->insert([
                    'nav_link_id' => $linkId,
                    'role'        => $role,
                    'is_visible'  => in_array($role, $visible, true),
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nav_link_role_visibility');
        Schema::dropIfExists('nav_links');
    }
};
