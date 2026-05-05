<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add per-batch consolidate progress tracking sa bulk_upload_runs.
 *
 * Sa bagong consolidate approach (ProcessStagingChunkJntV2Run):
 *   - consolidate_total      = total unique waybills to merge (one-time count)
 *   - consolidate_processed  = how many waybills already merged
 *   - paused_at              = kung in-pause ng user, timestamp dito
 *   - cancel_requested_at    = kung cinancel mid-flight, mag-eexit gracefully
 *
 * Lahat nullable — backwards-compatible sa existing runs.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('bulk_upload_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('consolidate_total')->nullable()->after('total_errors');
            $table->unsignedBigInteger('consolidate_processed')->default(0)->after('consolidate_total');
            $table->timestamp('paused_at')->nullable()->after('consolidate_processed');
            $table->timestamp('cancel_requested_at')->nullable()->after('paused_at');
        });
    }

    public function down(): void
    {
        Schema::table('bulk_upload_runs', function (Blueprint $table) {
            $table->dropColumn(['consolidate_total', 'consolidate_processed', 'paused_at', 'cancel_requested_at']);
        });
    }
};
