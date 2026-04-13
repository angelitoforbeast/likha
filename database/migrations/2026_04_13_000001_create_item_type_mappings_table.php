<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_type_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('item_type');  // e.g. "flashlight"
            $table->string('item_name');  // e.g. "mini flashlight"
            $table->timestamps();
            $table->unique(['item_name']); // one item_name belongs to only one type
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_type_mappings');
    }
};
