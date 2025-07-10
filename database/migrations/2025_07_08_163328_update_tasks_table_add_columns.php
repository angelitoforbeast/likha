<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('tasks', 'task_name')) {
                $table->string('task_name')->nullable()->after('role_target');
            }

            if (!Schema::hasColumn('tasks', 'priority_level')) {
                $table->string('priority_level')->nullable()->after('is_repeating');
            }

            if (!Schema::hasColumn('tasks', 'is_notified')) {
                $table->boolean('is_notified')->default(false)->after('status');
            }

            if (!Schema::hasColumn('tasks', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('is_notified');
            }

            if (!Schema::hasColumn('tasks', 'remarks_assigned')) {
                $table->text('remarks_assigned')->nullable()->after('completed_at');
            }

            if (!Schema::hasColumn('tasks', 'remarks_creator')) {
                $table->text('remarks_creator')->nullable()->after('remarks_assigned');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn([
                'task_name',
                'priority_level',
                'is_notified',
                'completed_at',
                'remarks_assigned',
                'remarks_creator',
            ]);
        });
    }
};
