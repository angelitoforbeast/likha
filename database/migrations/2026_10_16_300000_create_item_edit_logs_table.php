<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * item_edit_logs — bawat edit sa /item (quotes, category, lead / palugit, item photo), isang row
 * kada field na nagbago: sino (user + role noon), ano (action, item, field), mula saan papunta saan.
 * Append-only: walang update o delete sa app. Walang file data — pangalan lang ng file.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('item_edit_logs')) return;

        Schema::create('item_edit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('role', 60)->nullable();
            $table->string('action', 40);
            $table->string('item_key', 190)->nullable();
            $table->string('item_name', 255)->nullable();
            $table->string('page_name', 190)->nullable();
            $table->string('field', 190);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('created_at', 'item_edit_logs_created_idx');
            $table->index(['item_key', 'created_at'], 'item_edit_logs_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_edit_logs');
    }
};
