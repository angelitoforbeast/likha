<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ✅ If table does NOT exist, create it (normal case)
        if (!Schema::hasTable('jnt_shipments')) {
            Schema::create('jnt_shipments', function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger('jnt_batch_run_id')->nullable();
                $table->unsignedBigInteger('macro_output_id')->nullable();

                $table->string('txlogisticid', 64)->nullable();
                $table->string('mailno', 64)->nullable(); // waybill / billcode
                $table->string('sortingcode', 64)->nullable();
                $table->string('sortingNo', 32)->nullable();

                $table->boolean('success')->default(false);
                $table->string('reason', 80)->nullable();

                $table->json('request_payload')->nullable();
                $table->json('response_payload')->nullable();

                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index('jnt_batch_run_id');
                $table->index('macro_output_id');
                $table->index('mailno');

                return;
            });

            return;
        }

        // ✅ If table ALREADY exists, patch it safely (prevents "already exists" errors)
        Schema::table('jnt_shipments', function (Blueprint $table) {
            // Columns (add only if missing)
            if (!Schema::hasColumn('jnt_shipments', 'jnt_batch_run_id')) {
                $table->unsignedBigInteger('jnt_batch_run_id')->nullable()->after('id');
            }
            if (!Schema::hasColumn('jnt_shipments', 'macro_output_id')) {
                $table->unsignedBigInteger('macro_output_id')->nullable()->after('jnt_batch_run_id');
            }

            if (!Schema::hasColumn('jnt_shipments', 'txlogisticid')) {
                $table->string('txlogisticid', 64)->nullable()->after('macro_output_id');
            }
            if (!Schema::hasColumn('jnt_shipments', 'mailno')) {
                $table->string('mailno', 64)->nullable()->after('txlogisticid');
            }
            if (!Schema::hasColumn('jnt_shipments', 'sortingcode')) {
                $table->string('sortingcode', 64)->nullable()->after('mailno');
            }
            if (!Schema::hasColumn('jnt_shipments', 'sortingNo')) {
                $table->string('sortingNo', 32)->nullable()->after('sortingcode');
            }

            if (!Schema::hasColumn('jnt_shipments', 'success')) {
                $table->boolean('success')->default(false)->after('sortingNo');
            }
            if (!Schema::hasColumn('jnt_shipments', 'reason')) {
                $table->string('reason', 80)->nullable()->after('success');
            }

            if (!Schema::hasColumn('jnt_shipments', 'request_payload')) {
                $table->json('request_payload')->nullable()->after('reason');
            }
            if (!Schema::hasColumn('jnt_shipments', 'response_payload')) {
                $table->json('response_payload')->nullable()->after('request_payload');
            }

            if (!Schema::hasColumn('jnt_shipments', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('response_payload');
            }

            if (!Schema::hasColumn('jnt_shipments', 'created_at')) {
                $table->timestamps();
            }

            // Indexes (create with explicit names so we can safely check)
            // NOTE: Schema::hasIndex doesn't exist, so we rely on named index convention & avoid duplicates by naming.
            // If you previously created different index names, drop/rename them manually once.
            $table->index('jnt_batch_run_id', 'jnt_shipments_jnt_batch_run_id_idx');
            $table->index('macro_output_id', 'jnt_shipments_macro_output_id_idx');
            $table->index('mailno', 'jnt_shipments_mailno_idx');
        });
    }

    public function down(): void
    {
        // ✅ Keep down simple; if you want "rollback-safe" without deleting prod data,
        // you can comment this out. For dev/testing, it's fine.
        Schema::dropIfExists('jnt_shipments');
    }
};
