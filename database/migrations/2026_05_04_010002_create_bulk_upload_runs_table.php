<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bulk_upload_runs', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('type')->default('jnt_v2');
            $table->unsignedBigInteger('user_id')->nullable();

            // queued | precheck | confirmed | processing | done | partial | failed
            $table->string('status')->default('queued');

            $table->unsignedInteger('total_files')->default(0);
            $table->unsignedInteger('files_done')->default(0);
            $table->unsignedInteger('files_failed')->default(0);
            $table->unsignedInteger('files_skipped')->default(0);

            $table->unsignedBigInteger('total_processed')->default(0);
            $table->unsignedBigInteger('total_inserted')->default(0);
            $table->unsignedBigInteger('total_updated')->default(0);
            $table->unsignedBigInteger('total_skipped')->default(0);
            $table->unsignedBigInteger('total_errors')->default(0);

            $table->dateTime('batch_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->text('message')->nullable();

            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index('user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_upload_runs');
    }
};
