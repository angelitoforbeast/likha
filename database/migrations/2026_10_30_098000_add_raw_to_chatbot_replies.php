<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * chatbot_replies.raw: ang buong sagot ng Botcake sa pumalyang padala (hanggang 1,000 letra, walang token), para
     * makita kung ano talaga ang pumalya. Ang error ay nananatiling maikli; ang content ay ang listahan ng mensahe lang.
     */
    public function up(): void
    {
        if (Schema::hasTable('chatbot_replies') && !Schema::hasColumn('chatbot_replies', 'raw')) {
            Schema::table('chatbot_replies', fn (Blueprint $table) => $table->text('raw')->nullable()->after('error'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('chatbot_replies') && Schema::hasColumn('chatbot_replies', 'raw')) {
            Schema::table('chatbot_replies', fn (Blueprint $table) => $table->dropColumn('raw'));
        }
    }
};
