<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * page_day_claude_actions — hiwalay na note ni Claude (recommendation + reason) per (page_key, ts_date).
 * Read-only sa /owner/private, CEO lang ang nakakakita; artisan command lang ang sumusulat.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('page_day_claude_actions')) {
            Schema::create('page_day_claude_actions', function (Blueprint $table) {
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
        Schema::dropIfExists('page_day_claude_actions');
    }
};
