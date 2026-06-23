<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Palakihin ang final_code column ng ai_checker_logs (16 → 64).
 *
 * Bug: ang `final_code` (galing computeStatusCode) ay pwedeng umabot ng 21 chars
 * (hal. "Province and Barangay"), pero 16 lang ang dati — kaya nag-fa-fail ang
 * insert sa MySQL strict mode → tahimik na nawawala ang row sa logs ("hindi tally").
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_checker_logs') && Schema::hasColumn('ai_checker_logs', 'final_code')) {
            Schema::table('ai_checker_logs', function (Blueprint $table) {
                $table->string('final_code', 64)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_checker_logs') && Schema::hasColumn('ai_checker_logs', 'final_code')) {
            Schema::table('ai_checker_logs', function (Blueprint $table) {
                $table->string('final_code', 16)->nullable()->change();
            });
        }
    }
};
