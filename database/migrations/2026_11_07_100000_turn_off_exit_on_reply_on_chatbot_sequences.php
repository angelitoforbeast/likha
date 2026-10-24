<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * "Exit when the customer replies" ay naka-off na sa bagong sequence, at pinapatay rin sa lahat ng existing na
     * sequence. Ang down() ay ibinabalik lang ang lumang default (on); hindi nito alam kung alin ang naka-on dati.
     */
    public function up(): void
    {
        if (!Schema::hasTable('chatbot_sequences') || !Schema::hasColumn('chatbot_sequences', 'exit_on_reply')) return;

        Schema::table('chatbot_sequences', function (Blueprint $table) {
            $table->boolean('exit_on_reply')->default(false)->change();
        });
        DB::table('chatbot_sequences')->where('exit_on_reply', true)->update(['exit_on_reply' => false]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('chatbot_sequences') || !Schema::hasColumn('chatbot_sequences', 'exit_on_reply')) return;

        Schema::table('chatbot_sequences', function (Blueprint $table) {
            $table->boolean('exit_on_reply')->default(true)->change();
        });
    }
};
