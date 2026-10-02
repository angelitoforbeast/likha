<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * app_settings 'item_stock_start' — petsa (Asia/Manila) kung kailan nagsimulang magbilang ng stock.
 * Isang beses lang itong sine-set; hindi ino-overwrite kung meron na.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('app_settings')) return;
        if (DB::table('app_settings')->where('key', 'item_stock_start')->exists()) return;

        DB::table('app_settings')->insert([
            'key'        => 'item_stock_start',
            'value'      => Carbon::now('Asia/Manila')->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('app_settings')) return;

        DB::table('app_settings')->where('key', 'item_stock_start')->delete();
    }
};
