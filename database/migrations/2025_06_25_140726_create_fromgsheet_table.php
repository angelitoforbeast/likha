<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
Schema::create('fromgsheet', function (Blueprint $table) {
    $table->id();
    $table->string('column1')->nullable();
    $table->string('column2')->nullable();
    $table->string('column3')->nullable();
    $table->string('column4')->nullable();
    $table->timestamps();
});


    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fromgsheet');
    }
};
