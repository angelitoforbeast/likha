<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 264: ads_manager_reports.headline goes from varchar(255) to varchar(1000), so an Ads Manager headline longer
 * than 255 characters no longer fails the upload (MySQL 1406 "Data too long for column 'headline'").
 *
 * MySQL: in utf8mb4 both lengths use a 2-byte length prefix, so the change is in place (no copy of the ~1M-row
 * table, no lock). The column keeps its own charset and collation (read from information_schema), stays nullable
 * and keeps its position. Other drivers (sqlite in the tests) use the schema builder.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `ads_manager_reports` MODIFY `headline` VARCHAR(1000) ' . $this->charsetClause()
                . ' NULL, ALGORITHM=INPLACE, LOCK=NONE');

            return;
        }

        Schema::table('ads_manager_reports', function (Blueprint $t) {
            $t->string('headline', 1000)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Shortening is not in place: MySQL copies the table. Fails (strict mode) if a headline is over 255.
            DB::statement('ALTER TABLE `ads_manager_reports` MODIFY `headline` VARCHAR(255) ' . $this->charsetClause() . ' NULL');

            return;
        }

        Schema::table('ads_manager_reports', function (Blueprint $t) {
            $t->string('headline', 255)->nullable()->change();
        });
    }

    /** The column's current "CHARACTER SET x COLLATE y", so the change keeps it. */
    private function charsetClause(): string
    {
        $col = DB::selectOne("SELECT CHARACTER_SET_NAME AS cs, COLLATION_NAME AS co FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ads_manager_reports' AND COLUMN_NAME = 'headline'");

        if (!$col || !$col->cs || !$col->co) {
            return '';
        }

        foreach ([$col->cs, $col->co] as $name) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                throw new \RuntimeException("Unexpected charset or collation name on ads_manager_reports.headline: {$name}");
            }
        }

        return "CHARACTER SET {$col->cs} COLLATE {$col->co}";
    }
};
