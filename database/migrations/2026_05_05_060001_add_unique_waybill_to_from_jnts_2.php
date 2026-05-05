<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add UNIQUE INDEX sa from_jnts_2.waybill_number para magamit yung
 * INSERT ... ON DUPLICATE KEY UPDATE optimization sa consolidate phase.
 *
 * Pre-checked: 0 duplicate waybills sa current data, safe to add.
 *
 * Pinanatili yung old non-unique index para hindi ma-disrupt ibang queries
 * — UNIQUE index is a superset (works for lookups too) pero may existing code
 * baka may reference sa specific index name.
 *
 * Lock time estimate: 30-60 secs sa ~2M row table.
 */
return new class extends Migration {
    public function up(): void
    {
        // Defensive — drop kung may existing unique-named na (idempotent)
        $exists = DB::select("
            SELECT COUNT(*) AS c FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'from_jnts_2'
              AND index_name = 'idx_from_jnts_2_waybill_unique'
        ");
        if (($exists[0]->c ?? 0) > 0) {
            DB::statement('ALTER TABLE from_jnts_2 DROP INDEX idx_from_jnts_2_waybill_unique');
        }

        DB::statement('ALTER TABLE from_jnts_2 ADD UNIQUE INDEX idx_from_jnts_2_waybill_unique (waybill_number)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE from_jnts_2 DROP INDEX idx_from_jnts_2_waybill_unique');
    }
};
