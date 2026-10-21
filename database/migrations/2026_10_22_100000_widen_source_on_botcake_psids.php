<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Ang source ng botcake_psids ay 10 letra (sheet|api); ang PSID na pinatunayan ng /api/botcake/chat ay "chat_verify"
     * (11), na tatanggihan ng MySQL na strict. 20 na: ang pagpapalaki ng varchar sa ilalim ng 255 ay hindi kinokopya ang
     * table sa MySQL.
     */
    public function up(): void
    {
        if (!Schema::hasTable('botcake_psids') || !Schema::hasColumn('botcake_psids', 'source')) return;

        Schema::table('botcake_psids', function (Blueprint $table) {
            $table->string('source', 20)->default('sheet')->change();
        });
    }

    public function down(): void
    {
        // Hindi pinaliliit: maaaring may "chat_verify" na.
    }
};
