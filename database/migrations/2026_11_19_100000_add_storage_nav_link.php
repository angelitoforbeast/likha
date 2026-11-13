<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nav link "Storage" → /system-monitor/storage (spec 243). Checking a role in /owner/nav-settings both shows the link and
 * opens the Storage view and its banner (NavAccess, StorageWarning). It starts checked for CEO and Marketing - OIC, the
 * two roles that had it in spec 242. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('nav_links') || !Schema::hasTable('nav_link_role_visibility')) return;
        if (DB::table('nav_links')->where('key', 'storage')->exists()) return;

        $now = now();
        $id  = DB::table('nav_links')->insertGetId([
            'key'            => 'storage',
            'label'          => 'Storage',
            'route_url'      => '/system-monitor/storage',
            'icon'           => 'fa-solid fa-hard-drive',
            'active_pattern' => 'system-monitor/storage*',
            'sort_order'     => (int) DB::table('nav_links')->max('sort_order') + 1,
            'is_active'      => true,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        foreach (['CEO', 'Marketing - OIC', 'Marketing', 'Data Encoder', 'Data Encoder - OIC'] as $role) {
            DB::table('nav_link_role_visibility')->insert([
                'nav_link_id' => $id, 'role' => $role, 'is_visible' => in_array($role, ['CEO', 'Marketing - OIC'], true),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('nav_links')) return;
        $link = DB::table('nav_links')->where('key', 'storage')->first();
        if (!$link) return;
        DB::table('nav_link_role_visibility')->where('nav_link_id', $link->id)->delete();
        DB::table('nav_links')->where('id', $link->id)->delete();
    }
};
