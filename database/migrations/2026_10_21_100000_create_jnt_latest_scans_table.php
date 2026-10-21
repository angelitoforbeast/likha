<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * jnt_latest_scans: ang pinakabagong J&T scan ng bawat waybill na sinagot ng kusang track-sync (kada oras). Hiwalay na
 * maliit na table: walang ALTER sa from_jnts (malaki, hinaharangan ang hourly import). Binabasa ng chatblast; maaari
 * ring basahin ng ibang page. Kasama ang kind ng jnt_track_sync_runs (maliit na table): auto = ang kusang track-sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('jnt_latest_scans')) {
            Schema::create('jnt_latest_scans', function (Blueprint $table) {
                $table->id();
                $table->string('waybill', 64)->unique();
                $table->string('scan_type', 64)->nullable();   // ang pinakabagong scan, kahit Problematic o di-kilala
                $table->dateTime('scan_time')->nullable();     // oras ng scan ayon sa J&T (Manila)
                $table->string('scan_place', 191)->nullable();
                $table->string('rider_name', 191)->nullable(); // mula sa pinakabagong On Delivery scan ng sagot
                $table->string('rider_phone', 30)->nullable();
                $table->timestamp('checked_at')->nullable();   // kailan sumagot ang J&T (hindi binabago ng bigong tawag)
            });
        }

        if (Schema::hasTable('jnt_track_sync_runs') && !Schema::hasColumn('jnt_track_sync_runs', 'kind')) {
            Schema::table('jnt_track_sync_runs', function (Blueprint $table) {
                $table->string('kind', 20)->nullable()->index(); // auto = kusang track-sync; null = manual o lumang chatblast
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('jnt_track_sync_runs') && Schema::hasColumn('jnt_track_sync_runs', 'kind')) {
            Schema::table('jnt_track_sync_runs', function (Blueprint $table) {
                $table->dropIndex(['kind']);
                $table->dropColumn('kind');
            });
        }
        Schema::dropIfExists('jnt_latest_scans');
    }
};
