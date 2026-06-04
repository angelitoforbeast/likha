<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * page_day_actions — isang editable "Action" note per (page_key, ts_date).
 * Ginagamit sa /owner/private (end-date comment) + /owner/private/breakdown
 * (per-date comment). Layunin: i-log kung anong aksyon ang ginawa sa isang
 * page sa isang araw.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('page_day_actions')) {
            Schema::create('page_day_actions', function (Blueprint $table) {
                $table->id();
                $table->string('page_key')->index();
                $table->date('ts_date')->index();
                $table->text('comment')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['page_key', 'ts_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('page_day_actions');
    }
};
