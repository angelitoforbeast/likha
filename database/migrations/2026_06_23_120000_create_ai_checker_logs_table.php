<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ai_checker_logs — isang row kada processed na order sa AI Checker / AI Fix
 * (parehong dumadaan sa /encoder/checker_1/ai-checker/run-row).
 *
 *   source = 'single' (AI Fix per row) | 'batch' (AI Checker bulk)
 *   batch_id = nag-uugnay ng isang AI Checker run (NULL para sa single)
 *   batch_total = ilang row ang target ng batch (para sa "processed / target")
 *   outcome = 'fixed' | 'partial' | 'failed'
 *
 * Per-batch summary = i-group by batch_id. Per-row detail = bawat row mismo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ai_checker_logs')) {
            Schema::create('ai_checker_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('user_name')->nullable();
                $table->string('source', 10)->default('single');     // single | batch
                $table->string('batch_id', 40)->nullable()->index();  // groups one AI Checker run
                $table->unsignedInteger('batch_total')->nullable();   // target N (batch)
                $table->unsignedBigInteger('macro_output_id')->nullable()->index();
                $table->string('page')->nullable();
                $table->string('item')->nullable();
                $table->string('final_code', 64)->nullable();
                $table->boolean('all_filled')->default(false);
                $table->string('outcome', 10)->default('partial');    // fixed | partial | failed
                $table->unsignedInteger('duration_ms')->nullable();
                $table->timestamps();                                  // created_at = kelan processed
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_checker_logs');
    }
};
