<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-time data cleanup: NULL out '0000-00-00 00:00:00' sentinel datetime values
 * sa from_jnts_2_staging, from_jnts_2_winners, at from_jnts_2 tables.
 *
 * Bakit kailangan:
 *   JNT data sometimes contains '0000-00-00 00:00:00' as sentinel for "unknown date".
 *   MySQL strict mode (NO_ZERO_DATE in SQL_MODE) rejects this value at the SQL parser
 *   level — kahit sa loob lang ng NULLIF()/literal expressions. Migration replaces
 *   these with proper NULL so future queries (consolidator, pipeline jobs) walang
 *   ma-encounter na bad value sa data.
 *
 * Use YEAR(col) = 0 to detect sentinel zero-dates — strict-mode-safe (uses function,
 * walang bad literal). YEAR returns 0 for '0000-00-00 00:00:00'.
 *
 * Idempotent — safe to run multiple times. Pag wala nang bad rows, no-op lang.
 */
return new class extends Migration {
    public function up(): void
    {
        $tables = [
            'from_jnts_2_staging',
            'from_jnts_2_winners',
            'from_jnts_2',
        ];
        $columns = ['submission_time', 'signingtime'];

        foreach ($tables as $table) {
            // Skip kung table doesn't exist (defensive)
            if (!\Illuminate\Support\Facades\Schema::hasTable($table)) {
                Log::info("sanitize_zero_dates: skipping {$table} (table doesn't exist)");
                continue;
            }

            foreach ($columns as $col) {
                if (!\Illuminate\Support\Facades\Schema::hasColumn($table, $col)) {
                    continue;
                }

                $affected = DB::update("UPDATE {$table} SET {$col} = NULL WHERE YEAR({$col}) = 0");
                if ($affected > 0) {
                    Log::info("sanitize_zero_dates: nulled {$affected} rows in {$table}.{$col}");
                }
            }
        }
    }

    public function down(): void
    {
        // No reverse — sentinel '0000-00-00' values are intentionally lost.
        // Pag rollback man, hindi natin maibalik because we don't track which rows
        // were originally zero-dates vs proper NULLs.
    }
};
