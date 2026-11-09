<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 251: the send-queue sweep looks up chatbot results whose resume job was lost (kind = 'chatbot', status done or
 * failed, updated_at at least 30 s old). No index covered kind, so the query walked the whole table, most of it old
 * resumed rows. Equalities first (kind, status), then the range (updated_at). It also serves the other kind + status
 * lookups (the page transfer and the sequence lateness report).
 * The table holds up to a few million rows, so on MySQL the index is added online (InnoDB in-place, no write lock).
 */
return new class extends Migration {
    private const TABLE = 'botcake_send_requests';
    private const INDEX = 'botcake_send_requests_kind_status_updated_at_idx';

    // An in-place ALTER commits on its own on MySQL; there is nothing to wrap.
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE) || Schema::hasIndex(self::TABLE, self::INDEX)) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `' . self::TABLE . '` ADD INDEX `' . self::INDEX . '` (`kind`, `status`, `updated_at`), ALGORITHM=INPLACE, LOCK=NONE');
            return;
        }

        Schema::table(self::TABLE, fn ($table) => $table->index(['kind', 'status', 'updated_at'], self::INDEX));
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE) || !Schema::hasIndex(self::TABLE, self::INDEX)) {
            return;
        }

        Schema::table(self::TABLE, fn ($table) => $table->dropIndex(self::INDEX));
    }
};
