<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add submission_type to checklist_tasks (skip if already exists)
        if (!Schema::hasColumn('checklist_tasks', 'submission_type')) {
            Schema::table('checklist_tasks', function (Blueprint $table) {
                $table->enum('submission_type', ['group', 'individual'])
                      ->default('group')
                      ->after('scheduled_time');
            });
        }

        // Swap unique constraint on checklist_submissions:
        // Add (checklist_task_id, user_id, date) FIRST (so FK still has a covering index),
        // then drop the old (checklist_task_id, date) — but only if it still exists.
        $sm      = Schema::getConnection()->getDoctrineSchemaManager();
        $indexes = $sm->listTableIndexes('checklist_submissions');
        $oldKey  = 'checklist_submissions_checklist_task_id_date_unique';
        $newKey  = 'checklist_submissions_checklist_task_id_user_id_date_unique';

        if (!array_key_exists($newKey, $indexes)) {
            Schema::table('checklist_submissions', function (Blueprint $table) {
                $table->unique(['checklist_task_id', 'user_id', 'date']);
            });
        }

        if (array_key_exists($oldKey, $indexes)) {
            Schema::table('checklist_submissions', function (Blueprint $table) {
                $table->dropUnique(['checklist_task_id', 'date']);
            });
        }
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
