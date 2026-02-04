<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // safety: avoid duplicate add
        if (
            Schema::hasColumn('macro_output', 'validate_1') ||
            Schema::hasColumn('macro_output', 'validate_2') ||
            Schema::hasColumn('macro_output', 'item_checker')
        ) {
            return;
        }

        // If edited_barangay exists, put flags right after it (so before ts_date)
        if (Schema::hasColumn('macro_output', 'edited_barangay')) {
            DB::statement("
                ALTER TABLE `macro_output`
                    ADD COLUMN `validate_1` TINYINT(1) NOT NULL DEFAULT 0 AFTER `edited_barangay`,
                    ADD COLUMN `validate_2` TINYINT(1) NOT NULL DEFAULT 0 AFTER `validate_1`,
                    ADD COLUMN `item_checker` TINYINT(1) NOT NULL DEFAULT 0 AFTER `validate_2`
            ");
            return;
        }

        // fallback: just append at end (rare)
        DB::statement("
            ALTER TABLE `macro_output`
                ADD COLUMN `validate_1` TINYINT(1) NOT NULL DEFAULT 0,
                ADD COLUMN `validate_2` TINYINT(1) NOT NULL DEFAULT 0,
                ADD COLUMN `item_checker` TINYINT(1) NOT NULL DEFAULT 0
        ");
    }

    public function down(): void
    {
        if (Schema::hasColumn('macro_output', 'item_checker')) {
            DB::statement("ALTER TABLE `macro_output` DROP COLUMN `item_checker`");
        }
        if (Schema::hasColumn('macro_output', 'validate_2')) {
            DB::statement("ALTER TABLE `macro_output` DROP COLUMN `validate_2`");
        }
        if (Schema::hasColumn('macro_output', 'validate_1')) {
            DB::statement("ALTER TABLE `macro_output` DROP COLUMN `validate_1`");
        }
    }
};
