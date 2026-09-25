<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TeleSMS / Opsyon A — 5 J&T Excel columns na dating NILALAKTAWAN ng importer:
 *   Address, Sender Cellphone, Item Weight, Valuation Fee, Payment Method.
 * Idinadagdag sa from_jnts (v1) at sa buong v2 pipeline (from_jnts_2 + staging
 * + winners) para tuloy-tuloy ang column list ng CSV → LOAD DATA → merge.
 * Nullable lahat — bagong uploads lang ang magkakalaman; ang lumang rows ay
 * NULL (address = ba-backfill mula macro_output via `jnt:backfill-address`).
 */
return new class extends Migration
{
    private const TABLES = ['from_jnts', 'from_jnts_2', 'from_jnts_2_staging', 'from_jnts_2_winners'];

    public function up(): void
    {
        foreach (self::TABLES as $t) {
            if (!Schema::hasTable($t)) continue;
            Schema::table($t, function (Blueprint $table) use ($t) {
                if (!Schema::hasColumn($t, 'address'))        $table->text('address')->nullable();
                if (!Schema::hasColumn($t, 'sender_phone'))   $table->string('sender_phone', 50)->nullable();
                if (!Schema::hasColumn($t, 'item_weight'))    $table->decimal('item_weight', 10, 3)->nullable();
                if (!Schema::hasColumn($t, 'valuation_fee'))  $table->decimal('valuation_fee', 10, 2)->nullable();
                if (!Schema::hasColumn($t, 'payment_method')) $table->string('payment_method', 50)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $t) {
            if (!Schema::hasTable($t)) continue;
            Schema::table($t, function (Blueprint $table) use ($t) {
                foreach (['address', 'sender_phone', 'item_weight', 'valuation_fee', 'payment_method'] as $c) {
                    if (Schema::hasColumn($t, $c)) $table->dropColumn($c);
                }
            });
        }
    }
};
