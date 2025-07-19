<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checker_2_settings', function (Blueprint $table) {
            $table->id();
            $table->string('sheet_name');       // Example: Sheet1
            $table->text('sheet_url');          // Full Google Sheet URL
            $table->string('sheet_range');      // Example: A1:D100
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checker_2_settings');
    }
};
