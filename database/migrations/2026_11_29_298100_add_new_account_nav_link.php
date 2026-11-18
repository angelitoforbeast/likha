<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nav link "New Account" → /accounts/create (spec 298), at the end of the menu, visible to CEO and Marketing - OIC
 * only (the two roles that may create accounts; the page checks the role itself, so unchecking a role in
 * /owner/nav-settings only hides the link). Every other role found in nav_link_role_visibility, plus the standard
 * ones, gets a hidden row so the nav settings matrix shows it unchecked. When nav_links has a section column (spec
 * 295), the link goes in "Settings", next to Users. Idempotent; down() removes the link and its rows.
 */
return new class extends Migration
{
    private const KEY = 'accounts_create';

    private const VISIBLE = ['CEO', 'Marketing - OIC'];

    private const ROLES = ['CEO', 'Marketing - OIC', 'Marketing', 'Data Encoder - OIC', 'Data Encoder'];

    public function up(): void
    {
        if (!Schema::hasTable('nav_links') || !Schema::hasTable('nav_link_role_visibility')) {
            return;
        }
        if (DB::table('nav_links')->where('key', self::KEY)->exists()) {
            return;
        }

        $now = now();
        $row = [
            'key'            => self::KEY,
            'label'          => 'New Account',
            'route_url'      => '/accounts/create',
            'icon'           => 'fa-solid fa-user-plus',
            'active_pattern' => 'accounts*',
            'sort_order'     => (int) DB::table('nav_links')->max('sort_order') + 1,
            'is_active'      => true,
            'created_at'     => $now,
            'updated_at'     => $now,
        ];
        if (Schema::hasColumn('nav_links', 'section')) {
            $row['section'] = 'Settings';
        }
        $id = DB::table('nav_links')->insertGetId($row);

        $roles = DB::table('nav_link_role_visibility')->distinct()->pluck('role')
            ->map(fn ($r) => trim((string) $r))
            ->filter()
            ->merge(self::ROLES)
            ->unique()
            ->values();
        DB::table('nav_link_role_visibility')->insert($roles->map(fn ($role) => [
            'nav_link_id' => $id,
            'role'        => $role,
            'is_visible'  => in_array($role, self::VISIBLE, true),
            'created_at'  => $now,
            'updated_at'  => $now,
        ])->all());
    }

    public function down(): void
    {
        if (!Schema::hasTable('nav_links')) {
            return;
        }
        $link = DB::table('nav_links')->where('key', self::KEY)->first();
        if (!$link) {
            return;
        }
        DB::table('nav_link_role_visibility')->where('nav_link_id', $link->id)->delete();
        DB::table('nav_links')->where('id', $link->id)->delete();
    }
};
