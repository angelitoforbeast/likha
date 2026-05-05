<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add batch tracking columns para sa per-batch monitoring sa consolidate phase:
 *
 *   last_batch_at          - timestamp ng pinakahuling natapos na batch
 *   last_batch_duration_ms - duration nung last batch (ms), reference for typical pace
 *
 * Derived sa controller:
 *   - "current batch elapsed" = NOW - last_batch_at (assumes new batch starts right after last)
 *   - "stuck detection" = last_batch_at > 60 secs ago habang processing
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('bulk_upload_runs', function (Blueprint $table) {
            $table->timestamp('last_batch_at')->nullable()->after('consolidate_started_at');
            $table->unsignedInteger('last_batch_duration_ms')->nullable()->after('last_batch_at');
        });
    }

    public function down(): void
    {
        Schema::table('bulk_upload_runs', function (Blueprint $table) {
            $table->dropColumn(['last_batch_at', 'last_batch_duration_ms']);
        });
    }
};
