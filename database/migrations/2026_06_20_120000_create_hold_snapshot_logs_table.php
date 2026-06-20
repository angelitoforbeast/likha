<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * hold_snapshot_logs — run history ng HOLD snapshot (holds:snapshot command /
 * manual "Snapshot now"). Isang row kada takbo: kelan tumakbo (created_at),
 * para saang snapshot_date, ilang items/units ang nakuha, success/error, at
 * source (cron | manual). Para makita kung tuloy-tuloy ba ang daily cron o
 * tumigil (gaya ng nangyari — June 3 pa ang huli).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hold_snapshot_logs')) {
            Schema::create('hold_snapshot_logs', function (Blueprint $table) {
                $table->id();
                $table->date('snapshot_date')->index();        // araw na kinatawan ng snapshot
                $table->unsignedSmallInteger('window')->default(60); // days lookback
                $table->string('source', 20)->default('cron'); // 'cron' | 'manual'
                $table->string('status', 20)->default('success'); // 'success' | 'error'
                $table->unsignedInteger('items')->default(0);
                $table->unsignedInteger('units')->default(0);
                $table->unsignedInteger('duration_ms')->nullable();
                $table->text('message')->nullable();
                $table->timestamps();                          // created_at = kelan tumakbo
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hold_snapshot_logs');
    }
};
