<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('from_jnts_2', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->dateTime('submission_time')->nullable();
            $table->string('waybill_number')->nullable();
            $table->string('receiver')->nullable();
            $table->string('receiver_cellphone')->nullable();
            $table->string('sender')->nullable();
            $table->string('item_name')->nullable();
            $table->string('cod')->nullable();
            $table->string('remarks')->nullable();
            $table->string('status')->nullable();
            $table->dateTime('signingtime')->nullable();
            $table->longText('status_logs')->nullable();

            $table->string('province')->nullable();
            $table->string('city')->nullable();
            $table->string('barangay')->nullable();
            $table->decimal('total_shipping_cost', 10, 2)->nullable();
            $table->string('rts_reason')->nullable();

            // v2 audit columns
            $table->unsignedBigInteger('last_uploaded_by_user_id')->nullable();
            $table->unsignedBigInteger('last_upload_log_id')->nullable();

            $table->timestamps();

            $table->index('submission_time', 'idx_from_jnts_2_submission_time');
            $table->index('signingtime', 'idx_from_jnts_2_signingtime');
            $table->index('status', 'idx_from_jnts_2_status');
            $table->index('waybill_number', 'idx_from_jnts_2_waybill');
            $table->index(['signingtime', 'status'], 'idx_from_jnts_2_signing_status');
            $table->index(['sender', 'item_name'], 'idx_from_jnts_2_sender_item');
            $table->index('last_upload_log_id', 'idx_from_jnts_2_last_upload');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('from_jnts_2');
    }
};
