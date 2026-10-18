<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Mga petsa ng Botcake sync run: ang pinakabago at pinakalumang date_subscribed_at na nabasa, at ang stop date ng
        // by date read. Mula rito ang stop date ng susunod na by date read ng page (huling malinis na petsa minus 1 araw).
        if (!Schema::hasTable('botcake_psid_import_runs')) return;

        Schema::table('botcake_psid_import_runs', function (Blueprint $table) {
            foreach (['subscribed_newest', 'subscribed_oldest', 'stop_date'] as $col) {
                if (!Schema::hasColumn('botcake_psid_import_runs', $col)) $table->date($col)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('botcake_psid_import_runs')) return;

        Schema::table('botcake_psid_import_runs', function (Blueprint $table) {
            foreach (['subscribed_newest', 'subscribed_oldest', 'stop_date'] as $col) {
                if (Schema::hasColumn('botcake_psid_import_runs', $col)) $table->dropColumn($col);
            }
        });
    }
};
