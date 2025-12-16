<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        // Works for both MySQL + Postgres (normal btree index)
        Schema::table('macro_output', function ($table) {
            // STATUS index (name explicitly set)
            $table->index('STATUS', 'idx_macro_output_status');
        });

        if ($driver === 'mysql') {
            // FULLTEXT for MySQL
            DB::statement("ALTER TABLE `macro_output` ADD FULLTEXT INDEX `ft_all_user_input` (`all_user_input`)");
        }

        if ($driver === 'pgsql') {
            // GIN index on to_tsvector('simple', all_user_input)
            // NOTE: double quotes because your column names are uppercase/mixed-case in codebase.
            DB::statement("CREATE INDEX idx_macro_output_all_user_input_fts
                ON macro_output
                USING GIN (to_tsvector('simple', \"all_user_input\"))");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        // Drop STATUS index (Laravel dropIndex expects index name)
        Schema::table('macro_output', function ($table) {
            $table->dropIndex('idx_macro_output_status');
        });

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE `macro_output` DROP INDEX `ft_all_user_input`");
        }

        if ($driver === 'pgsql') {
            DB::statement("DROP INDEX IF EXISTS idx_macro_output_all_user_input_fts");
        }
    }
};
