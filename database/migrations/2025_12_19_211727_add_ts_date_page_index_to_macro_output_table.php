<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $table = 'macro_output';
    private string $indexName = 'macro_output_ts_date_page_idx';

    /**
     * Laravel 12 way to disable wrapping in a transaction.
     * Needed for Postgres CREATE INDEX CONCURRENTLY.
     */
    public function withinTransaction(): bool
    {
        return false;
    }

    private function indexExists(): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $row = DB::selectOne("
                select 1
                from pg_indexes
                where schemaname = current_schema()
                  and tablename = ?
                  and indexname = ?
                limit 1
            ", [$this->table, $this->indexName]);

            return (bool) $row;
        }

        if ($driver === 'mysql') {
            $row = DB::selectOne("
                select 1
                from information_schema.statistics
                where table_schema = database()
                  and table_name = ?
                  and index_name = ?
                limit 1
            ", [$this->table, $this->indexName]);

            return (bool) $row;
        }

        // other drivers (sqlite, etc.)
        return false;
    }

    public function up(): void
    {
        if (!Schema::hasTable($this->table)) return;
        if (!Schema::hasColumn($this->table, 'ts_date')) return;
        if (!Schema::hasColumn($this->table, 'PAGE')) return;

        if ($this->indexExists()) {
            // already created; do nothing
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            // IMPORTANT: "PAGE" must be quoted (uppercase column)
            DB::statement(sprintf(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (ts_date, "PAGE")',
                $this->indexName,
                $this->table
            ));
            return;
        }

        // MySQL: use schema builder
        Schema::table($this->table, function (Blueprint $table) {
            $table->index(['ts_date', 'PAGE'], $this->indexName);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable($this->table)) return;

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(sprintf(
                'DROP INDEX CONCURRENTLY IF EXISTS %s',
                $this->indexName
            ));
            return;
        }

        if ($this->indexExists()) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->dropIndex($this->indexName);
            });
        }
    }
};
