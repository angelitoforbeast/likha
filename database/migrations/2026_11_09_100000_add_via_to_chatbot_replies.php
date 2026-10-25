<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * chatbot_replies.via: aling bot ang nagdala ng padala (botcake o forbeast); null = sheet row, o lumang row na hindi
     * tiyak. Ang mga lumang row ay pinupunan ng chatbot:replies-via-backfill (hiwalay, kada bahagi), hindi rito: malaki
     * ang table at ang migration ay dapat mabilis.
     */
    public function up(): void
    {
        if (!Schema::hasTable('chatbot_replies') || Schema::hasColumn('chatbot_replies', 'via')) return;

        Schema::table('chatbot_replies', function (Blueprint $table) {
            $table->string('via', 10)->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('chatbot_replies') && Schema::hasColumn('chatbot_replies', 'via')) {
            Schema::table('chatbot_replies', fn (Blueprint $table) => $table->dropColumn('via'));
        }
    }
};
