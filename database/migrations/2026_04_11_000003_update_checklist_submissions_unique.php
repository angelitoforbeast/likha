<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_submissions', function (Blueprint $table) {
            // Drop FK first (it uses the unique index)
            $table->dropForeign(['checklist_task_id']);
            $table->dropForeign(['user_id']);
            // Drop old unique: (task, user, date)
            $table->dropUnique(['checklist_task_id', 'user_id', 'date']);
            // New unique: (task, date) — one submission per task per day total
            $table->unique(['checklist_task_id', 'date']);
            // Re-add FKs
            $table->foreign('checklist_task_id')->references('id')->on('checklist_tasks')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('checklist_submissions', function (Blueprint $table) {
            $table->dropForeign(['checklist_task_id']);
            $table->dropForeign(['user_id']);
            $table->dropUnique(['checklist_task_id', 'date']);
            $table->unique(['checklist_task_id', 'user_id', 'date']);
            $table->foreign('checklist_task_id')->references('id')->on('checklist_tasks')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
