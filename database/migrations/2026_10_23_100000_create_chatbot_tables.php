<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Ang sariling chatbot (/chatbot):
     * - chatbot_flows: ang mga flow; ang graph (nodes at edges) ay iisang JSON.
     * - chatbot_customer_values: mga customer field kada page + PSID (isang row kada key, pinapalitan, hindi dinadagdagan).
     *   Ang mga key na nagsisimula sa "__" ay run state (hal. __wait, __last_chat). PSID 0 = ang test customer ng Test tab.
     * - chatbot_replies: ang talaan ng bawat padala (send_content) at ng bawat sheet row, kasama ang skipped at failed.
     * Pang-~1M chat kada araw: numeric na ID, at ang mga listahan ay laging may index (page + oras, o PSID).
     */
    public function up(): void
    {
        if (!Schema::hasTable('chatbot_flows')) {
            Schema::create('chatbot_flows', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100)->unique();
                $table->longText('graph')->nullable(); // {"nodes":[…],"edges":[…]}
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('chatbot_customer_values')) {
            Schema::create('chatbot_customer_values', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('page_id');
                $table->unsignedBigInteger('psid');
                $table->string('key', 100);
                $table->text('value')->nullable();
                $table->timestamp('updated_at')->nullable();

                $table->unique(['page_id', 'psid', 'key']);
            });
        }

        if (!Schema::hasTable('chatbot_replies')) {
            Schema::create('chatbot_replies', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('page_id');
                $table->unsignedBigInteger('psid');
                $table->unsignedBigInteger('chat_id')->nullable();
                $table->unsignedBigInteger('flow_id')->nullable();
                $table->string('node_id', 20)->nullable();
                $table->string('kind', 10)->default('send'); // send | sheet
                $table->json('content')->nullable();          // send: [{type,text|url}]; sheet: {"sheet","tab","row"}
                $table->string('status', 10);                 // sent | failed | skipped
                $table->string('error', 255)->nullable();
                $table->unsignedSmallInteger('http')->nullable();
                $table->timestamp('sent_at');

                $table->index(['page_id', 'sent_at']);
                $table->index('psid');
                $table->index('sent_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_replies');
        Schema::dropIfExists('chatbot_customer_values');
        Schema::dropIfExists('chatbot_flows');
    }
};
