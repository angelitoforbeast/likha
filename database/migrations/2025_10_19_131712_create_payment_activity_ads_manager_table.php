<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ✅ 1️⃣ Create table if not exists
        if (!Schema::hasTable('payment_activity_ads_manager')) {
            Schema::create('payment_activity_ads_manager', function (Blueprint $table) {
                $table->id();

                // Core columns
                $table->date('date')->nullable()->index();
                $table->string('transaction_id', 100)->nullable()->index();
                $table->decimal('amount', 12, 2)->nullable();
                $table->string('ad_account', 32)->nullable()->index();
                $table->string('payment_method', 128)->nullable();

                // Metadata
                $table->string('source_filename')->nullable();
                $table->uuid('import_batch_id')->nullable()->index();
                $table->string('uploaded_by', 128)->nullable();
                $table->timestamp('uploaded_at')->nullable();

                $table->timestamps();

                $table->unique(['transaction_id', 'ad_account'], 'uniq_txn_acc');
            });

            return;
        }

        // ✅ 2️⃣ If table already exists, fix columns / add missing ones
        Schema::table('payment_activity_ads_manager', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_activity_ads_manager', 'date')) {
                $table->date('date')->nullable()->index();
            }
            if (!Schema::hasColumn('payment_activity_ads_manager', 'transaction_id')) {
                $table->string('transaction_id', 100)->nullable()->index();
            }
            if (!Schema::hasColumn('payment_activity_ads_manager', 'amount')) {
                $table->decimal('amount', 12, 2)->nullable();
            }
            if (!Schema::hasColumn('payment_activity_ads_manager', 'ad_account')) {
                $table->string('ad_account', 32)->nullable()->index();
            }
            if (!Schema::hasColumn('payment_activity_ads_manager', 'payment_method')) {
                $table->string('payment_method', 128)->nullable();
            }
            if (!Schema::hasColumn('payment_activity_ads_manager', 'source_filename')) {
                $table->string('source_filename')->nullable();
            }
            if (!Schema::hasColumn('payment_activity_ads_manager', 'import_batch_id')) {
                $table->uuid('import_batch_id')->nullable()->index();
            }
            if (!Schema::hasColumn('payment_activity_ads_manager', 'uploaded_by')) {
                $table->string('uploaded_by', 128)->nullable();
            }
            if (!Schema::hasColumn('payment_activity_ads_manager', 'uploaded_at')) {
                $table->timestamp('uploaded_at')->nullable();
            }
            if (!Schema::hasColumn('payment_activity_ads_manager', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn('payment_activity_ads_manager', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }

            // Ensure unique key exists
            try {
                $sm = Schema::getConnection()->getDoctrineSchemaManager();
                $indexes = array_map('strtolower', array_keys($sm->listTableIndexes('payment_activity_ads_manager')));
                if (!in_array('uniq_txn_acc', $indexes)) {
                    $table->unique(['transaction_id', 'ad_account'], 'uniq_txn_acc');
                }
            } catch (\Throwable $e) {
                // ignore if Doctrine not loaded
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_activity_ads_manager');
    }
};
