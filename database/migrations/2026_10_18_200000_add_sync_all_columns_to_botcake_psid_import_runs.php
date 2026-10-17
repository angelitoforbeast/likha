<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const COLUMNS = ['ad_filled', 'run_key', 'sync_state', 'page_index', 'current_page', 'started_by', 'finished_at'];

    public function up(): void
    {
        // ad_filled: bilang ng napunang ad id ng bawat api run. Ang iba ay para sa "Sync lahat" (source = sync_all, isang
        // row kada run): run_key (id ng run sa mga job), sync_state (listahan ng page at bilang, JSON), page_index (ang
        // page na susunod o kasalukuyan; binabantayan sa bawat update), current_page (habang tumatakbo ang page),
        // started_by (user), finished_at. Maliit na table: walang ginagalaw na macro_output o from_jnts.
        if (!Schema::hasTable('botcake_psid_import_runs')) return;

        Schema::table('botcake_psid_import_runs', function (Blueprint $table) {
            $has = fn ($c) => Schema::hasColumn('botcake_psid_import_runs', $c);
            if (!$has('ad_filled')) $table->unsignedInteger('ad_filled')->nullable();
            if (!$has('run_key')) $table->string('run_key', 40)->nullable()->index();
            if (!$has('sync_state')) $table->json('sync_state')->nullable();
            if (!$has('page_index')) $table->unsignedInteger('page_index')->nullable();
            if (!$has('current_page')) $table->string('current_page')->nullable();
            if (!$has('started_by')) $table->unsignedBigInteger('started_by')->nullable();
            if (!$has('finished_at')) $table->timestamp('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('botcake_psid_import_runs')) return;

        Schema::table('botcake_psid_import_runs', function (Blueprint $table) {
            if (Schema::hasColumn('botcake_psid_import_runs', 'run_key')) $table->dropIndex(['run_key']);
            foreach (self::COLUMNS as $col) {
                if (Schema::hasColumn('botcake_psid_import_runs', $col)) $table->dropColumn($col);
            }
        });
    }
};
