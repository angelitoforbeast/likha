<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * order_encodes.final_compare: ang paghahambing ng encode ng Likha sa FINAL na order sa macro_output. Hiwalay sa
     * `compare` / `compare_state` dahil ang mga iyon ay para sa DATABASE row ng sheet at ibinabalik ng compare ng sheet. Additive;
     * walang laman (null) hangga't hindi pa naihahambing.
     */
    public function up(): void
    {
        if (!Schema::hasTable('order_encodes') || Schema::hasColumn('order_encodes', 'final_compare')) return;

        Schema::table('order_encodes', function (Blueprint $table) {
            $table->json('final_compare')->nullable()->after('compare_equal');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('order_encodes') && Schema::hasColumn('order_encodes', 'final_compare')) {
            Schema::table('order_encodes', function (Blueprint $table) {
                $table->dropColumn('final_compare');
            });
        }
    }
};
