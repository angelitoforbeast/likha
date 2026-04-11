<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_submissions', function (Blueprint $table) {
            // Drop old unique: (task, user, date) — was one per user per task per day
            $table->dropUnique(['checklist_task_id', 'user_id', 'date']);
            // New unique: (task, date) — one submission per task per day total
            $table->unique(['checklist_task_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('checklist_submissions', function (Blueprint $table) {
            $table->dropUnique(['checklist_task_id', 'date']);
            $table->unique(['checklist_task_id', 'user_id', 'date']);
        });
    }
};
