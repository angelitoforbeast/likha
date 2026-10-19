<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * - chatbot_events: ang ginawa ng bot sa bawat takbo (executed actions sa /chatbot/inbox): simula, condition, field,
     *   AI, sheet, paghihintay, dulo, at skipped. Maikli ang detail (hanggang 200 letra kada value); 30 araw lang.
     * - chatbot_replies.user_id: sino ang nagpadala ng manual na sagot (kind = manual).
     */
    public function up(): void
    {
        if (!Schema::hasTable('chatbot_events')) {
            Schema::create('chatbot_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('page_id');
                $table->unsignedBigInteger('psid');
                $table->unsignedBigInteger('chat_id')->nullable();
                $table->unsignedBigInteger('flow_id')->nullable();
                $table->string('node_id', 20)->nullable();
                $table->string('type', 20);
                $table->json('detail')->nullable();
                $table->timestamp('created_at');

                $table->index(['page_id', 'psid', 'created_at']);
                $table->index('created_at');
            });
        }

        if (Schema::hasTable('chatbot_replies') && !Schema::hasColumn('chatbot_replies', 'user_id')) {
            Schema::table('chatbot_replies', fn (Blueprint $table) => $table->unsignedBigInteger('user_id')->nullable()->after('node_id'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_events');
        if (Schema::hasTable('chatbot_replies') && Schema::hasColumn('chatbot_replies', 'user_id')) {
            Schema::table('chatbot_replies', fn (Blueprint $table) => $table->dropColumn('user_id'));
        }
    }
};
