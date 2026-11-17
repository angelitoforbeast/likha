<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Likha Chat (spec 296): one message. The sender is a person (sender_user_id) or an AI (sender_ai, a short key like
 * "mira"); a row with neither is a system line ("Ana joined"). attachments: [{path, name, size, mime, kind}].
 * Soft deletes: a removed message shows "Message removed" and the row is kept.
 * Hot query: messages after an id in one conversation, served by (conversation_id, id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conversation_id');
            $t->foreignId('sender_user_id')->nullable();
            $t->string('sender_ai', 40)->nullable();
            $t->text('body')->nullable();
            $t->unsignedBigInteger('reply_to_id')->nullable();
            $t->json('attachments')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
