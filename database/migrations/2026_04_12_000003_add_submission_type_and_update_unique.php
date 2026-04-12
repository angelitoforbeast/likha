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
        // Must add new index FIRST so MySQL still has an index covering checklist_task_id
        // (needed for the FK), then drop the old one.
        Schema::table('checklist_submissions', function (Blueprint $table) {
            $table->unique(['checklist_task_id', 'user_id', 'date']);
        });
        Schema::table('checklist_submissions', function (Blueprint $table) {
            $table->dropUnique(['checklist_task_id', 'date']);
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
