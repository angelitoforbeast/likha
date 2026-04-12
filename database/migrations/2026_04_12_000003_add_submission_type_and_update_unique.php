<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add submission_type to checklist_tasks
        Schema::table('checklist_tasks', function (Blueprint $table) {
            $table->enum('submission_type', ['group', 'individual'])
                  ->default('group')
                  ->after('scheduled_time');
        });

        // Update unique constraint on checklist_submissions:
        // from (checklist_task_id, date) → (checklist_task_id, user_id, date)
        // This allows individual tasks to have one submission per user per day
        Schema::table('checklist_submissions', function (Blueprint $table) {
            $table->dropUnique(['checklist_task_id', 'date']);
            $table->unique(['checklist_task_id', 'user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('checklist_tasks', function (Blueprint $table) {
            $table->dropColumn('submission_type');
        });

        Schema::table('checklist_submissions', function (Blueprint $table) {
            $table->dropUnique(['checklist_task_id', 'user_id', 'date']);
            $table->unique(['checklist_task_id', 'date']);
        });
    }
};
