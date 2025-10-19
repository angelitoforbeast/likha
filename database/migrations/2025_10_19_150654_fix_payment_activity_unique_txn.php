<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // If you ever need CONCURRENTLY in Postgres, set this to false.
    // protected bool $withinTransaction = false;

    public function up(): void
    {
        $table  = 'payment_activity_ads_manager';
        $index  = 'pa_unique_txn';

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // 1) Remove duplicates (keep the smallest id for each transaction_id)
            DB::statement(<<<'SQL'
WITH dups AS (
  SELECT id
  FROM (
    SELECT
      id,
      transaction_id,
      ROW_NUMBER() OVER (PARTITION BY transaction_id ORDER BY id) AS rn
    FROM payment_activity_ads_manager
  ) t
  WHERE t.rn > 1
)
DELETE FROM payment_activity_ads_manager
WHERE id IN (SELECT id FROM dups);
SQL);

            // 2) Add unique index on transaction_id (idempotent)
            DB::statement("
                CREATE UNIQUE INDEX IF NOT EXISTS {$index}
                ON {$table} (transaction_id)
            ");
        } else {
            // Default: MySQL / MariaDB

            // 1) Remove duplicates (keep the smallest id for each transaction_id)
            DB::statement(<<<'SQL'
DELETE t1
FROM payment_activity_ads_manager t1
JOIN payment_activity_ads_manager t2
  ON t1.transaction_id = t2.transaction_id
 AND t1.id > t2.id
SQL);

            // 2) Add unique index on transaction_id (guarded)
            // MySQL has no IF NOT EXISTS for ADD UNIQUE in ALTER TABLE, so we try/catch.
            try {
                DB::statement("
                    ALTER TABLE {$table}
                    ADD UNIQUE INDEX {$index} (transaction_id)
                ");
            } catch (\Throwable $e) {
                // Likely already exists; ignore to keep idempotent.
            }
        }
    }

    public function down(): void
    {
        $table = 'payment_activity_ads_manager';
        $index = 'pa_unique_txn';

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        } else {
            // MySQL / MariaDB
            try {
                DB::statement("ALTER TABLE {$table} DROP INDEX {$index}");
            } catch (\Throwable $e) {
                // ignore if not found
            }
        }
    }
};
