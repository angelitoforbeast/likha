<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Isang row sa page_item_settings_log (ang edit log ng RTS / promo / COGS sa /owner/private/edit-logs).
 * Ginagamit ng mga sumusulat na wala sa saveItemSetting: delete sa /owner/private, /item/cogs, cogs sync.
 * Tinatawag sa loob ng transaction ng write, kaya sabay ang value at ang log.
 */
class ItemSettingsLog
{
    /** Mga column na idinagdag ng mga sumunod na migration — isinusulat lang kapag nandiyan na. */
    private const OPTIONAL = ['old_promo', 'new_promo', 'old_item_value_ceo', 'new_item_value_ceo', 'scope', 'source'];

    public static function write(array $row): void
    {
        if (!Schema::hasTable('page_item_settings_log')) return;

        foreach (self::OPTIONAL as $col) {
            if (array_key_exists($col, $row) && !Schema::hasColumn('page_item_settings_log', $col)) {
                unset($row[$col]);
            }
        }

        DB::table('page_item_settings_log')->insert($row + [
            // Global ang COGS (walang page) sa /item/cogs at cogs sync: '' dahil NOT NULL ang page_name.
            'page_name'  => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
