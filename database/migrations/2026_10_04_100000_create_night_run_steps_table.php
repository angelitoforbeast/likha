<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * night_run_steps — isang row kada hakbang ng gabi (spec 007 §5): ang apat na import at ang Astra.
 * Unique (night_date, kind) kaya isang beses lang ang bawat hakbang kada gabi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('night_run_steps')) {
            return;
        }

        Schema::create('night_run_steps', function (Blueprint $table) {
            $table->id();
            $table->date('night_date');                          // petsa (Manila) ng umaga ng gabing iyon
            $table->string('kind', 20);                          // macro_import_1|likha_import_1|macro_import_2|likha_import_2|astra
            $table->string('state', 20);                         // imports: started|skipped|failed; Astra: waiting|running|finished|stopped|did_not_run
            $table->string('reason', 500)->nullable();           // fixed strings lang
            $table->unsignedBigInteger('ref_id')->nullable();    // id ng import run
            $table->string('trigger', 10);                       // schedule|manual
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('stop_at')->nullable();            // kailan titigil magsimula ng bagong row
            $table->unsignedInteger('rows_found')->default(0);
            $table->unsignedInteger('rows_over_max')->default(0);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('failure_streak_started_at')->nullable(); // kailan nagsimula ang kasalukuyang sunod-sunod na palya
            $table->timestamps();

            $table->unique(['night_date', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('night_run_steps');
    }
};
