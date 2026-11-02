<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * app_settings 'stock_returned_source' — saan galing ang "returned" ng stock sa /item: 'jnt' (J&T Returned) o 'nexus'
 * (Nexus RTS). Default 'jnt'. Ang owner lang ang nagpapalit nito; hindi ino-overwrite kung meron na.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('app_settings')) return;
        if (DB::table('app_settings')->where('key', 'stock_returned_source')->exists()) return;

        DB::table('app_settings')->insert([
            'key' => 'stock_returned_source', 'value' => 'jnt', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('app_settings')) return;

        // Ang default lang ang tinatanggal; ang pinili ng owner ay naiiwan.
        DB::table('app_settings')->where('key', 'stock_returned_source')->where('value', 'jnt')->delete();
    }
};
