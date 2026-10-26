<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ang totoong oras ng padala sa chatbot_replies (mga bagong nullable na column lang, walang binabagong row):
     * - client_ref: ang ID ng padala na ibinigay sa Forbeast (para itugma ang send_status event).
     * - confirmed_at, confirmed_status: kailan talaga naipadala sa customer at ang huling resulta (sent, partial, failed).
     *   Ang Botcake ay sabay (ang oras ng talaan ay ang padala); ang Forbeast ay mula sa send_status event.
     * Ang mga index: ang pagtugma ng client_ref, at ang pending (via + confirmed_status + sent_at).
     */
    public function up(): void
    {
        if (!Schema::hasTable('chatbot_replies') || Schema::hasColumn('chatbot_replies', 'confirmed_at')) return;

        Schema::table('chatbot_replies', function (Blueprint $table) {
            $table->string('client_ref', 100)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('confirmed_status', 10)->nullable();
        });
        $via = Schema::hasColumn('chatbot_replies', 'via');
        Schema::table('chatbot_replies', function (Blueprint $table) use ($via) {
            $table->index('client_ref', 'chatbot_replies_client_ref_idx');
            if ($via) $table->index(['via', 'confirmed_status', 'sent_at'], 'chatbot_replies_pending_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('chatbot_replies') || !Schema::hasColumn('chatbot_replies', 'confirmed_at')) return;

        Schema::table('chatbot_replies', function (Blueprint $table) {
            $table->dropIndex('chatbot_replies_client_ref_idx');
            if (Schema::hasIndex('chatbot_replies', 'chatbot_replies_pending_idx')) $table->dropIndex('chatbot_replies_pending_idx');
        });
        Schema::table('chatbot_replies', fn (Blueprint $table) => $table->dropColumn(['client_ref', 'confirmed_at', 'confirmed_status']));
    }
};
