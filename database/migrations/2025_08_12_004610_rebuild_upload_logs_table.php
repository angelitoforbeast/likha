<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Drop the old minimal table
        Schema::dropIfExists('upload_logs');

        // Recreate with the correct schema
        Schema::create('upload_logs', function (Blueprint $table) {
            $table->bigIncrements('id');

            // File info
            $table->string('type')->default('jnt');      // e.g. jnt
            $table->string('disk')->default('local');    // storage disk
            $table->string('path');                      // storage path
            $table->string('original_name');             // original filename
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();

            // Status + metrics
            $table->string('status')->default('queued')->index(); // queued|processing|done|failed
            $table->unsignedBigInteger('total_rows')->nullable();
            $table->unsignedBigInteger('processed_rows')->default(0);
            $table->unsignedBigInteger('inserted')->default(0);
            $table->unsignedBigInteger('updated')->default(0);
            $table->unsignedBigInteger('skipped')->default(0);
            $table->unsignedBigInteger('error_rows')->default(0);

            // Errors CSV path (if any)
            $table->string('errors_path')->nullable();

            // Timestamps for processing
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            // Helpful indexes
            $table->index(['type', 'status']);
            $table->index('path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_logs');
    }
};
