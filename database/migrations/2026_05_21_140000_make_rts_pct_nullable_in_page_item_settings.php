<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Make rts_pct nullable so that promo can be saved standalone for new
 * (page, item, price) scopes that don't have RTS yet.
 *
 * Old model required rts_pct NOT NULL — which meant the user couldn't save
 * a promo for a brand-new reference (e.g., Kim Villanueva's first ₱199 day)
 * unless they also set RTS in the same save. New mental model: RTS and Promo
 * are independently settable per (page, item, price) reference. Either can
 * be empty initially.
 *
 * Backward-compat: existing rows already have rts_pct set, so nullable
 * doesn't break them. Reads handle NULL rts gracefully (displayed as "—",
 * profit calc skips when rts is null).
 *
 * Idempotent: doctrine/dbal-style change(); requires that package — if not
 * available, fallback to raw ALTER TABLE statement.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('page_item_settings')) return;

        // MySQL: raw ALTER for nullable change (avoids doctrine/dbal dep).
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE page_item_settings MODIFY COLUMN rts_pct DECIMAL(5,2) NULL');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE page_item_settings ALTER COLUMN rts_pct DROP NOT NULL');
        } else {
            // Fallback — try Schema builder (needs doctrine/dbal).
            try {
                Schema::table('page_item_settings', function (Blueprint $table) {
                    $table->decimal('rts_pct', 5, 2)->nullable()->change();
                });
            } catch (\Throwable $e) {
                // Silent fail — production deploys should manually adjust.
            }
        }
    }

    public function down(): void
    {
        // Reverting requires setting all NULL rows to some default first.
        // We choose 0 as a safe placeholder; downgrade is rare/manual anyway.
        if (!Schema::hasTable('page_item_settings')) return;

        DB::table('page_item_settings')->whereNull('rts_pct')->update(['rts_pct' => 0]);

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE page_item_settings MODIFY COLUMN rts_pct DECIMAL(5,2) NOT NULL');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE page_item_settings ALTER COLUMN rts_pct SET NOT NULL');
        }
    }
};
