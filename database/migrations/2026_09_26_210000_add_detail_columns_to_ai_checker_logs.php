<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ai_checker_logs — dagdag na detalye kada takbo ng AI Fix / AI Checker sa isang row:
 * ano ang aktwal na sagot ng AI (RESOLVE, candidates, MAP, GUARD, VERIFYK), mga search
 * query at source, before/after ng fields, gate, at gastos. Maliit na table (auto-prune 90
 * araw) kaya instant ang ALTER — HINDI ginagalaw ang macro_output.
 *
 *   model      = mga model na ginamit (hal. "gpt-5.2" o "gpt-5.2,gpt-6-astra")
 *   escalated  = tumawag ba sa mas malalim na model (hybrid escalation)
 *   searches   = bilang ng web search calls
 *   tokens_in / tokens_out / cost_usd = usage at tinatayang gastos ng row
 *   evidence   = mga linyang nababasa ng tao (RESOLVE/MAP/GUARD/VERIFYK/GATE)
 *   detail     = JSON: passes[] (resolve answers, map, assess, fallbacks, verify, before/after,
 *                gate), searches[], usage[], summary
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ai_checker_logs')) return;

        Schema::table('ai_checker_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('ai_checker_logs', 'model'))      $table->string('model', 96)->nullable()->after('duration_ms');
            if (!Schema::hasColumn('ai_checker_logs', 'escalated'))  $table->boolean('escalated')->default(false)->after('model');
            if (!Schema::hasColumn('ai_checker_logs', 'searches'))   $table->unsignedSmallInteger('searches')->default(0)->after('escalated');
            if (!Schema::hasColumn('ai_checker_logs', 'tokens_in'))  $table->unsignedInteger('tokens_in')->default(0)->after('searches');
            if (!Schema::hasColumn('ai_checker_logs', 'tokens_out')) $table->unsignedInteger('tokens_out')->default(0)->after('tokens_in');
            if (!Schema::hasColumn('ai_checker_logs', 'cost_usd'))   $table->decimal('cost_usd', 8, 4)->default(0)->after('tokens_out');
            if (!Schema::hasColumn('ai_checker_logs', 'evidence'))   $table->text('evidence')->nullable()->after('cost_usd');
            if (!Schema::hasColumn('ai_checker_logs', 'detail'))     $table->longText('detail')->nullable()->after('evidence');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('ai_checker_logs')) return;

        Schema::table('ai_checker_logs', function (Blueprint $table) {
            foreach (['detail', 'evidence', 'cost_usd', 'tokens_out', 'tokens_in', 'searches', 'escalated', 'model'] as $col) {
                if (Schema::hasColumn('ai_checker_logs', $col)) $table->dropColumn($col);
            }
        });
    }
};
