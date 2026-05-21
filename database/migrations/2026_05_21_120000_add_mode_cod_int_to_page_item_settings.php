<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `mode_cod_int` (integer, rounded ₱price) sa page_item_settings AND
 * auto-backfills existing rows using daily_page_primary_item as the price
 * source. No "blank" window — pag matapos yung migrate, lahat ng existing
 * rows na ma-match ay agad naka-tag na ng tamang price.
 *
 * Scope of RTS+Promo before this change: (page_name, item_name).
 * After this change:                      (page_name, item_name, mode_cod_int).
 *
 * Reason: price change for the same page+item should be treated as a new
 * RTS/Promo period (no inheritance from the prior price). Matches the
 * price-based anchor logic na ginagamit ng /owner/private anchor walk.
 *
 * Auto-backfill sa up():
 *   - SQL UPDATE joining page_item_settings ↔ daily_page_primary_item
 *   - Matches: (page_key, normalized item_key, ts_date = effective_date)
 *   - Sets mode_cod_int = ROUND(primary_mode_cod)
 *   - Coverage = however many days/pages exist sa daily_page_primary_item
 *
 * Rows na hindi ma-backfill (mananatili NULL):
 *   - Tied primary on that day (no daily_page_primary_item row generated)
 *   - Excluded pages (no row generated)
 *   - Item wasn't the page's primary on that day
 *   - Date is outside the daily_page_primary_item recompute window
 *
 * These NULL rows become orphaned (lookups won't match them). Para mai-tag
 * sila, recompute the missing date range via /owner/private's Primary Items
 * button (DailyPrimaryItemService also runs backfill after recompute).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('page_item_settings')) return;

        // 1) Add column + composite index (idempotent).
        if (!Schema::hasColumn('page_item_settings', 'mode_cod_int')) {
            Schema::table('page_item_settings', function (Blueprint $t) {
                // INT NULL — rounded ₱price tag for this settings row.
                $t->integer('mode_cod_int')->nullable()->after('rts_pct');

                // Composite index for fast (page, item, price, date) lookups.
                $t->index(
                    ['page_name', 'item_name', 'mode_cod_int', 'effective_date'],
                    'pis_page_item_price_date_idx'
                );
            });
        }

        // 2) Auto-backfill mode_cod_int sa existing rows using daily_page_primary_item.
        //    Only touches NULL rows — already-tagged rows preserved.
        if (!Schema::hasTable('daily_page_primary_item')) return;

        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            $itemKeyExpr = "LOWER(REGEXP_REPLACE(BTRIM(pis.item_name), '[ _-]+', '', 'g'))";
            $pageKeyExpr = "LOWER(BTRIM(pis.page_name))";
            $sql = "
                UPDATE page_item_settings AS pis
                SET mode_cod_int = ROUND(dpi.primary_mode_cod)
                FROM daily_page_primary_item AS dpi
                WHERE dpi.ts_date = pis.effective_date
                  AND dpi.page_key = {$pageKeyExpr}
                  AND dpi.primary_item_key = {$itemKeyExpr}
                  AND pis.mode_cod_int IS NULL
                  AND dpi.primary_mode_cod IS NOT NULL
            ";
        } else {
            $itemKeyExpr = "LOWER(REPLACE(REPLACE(REPLACE(TRIM(pis.item_name),' ',''),'-',''),'_',''))";
            $pageKeyExpr = "LOWER(TRIM(pis.page_name))";
            $sql = "
                UPDATE page_item_settings pis
                INNER JOIN daily_page_primary_item dpi
                    ON dpi.ts_date = pis.effective_date
                   AND dpi.page_key = {$pageKeyExpr}
                   AND dpi.primary_item_key = {$itemKeyExpr}
                SET pis.mode_cod_int = ROUND(dpi.primary_mode_cod)
                WHERE pis.mode_cod_int IS NULL
                  AND dpi.primary_mode_cod IS NOT NULL
            ";
        }

        try {
            $affected = DB::affectingStatement($sql);
            \Log::info("page_item_settings backfill: {$affected} rows tagged with mode_cod_int during migration");
        } catch (\Throwable $e) {
            // Non-fatal — column add succeeded, just log the backfill failure.
            \Log::warning('page_item_settings backfill failed during migration: ' . $e->getMessage());
        }
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
