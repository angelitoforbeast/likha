<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // 1) Drop duplicates (keep the lowest id per transaction_id)
        DB::statement("
            DELETE t1 FROM payment_activity_ads_manager t1
            JOIN payment_activity_ads_manager t2
              ON t1.transaction_id = t2.transaction_id
             AND t1.id > t2.id
        ");

        // 2) Drop old composite unique if it exists
        try {
            DB::statement("ALTER TABLE payment_activity_ads_manager DROP INDEX `uniq_txn_acc`");
        } catch (\Throwable $e) { /* ignore */ }

        // 3) Add UNIQUE(transaction_id)
        DB::statement("
            ALTER TABLE payment_activity_ads_manager
            ADD UNIQUE KEY `uniq_txn` (`transaction_id`)
        ");
    }

    public function down(): void
    {
        // Revert unique if needed
        try {
            DB::statement("ALTER TABLE payment_activity_ads_manager DROP INDEX `uniq_txn`");
        } catch (\Throwable $e) { /* ignore */ }

        // Optional: bring back the old composite
        // DB::statement("
        //   ALTER TABLE payment_activity_ads_manager
        //   ADD UNIQUE KEY `uniq_txn_acc` (`transaction_id`,`ad_account`)
        // ");
    }
};
