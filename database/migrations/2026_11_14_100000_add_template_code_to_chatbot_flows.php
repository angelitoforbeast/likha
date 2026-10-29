<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Ang template code ng flow (LK-XXXX-XX): ipinapaste sa Forbeast para itali ang page sa flow na ito. Null = walang
        // code (o binawi). Walang settings store ang flow, kaya isang column lang, walang bagong table.
        if (!Schema::hasTable('chatbot_flows') || Schema::hasColumn('chatbot_flows', 'template_code')) return;

        Schema::table('chatbot_flows', function (Blueprint $table) {
            $table->string('template_code', 10)->nullable()->unique();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('chatbot_flows', 'template_code')) return;

        Schema::table('chatbot_flows', function (Blueprint $table) {
            $table->dropUnique(['template_code']);
            $table->dropColumn('template_code');
        });
    }
};
