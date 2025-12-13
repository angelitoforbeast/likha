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

        // 1) Cleanup duplicates BEFORE adding unique index
        // Rule: keep the smallest id per sender_name, delete the rest.
        if ($driver === 'mysql') {
            DB::statement("
                DELETE p1 FROM page_sender_mappings p1
                INNER JOIN page_sender_mappings p2
                  ON p1.sender_name = p2.sender_name
                 AND p1.id > p2.id
            ");
        } elseif ($driver === 'pgsql') {
            DB::statement("
                DELETE FROM page_sender_mappings
                WHERE id IN (
                    SELECT id FROM (
                        SELECT id,
                               ROW_NUMBER() OVER (PARTITION BY sender_name ORDER BY id) AS rn
                        FROM page_sender_mappings
                    ) x
                    WHERE x.rn > 1
                )
            ");
        }

        // 2) Add unique constraint
        Schema::table('page_sender_mappings', function (Blueprint $table) {
            $table->unique('sender_name', 'uniq_sender_name');
        });
    }

    public function down(): void
    {
        Schema::table('page_sender_mappings', function (Blueprint $table) {
            $table->dropUnique('uniq_sender_name');
        });
    }
};
