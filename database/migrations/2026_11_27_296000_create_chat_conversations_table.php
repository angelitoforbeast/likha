<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Likha Chat (spec 296): one conversation, direct (two people) or group (a name and members).
 * direct_key ("<low user id>:<high user id>") is set on direct chats only and is unique, so a pair has at most one
 * direct chat even when both open it at the same moment. Soft deletes: nothing is deleted.
 * Hot query: a person's conversations by last activity (chat_members by user, then last_message_at here).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 10);                         // direct | group
            $t->string('title', 80)->nullable();            // group name; null on direct chats
            $t->string('direct_key', 41)->nullable()->unique();
            $t->foreignId('created_by')->nullable();
            $t->timestamp('last_message_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index('last_message_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_conversations');
    }
};
