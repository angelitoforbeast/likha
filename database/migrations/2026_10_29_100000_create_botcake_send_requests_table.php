<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * botcake_send_requests: ang padala sa Botcake na hindi naghihintay (botcake:send-pool). Bawat row ay ang ticket: ang
     * send_content ng isang takbo ng chatbot (page, PSID, chat, flow, node) o ang tatlong hakbang ng isang chatblast na
     * mensahe. Walang token dito (binabasa ng pool sa page settings). Bagong table lang: walang binabagong row. Binubura
     * ang lampas 2 araw.
     */
    public function up(): void
    {
        if (Schema::hasTable('botcake_send_requests')) return;

        Schema::create('botcake_send_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_id', 36)->unique();
            $table->string('kind', 10); // chatbot | chatblast
            $table->string('customer', 80); // ayos kada customer: ang mas naunang ticket ang nauuna
            $table->unsignedBigInteger('page_id');
            $table->string('page_name');
            $table->string('psid', 32);
            $table->unsignedBigInteger('chat_id')->nullable();
            $table->unsignedBigInteger('flow_id')->nullable();
            $table->string('node_id', 20)->nullable();
            $table->string('run_id', 16)->nullable();
            $table->unsignedBigInteger('message_id')->nullable(); // botcake_messages (chatblast)
            $table->string('lock_owner', 64)->nullable(); // ang lock ng customer na binibitawan ng pool (chatblast)
            $table->longText('payload');
            $table->text('context')->nullable();
            $table->string('status', 12); // pending | sending | done | failed | resumed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_at')->nullable();
            $table->timestamp('deadline_at');
            $table->string('claimed_by', 36)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('called_at')->nullable(); // naitala bago tumawag: hindi na inuulit pagkatapos ng crash
            $table->text('result')->nullable();
            $table->string('error', 255)->nullable();
            $table->unsignedSmallInteger('http')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamp('resumed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_at']);
            $table->index(['customer', 'status', 'id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('botcake_send_requests');
    }
};
