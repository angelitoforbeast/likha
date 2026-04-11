<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_submission_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('action', ['submitted', 'updated']);
            $table->text('notes_snapshot')->nullable();
            $table->unsignedTinyInteger('file_count')->default(0);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_submission_logs');
    }
};
