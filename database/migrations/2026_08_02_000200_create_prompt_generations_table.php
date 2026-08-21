<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompt_generations', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 20)->default('template');   // template | ai
            $table->string('model')->nullable();               // OpenAI model kung ai mode
            $table->string('store_name')->nullable();          // snapshot para sa listing
            $table->string('product_name')->nullable();
            $table->json('inputs')->nullable();                // lahat ng input fields
            $table->longText('output')->nullable();            // ang na-generate na prompt
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prompt_generations');
    }
};
