<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gpt_prompts', function (Blueprint $t) {
            $t->id();
            $t->mediumText('prompt_text');
            $t->string('saved_by_email', 255)->nullable()->index();
            $t->string('note', 500)->nullable();
            $t->timestamps();
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gpt_prompts');
    }
};
