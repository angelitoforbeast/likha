<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('upload_logs_v2', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('bulk_run_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('type')->default('jnt_v2');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();

            // queued | precheck_ok | precheck_failed | processing | done | failed | skipped
            $table->string('status')->default('queued');

            $table->json('precheck_report')->nullable();

            $table->unsignedBigInteger('total_rows')->nullable();
            $table->unsignedBigInteger('processed_rows')->default(0);
            $table->unsignedBigInteger('inserted')->default(0);
            $table->unsignedBigInteger('updated')->default(0);
            $table->unsignedBigInteger('skipped')->default(0);
            $table->unsignedBigInteger('error_rows')->default(0);

            $table->string('errors_path')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->index('bulk_run_id');
            $table->index('user_id');
            $table->index(['type', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_logs_v2');
    }
};
