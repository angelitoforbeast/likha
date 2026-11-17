<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The "Chat" link (/chat, spec 296) must cover /chat and /chat/* only. Its pattern `chat*` (a hand-made row) also
 * matched /chatblast and /chatbot, and `chat` (296's migration) missed /chat/5. One Str::is pattern cannot say both,
 * so the pattern is cleared: with no pattern the link covers its route_url and the paths below it, on whole path
 * segments, in the sidebar (spec 295) and in AccessRule (spec 294) alike. Only the /chat link is touched.
 * down() puts back 296's `chat`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nav_links')) {
            return;
        }
        $this->chatLinks()->update(['active_pattern' => null, 'updated_at' => now()]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('nav_links')) {
            return;
        }
        $this->chatLinks()->whereNull('active_pattern')->update(['active_pattern' => 'chat', 'updated_at' => now()]);
    }

    private function chatLinks()
    {
        return DB::table('nav_links')->where(function ($q) {
            $q->where('key', 'chat')->orWhereIn('route_url', ['/chat', 'chat', '/chat/', 'chat/']);
        });
    }
};
