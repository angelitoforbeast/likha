<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Computes notification badge counts ("needs attention" superscript numbers)
 * for nav links. Used by:
 *   - Top nav (`/owner/nav-settings` config): badge per navlink
 *   - JNT Config Hub (/jnt/config): per-card badge + total badge on hub link itself
 *
 * Each method returns int count; render as a small red superscript dot when > 0.
 * Cached briefly per request via static memo to avoid re-querying.
 *
 * Add new check methods here, then register sa `keyToCount` map.
 */
class NavBadgeService
{
    /** Per-request memoization. */
    private static ?array $cache = null;

    /**
     * Returns badge counts keyed by nav_link.key.
     * Only includes keys with count > 0 (zero = no badge rendered).
     */
    public static function counts(): array
    {
        if (self::$cache !== null) return self::$cache;

        $map = [
            'jnt_acct_mapping'   => self::unmappedPagesForJntAccount(),
            'jnt_sender_name'    => self::unmappedPagesForSender(),
            'jnt_item_types'     => self::unmappedItems(),
            'jnt_fee_settings'   => self::missingFeeSettings(),
            'jnt_accounts'       => self::noAccountsConfigured(),
        ];

        // Total for the JNT Config hub link.
        $jntKeys = ['jnt_acct_mapping', 'jnt_sender_name', 'jnt_item_types', 'jnt_fee_settings', 'jnt_accounts'];
        $jntTotal = 0;
        foreach ($jntKeys as $k) $jntTotal += (int) ($map[$k] ?? 0);
        $map['jnt_config'] = $jntTotal;

        // Strip zeros — view renders only when value > 0.
        self::$cache = array_filter($map, fn ($v) => (int) $v > 0);
        return self::$cache;
    }

    /** Count of distinct `macro_output.PAGE` not in `page_jnt_mappings.page`. */
    private static function unmappedPagesForJntAccount(): int
    {
        if (!Schema::hasTable('page_jnt_mappings') || !Schema::hasTable('macro_output')) return 0;
        return DB::table('macro_output')
            ->whereNotNull('PAGE')->where('PAGE', '!=', '')
            ->distinct()
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('page_jnt_mappings')
                  ->whereColumn('page_jnt_mappings.page', 'macro_output.PAGE');
            })
            ->count('PAGE');
    }

    /** Count of distinct `macro_output.PAGE` not in `page_sender_mappings.PAGE`. */
    private static function unmappedPagesForSender(): int
    {
        if (!Schema::hasTable('page_sender_mappings') || !Schema::hasTable('macro_output')) return 0;
        return DB::table('macro_output')
            ->whereNotNull('PAGE')->where('PAGE', '!=', '')
            ->distinct()
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('page_sender_mappings')
                  ->whereColumn('page_sender_mappings.PAGE', 'macro_output.PAGE');
            })
            ->count('PAGE');
    }

    /** Count of distinct `macro_output.ITEM_NAME` not in `item_type_mappings.item_name`. */
    private static function unmappedItems(): int
    {
        if (!Schema::hasTable('item_type_mappings') || !Schema::hasTable('macro_output')) return 0;
        return DB::table('macro_output')
            ->whereNotNull('ITEM_NAME')->where('ITEM_NAME', '!=', '')
            ->distinct()
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('item_type_mappings')
                  ->whereColumn('item_type_mappings.item_name', 'macro_output.ITEM_NAME');
            })
            ->count('ITEM_NAME');
    }

    /**
     * Count of REQUIRED fee_settings keys that have no record for the current
     * host. Checks: shipping_fee_per_order, cod_fee_rate, cod_fee_vat_rate.
     */
    private static function missingFeeSettings(): int
    {
        if (!Schema::hasTable('fee_settings')) return 0;
        $required = ['shipping_fee_per_order', 'cod_fee_rate', 'cod_fee_vat_rate'];
        $host = strtolower((string) request()->getHost());
        $found = DB::table('fee_settings')
            ->whereIn('key', $required)
            ->where('host', $host)
            ->pluck('key')
            ->unique()
            ->all();
        return count($required) - count(array_intersect($required, $found));
    }

    /** 1 if zero JNT accounts configured, else 0. Hard-required for waybill flow. */
    private static function noAccountsConfigured(): int
    {
        if (!Schema::hasTable('jnt_accounts')) return 0;
        return DB::table('jnt_accounts')->count() === 0 ? 1 : 0;
    }
}
