<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * page_day_ceo_actions — hiwalay na sariling note ng CEO (action + reason) per (page_key, ts_date).
 * Hiwalay sa team Action at sa note ni Claude; walang artisan command na humahawak dito.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('page_day_ceo_actions')) {
            Schema::create('page_day_ceo_actions', function (Blueprint $table) {
                $table->id();
                $table->string('page_key')->index();
                $table->date('ts_date')->index();
                $table->text('action')->nullable();
                $table->text('reason')->nullable();
                $table->string('source')->nullable();
                $table->timestamps();
                $table->unique(['page_key', 'ts_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('page_day_ceo_actions');
    }
};
