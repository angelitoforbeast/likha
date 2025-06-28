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
    Schema::create('likha_orders', function (Blueprint $table) {
    $table->id();
    $table->date('date')->nullable();
    $table->string('page_name')->nullable();
    $table->string('name')->nullable();
    $table->string('phone_number')->nullable();
    $table->text('all_user_input')->nullable();
    $table->text('shop_details')->nullable();
    $table->text('extracted_details')->nullable();
    $table->text('price')->nullable();
    $table->timestamps();
});

}


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('likha_orders');
    }
};
