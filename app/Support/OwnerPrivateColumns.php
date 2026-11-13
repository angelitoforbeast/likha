<?php

namespace App\Support;

/**
 * The one column list of the /owner/private page summary table (spec 258).
 *
 * Both readers use it:
 *   - /owner/private builds its JS column list (defaultCols) from it with jsList().
 *   - OwnerColumnSettingsController::CATALOG['owner_private'] is this list, so the column settings page
 *     (role visibility, order, conditional formatting) and the saved app_settings ids follow it.
 * Adding a column here puts it on the page and in the settings; its cell markup still lives in the view.
 *
 * Keys per column:
 *   id        column id (saved in app_settings owner_private_cols and the conditional formatting rules)
 *   label     name on the column settings page
 *   header    header on /owner/private when it differs from label
 *   label_fn  /owner/private takes the header from this Alpine method instead (e.g. the breakeven %)
 *   sort      row field the header sorts by
 *   align     cell text-align
 *   minw      minimum width in px
 *   ceo_only  only CEO viewers get the column in the page source
 *   item_only used by /item rows only, not part of the /owner/private page
 */
final class OwnerPrivateColumns
{
    public const COLUMNS = [
        // Days from the date the current primary item became primary to the range end (inclusive).
        ['id' => 'days_running', 'label' => 'Days running', 'sort' => 'days_running', 'align' => 'center', 'minw' => 80],
        ['id' => 'adspent', 'label' => 'Adspent', 'sort' => 'adspent', 'align' => 'center', 'minw' => 90],
        ['id' => 'orders', 'label' => 'Orders', 'sort' => 'orders', 'align' => 'center', 'minw' => 65],
        ['id' => 'orders_1d', 'label' => 'Orders (1D)', 'sort' => 'orders_last_day', 'align' => 'center', 'minw' => 80],
        ['id' => 'cpp', 'label' => 'CPP', 'sort' => 'cpp', 'align' => 'center', 'minw' => 75],
        ['id' => 'proceed', 'label' => 'Proceed', 'sort' => 'proceed_orders', 'align' => 'center', 'minw' => 70],
        ['id' => 'pcpp', 'label' => 'P.CPP', 'sort' => 'proceed_cpp', 'align' => 'center', 'minw' => 75],
        ['id' => 'tcpr', 'label' => 'TCPR (Pending Rate)', 'header' => 'TCPR', 'sort' => 'tcpr_computed', 'align' => 'center', 'minw' => 65],
        ['id' => 'breakeven_cpp', 'label' => 'Breakeven CPP', 'label_fn' => '_breakevenLabel', 'sort' => 'breakeven_cpp_computed', 'align' => 'center', 'minw' => 115],
        ['id' => 'proj_profit', 'label' => 'Prof.Profit', 'sort' => 'projected_profit', 'align' => 'center', 'minw' => 95],
        ['id' => 'proj_profit_mira', 'label' => 'Profit (Mira RTS)', 'sort' => 'projected_profit_mira', 'align' => 'center', 'minw' => 105],
        ['id' => 'per_order', 'label' => '/Order', 'sort' => 'proj_profit_per_order', 'align' => 'center', 'minw' => 75],
        ['id' => 'np_per_order', 'label' => 'NP/O (1D)', 'header' => 'NP/O', 'sort' => 'np_per_order_computed', 'align' => 'center', 'minw' => 75],
        ['id' => 'np_per_order_3d', 'label' => 'NP/O (3D)', 'header' => 'NP/O(3D)', 'sort' => 'np_per_order_3d_computed', 'align' => 'center', 'minw' => 80],
        ['id' => 'np_per_order_7d', 'label' => 'NP/O (7D)', 'header' => 'NP/O(7D)', 'sort' => 'np_per_order_7d_computed', 'align' => 'center', 'minw' => 80],
        ['id' => 'np_per_order_1m', 'label' => 'NP/O (1M, entire range)', 'header' => 'NP/O(1M)', 'sort' => 'np_per_order_1m_computed', 'align' => 'center', 'minw' => 80],
        ['id' => 'proj_pct', 'label' => 'Prof.%(1M)', 'sort' => 'proj_pct_computed', 'align' => 'center', 'minw' => 75],
        ['id' => 'proj_pct_1d', 'label' => 'Prof.%(1D)', 'sort' => 'proj_pct_last_day', 'align' => 'center', 'minw' => 75],
        ['id' => 'proj_pct_3d', 'label' => 'Prof.%(3D)', 'sort' => 'proj_pct_last_3d', 'align' => 'center', 'minw' => 75],
        ['id' => 'proj_pct_7d', 'label' => 'Prof.%(7D)', 'sort' => 'proj_pct_last_7d', 'align' => 'center', 'minw' => 75],
        ['id' => 'proj_prof_1d', 'label' => 'Prof.Profit(1D)', 'sort' => 'projected_profit_last_day', 'align' => 'center', 'minw' => 105],
        ['id' => 'proj_prof_1d_m', 'label' => 'PROF(1D)-M', 'sort' => 'projected_profit_last_day_m', 'align' => 'center', 'minw' => 105],
        ['id' => 'proj_prof_1d_mira', 'label' => 'Prof 1D (Mira)', 'sort' => 'projected_profit_last_day_mira', 'align' => 'center', 'minw' => 105],
        ['id' => 'proj_prof_3d', 'label' => 'Prof.Profit(3D)', 'sort' => 'projected_profit_last_3d', 'align' => 'center', 'minw' => 105],
        ['id' => 'proj_prof_7d', 'label' => 'Prof.Profit(7D)', 'sort' => 'projected_profit_last_7d', 'align' => 'center', 'minw' => 105],
        ['id' => 'jnt_rts', 'label' => 'RTS%', 'sort' => 'jnt_rts_pct', 'align' => 'center', 'minw' => 100],
        ['id' => 'jnt_del', 'label' => 'Del%', 'sort' => 'jnt_del_pct', 'align' => 'center', 'minw' => 90],
        ['id' => 'jnt_transit', 'label' => 'Transit%', 'sort' => 'jnt_transit_pct', 'align' => 'center', 'minw' => 85],
        ['id' => 'rts_set', 'label' => 'Set RTS%', 'sort' => 'rts_pct', 'align' => 'center', 'minw' => 110],
        ['id' => 'mira_rts', 'label' => 'Projected RTS', 'sort' => 'mira_rts_pct', 'align' => 'center', 'minw' => 120],
        // Mira RTS = tinype ng analyst (may dahilan); ang dalawang gap ay para sa Conditional Formatting nito.
        ['id' => 'analyst_rts', 'label' => 'Mira RTS', 'sort' => 'analyst_rts_pct', 'align' => 'center', 'minw' => 110],
        ['id' => 'analyst_gap_pts', 'label' => 'Mira RTS Gap (Set − Mira)', 'sort' => 'analyst_gap_pts', 'align' => 'center', 'minw' => 90],
        ['id' => 'analyst_gap_abs', 'label' => 'Mira RTS Gap (abs)', 'sort' => 'analyst_gap_abs', 'align' => 'center', 'minw' => 80],
        // Error (puntos) ng Mira RTS 7 at 14 araw na ang nakalipas laban sa totoong RTS ng parehong mga order (AnalystRtsDaily).
        ['id' => 'analyst_rts_acc', 'label' => 'Mira RTS accuracy', 'sort' => 'analyst_rts_acc_abs', 'align' => 'center', 'minw' => 120],
        // Para sa Conditional Formatting ng Projected RTS (target o compare_col); nakatago hanggang buksan.
        ['id' => 'rts_gap_pts', 'label' => 'RTS Gap (Set − Projected)', 'sort' => 'rts_gap_pts', 'align' => 'center', 'minw' => 90],
        ['id' => 'rts_gap_abs', 'label' => 'RTS Gap (abs)', 'sort' => 'rts_gap_abs', 'align' => 'center', 'minw' => 80],
        ['id' => 'mira_rts_error_pts', 'label' => 'Projected RTS Error (pts)', 'sort' => 'mira_rts_error_pts', 'align' => 'center', 'minw' => 95],
        ['id' => 'rts_gap_ratio', 'label' => 'RTS Gap ÷ Error', 'sort' => 'rts_gap_ratio', 'align' => 'center', 'minw' => 90],
        ['id' => 'mira_rts_matched', 'label' => 'Projected RTS Orders', 'sort' => 'mira_rts_matched', 'align' => 'center', 'minw' => 90],
        ['id' => 'promo', 'label' => 'Promo', 'sort' => 'promo', 'align' => 'center', 'minw' => 90],
        ['id' => 'price', 'label' => 'Price', 'sort' => 'price', 'align' => 'center', 'minw' => 85],
        ['id' => 'item_val', 'label' => 'Item Val.', 'sort' => 'item_value', 'align' => 'center', 'minw' => 80],
        ['id' => 'item_val_ceo', 'label' => 'Item Val. (CEO)', 'sort' => 'item_value_ceo', 'align' => 'center', 'minw' => 90],
        ['id' => 'ship', 'label' => 'Ship', 'sort' => 'shipping_fee', 'align' => 'center', 'minw' => 58],
        ['id' => 'cod_fee', 'label' => 'COD Fee', 'sort' => 'cod_fee', 'align' => 'center', 'minw' => 72],
        ['id' => 'hold', 'label' => 'Hold', 'sort' => 'hold_units', 'align' => 'center', 'minw' => 60],
        ['id' => 'action', 'label' => 'Action', 'sort' => 'action_at', 'align' => 'left', 'minw' => 160],
        ['id' => 'claude_action', 'label' => 'Claude Action (CEO)', 'header' => 'Claude Action', 'sort' => 'claude_action', 'align' => 'left', 'minw' => 160, 'ceo_only' => true],
        ['id' => 'claude_reason', 'label' => 'Claude Reason (CEO)', 'header' => 'Claude Reason', 'sort' => 'claude_reason', 'align' => 'left', 'minw' => 200, 'ceo_only' => true],
        ['id' => 'ceo_action', 'label' => 'CEO Action', 'sort' => 'ceo_action', 'align' => 'left', 'minw' => 160, 'ceo_only' => true],
        ['id' => 'ceo_reason', 'label' => 'CEO Reason', 'sort' => 'ceo_reason', 'align' => 'left', 'minw' => 200, 'ceo_only' => true],
        // /item lang (item rows) — hindi ginagamit ng /owner/private.
        ['id' => 'category', 'label' => 'Category (/item)', 'item_only' => true],
        ['id' => 'stock', 'label' => 'Stock (/item)', 'item_only' => true],
        ['id' => 'incoming', 'label' => 'Paparating (/item)', 'item_only' => true],
        ['id' => 'units_per_day', 'label' => 'Benta/araw (/item)', 'item_only' => true],
        ['id' => 'doi', 'label' => 'DOI (/item)', 'item_only' => true],
        ['id' => 'order_qty', 'label' => 'I-order (/item)', 'item_only' => true],
        ['id' => 'lifecycle', 'label' => 'Lifecycle (/item)', 'item_only' => true],
    ];

    /**
     * The /owner/private columns, in catalog order, as the page's JS uses them
     * (id, label, sort, align, minw; label_fn when the header comes from an Alpine method).
     *
     * @param  array<int, array<string, mixed>>|null  $columns  defaults to COLUMNS
     * @return array<int, array<string, mixed>>
     */
    public static function forPage(bool $isCeo, ?array $columns = null): array
    {
        $out = [];
        foreach ($columns ?? self::COLUMNS as $c) {
            if (!empty($c['item_only'])) continue;
            if (!empty($c['ceo_only']) && !$isCeo) continue;
            $col = ['id' => $c['id'], 'label' => $c['header'] ?? $c['label'], 'sort' => $c['sort'], 'align' => $c['align'], 'minw' => $c['minw']];
            if (!empty($c['label_fn'])) $col['label_fn'] = $c['label_fn'];
            $out[] = $col;
        }
        return $out;
    }

    /**
     * forPage() as JS object literals, one per line, for the body of defaultCols():
     *   { id:'adspent', label:'Adspent', sort:'adspent', align:'center', minw:90 },
     * A label_fn column gets `label:this.<fn>()` so the header follows the live setting.
     *
     * @param  array<int, array<string, mixed>>|null  $columns  defaults to COLUMNS
     */
    public static function jsList(bool $isCeo, ?array $columns = null, string $indent = '          '): string
    {
        $lines = [];
        foreach (self::forPage($isCeo, $columns) as $c) {
            $label = isset($c['label_fn']) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $c['label_fn'])
                ? 'this.' . $c['label_fn'] . '()'
                : self::js($c['label']);
            $lines[] = $indent . '{ id:' . self::js($c['id']) . ', label:' . $label . ', sort:' . self::js($c['sort'])
                . ', align:' . self::js($c['align']) . ', minw:' . (int) $c['minw'] . ' },';
        }
        return implode("\n", $lines);
    }

    /** A single-quoted JS string literal that is also safe inside a <script> element. */
    private static function js(string $s): string
    {
        return "'" . str_replace(['\\', "'", "\r", "\n", '<', '>'], ['\\\\', "\\'", '\\r', '\\n', '\\x3C', '\\x3E'], $s) . "'";
    }

    /**
     * Whole days a page has run on its current primary item: from $since (the date that item became primary,
     * the row's anchor_first_date) to the range end, counting both days (since Nov 3, viewing to Nov 9 = 7).
     * A range that ends after today counts to today. Null when there is no since date, or it falls after the end.
     */
    public static function daysRunning(?string $since, string $endDate, string $today): ?int
    {
        $valid = fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
        if (!$valid($since) || !$valid($endDate)) return null;
        $end = ($valid($today) && $today < $endDate) ? $today : $endDate;
        if ($since > $end) return null;
        $from = new \DateTimeImmutable($since, new \DateTimeZone('UTC'));
        $to   = new \DateTimeImmutable($end, new \DateTimeZone('UTC'));
        return (int) $from->diff($to)->days + 1;
    }
}
