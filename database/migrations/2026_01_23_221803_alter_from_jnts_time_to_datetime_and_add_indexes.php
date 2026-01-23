<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private function indexExists(string $table, string $index): bool
    {
        $row = DB::selectOne("
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND index_name = ?
            LIMIT 1
        ", [$table, $index]);

        return (bool) $row;
    }

    public function up(): void
    {
        // Normalize empties -> NULL
        DB::statement("UPDATE from_jnts SET submission_time = NULL WHERE submission_time = ''");
        DB::statement("UPDATE from_jnts SET signingtime     = NULL WHERE signingtime     = ''");

        // Convert types (safe dahil 0 bad_sub/bad_sign ka)
        DB::statement("ALTER TABLE from_jnts MODIFY submission_time DATETIME NULL");
        DB::statement("ALTER TABLE from_jnts MODIFY signingtime     DATETIME NULL");

        // Indexes
        $indexes = [
            'idx_from_jnts_submission_time' => "CREATE INDEX idx_from_jnts_submission_time ON from_jnts (submission_time)",
            'idx_from_jnts_signingtime'     => "CREATE INDEX idx_from_jnts_signingtime     ON from_jnts (signingtime)",
            'idx_from_jnts_status'          => "CREATE INDEX idx_from_jnts_status          ON from_jnts (status)",
            'idx_from_jnts_waybill'         => "CREATE INDEX idx_from_jnts_waybill         ON from_jnts (waybill_number)",
            'idx_from_jnts_signing_status'  => "CREATE INDEX idx_from_jnts_signing_status  ON from_jnts (signingtime, status)",
            'idx_from_jnts_sender_item'     => "CREATE INDEX idx_from_jnts_sender_item     ON from_jnts (sender, item_name)",
        ];

        foreach ($indexes as $name => $sql) {
            if (!$this->indexExists('from_jnts', $name)) {
                DB::statement($sql);
            }
        }
    }

    public function down(): void
    {
        // Drop indexes if present
        $drop = [
            'idx_from_jnts_sender_item',
            'idx_from_jnts_signing_status',
            'idx_from_jnts_waybill',
            'idx_from_jnts_status',
            'idx_from_jnts_signingtime',
            'idx_from_jnts_submission_time',
        ];

        foreach ($drop as $name) {
            if ($this->indexExists('from_jnts', $name)) {
                DB::statement("DROP INDEX {$name} ON from_jnts");
            }
        }

        DB::statement("ALTER TABLE from_jnts MODIFY submission_time VARCHAR(255) NULL");
        DB::statement("ALTER TABLE from_jnts MODIFY signingtime     VARCHAR(255) NULL");
    }
};
