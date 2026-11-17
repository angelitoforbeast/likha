<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Likha Chat (spec 296): who is in a conversation. One row per conversation and user; leaving or being removed sets
 * left_at (the row stays), being added again clears it. last_read_message_id drives unread counts and "Seen by".
 * (user_id, conversation_id) serves the list of a person's conversations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conversation_id');
            $t->foreignId('user_id');
            $t->string('role', 10)->default('member');      // member | admin
            $t->unsignedBigInteger('last_read_message_id')->nullable();
            $t->timestamp('joined_at')->nullable();
            $t->timestamp('left_at')->nullable();
            $t->timestamps();
            $t->unique(['conversation_id', 'user_id']);
            $t->index(['user_id', 'conversation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_members');
    }
};
