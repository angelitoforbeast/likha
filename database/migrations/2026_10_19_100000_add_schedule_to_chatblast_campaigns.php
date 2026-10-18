<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Ang schedule ng campaign: mga araw ng linggo (NULL = araw-araw), ang sariling oras ng bawat step (kahanay ng days;
     * null = ang oras ng campaign), at ang cap ng campaign kada page kada araw (NULL = wala; ang cap ng page ay laging
     * sinusunod). Walang dating column na binabago: pareho ang text, bersyon, status, page at oras ng mga campaign.
     */
    public function up(): void
    {
        if (!Schema::hasTable('chatblast_campaigns')) return;

        Schema::table('chatblast_campaigns', function (Blueprint $table) {
            if (!Schema::hasColumn('chatblast_campaigns', 'weekdays')) $table->json('weekdays')->nullable();     // ISO 1 (Lun) … 7 (Lin)
            if (!Schema::hasColumn('chatblast_campaigns', 'step_times')) $table->json('step_times')->nullable(); // kada step: null o mga "HH:MM"
            if (!Schema::hasColumn('chatblast_campaigns', 'daily_cap')) $table->unsignedInteger('daily_cap')->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('chatblast_campaigns')) return;

        Schema::table('chatblast_campaigns', function (Blueprint $table) {
            foreach (['weekdays', 'step_times', 'daily_cap'] as $col) {
                if (Schema::hasColumn('chatblast_campaigns', $col)) $table->dropColumn($col);
            }
        });
    }
};
