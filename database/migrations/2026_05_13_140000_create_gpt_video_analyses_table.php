<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores per-video AI analysis results sa GPT Ad Generator.
 *
 * Workflow: user uploads a video → backend extracts frames + audio →
 * Whisper API transcribes audio → GPT-4o Vision analyzes frames + transcript
 * → returns item_name, description, summary. Resulting row saved here so
 * the same video (matched by file_sha256) can be reused without re-spending
 * OpenAI tokens. Original video file is deleted right after analysis;
 * only metadata + AI outputs persist in this table.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('gpt_video_analyses', function (Blueprint $table) {
            $table->id();

            // File identity
            $table->string('file_name');                 // original upload name (no path)
            $table->string('file_sha256', 64)->unique(); // for dedup lookup
            $table->unsignedBigInteger('file_size_bytes')->default(0);

            // Video properties
            $table->decimal('duration_seconds', 10, 2)->nullable();
            $table->unsignedSmallInteger('frame_count')->default(0);

            // Who uploaded — graceful fallback if user deleted later
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable()->index();
            $table->string('uploaded_by_email')->nullable();

            // AI outputs (text fields for flexibility on length)
            $table->text('transcript')->nullable();      // from Whisper
            $table->text('summary')->nullable();         // GPT-4o Vision narrative summary
            $table->string('item_name', 500)->nullable();
            $table->text('description')->nullable();

            // Audit + cost tracking
            $table->string('model_used', 64)->nullable(); // e.g. 'gpt-4o'
            $table->decimal('cost_estimate_php', 10, 4)->nullable();
            $table->dateTime('analyzed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gpt_video_analyses');
    }
};
