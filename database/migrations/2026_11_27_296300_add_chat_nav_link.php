<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nav link "Chat" → /chat (spec 296, Likha Chat), at the end of the menu. Every role is on chat, so it starts visible
 * to every role found in nav_link_role_visibility today plus the five standard ones (CEO, Marketing - OIC, Marketing,
 * Data Encoder - OIC, Data Encoder); the owner can still uncheck a role in /owner/nav-settings. The page itself is
 * open to every signed-in person (no NavAccess): unchecking only hides the link. When nav_links has a section column
 * (spec 295), the link goes in the "Chat" section. Idempotent; down() removes the link and its rows.
 */
return new class extends Migration
{
    private const ROLES = ['CEO', 'Marketing - OIC', 'Marketing', 'Data Encoder - OIC', 'Data Encoder'];

    public function up(): void
    {
        if (! Schema::hasTable('nav_links') || ! Schema::hasTable('nav_link_role_visibility')) {
            return;
        }
        if (DB::table('nav_links')->where('key', 'chat')->exists()) {
            return;
        }

        $now = now();
        $row = [
            'key' => 'chat',
            'label' => 'Chat',
            'route_url' => '/chat',
            'icon' => 'fa-solid fa-comment-dots',
            'active_pattern' => 'chat',
            'sort_order' => (int) DB::table('nav_links')->max('sort_order') + 1,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if (Schema::hasColumn('nav_links', 'section')) {
            $row['section'] = 'Chat';
        }
        $id = DB::table('nav_links')->insertGetId($row);

        $roles = DB::table('nav_link_role_visibility')->distinct()->pluck('role')
            ->map(fn ($r) => trim((string) $r))
            ->filter()
            ->merge(self::ROLES)
            ->unique()
            ->values();
        DB::table('nav_link_role_visibility')->insert($roles->map(fn ($role) => [
            'nav_link_id' => $id, 'role' => $role, 'is_visible' => true, 'created_at' => $now, 'updated_at' => $now,
        ])->all());
    }

    public function down(): void
    {
        if (! Schema::hasTable('nav_links')) {
            return;
        }
        $link = DB::table('nav_links')->where('key', 'chat')->first();
        if (! $link) {
            return;
        }
        DB::table('nav_link_role_visibility')->where('nav_link_id', $link->id)->delete();
        DB::table('nav_links')->where('id', $link->id)->delete();
    }
};
