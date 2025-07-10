<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('tasks');

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->string('role_target');
            $table->text('collaborators')->nullable(); // Optional JSON or comma-separated
            $table->string('task_name');
            $table->text('description')->nullable();
            $table->string('type');
            $table->boolean('is_repeating')->default(false);
            $table->integer('priority_score')->default(3); // Range: 1–5
            $table->date('due_date')->nullable();
            $table->time('due_time')->nullable();
            $table->timestamp('reminder_at')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->boolean('is_notified')->default(false);
            $table->string('review_status')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('creator_remarks')->nullable();
            $table->unsignedBigInteger('remarks_created_by')->nullable();
            $table->text('assignee_remarks')->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('parent_task_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
