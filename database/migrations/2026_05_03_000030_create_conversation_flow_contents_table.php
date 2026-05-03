<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_flow_contents', function (Blueprint $t) {
            $t->id();
            $t->string('page_name', 255)->index();
            $t->string('flow_name', 255)->index();
            // Bubbles array — each item: {type:"text"|"image"|"video", text?, url?, caption?}
            $t->json('bubbles');
            $t->timestamps();
            $t->unique(['page_name', 'flow_name'], 'cfc_page_flow_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_flow_contents');
    }
};
