<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alias-aware backfill for page_item_settings.mode_cod_int.
 *
 * The previous backfill migration (2026_05_21_120100_*) joined directly on
 * raw normalized item_name → daily_page_primary_item.primary_item_key.
 * That works sa likha (zero aliases) pero fails sa incepxion (584 aliases)
 * kasi primary_item_key is now CANONICAL (alias-collapsed) while item_name
 * sa page_item_settings stores the RAW variant.
 *
 * Example sa incepxion:
 *   page_item_settings.item_name = "1 x ALAGANG PAMILYA II"
 *   → raw normalized = "1xalagangpamilyaii"
 *   daily_page_primary_item.primary_item_key = "1alagangpamilya"
 *   → JOIN fails → row stays NULL
 *
 * This migration's SQL:
 *   - First tries to canonicalize pis.item_name via item_type_mappings lookup
 *   - Falls back to raw normalized item_name kung walang mapping (likha case)
 *   - Idempotent — only touches NULL rows
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('page_item_settings')) return;
        if (!Schema::hasColumn('page_item_settings', 'mode_cod_int')) return;
        if (!Schema::hasTable('daily_page_primary_item')) return;

        $driver = DB::getDriverName();
        $hasAliases = Schema::hasTable('item_type_mappings');

        // Build canonical-key expression for pis.item_name.
        // If item_type_mappings exists, use COALESCE(alias_lookup, raw_normalized).
        // Else (no alias table), just raw normalized.
        if ($driver === 'pgsql') {
            $rawNorm = "LOWER(REGEXP_REPLACE(BTRIM(pis.item_name), '[ _-]+', '', 'g'))";
            $pageNorm = "LOWER(BTRIM(pis.page_name))";

            if ($hasAliases) {
                $canonKeyExpr = "
                    COALESCE(
                        (SELECT LOWER(REGEXP_REPLACE(BTRIM(itm.item_type), '[ _-]+', '', 'g'))
                         FROM item_type_mappings itm
                         WHERE LOWER(REGEXP_REPLACE(BTRIM(itm.item_name), '[ _-]+', '', 'g'))
                             = {$rawNorm}
                         LIMIT 1),
                        {$rawNorm}
                    )
                ";
            } else {
                $canonKeyExpr = $rawNorm;
            }

            $sql = "
                UPDATE page_item_settings AS pis
                SET mode_cod_int = ROUND(dpi.primary_mode_cod)
                FROM daily_page_primary_item AS dpi
                WHERE dpi.ts_date = pis.effective_date
                  AND dpi.page_key = {$pageNorm}
                  AND dpi.primary_item_key = {$canonKeyExpr}
                  AND pis.mode_cod_int IS NULL
                  AND dpi.primary_mode_cod IS NOT NULL
            ";
        } else {
            $rawNorm = "LOWER(REPLACE(REPLACE(REPLACE(TRIM(pis.item_name),' ',''),'-',''),'_',''))";
            $pageNorm = "LOWER(TRIM(pis.page_name))";

            if ($hasAliases) {
                $canonKeyExpr = "
                    COALESCE(
                        (SELECT LOWER(REPLACE(REPLACE(REPLACE(TRIM(itm.item_type),' ',''),'-',''),'_',''))
                         FROM item_type_mappings itm
                         WHERE LOWER(REPLACE(REPLACE(REPLACE(TRIM(itm.item_name),' ',''),'-',''),'_',''))
                             = {$rawNorm}
                         LIMIT 1),
                        {$rawNorm}
                    )
                ";
            } else {
                $canonKeyExpr = $rawNorm;
            }

            $sql = "
                UPDATE page_item_settings pis
                INNER JOIN daily_page_primary_item dpi
                    ON dpi.ts_date = pis.effective_date
                   AND dpi.page_key = {$pageNorm}
                   AND dpi.primary_item_key = {$canonKeyExpr}
                SET pis.mode_cod_int = ROUND(dpi.primary_mode_cod)
                WHERE pis.mode_cod_int IS NULL
                  AND dpi.primary_mode_cod IS NOT NULL
            ";
        }

        try {
            $affected = DB::affectingStatement($sql);
            \Log::info("[backfill_mode_cod_int_alias_aware] tagged {$affected} page_item_settings rows (hasAliases={$hasAliases})");
        } catch (\Throwable $e) {
            \Log::error('[backfill_mode_cod_int_alias_aware] failed: ' . $e->getMessage());
            throw $e;
        }

        // Bump /owner/private cache version so live users see the fresh-tagged
        // data immediately without manually clicking the Refresh button.
        if (class_exists(\App\Http\Controllers\OwnerPrivateController::class)) {
            try {
                \App\Http\Controllers\OwnerPrivateController::bumpCacheVersion();
            } catch (\Throwable $e) {
                // Non-fatal — cache will expire eventually or user can click Refresh.
            }
        }
    }

    public function down(): void
    {
        // Data-only migration — no down().
    }
};
