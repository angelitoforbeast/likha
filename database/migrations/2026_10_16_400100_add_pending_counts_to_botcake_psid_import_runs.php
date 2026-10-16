<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('botcake_psid_import_runs', function (Blueprint $table) {
            $table->unsignedInteger('total_waiting')->default(0);
            $table->unsignedInteger('total_matched_from_waiting')->default(0);
            $table->unsignedInteger('total_expired')->default(0);
            $table->unsignedInteger('total_conflicts')->default(0);
            $table->unsignedInteger('total_reorder_copies')->default(0);
            $table->unsignedInteger('total_deleted_rows')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('botcake_psid_import_runs', function (Blueprint $table) {
            $table->dropColumn([
                'total_waiting', 'total_matched_from_waiting', 'total_expired',
                'total_conflicts', 'total_reorder_copies', 'total_deleted_rows',
            ]);
        });
    }
};
