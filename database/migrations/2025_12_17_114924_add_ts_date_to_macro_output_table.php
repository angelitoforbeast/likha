<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('macro_output', function (Blueprint $table) {
            $table->date('ts_date')->nullable();
        });

        // index separately (works cleanly for both)
        Schema::table('macro_output', function (Blueprint $table) {
            $table->index('ts_date');
        });

        $driver = DB::getDriverName(); // 'mysql' | 'pgsql' | ...

        if ($driver === 'mysql') {
            // TIMESTAMP sample: "00:03 17-12-2025"  -> take last 10 chars => "17-12-2025"
            DB::statement("
                UPDATE macro_output
                SET ts_date = STR_TO_DATE(RIGHT(`TIMESTAMP`, 10), '%d-%m-%Y')
                WHERE ts_date IS NULL
                  AND `TIMESTAMP` IS NOT NULL
                  AND CHAR_LENGTH(`TIMESTAMP`) >= 10
            ");
        } elseif ($driver === 'pgsql') {
            // PostgreSQL: RIGHT(TIMESTAMP,10) then to_date('DD-MM-YYYY')
            DB::statement("
                UPDATE macro_output
                SET ts_date = to_date(right(\"TIMESTAMP\", 10), 'DD-MM-YYYY')
                WHERE ts_date IS NULL
                  AND \"TIMESTAMP\" IS NOT NULL
                  AND length(\"TIMESTAMP\") >= 10
            ");
        } else {
            // If you ever use another DB, keep migration safe
            // (ts_date column + index will still exist, just no backfill)
        }
    }

    public function down(): void
    {
        Schema::table('macro_output', function (Blueprint $table) {
            $table->dropIndex(['ts_date']);
            $table->dropColumn('ts_date');
        });
    }
};
