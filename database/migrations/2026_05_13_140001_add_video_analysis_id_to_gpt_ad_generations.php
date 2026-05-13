<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link generated ad copy back to its source video analysis (if any).
 *
 * When user runs Generate sa /gpt-ad-generator after analyzing a video, the
 * resulting gpt_ad_generations row gets tagged with the analysis's id. Helps
 * trace which copies came from which video so history view shows that.
 *
 * Nullable for generations done without a video (most cases).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('gpt_ad_generations')) return;

        Schema::table('gpt_ad_generations', function (Blueprint $table) {
            if (! Schema::hasColumn('gpt_ad_generations', 'video_analysis_id')) {
                $table->unsignedBigInteger('video_analysis_id')->nullable()->after('user_email');
                $table->index('video_analysis_id', 'gpt_gen_video_analysis_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('gpt_ad_generations')) return;

        Schema::table('gpt_ad_generations', function (Blueprint $table) {
            if (Schema::hasColumn('gpt_ad_generations', 'video_analysis_id')) {
                try { $table->dropIndex('gpt_gen_video_analysis_idx'); } catch (\Throwable $e) {}
                $table->dropColumn('video_analysis_id');
            }
        });
    }
};
