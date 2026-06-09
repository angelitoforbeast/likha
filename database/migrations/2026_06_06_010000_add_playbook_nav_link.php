<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds "Playbook" nav link → /playbook (Problem & Solution knowledge base).
 * Visible sa CEO + Marketing - OIC + Marketing. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('nav_links')) return;
        if (DB::table('nav_links')->where('key', 'playbook')->exists()) return;

        $maxSort = (int) DB::table('nav_links')->max('sort_order');
        $now     = now();

        $linkId = DB::table('nav_links')->insertGetId([
            'key'            => 'playbook',
            'label'          => 'Playbook',
            'route_url'      => '/playbook',
            'icon'           => 'fa-solid fa-book-medical',
            'active_pattern' => 'playbook*',
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
                'is_visible'  => in_array($role, ['CEO', 'Marketing - OIC', 'Marketing'], true),
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('nav_links')) return;
        $link = DB::table('nav_links')->where('key', 'playbook')->first();
        if (!$link) return;
        DB::table('nav_link_role_visibility')->where('nav_link_id', $link->id)->delete();
        DB::table('nav_links')->where('id', $link->id)->delete();
    }
};
