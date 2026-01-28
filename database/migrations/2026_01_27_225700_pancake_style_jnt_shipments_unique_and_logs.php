<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * 1) Create logs table (safe)
         */
        if (!Schema::hasTable('jnt_api_logs')) {
            Schema::create('jnt_api_logs', function (Blueprint $table) {
                $table->bigIncrements('id');

                $table->unsignedBigInteger('jnt_shipment_id')->nullable()->index();
                $table->unsignedBigInteger('macro_output_id')->nullable()->index();
                $table->unsignedBigInteger('jnt_batch_run_id')->nullable()->index();

                $table->string('action', 32)->index(); // CREATE_ORDER / ORDERQUERY / TRACK etc

                $table->json('request_payload')->nullable();
                $table->json('response_payload')->nullable();

                $table->tinyInteger('success')->nullable(); // 1/0
                $table->integer('http_status')->nullable();
                $table->text('error')->nullable();

                // snapshot fields (optional but useful)
                $table->string('mailno', 64)->nullable()->index();
                $table->string('txlogisticid', 64)->nullable()->index();

                $table->timestamp('created_at')->useCurrent();
            });
        }

        /**
         * 2) Enforce Pancake style: UNIQUE(macro_output_id) on jnt_shipments
         *    But before adding unique, we must DEDUPE existing rows.
         */
        if (Schema::hasTable('jnt_shipments') && Schema::hasColumn('jnt_shipments', 'macro_output_id')) {

            // ✅ DEDUPE: keep the latest row (highest id) for each macro_output_id
            // MySQL-safe pattern (delete older duplicates)
            DB::statement("
                DELETE s1
                FROM jnt_shipments s1
                INNER JOIN jnt_shipments s2
                    ON s1.macro_output_id = s2.macro_output_id
                   AND s1.id < s2.id
                WHERE s1.macro_output_id IS NOT NULL
            ");

            // ✅ Add UNIQUE index if not exists
            $exists = DB::select("
                SHOW INDEX FROM jnt_shipments
                WHERE Key_name = 'jnt_shipments_macro_output_id_unique'
            ");

            if (empty($exists)) {
                Schema::table('jnt_shipments', function (Blueprint $table) {
                    $table->unique('macro_output_id', 'jnt_shipments_macro_output_id_unique');
                });
            }
        }
    }

    public function down(): void
    {
        // drop unique index if present
        if (Schema::hasTable('jnt_shipments')) {
            try {
                Schema::table('jnt_shipments', function (Blueprint $table) {
                    $table->dropUnique('jnt_shipments_macro_output_id_unique');
                });
            } catch (\Throwable $e) {
                // ignore (index may not exist)
            }
        }

        // drop logs table
        if (Schema::hasTable('jnt_api_logs')) {
            Schema::drop('jnt_api_logs');
        }
    }
};
