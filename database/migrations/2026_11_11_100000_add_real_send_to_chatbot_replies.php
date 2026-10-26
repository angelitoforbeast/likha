<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = ['client_ref', 'confirmed_at', 'confirmed_status'];
    private const CLIENT_REF_INDEX = 'chatbot_replies_client_ref_idx';
    private const PENDING_INDEX = 'chatbot_replies_pending_idx';

    /**
     * Ang totoong oras ng padala sa chatbot_replies (mga bagong nullable na column lang, walang binabagong row):
     * - client_ref: ang ID ng padala na ibinigay sa Forbeast (para itugma ang send_status event).
     * - confirmed_at, confirmed_status: kailan talaga naipadala sa customer at ang huling resulta (sent, partial, failed).
     *   Ang Botcake ay sabay (ang oras ng talaan ay ang padala); ang Forbeast ay mula sa send_status event.
     * Ang mga index: ang pagtugma ng client_ref, at ang pending (via + confirmed_status + sent_at).
     * Bawat column at index ay idinadagdag lang kung wala pa: ang kalahating takbo ay natatapos sa ulit.
     */
    public function up(): void
    {
        if (!Schema::hasTable('chatbot_replies')) return;

        foreach (self::COLUMNS as $column) {
            if (Schema::hasColumn('chatbot_replies', $column)) continue;
            Schema::table('chatbot_replies', fn (Blueprint $table) => match ($column) {
                'client_ref' => $table->string('client_ref', 100)->nullable(),
                'confirmed_at' => $table->timestamp('confirmed_at')->nullable(),
                'confirmed_status' => $table->string('confirmed_status', 10)->nullable(),
            });
        }
        if (!Schema::hasIndex('chatbot_replies', self::CLIENT_REF_INDEX)) {
            Schema::table('chatbot_replies', fn (Blueprint $table) => $table->index('client_ref', self::CLIENT_REF_INDEX));
        }
        if (Schema::hasColumn('chatbot_replies', 'via') && !Schema::hasIndex('chatbot_replies', self::PENDING_INDEX)) {
            Schema::table('chatbot_replies', fn (Blueprint $table) => $table->index(['via', 'confirmed_status', 'sent_at'], self::PENDING_INDEX));
        }
    }

    /** Binubura lang ang mayroon (pati pagkatapos ng kalahating takbo). */
    public function down(): void
    {
        if (!Schema::hasTable('chatbot_replies')) return;

        foreach ([self::CLIENT_REF_INDEX, self::PENDING_INDEX] as $index) {
            if (Schema::hasIndex('chatbot_replies', $index)) Schema::table('chatbot_replies', fn (Blueprint $table) => $table->dropIndex($index));
        }
        $columns = array_values(array_filter(self::COLUMNS, fn ($c) => Schema::hasColumn('chatbot_replies', $c)));
        if ($columns) Schema::table('chatbot_replies', fn (Blueprint $table) => $table->dropColumn($columns));
    }
};
