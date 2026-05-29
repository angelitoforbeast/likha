<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Import job batch tracking. One row per "Start Import" click — tracks
 * overall status + aggregate counters across all sheets sa that batch.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cancel_detector_runs')) return;

        Schema::create('cancel_detector_runs', function (Blueprint $t) {
            $t->id();
            $t->string('status', 50)->default('queued'); // queued|running|done|failed
            $t->integer('total_settings')->default(0);
            $t->integer('total_processed')->default(0);
            $t->integer('total_inserted')->default(0);
            $t->integer('total_updated')->default(0);
            $t->integer('total_skipped')->default(0);
            $t->integer('total_failed')->default(0);
            $t->text('message')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cancel_detector_runs');
    }
};
