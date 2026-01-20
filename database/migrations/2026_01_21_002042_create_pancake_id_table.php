<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pancake_id', function (Blueprint $table) {
            $table->id();
            $table->string('pancake_page_id')->unique();
            $table->string('pancake_page_name');
            $table->timestamps();

            $table->index('pancake_page_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pancake_id');
    }
};
