<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * macro_output.botcake_ad_id: ang Botcake ad_id ng customer noong naikabit ang PSID (para sa per-ad na report).
 * Dagdag lang (nullable), at nilalaktawan kapag mayroon na. Postgres: CONCURRENTLY ang index, kaya labas sa transaction.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEX = 'macro_output_botcake_ad_id_idx';

    public function up(): void
    {
        if (!Schema::hasTable('macro_output')) {
            return;
        }

        if (!Schema::hasColumn('macro_output', 'botcake_ad_id')) {
            Schema::table('macro_output', function (Blueprint $table) {
                $table->string('botcake_ad_id', 64)->nullable();
            });
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS ' . self::INDEX . ' ON macro_output (botcake_ad_id)');
            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $exists = DB::selectOne('
                SELECT 1 FROM information_schema.statistics
                WHERE table_schema = ? AND table_name = ? AND index_name = ?
                LIMIT 1
            ', [DB::getDatabaseName(), 'macro_output', self::INDEX]);

            if ($exists) {
                return;
            }
        }

        Schema::table('macro_output', function (Blueprint $table) {
            $table->index('botcake_ad_id', self::INDEX);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('macro_output') || !Schema::hasColumn('macro_output', 'botcake_ad_id')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . self::INDEX);
        } else {
            try {
                Schema::table('macro_output', function (Blueprint $table) {
                    $table->dropIndex(self::INDEX);
                });
            } catch (\Throwable $e) {
                // walang index: tuloy sa column
            }
        }

        Schema::table('macro_output', function (Blueprint $table) {
            $table->dropColumn('botcake_ad_id');
        });
    }
};
