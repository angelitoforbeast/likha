<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zk_attendance_processed', function (Blueprint $table) {
            $table->id();

            // Biometric ID kept as string for flexibility (works on MySQL/PostgreSQL)
            $table->string('zk_user_id', 50)->index();

            // Work day (DATE) — DB-agnostic
            $table->date('date')->index();

            // Daily punches (TIME) — DB-agnostic across MySQL/PostgreSQL
            $table->time('time_in')->nullable();
            $table->time('lunch_out')->nullable();
            $table->time('lunch_in')->nullable();
            $table->time('time_out')->nullable();

            // Total effective hours (minus lunch), e.g., 8.75
            $table->decimal('work_hours', 5, 2)->default(0);

            // Traceability to your upload batch (optional)
            $table->string('upload_batch', 100)->nullable()->index();

            $table->timestamps();

            // Prevent duplicates: one row per user per date
            $table->unique(['zk_user_id', 'date'], 'u_attendance_user_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zk_attendance_processed');
    }
};
