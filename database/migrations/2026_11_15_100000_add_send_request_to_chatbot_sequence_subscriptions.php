<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Dalawang nullable na column, walang binabagong row:
     * - send_request_id: ang padala ng step na nasa chatbot:sequence-pool pa (request_id ng ticket); habang may laman, hindi
     *   kinukuha ulit ng tick ang subscription;
     * - attempt: ang takbo ng step na nagsimula ("step|due|ilang takbo|may tawag na|simula"), binubura kapag natapos ang takbo;
     *   ang naiwan ay ang job na namatay (hindi umaasa sa cache).
     */
    public function up(): void
    {
        if (!Schema::hasTable('chatbot_sequence_subscriptions')) return;
        // Hindi naghihintay nang matagal sa lock ng table (ang mahabang transaksyon ay hindi humaharang): pumapalya, at inuulit sa tahimik na oras.
        if (DB::getDriverName() === 'mysql') DB::statement('SET SESSION lock_wait_timeout = 5');
        if (!Schema::hasColumn('chatbot_sequence_subscriptions', 'send_request_id')) {
            Schema::table('chatbot_sequence_subscriptions', function (Blueprint $table) {
                $table->string('send_request_id', 36)->nullable();
                $table->index('send_request_id');
            });
        }
        if (!Schema::hasColumn('chatbot_sequence_subscriptions', 'attempt')) {
            Schema::table('chatbot_sequence_subscriptions', function (Blueprint $table) {
                $table->string('attempt', 80)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('chatbot_sequence_subscriptions', 'attempt')) {
            Schema::table('chatbot_sequence_subscriptions', fn (Blueprint $table) => $table->dropColumn('attempt'));
        }
        if (Schema::hasColumn('chatbot_sequence_subscriptions', 'send_request_id')) {
            Schema::table('chatbot_sequence_subscriptions', function (Blueprint $table) {
                $table->dropIndex(['send_request_id']);
                $table->dropColumn('send_request_id');
            });
        }
    }
};
