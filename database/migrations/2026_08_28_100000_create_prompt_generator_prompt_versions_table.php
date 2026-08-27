<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompt_generator_prompt_versions', function (Blueprint $table) {
            $table->id();
            $table->string('prompt_key', 60)->index();
            $table->longText('content')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prompt_generator_prompt_versions');
    }
};
