<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Materialized winners table — Phase 1 ng consolidate flow.
 *
 * Workflow:
 *   Phase 1: Materialize winners (one-time per run)
 *     INSERT INTO from_jnts_2_winners
 *     SELECT winners FROM from_jnts_2_staging
 *       WITH priority logic (Returned > Delivered > Others, MAX id tiebreaker)
 *
 *   Phase 2: Iterate winners table, UPSERT to from_jnts_2 in chunks
 *     SELECT next 5000 from winners (cursor-based by id)
 *     UPSERT to from_jnts_2 (no complex subquery — winners pre-computed)
 *     DELETE processed waybills from winners + staging
 *
 *   Phase 3: Cleanup
 *     DELETE remaining staging rows (idempotency safety net)
 *     DROP/TRUNCATE winners table for this run
 *
 * Schema: mirrors from_jnts_2_staging (minus parsed_at — not needed downstream).
 * Indexed by (bulk_run_id, id) for fast cursor pagination during Phase 2.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('from_jnts_2_winners', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Per-batch isolation
            $table->unsignedBigInteger('bulk_run_id');

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

            // Cursor-friendly index for Phase 2 iteration
            $table->index(['bulk_run_id', 'id'], 'idx_from_jnts_2_winners_run_id');
            $table->index('waybill_number', 'idx_from_jnts_2_winners_waybill');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('from_jnts_2_winners');
    }
};
