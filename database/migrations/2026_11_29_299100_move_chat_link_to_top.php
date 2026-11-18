<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The "Chat" link (/chat, spec 296) is the first link of the sidebar (spec 299, the owner: "priority yung chat sa
 * listahan, sa ibabaw ng checklist"): it moves to the Home section (spec 295's nav_links.section) with a sort_order
 * just below the lowest of the other Home links, so it sits above Checklist for every role that sees it. Only the
 * /chat link is touched; visibility is not. Its old section and sort_order are kept in app_settings
 * (nav.299_chat_before), so down() puts it back exactly where it was. Runs only when the section column exists.
 */
return new class extends Migration
{
    private const KEEP = 'nav.299_chat_before';

    public function up(): void
    {
        if (! Schema::hasTable('nav_links') || ! Schema::hasColumn('nav_links', 'section')) {
            return;
        }
        $chat = $this->chatLinks()->orderBy('id')->get(['id', 'section', 'sort_order']);
        if ($chat->isEmpty()) {
            return;
        }
        $homeMin = DB::table('nav_links')->where('section', 'Home')->whereNotIn('id', $chat->pluck('id'))->min('sort_order');
        $top = $homeMin === null ? (int) $chat->min('sort_order') : (int) $homeMin - 1;

        $before = $this->kept() ?? [];
        foreach ($chat as $link) {
            if ($link->section === 'Home' && (int) $link->sort_order <= $top) {
                continue; // already on top: a rerun changes nothing
            }
            $before[(string) $link->id] ??= ['section' => $link->section, 'sort_order' => (int) $link->sort_order];
            DB::table('nav_links')->where('id', $link->id)->update(['section' => 'Home', 'sort_order' => $top, 'updated_at' => now()]);
        }
        if ($before && Schema::hasTable('app_settings')) {
            DB::table('app_settings')->updateOrInsert(['key' => self::KEEP], ['value' => json_encode($before), 'updated_at' => now(), 'created_at' => now()]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('nav_links') || ! Schema::hasColumn('nav_links', 'section')) {
            return;
        }
        $before = $this->kept();
        foreach ($this->chatLinks()->where('section', 'Home')->get(['id']) as $link) {
            // Without the kept place (no app_settings): back to the Chat section, last, as 296 put it.
            $place = $before[(string) $link->id] ?? ['section' => 'Chat', 'sort_order' => (int) DB::table('nav_links')->max('sort_order') + 1];
            DB::table('nav_links')->where('id', $link->id)->update([
                'section' => $place['section'], 'sort_order' => (int) $place['sort_order'], 'updated_at' => now(),
            ]);
        }
        if (Schema::hasTable('app_settings')) {
            DB::table('app_settings')->where('key', self::KEEP)->delete();
        }
    }

    private function chatLinks()
    {
        return DB::table('nav_links')->where(function ($q) {
            $q->where('key', 'chat')->orWhereIn('route_url', ['/chat', 'chat', '/chat/', 'chat/']);
        });
    }

    private function kept(): ?array
    {
        if (! Schema::hasTable('app_settings')) {
            return null;
        }
        $value = DB::table('app_settings')->where('key', self::KEEP)->value('value');

        return is_string($value) ? (json_decode($value, true) ?: null) : null;
    }
};
