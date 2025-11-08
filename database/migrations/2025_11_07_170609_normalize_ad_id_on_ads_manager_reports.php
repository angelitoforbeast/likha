<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1) Clean current data (NULL -> '')
        DB::table('ads_manager_reports')
            ->whereNull('ad_id')
            ->update(['ad_id' => '']);

        // 2) Trim whitespace just in case (MySQL-compatible)
        DB::statement("UPDATE ads_manager_reports SET ad_id = TRIM(ad_id)");

        // 3) Make ad_id NOT NULL (requires doctrine/dbal if the column already exists)
        Schema::table('ads_manager_reports', function (Blueprint $table) {
            $table->string('ad_id')->default('')->change();
        });
    }

    public function down(): void
    {
        Schema::table('ads_manager_reports', function (Blueprint $table) {
            $table->string('ad_id')->nullable()->default(null)->change();
        });
    }
};
