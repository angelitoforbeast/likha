<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ang mga sagot ng AI sa content check ng /chatbot/pages (item, presyo, larawan, ad video), isang row kada tanong.
 * cache_key = sha1(uri | value o link | inaasahang item): ang parehong tanong ay hindi na itinatanong ulit.
 * Kasama ang model, tokens at cost_usd (ang talaan ng gastos, gaya ng ai_checker_logs.detail).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chatbot_content_answers')) return;

        Schema::create('chatbot_content_answers', function (Blueprint $t) {
            $t->id();
            $t->string('cache_key', 40)->unique();
            $t->string('kind', 10);              // text | image | video
            $t->string('page_name')->nullable(); // ang page na unang nagtanong
            $t->string('field', 60)->nullable(); // hal. Main Flow, PICTURE, xyz.mp4
            $t->string('expected_item')->nullable();
            $t->text('answer');                  // JSON na nasuri na
            $t->string('model', 64)->nullable();
            $t->unsignedInteger('tokens_in')->default(0);
            $t->unsignedInteger('tokens_out')->default(0);
            $t->decimal('cost_usd', 10, 6)->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_content_answers');
    }
};
