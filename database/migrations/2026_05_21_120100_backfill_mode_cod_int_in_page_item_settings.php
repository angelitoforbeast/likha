<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Standalone backfill for page_item_settings.mode_cod_int.
 *
 * Reason: the earlier migration (2026_05_21_120000_*) added the column but the
 * initial deployed version did NOT include inline backfill. Servers that ran
 * that version ended up with all NULL mode_cod_int → strict price-aware lookup
 * returned no matches → /owner/private showed "—" for every RTS.
 *
 * This migration runs the backfill explicitly. Idempotent — only updates
 * NULL rows. Safe to re-run via :rollback + :migrate kung kailangan.
 *
 * NO date range filter — covers ALL rows (not limited to recompute window),
 * so dates older than 90 days also get tagged.
 *
 * Rows na hindi pa rin ma-match (mananatili NULL after this migration):
 *   - Tied primary on that day (no daily_page_primary_item row)
 *   - Excluded page (no row generated)
 *   - Item wasn't the page's primary on that day
 *   - Date too old — outside daily_page_primary_item coverage
 *
 * These remaining NULL rows can be addressed by:
 *   - Manually re-saving via Edit modal
 *   - Expanding daily_page_primary_item recompute coverage via tinker:
 *     `app(DailyPrimaryItemService::class)->recomputeRange('2024-01-01', date('Y-m-d'))`
 *     then re-running this migration: `php artisan migrate:refresh --path=...`
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('page_item_settings')) return;
        if (!Schema::hasColumn('page_item_settings', 'mode_cod_int')) return;
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
            \Log::info("[backfill_mode_cod_int] tagged {$affected} page_item_settings rows");
        } catch (\Throwable $e) {
            \Log::error('[backfill_mode_cod_int] failed: ' . $e->getMessage());
            throw $e; // rethrow so the migration FAILS visibly — user gets error msg
        }
    }

    public function down(): void
    {
        // Down does nothing — backfill is data-only, hindi reversible without
        // losing the price tags we just established. Use the parent migration's
        // down() to drop the column entirely if needed.
    }
};
