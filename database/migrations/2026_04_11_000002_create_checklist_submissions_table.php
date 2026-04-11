<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->text('notes')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_original_name')->nullable();
            $table->string('file_mime', 100)->nullable();
            $table->timestamps();

            // One submission per user per task per day
            $table->unique(['checklist_task_id', 'user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_submissions');
    }
};
