<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Ang Results ng chatbot (/chatbot/results): kung aling value at version ang naipadala, kung sinagot, kung umorder.
     * - chatbot_replies: mga bagong nullable na column lang (walang binabagong row): ang flow version, ang sequence at step,
     *   ang ad_id ng customer, ang buong tags (JSON: ang botfields at customer fields ng bawat mensahe), at ang replied_at
     *   at reply_seconds (ang unang chat ng customer pagkatapos ng padala, sa loob ng 24 oras).
     * - chatbot_reply_tags: isang row kada mensahe at botfield (ang id ng page_settings row = ang version), para sa
     *   "By message" at sa paghahambing ng dalawang version nang may index, hindi binabasa ang JSON.
     * - chatbot_events (type, created_at): ang mga order sa isang petsa.
     */
    public function up(): void
    {
        if (Schema::hasTable('chatbot_replies') && !Schema::hasColumn('chatbot_replies', 'flow_version_id')) {
            Schema::table('chatbot_replies', function (Blueprint $table) {
                $table->unsignedBigInteger('flow_version_id')->nullable();
                $table->unsignedBigInteger('sequence_id')->nullable();
                $table->unsignedSmallInteger('sequence_step')->nullable();
                $table->string('ad_id', 64)->nullable();
                $table->json('tags')->nullable();
                $table->timestamp('replied_at')->nullable();
                $table->unsignedInteger('reply_seconds')->nullable();

                $table->index(['flow_id', 'sent_at']);
                $table->index(['sequence_id', 'sent_at']);
                $table->index(['page_id', 'psid', 'sent_at']);
            });
        }

        if (!Schema::hasTable('chatbot_reply_tags')) {
            Schema::create('chatbot_reply_tags', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('reply_id');
                $table->unsignedBigInteger('page_id');
                $table->unsignedBigInteger('flow_id')->nullable();
                $table->unsignedBigInteger('flow_version_id')->nullable();
                $table->string('node_id', 20)->nullable();
                $table->unsignedTinyInteger('message')->default(0); // ang puwesto ng mensahe sa padala (0 = una)
                $table->string('botfield', 100)->nullable();         // null = walang botfield ang mensahe
                $table->unsignedBigInteger('setting_id')->nullable(); // page_settings.id = ang version ng botfield
                $table->timestamp('sent_at');

                $table->index(['page_id', 'sent_at']);
                $table->index('reply_id');
                $table->index('setting_id');
            });
        }

        if (Schema::hasTable('chatbot_events')) {
            Schema::table('chatbot_events', fn (Blueprint $table) => $table->index(['type', 'created_at']));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('chatbot_events')) {
            Schema::table('chatbot_events', fn (Blueprint $table) => $table->dropIndex(['type', 'created_at']));
        }
        Schema::dropIfExists('chatbot_reply_tags');
        if (Schema::hasTable('chatbot_replies') && Schema::hasColumn('chatbot_replies', 'flow_version_id')) {
            Schema::table('chatbot_replies', function (Blueprint $table) {
                $table->dropIndex(['flow_id', 'sent_at']);
                $table->dropIndex(['sequence_id', 'sent_at']);
                $table->dropIndex(['page_id', 'psid', 'sent_at']);
                $table->dropColumn(['flow_version_id', 'sequence_id', 'sequence_step', 'ad_id', 'tags', 'replied_at', 'reply_seconds']);
            });
        }
    }
};
