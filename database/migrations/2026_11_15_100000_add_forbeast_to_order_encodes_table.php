<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Ang padala ng order sa Forbeast (spec 211), sa mismong order_encodes row na sumulat sa macro_output: walang bagong
     * table. forbeast_state: queued | sent | failed | paused (may index: ang listahan ng pumalya at ang bantay ng pause).
     * forbeast_version: ang version ng huling padala ng row, kaya ang lumang job ay humihinto at ang sulat ay kondisyonal.
     * forbeast: ref, gateway, stage, tries, reason, mga oras, order_id. Additive lang.
     */
    public function up(): void
    {
        if (!Schema::hasTable('order_encodes') || Schema::hasColumn('order_encodes', 'forbeast_state')) return;

        Schema::table('order_encodes', function (Blueprint $table) {
            $table->string('forbeast_state', 10)->nullable()->index('order_encodes_forbeast_state');
            $table->unsignedBigInteger('forbeast_version')->nullable();
            $table->json('forbeast')->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('order_encodes') || !Schema::hasColumn('order_encodes', 'forbeast_state')) return;

        Schema::table('order_encodes', function (Blueprint $table) {
            $table->dropIndex('order_encodes_forbeast_state');
            $table->dropColumn(['forbeast_state', 'forbeast_version', 'forbeast']);
        });
    }
};
