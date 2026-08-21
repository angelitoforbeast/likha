<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Idagdag ang "Prompt Gen" nav link + role visibility (CEO, Marketing, Marketing - OIC).
 * Idempotent — ligtas i-run kahit umulit.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $id = DB::table('nav_links')->where('key', 'prompt_generator')->value('id');
        if (!$id) {
            $maxSort = (int) DB::table('nav_links')->max('sort_order');
            $id = DB::table('nav_links')->insertGetId([
                'key'            => 'prompt_generator',
                'label'          => 'Prompt Gen',
                'route_url'      => '/prompt-generator',
                'icon'           => 'fa-solid fa-robot',
                'active_pattern' => 'prompt-generator*',
                'sort_order'     => $maxSort + 1,
                'is_active'      => true,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
        }

        foreach (['CEO', 'Marketing - OIC', 'Marketing'] as $role) {
            DB::table('nav_link_role_visibility')->updateOrInsert(
                ['nav_link_id' => $id, 'role' => $role],
                ['is_visible' => true, 'updated_at' => $now, 'created_at' => $now]
            );
        }
    }

    public function down(): void
    {
        $id = DB::table('nav_links')->where('key', 'prompt_generator')->value('id');
        if ($id) {
            DB::table('nav_link_role_visibility')->where('nav_link_id', $id)->delete();
            DB::table('nav_links')->where('id', $id)->delete();
        }
    }
};
