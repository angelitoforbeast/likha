<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Astra — in-site AI chat (OpenAI Responses API via server-side proxy).
 * Multi-user: bawat conversation ay pag-aari ng isang user; per-conversation
 * settings (model/effort/search/budget) + OpenAI previous_response_id chain.
 * Attachments (images) ay naka-private disk; nililink sa message pag na-send.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('astra_conversations')) {
            Schema::create('astra_conversations', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('title', 160)->nullable();
                $t->string('model', 60);
                $t->string('effort', 10)->default('medium');
                $t->string('search_mode', 10)->default('auto');      // auto|required|off
                $t->unsignedInteger('max_output_tokens')->default(8192);
                $t->string('openai_last_response_id', 80)->nullable(); // previous_response_id chain
                $t->timestamp('last_message_at')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['user_id', 'last_message_at']);
            });
        }

        if (!Schema::hasTable('astra_messages')) {
            Schema::create('astra_messages', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('conversation_id')->index();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('role', 20);                                // user|assistant|notice
                $t->longText('text')->nullable();
                $t->longText('reasoning')->nullable();                 // reasoning summary (kung meron)
                $t->json('sources')->nullable();                       // [{url,title}]
                $t->json('attachments')->nullable();                   // [{id,name,url,mime}]
                $t->string('openai_response_id', 80)->nullable();
                $t->string('status', 20)->default('completed');        // completed|incomplete|failed|aborted
                $t->json('usage')->nullable();                         // OpenAI usage
                $t->unsignedInteger('duration_ms')->nullable();
                $t->timestamps();
                $t->index(['conversation_id', 'id']);
            });
        }

        if (!Schema::hasTable('astra_attachments')) {
            Schema::create('astra_attachments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->index();
                $t->unsignedBigInteger('conversation_id')->nullable()->index();
                $t->unsignedBigInteger('message_id')->nullable()->index();
                $t->string('path');                                    // sa private 'local' disk
                $t->string('mime', 80);
                $t->unsignedInteger('size');
                $t->string('original_name');
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('astra_attachments');
        Schema::dropIfExists('astra_messages');
        Schema::dropIfExists('astra_conversations');
    }
};
