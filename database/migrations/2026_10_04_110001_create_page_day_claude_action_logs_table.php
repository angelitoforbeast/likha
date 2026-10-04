<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * page_day_claude_action_logs — audit trail ng bawat pagbabago sa page_day_claude_actions
 * (old → new action at reason, source, kelan).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('page_day_claude_action_logs')) {
            Schema::create('page_day_claude_action_logs', function (Blueprint $table) {
                $table->id();
                $table->string('page_key')->index();
                $table->date('ts_date')->index();
                $table->text('old_action')->nullable();
                $table->text('new_action')->nullable();
                $table->text('old_reason')->nullable();
                $table->text('new_reason')->nullable();
                $table->string('source')->nullable();
                $table->timestamp('edited_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('page_day_claude_action_logs');
    }
};
