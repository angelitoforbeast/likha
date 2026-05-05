<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track which phase yung consolidate flow ay nasa:
 *   - NULL              = not yet started
 *   - 'materializing'   = Phase 1: populating from_jnts_2_winners
 *   - 'merging'         = Phase 2: iterating winners → from_jnts_2 (main work)
 *   - 'cleanup'         = Phase 3: dropping winners + final staging cleanup
 *
 * Used para sa UI phase indicator + recovery logic (kung saan dapat
 * mag-resume after a crash/pause).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('bulk_upload_runs', function (Blueprint $table) {
            $table->string('consolidate_phase', 32)->nullable()->after('consolidate_processed');
        });
    }

    public function down(): void
    {
        Schema::table('bulk_upload_runs', function (Blueprint $table) {
            $table->dropColumn('consolidate_phase');
        });
    }
};
