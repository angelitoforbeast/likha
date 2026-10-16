<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Index sa macro_output.botcake_psid: ang customers view ng /page-settings ay nagbibilang ng orders kada PSID
 * (50 PSID bawat page). Kung wala ito, buong macro_output ang binabasa sa bawat bukas ng page.
 * Dagdag lang, at nilalaktawan kapag may index na ang column. Postgres: CONCURRENTLY, kaya labas sa transaction.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEX = 'macro_output_botcake_psid_idx';

    public function up(): void
    {
        if (!Schema::hasTable('macro_output') || !Schema::hasColumn('macro_output', 'botcake_psid')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS ' . self::INDEX . ' ON macro_output (botcake_psid)');
            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            // Anumang index na nagsisimula sa botcake_psid ay sapat na (maaaring may idinagdag na sa server).
            $exists = DB::selectOne('
                SELECT 1 FROM information_schema.statistics
                WHERE table_schema = ? AND table_name = ? AND column_name = ? AND seq_in_index = 1
                LIMIT 1
            ', [DB::getDatabaseName(), 'macro_output', 'botcake_psid']);

            if ($exists) {
                return;
            }
        }

        Schema::table('macro_output', function (Blueprint $table) {
            $table->index('botcake_psid', self::INDEX);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('macro_output')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . self::INDEX);
            return;
        }

        try {
            Schema::table('macro_output', function (Blueprint $table) {
                $table->dropIndex(self::INDEX);
            });
        } catch (\Throwable $e) {
            // wala ang index na ito (hal. may dati nang ibang index): walang gagawin
        }
    }
};
