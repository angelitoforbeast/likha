<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('zk_raw_attlogs', function (Blueprint $table) {
            $table->id();

            // Biometric ID (FK-like link to zk_raw_users.zk_user_id — kept as string for flexibility)
            $table->string('zk_user_id')->index();

            // Full raw timestamp from attlog.dat
            $table->dateTime('datetime_raw')->index();

            // Convenience columns extracted from datetime_raw for faster queries/filters
            $table->date('date')->index();
            $table->time('time');

            // Upload batch tag (same idea as in users table)
            $table->string('upload_batch')->nullable()->index();

            $table->timestamps();

            // Optional composite index for fast lookups by user+date
            $table->index(['zk_user_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zk_raw_attlogs');
    }
};
