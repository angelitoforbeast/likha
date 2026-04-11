<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_submission_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_submission_id')->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->string('file_original_name');
            $table->string('file_mime', 100);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_submission_files');
    }
};
