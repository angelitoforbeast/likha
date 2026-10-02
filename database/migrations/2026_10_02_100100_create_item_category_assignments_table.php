<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * item_category_assignments — isang category kada item (base key, hal. "glow tape").
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('item_category_assignments')) return;

        Schema::create('item_category_assignments', function (Blueprint $table) {
            $table->id();
            $table->string('item_key', 190)->unique();
            $table->foreignId('category_id')->constrained('item_categories')->restrictOnDelete();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_category_assignments');
    }
};
