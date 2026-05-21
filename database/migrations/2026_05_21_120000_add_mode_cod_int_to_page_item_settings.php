<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `mode_cod_int` (integer, rounded ₱price) sa page_item_settings.
 *
 * Scope of RTS+Promo before this change: (page_name, item_name).
 * After this change:                       (page_name, item_name, mode_cod_int).
 *
 * Reason: price change for the same page+item should be treated as a new
 * RTS/Promo period (no inheritance from the prior price). Matches the
 * price-based anchor logic na ginagamit ng /owner/private anchor walk.
 *
 * Existing rows: mode_cod_int starts NULL. Backfill happens via the Recompute
 * Primary Items button (DailyPrimaryItemService::backfillSettingsPrices()) —
 * NULL rows in the recompute range get tagged with the actual price from
 * daily_page_primary_item.primary_mode_cod. Rows na hindi ma-match (e.g.,
 * tied primary on that day, excluded page, or item wasn't the page's primary)
 * stay NULL — they become orphaned (lookups won't match them).
 *
 * Lookup logic (after this change): strict (page, item, mode_cod_int) match.
 * NULL mode_cod_int rows are ignored — sila yung orphaned rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('page_item_settings')) return;
        if (Schema::hasColumn('page_item_settings', 'mode_cod_int')) return;

        Schema::table('page_item_settings', function (Blueprint $t) {
            // INT NULL — rounded ₱price tag for this settings row.
            // Null = no price tag yet (orphaned; ignored sa lookups).
            $t->integer('mode_cod_int')->nullable()->after('rts_pct');

            // Composite index para sa fast (page, item, price, date) lookups.
            // Complements the existing (page_name, item_name, effective_date) index.
            $t->index(
                ['page_name', 'item_name', 'mode_cod_int', 'effective_date'],
                'pis_page_item_price_date_idx'
            );
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('page_item_settings')) return;
        if (!Schema::hasColumn('page_item_settings', 'mode_cod_int')) return;

        Schema::table('page_item_settings', function (Blueprint $t) {
            $t->dropIndex('pis_page_item_price_date_idx');
            $t->dropColumn('mode_cod_int');
        });
    }
};
