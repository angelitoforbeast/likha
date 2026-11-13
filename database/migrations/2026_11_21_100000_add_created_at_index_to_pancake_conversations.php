<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 247: Retrieve 2 filters pancake_conversations by a created_at range and orders by it.
 * The table has about 730,000 rows, so on MySQL the index is added online (InnoDB in-place, no write lock).
 */
return new class extends Migration {
    private const INDEX = 'pancake_conversations_created_at_idx';

    // An in-place ALTER commits on its own on MySQL; there is nothing to wrap.
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasTable('pancake_conversations') || Schema::hasIndex('pancake_conversations', self::INDEX)) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `pancake_conversations` ADD INDEX `' . self::INDEX . '` (`created_at`), ALGORITHM=INPLACE, LOCK=NONE');
            return;
        }

        Schema::table('pancake_conversations', fn ($table) => $table->index('created_at', self::INDEX));
    }

    public function down(): void
    {
        if (!Schema::hasTable('pancake_conversations') || !Schema::hasIndex('pancake_conversations', self::INDEX)) {
            return;
        }

        Schema::table('pancake_conversations', fn ($table) => $table->dropIndex(self::INDEX));
    }
};
