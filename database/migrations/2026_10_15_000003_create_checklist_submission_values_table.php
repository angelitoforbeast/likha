<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('checklist_submission_values')) {
            return;
        }

        // Ang mga value na nakuha ng AI sa isang submission, isang row kada field ng task.
        Schema::create('checklist_submission_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained('checklist_submissions')->cascadeOnDelete();
            $table->foreignId('checklist_task_id')->constrained('checklist_tasks')->cascadeOnDelete();
            $table->date('date');   // date ng submission
            $table->string('field_key', 40);
            $table->decimal('value_number', 18, 4)->nullable();
            $table->text('value_text')->nullable();
            $table->boolean('value_bool')->nullable();
            $table->string('source', 20)->default('ai');
            $table->text('raw')->nullable();   // ang sagot ng AI para sa field, bago i-normalise
            $table->timestamps();

            $table->unique(['submission_id', 'field_key']);
            $table->index(['date', 'checklist_task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_submission_values');
    }
};
