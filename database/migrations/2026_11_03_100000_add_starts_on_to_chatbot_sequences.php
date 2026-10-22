<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ang sequence na kusang nagsisimula kapag na-upload ang order ng customer: starts_on = 'order_uploaded' (null = mano-mano
 * lang, gaya ng dati). Nullable lang; walang nababago sa mga dating row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('chatbot_sequences') || Schema::hasColumn('chatbot_sequences', 'starts_on')) return;

        Schema::table('chatbot_sequences', function (Blueprint $t) {
            $t->string('starts_on', 20)->nullable()->after('exit_on_reply');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('chatbot_sequences', 'starts_on')) {
            Schema::table('chatbot_sequences', fn (Blueprint $t) => $t->dropColumn('starts_on'));
        }
    }
};
