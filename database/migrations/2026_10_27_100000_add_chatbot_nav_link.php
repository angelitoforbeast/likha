<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nav link na "Chatbot" → /chatbot, hiwalay sa Chatblast: nakikita ng mga role na nakakakita ng Chatblast ngayon (kinokopya
 * ang visibility ng chatblast link; kung wala iyon, CEO lang). Ipinapakita lang ng main nav kapag may /chatblast access
 * ang role (ChatblastNav::mainLinks), gaya ng mga route ng /chatbot. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('nav_links') || !Schema::hasTable('nav_link_role_visibility')) return;
        if (DB::table('nav_links')->where('key', 'chatbot')->exists()) return;

        $now = now();
        $chatblast = DB::table('nav_links')->where('key', 'chatblast')->first();
        $id = DB::table('nav_links')->insertGetId([
            'key'            => 'chatbot',
            'label'          => 'Chatbot',
            'route_url'      => '/chatbot',
            'icon'           => 'fa-solid fa-robot',
            'active_pattern' => 'chatbot*',
            'sort_order'     => $chatblast ? (int) $chatblast->sort_order : (int) DB::table('nav_links')->max('sort_order') + 1,
            'is_active'      => true,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        $roles = $chatblast
            ? DB::table('nav_link_role_visibility')->where('nav_link_id', $chatblast->id)->get(['role', 'is_visible'])->map(fn ($r) => [$r->role, (bool) $r->is_visible])->all()
            : [['CEO', true]];
        foreach ($roles as [$role, $visible]) {
            DB::table('nav_link_role_visibility')->insert([
                'nav_link_id' => $id, 'role' => $role, 'is_visible' => $visible, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('nav_links')) return;
        $link = DB::table('nav_links')->where('key', 'chatbot')->first();
        if (!$link) return;
        DB::table('nav_link_role_visibility')->where('nav_link_id', $link->id)->delete();
        DB::table('nav_links')->where('id', $link->id)->delete();
    }
};
