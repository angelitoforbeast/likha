<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Postgres needs this off (CONCURRENTLY)
    public $withinTransaction = false;

    public function up(): void
    {
        // 0) Ensure ts_date column exists
        if (!Schema::hasColumn('macro_output', 'ts_date')) {
            Schema::table('macro_output', function (Blueprint $table) {
                $table->date('ts_date')->nullable()->index('macro_output_ts_date_index');
            });
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // ===== PostgreSQL (Heroku) =====

            // 1) Backfill safely
            DB::statement(<<<'SQL'
UPDATE macro_output
SET ts_date = to_date(right("TIMESTAMP", 10), 'DD-MM-YYYY')
WHERE ts_date IS NULL
  AND "TIMESTAMP" IS NOT NULL
  AND "TIMESTAMP" <> ''
  AND right("TIMESTAMP", 10) ~ '^[0-9]{2}-[0-9]{2}-[0-9]{4}$';
SQL);

            // 2) Trigger function
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION macro_output_set_ts_date()
RETURNS trigger AS $$
BEGIN
  NEW.ts_date :=
    CASE
      WHEN NEW."TIMESTAMP" IS NULL OR NEW."TIMESTAMP" = '' THEN NULL
      WHEN right(NEW."TIMESTAMP", 10) ~ '^[0-9]{2}-[0-9]{2}-[0-9]{4}$'
        THEN to_date(right(NEW."TIMESTAMP", 10), 'DD-MM-YYYY')
      ELSE NULL
    END;

  RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

            // 3) Triggers
            DB::unprepared('DROP TRIGGER IF EXISTS trg_macro_output_set_ts_date_ins ON macro_output;');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_macro_output_set_ts_date_upd ON macro_output;');

            DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_macro_output_set_ts_date_ins
BEFORE INSERT ON macro_output
FOR EACH ROW
EXECUTE FUNCTION macro_output_set_ts_date();
SQL);

            DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_macro_output_set_ts_date_upd
BEFORE UPDATE OF "TIMESTAMP" ON macro_output
FOR EACH ROW
EXECUTE FUNCTION macro_output_set_ts_date();
SQL);

            // 4) Index (ts_date, "PAGE")
            DB::statement(<<<'SQL'
CREATE INDEX CONCURRENTLY IF NOT EXISTS macro_output_ts_date_page_idx
ON macro_output (ts_date, "PAGE");
SQL);

        } else {
            // ===== MySQL / MariaDB (local/dev) =====

            // 1) Backfill safely (skip invalid TIMESTAMP endings)
            DB::statement(<<<'SQL'
UPDATE macro_output
SET ts_date = STR_TO_DATE(RIGHT(`TIMESTAMP`, 10), '%d-%m-%Y')
WHERE ts_date IS NULL
  AND `TIMESTAMP` IS NOT NULL
  AND `TIMESTAMP` <> ''
  AND RIGHT(`TIMESTAMP`, 10) REGEXP '^[0-9]{2}-[0-9]{2}-[0-9]{4}$';
SQL);

            // 2) Drop triggers if existing
            DB::unprepared('DROP TRIGGER IF EXISTS trg_macro_output_set_ts_date_ins;');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_macro_output_set_ts_date_upd;');

            // 3) Create INSERT trigger
            DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_macro_output_set_ts_date_ins
BEFORE INSERT ON macro_output
FOR EACH ROW
SET NEW.ts_date = (
  CASE
    WHEN NEW.`TIMESTAMP` IS NULL OR NEW.`TIMESTAMP` = '' THEN NULL
    WHEN RIGHT(NEW.`TIMESTAMP`, 10) REGEXP '^[0-9]{2}-[0-9]{2}-[0-9]{4}$'
      THEN STR_TO_DATE(RIGHT(NEW.`TIMESTAMP`, 10), '%d-%m-%Y')
    ELSE NULL
  END
);
SQL);

            // 4) Create UPDATE trigger
            DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_macro_output_set_ts_date_upd
BEFORE UPDATE ON macro_output
FOR EACH ROW
SET NEW.ts_date = (
  CASE
    WHEN NEW.`TIMESTAMP` IS NULL OR NEW.`TIMESTAMP` = '' THEN NULL
    WHEN RIGHT(NEW.`TIMESTAMP`, 10) REGEXP '^[0-9]{2}-[0-9]{2}-[0-9]{4}$'
      THEN STR_TO_DATE(RIGHT(NEW.`TIMESTAMP`, 10), '%d-%m-%Y')
    ELSE NULL
  END
);
SQL);

            // 5) Index existence check (information_schema) then create if missing
            $idx = DB::selectOne(<<<'SQL'
SELECT 1
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'macro_output'
  AND index_name = 'macro_output_ts_date_page_idx'
LIMIT 1
SQL);

            if (!$idx) {
                Schema::table('macro_output', function (Blueprint $table) {
                    $table->index(['ts_date', 'PAGE'], 'macro_output_ts_date_page_idx');
                });
            }
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_macro_output_set_ts_date_ins ON macro_output;');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_macro_output_set_ts_date_upd ON macro_output;');
            DB::unprepared('DROP FUNCTION IF EXISTS macro_output_set_ts_date();');

            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS macro_output_ts_date_page_idx;');

        } else {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_macro_output_set_ts_date_ins;');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_macro_output_set_ts_date_upd;');

            Schema::table('macro_output', function (Blueprint $table) {
                $table->dropIndex('macro_output_ts_date_page_idx');
            });
        }

        // optional drop column:
        // Schema::table('macro_output', fn (Blueprint $t) => $t->dropColumn('ts_date'));
    }
};
