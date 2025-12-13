<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        // ✅ column is "SENDER_NAME" (quoted = case-sensitive in Postgres)
        if ($driver === 'mysql') {
            // MySQL treats identifiers case-insensitively (usually), but keep it consistent anyway
            DB::statement("
                DELETE p1 FROM page_sender_mappings p1
                INNER JOIN page_sender_mappings p2
                  ON p1.SENDER_NAME = p2.SENDER_NAME
                 AND p1.id > p2.id
            ");
        } elseif ($driver === 'pgsql') {
            DB::statement('
                DELETE FROM page_sender_mappings
                WHERE id IN (
                    SELECT id FROM (
                        SELECT id,
                               ROW_NUMBER() OVER (PARTITION BY "SENDER_NAME" ORDER BY id) AS rn
                        FROM page_sender_mappings
                    ) x
                    WHERE x.rn > 1
                )
            ');
        }

        // 2) Add unique constraint on the correct column
        Schema::table('page_sender_mappings', function (Blueprint $table) use ($driver) {
            if ($driver === "pgsql") {
                // ✅ Postgres needs quotes if the column was created as "SENDER_NAME"
                DB::statement('ALTER TABLE page_sender_mappings ADD CONSTRAINT uniq_sender_name UNIQUE ("SENDER_NAME")');
            } else {
                $table->unique('SENDER_NAME', 'uniq_sender_name');
            }
        });
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === "pgsql") {
            DB::statement('ALTER TABLE page_sender_mappings DROP CONSTRAINT IF EXISTS uniq_sender_name');
        } else {
            Schema::table('page_sender_mappings', function (Blueprint $table) {
                $table->dropUnique('uniq_sender_name');
            });
        }
    }
};
