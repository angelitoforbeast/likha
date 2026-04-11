<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_tasks', function (Blueprint $table) {
            $table->text('approval_prompt')->nullable()->after('ai_prompt');
        });

        Schema::table('checklist_analysis_logs', function (Blueprint $table) {
            $table->string('log_type')->default('analysis')->after('id');
            $table->string('verdict')->nullable()->after('analysis_result');
        });
    }

    public function down(): void
    {
        Schema::table('checklist_tasks', function (Blueprint $table) {
            $table->dropColumn('approval_prompt');
        });

        Schema::table('checklist_analysis_logs', function (Blueprint $table) {
            $table->dropColumn(['log_type', 'verdict']);
        });
    }
};
