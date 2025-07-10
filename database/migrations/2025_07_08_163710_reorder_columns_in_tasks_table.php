<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Rename 'name' to 'task_name' if not already done
            if (Schema::hasColumn('tasks', 'name') && !Schema::hasColumn('tasks', 'task_name')) {
                $table->renameColumn('name', 'task_name');
            }

            // Add 'priority_level', 'remarks_assigned', 'remarks_created_by' if they don't exist
            if (!Schema::hasColumn('tasks', 'priority_level')) {
                $table->string('priority_level')->nullable()->after('is_repeating');
            }

            if (!Schema::hasColumn('tasks', 'remarks_assigned')) {
                $table->text('remarks_assigned')->nullable()->after('completed_at');
            }

            if (!Schema::hasColumn('tasks', 'remarks_created_by')) {
                $table->text('remarks_created_by')->nullable()->after('remarks_assigned');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Reverse changes if needed
            if (Schema::hasColumn('tasks', 'task_name')) {
                $table->renameColumn('task_name', 'name');
            }

            $table->dropColumn([
                'priority_level',
                'remarks_assigned',
                'remarks_created_by',
            ]);
        });
    }
};
