<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nav link "Botcake Accounts" → /chatbot/botcake-accounts (spec 272), placed right after the Chatbot link (else the
 * Chatblast link, else at the end). Checking a role in /owner/nav-settings both shows the link and opens the page and
 * its actions (NavAccess). The page stores tokens that can edit and broadcast on every page of a Botcake account, so it
 * starts visible to the CEO only, the way nav settings treats a new link; the owner checks the roles that may use it.
 * Idempotent; down() removes the link and its rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nav_links') || ! Schema::hasTable('nav_link_role_visibility')) {
            return;
        }
        if (DB::table('nav_links')->where('key', 'botcake_accounts')->exists()) {
            return;
        }

        $now = now();
        $near = DB::table('nav_links')->where('key', 'chatbot')->first() ?? DB::table('nav_links')->where('key', 'chatblast')->first();
        $id = DB::table('nav_links')->insertGetId([
            'key' => 'botcake_accounts',
            'label' => 'Botcake Accounts',
            'route_url' => '/chatbot/botcake-accounts',
            'icon' => 'fa-solid fa-key',
            'active_pattern' => 'chatbot/botcake-accounts*',
            // The same sort order as its neighbour: the menu orders by sort_order, then id, so it lands right after it.
            'sort_order' => $near ? (int) $near->sort_order : (int) DB::table('nav_links')->max('sort_order') + 1,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('nav_link_role_visibility')->insert([
            'nav_link_id' => $id, 'role' => 'CEO', 'is_visible' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('nav_links')) {
            return;
        }
        $link = DB::table('nav_links')->where('key', 'botcake_accounts')->first();
        if (! $link) {
            return;
        }
        DB::table('nav_link_role_visibility')->where('nav_link_id', $link->id)->delete();
        DB::table('nav_links')->where('id', $link->id)->delete();
    }
};
