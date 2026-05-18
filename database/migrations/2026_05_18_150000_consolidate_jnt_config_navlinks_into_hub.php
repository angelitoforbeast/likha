<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Consolidates the 7 individual JNT config navlinks into ONE hub link
 * (/jnt/config) so the top nav stays tight. The individual links are kept sa
 * nav_links table (registered) pero set is_visible=false for ALL roles so
 * they don't render. CEO can re-enable individual ones via /owner/nav-settings
 * kung gusto ng quick top-nav shortcut.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Bail safely if the prior nav tables migration hasn't run yet.
        if (!\Schema::hasTable('nav_links') || !\Schema::hasTable('nav_link_role_visibility')) {
            return;
        }

        // 1) Add the new hub navlink (CEO-only by default).
        $hubExists = DB::table('nav_links')->where('key', 'jnt_config')->exists();
        if (!$hubExists) {
            $maxSort = (int) DB::table('nav_links')->max('sort_order');
            $hubId = DB::table('nav_links')->insertGetId([
                'key'            => 'jnt_config',
                'label'          => 'JNT Config',
                'route_url'      => '/jnt/config',
                'icon'           => 'fa-solid fa-sliders',
                'active_pattern' => 'jnt/config*',
                'sort_order'     => $maxSort + 1,
                'is_active'      => true,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);

            $allRoles = ['CEO', 'Marketing - OIC', 'Marketing', 'Data Encoder', 'Data Encoder - OIC'];
            foreach ($allRoles as $role) {
                DB::table('nav_link_role_visibility')->insert([
                    'nav_link_id' => $hubId,
                    'role'        => $role,
                    'is_visible'  => $role === 'CEO', // CEO-only
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            }
        }

        // 2) Hide the 7 individual JNT config navlinks from ALL roles (the hub
        //    is now the primary entry). Records stay registered so CEO can
        //    re-enable individually via /owner/nav-settings if needed.
        $consolidatedKeys = [
            'jnt_accounts',
            'jnt_acct_mapping',
            'jnt_sender_name',
            'jnt_item_sender',
            'jnt_item_types',
            'jnt_orders',
            'jnt_waybills_print',
        ];
        $linkIds = DB::table('nav_links')
            ->whereIn('key', $consolidatedKeys)
            ->pluck('id');
        if ($linkIds->isNotEmpty()) {
            DB::table('nav_link_role_visibility')
                ->whereIn('nav_link_id', $linkIds)
                ->update(['is_visible' => false, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        if (!\Schema::hasTable('nav_links') || !\Schema::hasTable('nav_link_role_visibility')) {
            return;
        }
        // Restore individual JNT config links to CEO-only visibility (their
        // original seeded state) and remove the hub link.
        $consolidatedKeys = [
            'jnt_accounts', 'jnt_acct_mapping', 'jnt_sender_name',
            'jnt_item_sender', 'jnt_item_types', 'jnt_orders', 'jnt_waybills_print',
        ];
        $linkIds = DB::table('nav_links')->whereIn('key', $consolidatedKeys)->pluck('id');
        if ($linkIds->isNotEmpty()) {
            DB::table('nav_link_role_visibility')
                ->whereIn('nav_link_id', $linkIds)
                ->update(['is_visible' => DB::raw("CASE WHEN role = 'CEO' THEN 1 ELSE 0 END")]);
        }
        DB::table('nav_links')->where('key', 'jnt_config')->delete();
    }
};
