<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::create('macro_output', function (Blueprint $table) {
        $table->id();
        $table->string('TIMESTAMP')->nullable();
        $table->string('FULL NAME')->nullable();
        $table->string('PHONE NUMBER')->nullable();
        $table->text('ADDRESS')->nullable();
        $table->string('PROVINCE')->nullable();
        $table->string('CITY')->nullable();
        $table->string('BARANGAY')->nullable();
        $table->string('ITEM')->nullable();
        $table->string('NAME')->nullable();
        $table->string('COD')->nullable();
        $table->string('PAGE')->nullable();
        $table->text('ALL USER INPUT')->nullable();
        $table->text('SHOP DETAILS')->nullable();
        $table->string('CXD')->nullable();
        $table->string('AI ANALYZE')->nullable();
        $table->string('HUMAN CHECKER STATUS')->nullable();
        $table->string('RESERVE COLUMN')->nullable();
        $table->string('STATUS')->nullable();
        $table->timestamps();
    });
}


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('macro_output');
    }
};
