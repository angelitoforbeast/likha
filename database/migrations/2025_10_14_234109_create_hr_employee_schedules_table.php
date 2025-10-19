<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_employee_schedules', function (Blueprint $table) {
            $table->id();

            // Biometric/User ID (string for flexibility, consistent with your raw tables)
            $table->string('zk_user_id', 50)->index();

            // Display/label for quick reference (e.g., "10–7", "12–9", "8–5")
            $table->string('shift_label', 50)->nullable();

            // Default shift times
            $table->time('time_in_default');   // e.g., 10:00:00
            $table->time('time_out_default');  // e.g., 19:00:00

            // Lunch/break window (optional)
            $table->time('break_start')->nullable(); // e.g., 14:00:00
            $table->time('break_end')->nullable();   // e.g., 15:00:00

            // Grace period for lateness (minutes)
            $table->unsignedSmallInteger('grace_period_minutes')->default(0);

            // Effective date range (open-ended)
            $table->date('effective_start');         // inclusive
            $table->date('effective_end')->nullable(); // inclusive; NULL = open-ended

            // Status/notes
            $table->boolean('is_active')->default(true);
            $table->string('remarks', 255)->nullable();

            $table->timestamps();

            // Helpful indexes
            $table->index(['zk_user_id', 'effective_start']);
            $table->index(['zk_user_id', 'effective_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employee_schedules');
    }
};
