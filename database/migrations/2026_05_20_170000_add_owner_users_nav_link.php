<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds "Users" nav link na pumupunta sa /owner/users — CEO oversight ng
 * credentials (view-only list + password edit). Visible CEO-only.
 *
 * Idempotent: skips kung exists na yung 'users_admin' key.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('nav_links')) return;

        $existing = DB::table('nav_links')->where('key', 'users_admin')->first();
        if ($existing) return;

        // Position after "Column Settings" — append to end of sort order.
        $maxSort = (int) DB::table('nav_links')->max('sort_order');

        $now = now();
        $linkId = DB::table('nav_links')->insertGetId([
            'key'            => 'users_admin',
            'label'          => 'Users',
            'route_url'      => '/owner/users',
            'icon'           => 'fa-solid fa-users-gear',
            'active_pattern' => 'owner/users*',
            'sort_order'     => $maxSort + 1,
            'is_active'      => true,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        // Seed visibility for ALL known roles — CEO only sees this.
        $allRoles = ['CEO', 'Marketing - OIC', 'Marketing', 'Data Encoder', 'Data Encoder - OIC'];
        foreach ($allRoles as $role) {
            DB::table('nav_link_role_visibility')->insert([
                'nav_link_id' => $linkId,
                'role'        => $role,
                'is_visible'  => $role === 'CEO',
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('nav_links')) return;

        $link = DB::table('nav_links')->where('key', 'users_admin')->first();
        if (!$link) return;

        DB::table('nav_link_role_visibility')->where('nav_link_id', $link->id)->delete();
        DB::table('nav_links')->where('id', $link->id)->delete();
    }
};
