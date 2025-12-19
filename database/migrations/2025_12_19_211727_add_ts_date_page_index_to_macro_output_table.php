<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ✅ IMPORTANT: For Postgres CONCURRENTLY, must NOT be inside a transaction.
     * NOTE: Do NOT type this property (no "bool") to avoid PHP/Laravel property type conflicts.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasTable('macro_output')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // ✅ Skip if already exists
            $exists = DB::selectOne("
                SELECT 1
                FROM pg_indexes
                WHERE schemaname = current_schema()
                  AND tablename  = 'macro_output'
                  AND indexname  = 'macro_output_ts_date_page_idx'
                LIMIT 1
            ");

            if ($exists) {
                return;
            }

            // ✅ Postgres: CONCURRENTLY + IF NOT EXISTS (must be outside transaction)
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS macro_output_ts_date_page_idx ON macro_output (ts_date, "PAGE")');
            return;
        }

        // ✅ MySQL / MariaDB / others
        if (!Schema::hasColumn('macro_output', 'ts_date') || !Schema::hasColumn('macro_output', 'PAGE')) {
            return;
        }

        // ✅ Skip if already exists (MySQL)
        try {
            $dbName = DB::getDatabaseName();

            $exists = DB::selectOne("
                SELECT 1
                FROM information_schema.statistics
                WHERE table_schema = ?
                  AND table_name   = ?
                  AND index_name   = ?
                LIMIT 1
            ", [$dbName, 'macro_output', 'macro_output_ts_date_page_idx']);

            if ($exists) {
                return;
            }
        } catch (\Throwable $e) {
            // If something weird happens, we'll just attempt to create below.
        }

        Schema::table('macro_output', function (Blueprint $table) {
            $table->index(['ts_date', 'PAGE'], 'macro_output_ts_date_page_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('macro_output')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS macro_output_ts_date_page_idx');
            return;
        }

        // ✅ MySQL safe drop
        try {
            Schema::table('macro_output', function (Blueprint $table) {
                $table->dropIndex('macro_output_ts_date_page_idx');
            });
        } catch (\Throwable $e) {
            // ignore
        }
    }
};
