<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Uri ng basa ng Botcake sync (incremental|window|full) at ang unang batch na binasa: ang window read na naputol
        // sa oras (stop reason time) ay itinutuloy ng susunod na sync mula rito, hindi mula batch 1.
        if (!Schema::hasTable('botcake_psid_import_runs')) return;

        Schema::table('botcake_psid_import_runs', function (Blueprint $table) {
            if (!Schema::hasColumn('botcake_psid_import_runs', 'read_kind')) $table->string('read_kind', 20)->nullable();
            if (!Schema::hasColumn('botcake_psid_import_runs', 'start_batch')) $table->unsignedInteger('start_batch')->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('botcake_psid_import_runs')) return;

        Schema::table('botcake_psid_import_runs', function (Blueprint $table) {
            $table->dropColumn(['read_kind', 'start_batch']);
        });
    }
};
