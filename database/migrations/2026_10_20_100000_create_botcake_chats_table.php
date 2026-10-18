<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Ang mga mensahe ng customer na ipinapasa ng JSON API block ng Botcake (/api/botcake/chat): isang row kada mensahe.
     * page_name = ang page na may botcake.page_id na ito sa page_settings (null kapag wala). payload = ang buong
     * ipinadala (walang key). 90 araw lang ang itinatago (botcake:prune-chats).
     */
    public function up(): void
    {
        if (Schema::hasTable('botcake_chats')) return;

        Schema::create('botcake_chats', function (Blueprint $table) {
            $table->id();
            $table->string('page_id', 64)->nullable();
            $table->string('page_name')->nullable();
            $table->string('psid', 64)->nullable();
            $table->string('name')->nullable();
            $table->text('message')->nullable();
            $table->string('message_id', 191)->nullable()->index();
            $table->json('payload')->nullable();
            $table->timestamp('received_at')->nullable();

            $table->index(['page_id', 'psid', 'received_at']);
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('botcake_chats');
    }
};
