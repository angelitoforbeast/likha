<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * night_astra_rows — isang row kada order na pinatakbo ng Astra sa isang gabi (spec 007 §5).
 * Unique (step_id, macro_output_id): isang beses lang ang order sa isang run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('night_astra_rows')) {
            return;
        }

        Schema::create('night_astra_rows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('step_id');
            $table->unsignedBigInteger('macro_output_id');
            $table->string('state', 12);                         // queued|running|done|failed|skipped|not_run
            $table->unsignedInteger('attempts')->default(0);     // dinadagdagan ng claim
            $table->string('code', 64)->nullable();              // final_code ng engine
            $table->boolean('proceed')->default(false);          // nag-PROCEED ang engine
            $table->string('reason', 500)->nullable();           // fixed strings lang
            $table->unsignedBigInteger('log_id')->nullable();    // ai_checker_logs row
            $table->decimal('cost_usd', 10, 4)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['step_id', 'macro_output_id']);
            $table->index(['step_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('night_astra_rows');
    }
};
