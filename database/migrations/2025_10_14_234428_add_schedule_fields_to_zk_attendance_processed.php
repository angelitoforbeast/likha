<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('zk_attendance_processed', function (Blueprint $table) {
            // schedule info used that day
            $table->string('shift_label_used', 50)->nullable()->after('upload_batch');
            $table->time('sched_time_in')->nullable()->after('shift_label_used');
            $table->time('sched_time_out')->nullable()->after('sched_time_in');
            $table->time('sched_break_start')->nullable()->after('sched_time_out');
            $table->time('sched_break_end')->nullable()->after('sched_break_start');
            $table->unsignedSmallInteger('sched_grace_minutes')->default(0)->after('sched_break_end');

            // computed metrics
            $table->unsignedSmallInteger('late_minutes')->default(0)->after('work_hours');
            $table->unsignedSmallInteger('undertime_minutes')->default(0)->after('late_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('zk_attendance_processed', function (Blueprint $table) {
            $table->dropColumn([
                'shift_label_used',
                'sched_time_in',
                'sched_time_out',
                'sched_break_start',
                'sched_break_end',
                'sched_grace_minutes',
                'late_minutes',
                'undertime_minutes',
            ]);
        });
    }
};
