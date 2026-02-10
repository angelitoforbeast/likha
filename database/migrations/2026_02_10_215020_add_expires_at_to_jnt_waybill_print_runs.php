<?php

// database/migrations/xxxx_add_expires_at_to_jnt_waybill_print_runs.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('jnt_waybill_print_runs', function (Blueprint $table) {
            if (!Schema::hasColumn('jnt_waybill_print_runs', 'expires_at')) {
                $table->dateTime('expires_at')->nullable()->index();
            }
        });
    }

    public function down(): void {
        Schema::table('jnt_waybill_print_runs', function (Blueprint $table) {
            if (Schema::hasColumn('jnt_waybill_print_runs', 'expires_at')) {
                $table->dropColumn('expires_at');
            }
        });
    }
};
