<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * chatbot_comment_events: isang row kada comment na dumating mula sa Forbeast (unique ang comment_id, kaya isang beses
     * lang), kasama ang dahilan kapag skipped at ang resulta ng private message at ng public reply. Ang text ng comment
     * ay hindi iniimbak (galing sa publiko, hindi kailangan); ang ipinadala lang namin.
     */
    public function up(): void
    {
        if (Schema::hasTable('chatbot_comment_events')) return;

        Schema::create('chatbot_comment_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('page_id');
            $table->string('comment_id', 100)->unique();
            $table->string('post_id', 100)->nullable();
            $table->string('from_id', 64)->nullable();
            $table->string('status', 10);                   // queued | sending | sent | failed | skipped
            $table->string('reason', 20)->nullable();       // kapag skipped: botcake_page, bot_off, reply, page_author, ours, not_set
            $table->string('pm_status', 16)->nullable();    // sent | failed | already_replied (null = walang Comment PM)
            $table->text('pm_text')->nullable();
            $table->string('pm_error', 255)->nullable();
            $table->string('reply_status', 16)->nullable(); // sent | failed (null = walang Comment Reply)
            $table->text('reply_text')->nullable();
            $table->string('reply_error', 255)->nullable();
            $table->string('reply_comment_id', 100)->nullable(); // ang comment na ginawa ng reply namin (para hindi sagutin ulit)
            $table->timestamp('received_at');
            $table->timestamp('handled_at')->nullable();

            $table->index(['page_id', 'received_at']);
            $table->index('received_at');
            $table->index('reply_comment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_comment_events');
    }
};
