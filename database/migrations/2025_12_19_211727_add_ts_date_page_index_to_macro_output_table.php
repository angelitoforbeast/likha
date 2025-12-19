<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ✅ PostgreSQL: CREATE INDEX CONCURRENTLY cannot run inside a transaction block.
     * Laravel migrations are usually wrapped in a transaction, so we disable it here.
     */
    public function withinTransaction(): bool
    {
        return false;
    }

    public function up(): void
    {
        // ✅ Guard: table must exist
        if (!Schema::hasTable('macro_output')) {
            return;
        }

        $driver = DB::getDriverName();

        // ✅ If index already exists, do nothing
        if ($driver === 'pgsql') {
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

            // ✅ Postgres: concurrent + quoting PAGE
            DB::statement('CREATE INDEX CONCURRENTLY macro_output_ts_date_page_idx ON macro_output (ts_date, "PAGE")');
            return;
        }

        // ✅ MySQL / MariaDB / others
        if (!Schema::hasColumn('macro_output', 'ts_date') || !Schema::hasColumn('macro_output', 'PAGE')) {
            return;
        }

        // Check existing index on MySQL
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
            // if information_schema not accessible, just attempt create safely
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
            // ✅ Safe drop (won't error if missing)
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS macro_output_ts_date_page_idx');
            return;
        }

        // ✅ MySQL/local dev safe drop
        try {
            Schema::table('macro_output', function (Blueprint $table) {
                $table->dropIndex('macro_output_ts_date_page_idx');
            });
        } catch (\Throwable $e) {
            // ignore if index doesn't exist
        }
    }
};
