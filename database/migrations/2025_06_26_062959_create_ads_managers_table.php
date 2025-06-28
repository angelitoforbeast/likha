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
    Schema::create('ads_managers', function (Blueprint $table) {
        $table->id();
        $table->string('reporting_starts')->nullable();
        $table->string('page')->nullable();
        $table->string('amount_spent')->nullable();
        $table->string('cpm')->nullable();
        $table->string('cpi')->nullable();
        $table->timestamps();
    });
}


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ads_managers');
    }
};
