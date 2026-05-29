<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Main data table for /conversation/cancel-detector. Stores rows imported
 * from configured Google Sheets — only the 5 columns na kailangan for AI
 * cancel detection: page, name, phone, shop details, conversation text.
 *
 * `ai_analysis` is nullable — Phase 1 leaves it blank. Phase 2 background
 * job will populate it ('cancel' / 'not_cancel' / 'unknown') after sending
 * each conversation to OpenAI.
 *
 * Upsert key: (page_name, phone_number) — same customer on the same page
 * shouldn't get a duplicate row sa re-imports.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cancel_detectors')) return;

        Schema::create('cancel_detectors', function (Blueprint $t) {
            $t->id();
            $t->string('page_name', 255)->nullable()->index();
            $t->string('name', 255)->nullable()->index();
            $t->string('phone_number', 50)->nullable()->index();
            $t->text('shop_details')->nullable();
            $t->mediumText('conversation')->nullable();
            // AI-computed cancel classification — populated by Phase 2 job.
            // Possible values: 'cancel', 'not_cancel', 'unknown', NULL (pending).
            $t->string('ai_analysis', 50)->nullable()->index();
            $t->timestamp('ai_analyzed_at')->nullable();
            $t->unsignedBigInteger('imported_run_id')->nullable()->index();
            $t->timestamps();
            // Composite for upsert lookup: same customer on same page = same row.
            $t->index(['page_name', 'phone_number'], 'cd_page_phone_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cancel_detectors');
    }
};
