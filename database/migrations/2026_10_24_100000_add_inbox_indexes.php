<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Ang usapan ng isang customer sa /chatbot/inbox: papasok (botcake_chats) at palabas (chatbot_replies), 50 kada basa
     * ayon sa id (mas luma o mas bago), kaya (page_id, psid, id) sa dalawa. Dagdag lang; walang binabago sa dati.
     */
    public function up(): void
    {
        foreach (['botcake_chats', 'chatbot_replies'] as $t) {
            if (!Schema::hasTable($t) || Schema::hasIndex($t, ['page_id', 'psid', 'id'])) continue;
            Schema::table($t, fn (Blueprint $table) => $table->index(['page_id', 'psid', 'id'], $t . '_page_psid_id_index'));
        }
    }

    public function down(): void
    {
        foreach (['botcake_chats', 'chatbot_replies'] as $t) {
            if (Schema::hasTable($t) && Schema::hasIndex($t, $t . '_page_psid_id_index')) {
                Schema::table($t, fn (Blueprint $table) => $table->dropIndex($t . '_page_psid_id_index'));
            }
        }
    }
};
