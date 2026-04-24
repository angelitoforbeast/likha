<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the `price` column from `page_item_settings`.
 *
 * Rationale: Unit cost (a.k.a. item_value) is now canonically stored in the
 * global `cogs` table keyed by (item_name, date) only. Having a per-page
 * `price` column caused stale overrides to leak into profit calculations
 * (e.g. Luna's "2 x MINI FLASHLIGHT" row with price=₱23 was incorrectly
 * used for the "1 x MINI FLASHLIGHT" slice). Removing the column eliminates
 * the possibility of this drift.
 *
 * After this migration:
 *   - page_item_settings remains per-page (rts_pct + comments only).
 *   - cogs is the single source of truth for unit cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('page_item_settings', 'price')) {
            Schema::table('page_item_settings', function (Blueprint $table) {
                $table->dropColumn('price');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('page_item_settings', 'price')) {
            Schema::table('page_item_settings', function (Blueprint $table) {
                // Nullable so rollback doesn't force a default for historical rows.
                $table->decimal('price', 10, 2)->nullable()->after('item_name');
            });
        }
    }
};
