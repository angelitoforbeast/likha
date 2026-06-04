<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * page_day_action_logs — audit trail ng bawat edit sa page_day_actions.
 * Sino nag-edit (edited_by + name), kelan, at ang old → new comment.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('page_day_action_logs')) {
            Schema::create('page_day_action_logs', function (Blueprint $table) {
                $table->id();
                $table->string('page_key')->index();
                $table->date('ts_date')->index();
                $table->text('old_comment')->nullable();
                $table->text('new_comment')->nullable();
                $table->unsignedBigInteger('edited_by')->nullable();
                $table->string('edited_by_name')->nullable();
                $table->timestamp('edited_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('page_day_action_logs');
    }
};
