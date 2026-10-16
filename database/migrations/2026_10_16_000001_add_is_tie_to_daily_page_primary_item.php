<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * is_tie = may ibang item na kapareho ng top count sa araw na iyon; ang main item ay pinili
 * ng tie rule (proceed → main item kahapon → mas mataas na mode COD → key), hindi nilaktawan ang araw.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('daily_page_primary_item') || Schema::hasColumn('daily_page_primary_item', 'is_tie')) {
            return;
        }
        Schema::table('daily_page_primary_item', function (Blueprint $table) {
            $table->boolean('is_tie')->default(false);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('daily_page_primary_item', 'is_tie')) {
            Schema::table('daily_page_primary_item', function (Blueprint $table) {
                $table->dropColumn('is_tie');
            });
        }
    }
};
