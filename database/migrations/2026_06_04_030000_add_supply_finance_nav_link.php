<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds "Supply Finance" nav link → /finance/supply (suppliers, orders,
 * partial payments + receipts). Visible sa CEO lang
 * (tugma sa controller access gate).
 *
 * Idempotent: skips kung naka-seed na ang 'supply_finance' key.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('nav_links')) return;

        if (DB::table('nav_links')->where('key', 'supply_finance')->exists()) return;

        $maxSort = (int) DB::table('nav_links')->max('sort_order');
        $now     = now();

        $linkId = DB::table('nav_links')->insertGetId([
            'key'            => 'supply_finance',
            'label'          => 'Supply Finance',
            'route_url'      => '/finance/supply',
            'icon'           => 'fa-solid fa-truck-ramp-box',
            'active_pattern' => 'finance/supply*',
            'sort_order'     => $maxSort + 1,
            'is_active'      => true,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

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

        $link = DB::table('nav_links')->where('key', 'supply_finance')->first();
        if (!$link) return;

        DB::table('nav_link_role_visibility')->where('nav_link_id', $link->id)->delete();
        DB::table('nav_links')->where('id', $link->id)->delete();
    }
};
