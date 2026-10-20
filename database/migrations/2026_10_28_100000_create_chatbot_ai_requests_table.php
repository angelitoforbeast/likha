<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * chatbot_ai_requests: ang AI step na hindi naghihintay (chatbot:ai-pool). Bawat row ay ang ticket (request_id, page,
     * PSID, chat, flow, node) at ang request mismo; ang sagot ay itinutugma sa request_id lang. Bagong table lang: walang
     * binabagong row. Binubura ang lampas 2 araw (may text ng customer).
     */
    public function up(): void
    {
        if (Schema::hasTable('chatbot_ai_requests')) return;

        Schema::create('chatbot_ai_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_id', 36)->unique();
            $table->unsignedBigInteger('page_id');
            $table->unsignedBigInteger('psid');
            $table->unsignedBigInteger('chat_id')->nullable(); // null: takbo ng wait-skip
            $table->unsignedBigInteger('flow_id');
            $table->string('node_id', 20);
            $table->string('run_id', 16)->nullable();
            $table->string('page_name');
            $table->string('model', 100);
            $table->longText('messages');
            $table->unsignedInteger('max_tokens');
            $table->text('context'); // input, pangalan, oras, ilang AI at hakbang na
            $table->string('status', 12); // pending | sending | answered | failed | resumed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_at')->nullable(); // 429: hindi muna bago nito
            $table->timestamp('deadline_at');
            $table->string('claimed_by', 36)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->longText('answer')->nullable();
            $table->string('error', 255)->nullable();
            $table->unsignedSmallInteger('http')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('resumed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_at']);
            $table->index(['page_id', 'psid']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_ai_requests');
    }
};
