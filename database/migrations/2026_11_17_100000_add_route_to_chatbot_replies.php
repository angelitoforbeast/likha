<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * chatbot_replies.route: ang address ng forbeast gateway na dinaanan ng padala (Gateways::route: forbeast, incepxion, …);
     * null = hindi forbeast ang nagdala, o lumang row (ang default na address). Malaki ang table, kaya sa MySQL ay
     * ALGORITHM=INSTANT lang (metadata, walang kopya ng table): kapag hindi kaya ng server, pumapalya ang migration sa halip
     * na kopyahin ang table. Ang ibang driver: ang karaniwang nullable column.
     */
    public function up(): void
    {
        if (!Schema::hasTable('chatbot_replies') || Schema::hasColumn('chatbot_replies', 'route')) return;
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `' . DB::getTablePrefix() . 'chatbot_replies` ADD COLUMN `route` VARCHAR(30) NULL, ALGORITHM=INSTANT');
            return;
        }

        Schema::table('chatbot_replies', fn (Blueprint $table) => $table->string('route', 30)->nullable());
    }

    public function down(): void
    {
        if (!Schema::hasTable('chatbot_replies') || !Schema::hasColumn('chatbot_replies', 'route')) return;
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `' . DB::getTablePrefix() . 'chatbot_replies` DROP COLUMN `route`, ALGORITHM=INSTANT');
            return;
        }

        Schema::table('chatbot_replies', fn (Blueprint $table) => $table->dropColumn('route'));
    }
};
