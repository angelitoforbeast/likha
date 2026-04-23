<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supply_item_settings', function (Blueprint $table) {
            $table->id();
            $table->string('item_name', 255)->unique(); // base item name (qty prefix stripped)
            $table->unsignedTinyInteger('lead_time_days')->default(7);
            $table->unsignedTinyInteger('safety_days')->default(3);
            $table->boolean('is_running')->nullable(); // null=auto-detect, true/false=override
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_item_settings');
    }
};
