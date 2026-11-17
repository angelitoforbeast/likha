<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nav link "Marketing Head" → /checklist/ai-followups (spec 293), placed right after the Checklist link (else at the end).
 * The page reviews AI follow-ups before they reach staff and is CEO only in the controller, so the link is visible to
 * the CEO only. Idempotent; down() removes the link and its rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nav_links') || ! Schema::hasTable('nav_link_role_visibility')) {
            return;
        }
        if (DB::table('nav_links')->where('key', 'checklist_ai_followups')->exists()) {
            return;
        }

        $now = now();
        $near = DB::table('nav_links')->where('route_url', '/checklist')->first();
        $id = DB::table('nav_links')->insertGetId([
            'key' => 'checklist_ai_followups',
            'label' => 'Marketing Head',
            'route_url' => '/checklist/ai-followups',
            'icon' => 'fa-solid fa-wand-magic-sparkles',
            'active_pattern' => 'checklist/ai-followups*',
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
        $link = DB::table('nav_links')->where('key', 'checklist_ai_followups')->first();
        if (! $link) {
            return;
        }
        DB::table('nav_link_role_visibility')->where('nav_link_id', $link->id)->delete();
        DB::table('nav_links')->where('id', $link->id)->delete();
    }
};
