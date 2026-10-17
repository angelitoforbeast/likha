<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nav link na "Chatblast" → /chatblast, nakikita ng CEO. Ang ibang role ay binubuksan sa /owner/nav-settings (iyon din
 * ang nagbubukas ng page, sa NavAccess). Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('nav_links') || !Schema::hasTable('nav_link_role_visibility')) return;
        if (DB::table('nav_links')->where('key', 'chatblast')->exists()) return;

        $now = now();
        $id  = DB::table('nav_links')->insertGetId([
            'key'            => 'chatblast',
            'label'          => 'Chatblast',
            'route_url'      => '/chatblast',
            'icon'           => 'fa-solid fa-paper-plane',
            'active_pattern' => 'chatblast*',
            'sort_order'     => (int) DB::table('nav_links')->max('sort_order') + 1,
            'is_active'      => true,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        foreach (['CEO', 'Marketing - OIC', 'Marketing', 'Data Encoder', 'Data Encoder - OIC'] as $role) {
            DB::table('nav_link_role_visibility')->insert([
                'nav_link_id' => $id, 'role' => $role, 'is_visible' => $role === 'CEO', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('nav_links')) return;
        $link = DB::table('nav_links')->where('key', 'chatblast')->first();
        if (!$link) return;
        DB::table('nav_link_role_visibility')->where('nav_link_id', $link->id)->delete();
        DB::table('nav_links')->where('id', $link->id)->delete();
    }
};
