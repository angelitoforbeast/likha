<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-sheet progress within a cancel_detector_runs batch. Allows the UI
 * to show live progress per configured sheet (X of Y processed) while
 * the async job runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cancel_detector_run_sheets')) return;

        Schema::create('cancel_detector_run_sheets', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('run_id');
            $t->unsignedBigInteger('setting_id');
            $t->string('status', 50)->default('queued'); // queued|fetching|processing|writing|done|failed
            $t->integer('processed_count')->default(0);
            $t->integer('inserted_count')->default(0);
            $t->integer('updated_count')->default(0);
            $t->integer('skipped_count')->default(0);
            $t->text('message')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->index(['run_id', 'setting_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cancel_detector_run_sheets');
    }
};
