<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `promo` column sa page_item_settings + audit log table.
 * Free-text varchar(255), required on save (validated controller-side).
 * Per-date inheritance same as rts_pct — latest effective_date ≤ cell_date wins.
 *
 * Existing rows ay backfilled with 'NONE' sentinel so legacy saves remain
 * lookup-able and downstream display ay walang null. Bagong saves must specify.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('page_item_settings') && !Schema::hasColumn('page_item_settings', 'promo')) {
            Schema::table('page_item_settings', function (Blueprint $t) {
                // Default NONE so backfill is deterministic; controller-level
                // validation enforces non-empty on bagong saves.
                $t->string('promo', 255)->default('NONE')->after('rts_pct');
            });

            // Backfill existing rows — safe even sa fresh installs (zero rows).
            \Illuminate\Support\Facades\DB::table('page_item_settings')
                ->whereNull('promo')
                ->orWhere('promo', '')
                ->update(['promo' => 'NONE']);
        }

        if (Schema::hasTable('page_item_settings_log')) {
            Schema::table('page_item_settings_log', function (Blueprint $t) {
                if (!Schema::hasColumn('page_item_settings_log', 'old_promo')) {
                    $t->string('old_promo', 255)->nullable()->after('new_item_value');
                }
                if (!Schema::hasColumn('page_item_settings_log', 'new_promo')) {
                    $t->string('new_promo', 255)->nullable()->after('old_promo');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('page_item_settings') && Schema::hasColumn('page_item_settings', 'promo')) {
            Schema::table('page_item_settings', function (Blueprint $t) {
                $t->dropColumn('promo');
            });
        }
        if (Schema::hasTable('page_item_settings_log')) {
            Schema::table('page_item_settings_log', function (Blueprint $t) {
                if (Schema::hasColumn('page_item_settings_log', 'old_promo')) $t->dropColumn('old_promo');
                if (Schema::hasColumn('page_item_settings_log', 'new_promo')) $t->dropColumn('new_promo');
            });
        }
    }
};
