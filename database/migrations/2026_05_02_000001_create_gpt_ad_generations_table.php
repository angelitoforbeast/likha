<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gpt_ad_generations', function (Blueprint $t) {
            $t->id();
            $t->string('user_email', 255)->nullable()->index();
            $t->string('product_name', 255);
            $t->text('product_description');
            $t->string('page_filter', 255)->nullable();
            $t->string('item_filter', 255)->nullable();
            $t->boolean('active_only')->default(true);
            $t->float('temperature')->nullable();
            $t->unsignedTinyInteger('variants_requested')->default(1);
            $t->mediumText('final_prompt')->nullable();
            $t->json('output_variants');                     // array of generated strings
            $t->string('chosen_variant_index', 8)->nullable(); // user's selection if logged
            $t->string('model', 50)->nullable();
            $t->timestamps();
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gpt_ad_generations');
    }
};
