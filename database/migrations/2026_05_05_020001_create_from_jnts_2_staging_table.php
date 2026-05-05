<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staging table para sa V2 batch consolidation.
 *
 * Workflow:
 *   1. Per-file workers bulk-INSERT into this table (no skip checks, no upserts)
 *   2. After all files done, ConsolidateAndMergeJntV2Run job runs:
 *      - GROUPs by waybill within bulk_run_id
 *      - Picks final status per waybill (latest signingtime, then latest parsed_at)
 *      - Bulk merges to from_jnts_2 with skip rule applied via SQL
 *   3. Cleanup: DELETE rows for the completed bulk_run_id
 *
 * Lighter indexing than from_jnts_2 — kasi temporary lang yung data,
 * at queried mostly by (bulk_run_id, waybill_number) lang.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('from_jnts_2_staging', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Per-batch isolation
            $table->unsignedBigInteger('bulk_run_id')->index('idx_from_jnts_2_staging_run');
            $table->unsignedBigInteger('upload_log_id')->nullable();

            // Same data shape as from_jnts_2 (minus status_logs — built downstream)
            $table->dateTime('submission_time')->nullable();
            $table->string('waybill_number')->nullable();
            $table->string('receiver')->nullable();
            $table->string('receiver_cellphone')->nullable();
            $table->string('sender')->nullable();
            $table->string('item_name')->nullable();
            $table->string('cod')->nullable();
            $table->string('remarks')->nullable();
            $table->string('status')->nullable();
            $table->dateTime('signingtime')->nullable();

            $table->string('province')->nullable();
            $table->string('city')->nullable();
            $table->string('barangay')->nullable();
            $table->decimal('total_shipping_cost', 10, 2)->nullable();
            $table->string('rts_reason')->nullable();

            // Sorting helpers for consolidation step
            $table->timestamp('parsed_at')->useCurrent();

            // Composite index for the GROUP BY waybill within run pattern
            $table->index(['bulk_run_id', 'waybill_number'], 'idx_from_jnts_2_staging_run_waybill');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('from_jnts_2_staging');
    }
};
