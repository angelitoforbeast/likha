<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Sino ang nag-alis (nag-clear) ng value: para sa Changes. Null = hindi alam / system.
        if (!Schema::hasTable('page_settings') || Schema::hasColumn('page_settings', 'replaced_by')) return;

        Schema::table('page_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('replaced_by')->nullable()->after('replaced_at');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('page_settings', 'replaced_by')) {
            Schema::table('page_settings', fn (Blueprint $table) => $table->dropColumn('replaced_by'));
        }
    }
};
