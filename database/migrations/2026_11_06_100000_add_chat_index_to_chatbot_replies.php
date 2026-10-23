<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Index para sa /chatbot/health: ang unang sagot ng bawat mensahe ay MIN(sent_at) ng mga send/sent ng chat_id nito.
     * Wala pang index ang chat_id; kapag wala, bawat chat ay scan ng buong table. Index lang, walang binabagong row.
     */
    public function up(): void
    {
        if (!Schema::hasTable('chatbot_replies') || !Schema::hasColumn('chatbot_replies', 'chat_id')) return;

        Schema::table('chatbot_replies', fn (Blueprint $table) => $table->index(['chat_id', 'kind', 'status', 'sent_at'], 'chatbot_replies_chat_first_idx'));
    }

    public function down(): void
    {
        if (!Schema::hasTable('chatbot_replies')) return;

        Schema::table('chatbot_replies', fn (Blueprint $table) => $table->dropIndex('chatbot_replies_chat_first_idx'));
    }
};
