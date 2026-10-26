<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Ang sumulat (source) o nag-clear (replaced_source) mula sa labas ng Likha, hal. ang X-Forbeast-User ng
        // /api/gateway/pages/{id}/values: walang user id dito, kaya text. Null = user ng Likha o system.
        if (!Schema::hasTable('page_settings') || Schema::hasColumn('page_settings', 'source')) return;

        Schema::table('page_settings', function (Blueprint $table) {
            $table->string('source', 200)->nullable()->after('created_by');
            $table->string('replaced_source', 200)->nullable()->after('replaced_by');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('page_settings', 'source')) {
            Schema::table('page_settings', fn (Blueprint $table) => $table->dropColumn(['source', 'replaced_source']));
        }
    }
};
