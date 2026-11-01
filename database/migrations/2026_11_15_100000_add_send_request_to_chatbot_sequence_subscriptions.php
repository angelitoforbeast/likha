<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Ang padala ng step na nasa chatbot:sequence-pool pa (ang request_id ng ticket sa botcake_send_requests): habang may
     * laman, hindi kinukuha ulit ng tick ang subscription. Isang nullable na column lang, walang binabagong row.
     */
    public function up(): void
    {
        if (!Schema::hasTable('chatbot_sequence_subscriptions') || Schema::hasColumn('chatbot_sequence_subscriptions', 'send_request_id')) return;

        Schema::table('chatbot_sequence_subscriptions', function (Blueprint $table) {
            $table->string('send_request_id', 36)->nullable();
            $table->index('send_request_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('chatbot_sequence_subscriptions', 'send_request_id')) return;

        Schema::table('chatbot_sequence_subscriptions', function (Blueprint $table) {
            $table->dropIndex(['send_request_id']);
            $table->dropColumn('send_request_id');
        });
    }
};
