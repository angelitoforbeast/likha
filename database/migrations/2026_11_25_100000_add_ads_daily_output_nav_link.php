<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nav link "Ads Daily Output" → /ads_manager/daily-output (spec 266). Checking a role in /owner/nav-settings both shows the
 * link and opens the page (NavAccess). It starts checked for CEO, Marketing and Marketing - OIC; the data encoders start
 * unchecked. Idempotent; down() removes the link and its rows.
 */
return new class extends Migration
{
    private const VISIBLE = ['CEO', 'Marketing', 'Marketing - OIC'];

    public function up(): void
    {
        if (!Schema::hasTable('nav_links') || !Schema::hasTable('nav_link_role_visibility')) return;
        if (DB::table('nav_links')->where('key', 'ads_daily_output')->exists()) return;

        $now = now();
        $id  = DB::table('nav_links')->insertGetId([
            'key'            => 'ads_daily_output',
            'label'          => 'Ads Daily Output',
            'route_url'      => '/ads_manager/daily-output',
            'icon'           => 'fa-solid fa-calendar-day',
            'active_pattern' => 'ads_manager/daily-output*',
            'sort_order'     => (int) DB::table('nav_links')->max('sort_order') + 1,
            'is_active'      => true,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        foreach (['CEO', 'Marketing - OIC', 'Marketing', 'Data Encoder', 'Data Encoder - OIC'] as $role) {
            DB::table('nav_link_role_visibility')->insert([
                'nav_link_id' => $id, 'role' => $role, 'is_visible' => in_array($role, self::VISIBLE, true),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('nav_links')) return;
        $link = DB::table('nav_links')->where('key', 'ads_daily_output')->first();
        if (!$link) return;
        DB::table('nav_link_role_visibility')->where('nav_link_id', $link->id)->delete();
        DB::table('nav_links')->where('id', $link->id)->delete();
    }
};
