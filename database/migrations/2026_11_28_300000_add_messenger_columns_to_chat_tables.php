<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Likha Chat gaya ng Messenger (spec 300): reactions, edit at mute bilang mga column, walang bagong table.
//  - chat_messages.reactions: json na user id → emoji (isa bawat tao);
//  - chat_messages.edited_at at edit_history: ang mga naunang text (walang binubura);
//  - chat_members.muted_until: walang alert at hindi bilang sa unread hanggang noon (9999-12-31 = hanggang i-unmute).
// Index (conversation_id, updated_at): ang "nagbago mula noong huling poll" (reaction, edit, remove).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $t) {
            if (! Schema::hasColumn('chat_messages', 'reactions')) {
                $t->json('reactions')->nullable();
            }
            if (! Schema::hasColumn('chat_messages', 'edited_at')) {
                $t->timestamp('edited_at')->nullable();
            }
            if (! Schema::hasColumn('chat_messages', 'edit_history')) {
                $t->json('edit_history')->nullable();
            }
        });
        Schema::table('chat_messages', function (Blueprint $t) {
            $t->index(['conversation_id', 'updated_at']);
        });
        Schema::table('chat_members', function (Blueprint $t) {
            if (! Schema::hasColumn('chat_members', 'muted_until')) {
                $t->dateTime('muted_until')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $t) {
            $t->dropIndex(['conversation_id', 'updated_at']);
        });
        Schema::table('chat_messages', function (Blueprint $t) {
            $t->dropColumn(['reactions', 'edited_at', 'edit_history']);
        });
        Schema::table('chat_members', function (Blueprint $t) {
            $t->dropColumn('muted_until');
        });
    }
};
