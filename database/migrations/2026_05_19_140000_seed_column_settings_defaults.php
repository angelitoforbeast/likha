<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshots the CURRENT column-settings + conditional-formatting state from
 * production (likha) as `*_default` keys sa app_settings. Used by the Reset
 * button at /owner/column-settings/* to restore to a known-good baseline.
 *
 * Also seeds the LIVE keys when a row doesn't exist yet — so a fresh site
 * (e.g., incepxion) gets the same working config out of the box.
 *
 * Safe to re-run: `insertOrIgnore` skips existing rows. Won't overwrite user
 * customizations on either default or live keys.
 *
 * To update the snapshot baseline later, the admin uses "Save Current as
 * Default" sa UI (separate endpoint), which overwrites the *_default rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('app_settings')) return;

        $now = now();

        // Captured from likha production on 2026-05-19. Edit carefully — these
        // become the factory defaults for ALL sites that run this migration.
        $defaults = [
            'owner_private_cols'        => '{"order":["price","np_per_order","proj_prof_1d","adspent","item_val","item_val_ceo","orders","proceed","cpp","proj_profit","orders_1d","rts_set","jnt_rts","jnt_del","jnt_transit","tcpr","breakeven_cpp","proj_pct","proj_pct_3d","proj_pct_7d","proj_pct_1d"],"hidden":["ship","cod_fee","pcpp","proj_prof_3d","proj_prof_7d","per_order"],"visible_by_role":{"Marketing - OIC":["adspent","item_val","price","orders","proceed","cpp","proj_pct","proj_pct_7d","proj_pct_3d","proj_pct_1d","jnt_rts","jnt_del","jnt_transit","rts_set","tcpr","breakeven_cpp"],"Marketing":["adspent","item_val","price","orders","proceed","cpp","proj_pct","proj_pct_7d","proj_pct_3d","proj_pct_1d","jnt_rts","jnt_del","jnt_transit","rts_set","tcpr","breakeven_cpp"]}}',

            'owner_private_col_format'  => '{"groups":[{"cols":["tcpr"],"rules":[{"op":">=","value":3,"bg":"#ff0000","color":"","bold":true,"label":"","compare_col":"","active_state":"active"},{"op":">=","value":2.5,"bg":"#ffa500","color":"","bold":true,"label":"","compare_col":"","active_state":"active"},{"op":">=","value":2,"bg":"#ffff00","color":"","bold":true,"label":"","compare_col":"","active_state":"active"},{"op":">=","value":1.5,"bg":"#00ff00","color":"","bold":true,"label":"","compare_col":"","active_state":"active"},{"op":"<","value":1.5,"bg":"#00ffff","color":"","bold":true,"label":"","compare_col":"","active_state":"active"}]},{"cols":["rts_set"],"rules":[{"op":"<","value":{"type":"formula","expr":"[[jnt_rts]]+[[jnt_transit]]-5"},"bg":"#ff0000","color":"","bold":false,"label":"","compare_col":"","active_state":"any"},{"op":"<","value":{"type":"formula","expr":"[[jnt_rts]]+[[jnt_transit]]-2.5"},"bg":"#ffa500","color":"","bold":false,"label":"","compare_col":"","active_state":"any"},{"op":"<","value":{"type":"formula","expr":"[[jnt_rts]]+[[jnt_transit]]-1"},"bg":"#ffff00","color":"","bold":false,"label":"","compare_col":"","active_state":"any"}]}]}',

            'campaigns_cols'            => '{"order":["on","active_subcount","name","first_started","days_running","spend","cpm_1000","cpm_msg","cpp","latest_started","cpr","cpp_7d","cpp_3d","cpp_today","impressions","messages","purchases","link_clicks","welcome_msg_rate","conversion_rate","account","profit_pct","profit_pct_7d","profit_pct_3d","profit_pct_today"],"hidden":["cpr","messages","latest_started","impressions","purchases","link_clicks","profit_pct_today","profit_pct_3d","profit_pct_7d"],"visible_by_role":{"Marketing - OIC":["on","name","first_started","days_running","spend","cpm_1000","cpm_msg","cpp","cpp_7d","cpp_3d","cpp_today","welcome_msg_rate","conversion_rate","account","active_subcount"],"Marketing":["on","name","first_started","days_running","spend","cpm_1000","cpm_msg","cpp","cpp_7d","cpp_3d","cpp_today","welcome_msg_rate","conversion_rate","account","active_subcount"]}}',

            'campaigns_col_format'      => '{"groups":[{"cols":["cpm_msg"],"rules":[{"op":">=","value":20,"bg":"#ff0000","color":"","bold":true,"label":"","compare_col":"","active_state":"active"},{"op":">=","value":15,"bg":"#ffa500","color":"","bold":true,"label":"","compare_col":"","active_state":"active"},{"op":">=","value":10,"bg":"#ffff00","color":"","bold":true,"label":"","compare_col":"","active_state":"active"},{"op":"<","value":10,"bg":"#00ff00","color":"","bold":true,"label":"","compare_col":"","active_state":"active"}]},{"cols":["cpp","cpp_today","cpp_3d","cpp_7d"],"rules":[{"op":">=","value":{"type":"ref","table":"owner_private","col":"breakeven_cpp"},"bg":"#ff0000","color":"","bold":false,"label":"","compare_col":"","active_state":"active"}]},{"cols":["welcome_msg_rate","conversion_rate"],"rules":[{"op":">=","value":100,"bg":"#ff0000","color":"","bold":false,"label":"","compare_col":"","active_state":"active"}]},{"cols":["welcome_msg_rate"],"rules":[{"op":"<=","value":10,"bg":"#ff0000","color":"","bold":false,"label":"","compare_col":"","active_state":"active"},{"op":"<=","value":15,"bg":"#ffa500","color":"","bold":false,"label":"","compare_col":"","active_state":"active"}]},{"cols":["cpp"],"rules":[{"op":">=","value":20,"bg":"#00ff00","color":"","bold":false,"label":"","compare_col":"profit_pct","active_state":"active"},{"op":">=","value":15,"bg":"#00ffff","color":"","bold":false,"label":"","compare_col":"profit_pct","active_state":"active"},{"op":">=","value":10,"bg":"#ffff00","color":"","bold":false,"label":"","compare_col":"profit_pct","active_state":"active"},{"op":">=","value":5,"bg":"#ffa500","color":"","bold":false,"label":"","compare_col":"profit_pct","active_state":"active"},{"op":"<","value":5,"bg":"#ff0000","color":"","bold":false,"label":"","compare_col":"profit_pct","active_state":"active"}]},{"cols":["cpp"],"rules":[{"op":">=","value":10,"bg":"#ff0000","color":"","bold":false,"label":"","compare_col":"profit_pct","active_state":"off"},{"op":">=","value":5,"bg":"#ffa500","color":"","bold":false,"label":"","compare_col":"profit_pct","active_state":"off"}]},{"cols":["cpp_7d"],"rules":[{"op":">=","value":20,"bg":"#00ff00","color":"","bold":false,"label":"","compare_col":"profit_pct_7d","active_state":"active"},{"op":">=","value":15,"bg":"#00ffff","color":"","bold":false,"label":"","compare_col":"profit_pct_7d","active_state":"active"},{"op":">=","value":10,"bg":"#ffff00","color":"","bold":false,"label":"","compare_col":"profit_pct_7d","active_state":"active"},{"op":">=","value":5,"bg":"#ffa500","color":"","bold":false,"label":"","compare_col":"profit_pct_7d","active_state":"active"},{"op":"<","value":5,"bg":"#ff0000","color":"","bold":false,"label":"","compare_col":"profit_pct_7d","active_state":"active"}]},{"cols":["cpp_3d"],"rules":[{"op":">=","value":20,"bg":"#00ff00","color":"","bold":false,"label":"","compare_col":"profit_pct_3d","active_state":"active"},{"op":">=","value":15,"bg":"#00ffff","color":"","bold":false,"label":"","compare_col":"profit_pct_3d","active_state":"active"},{"op":">=","value":10,"bg":"#ffff00","color":"","bold":false,"label":"","compare_col":"profit_pct_3d","active_state":"active"},{"op":">=","value":5,"bg":"#ffa500","color":"","bold":false,"label":"","compare_col":"profit_pct_3d","active_state":"active"},{"op":"<","value":5,"bg":"#ff0000","color":"","bold":false,"label":"","compare_col":"profit_pct_3d","active_state":"active"}]},{"cols":["cpp_today"],"rules":[{"op":">=","value":20,"bg":"#00ff00","color":"","bold":false,"label":"","compare_col":"profit_pct_today","active_state":"active"},{"op":">=","value":15,"bg":"#00ffff","color":"","bold":false,"label":"","compare_col":"profit_pct_today","active_state":"active"},{"op":">=","value":10,"bg":"#ffff00","color":"","bold":false,"label":"","compare_col":"profit_pct_today","active_state":"active"},{"op":">=","value":5,"bg":"#ffa500","color":"","bold":false,"label":"","compare_col":"profit_pct_today","active_state":"active"},{"op":"<","value":5,"bg":"#ff0000","color":"","bold":false,"label":"","compare_col":"profit_pct_today","active_state":"active"}]},{"cols":["cpm_msg"],"rules":[{"op":"<=","value":10,"bg":"#ff0000","color":"","bold":false,"label":"","compare_col":"","active_state":"off"}]}]}',

            'daily_summary_cols'        => '{"order":["date","proj_net_profit","adspent","orders","cpm","shipped","proceed","cpp","tcpr_pct","proj_net_pct","proceed_cpp","messages","cannot","odz","delivered","in_transit","rts","proj_gross","proj_shipping","proj_cogs"],"hidden":["proj_gross","proj_shipping","proj_cogs","rts","in_transit","delivered","odz","cannot","messages"],"visible_by_role":{"Marketing - OIC":[],"Marketing":[]}}',

            'owner_breakeven_target_pct'=> '5',
        ];

        foreach ($defaults as $key => $value) {
            $defaultKey = $key . '_default';

            // Always seed the *_default snapshot (skip if already exists — so
            // admin's "Save as Default" customizations are preserved on re-run).
            DB::table('app_settings')->insertOrIgnore([
                'key'        => $defaultKey,
                'value'      => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Conditionally seed the LIVE key only if it doesn't exist yet
            // (fresh sites get baseline; existing sites stay untouched).
            DB::table('app_settings')->insertOrIgnore([
                'key'        => $key,
                'value'      => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('app_settings')) return;

        // Only drop the *_default rows — leave live keys alone (admin may have
        // customized them).
        DB::table('app_settings')
            ->whereIn('key', [
                'owner_private_cols_default',
                'owner_private_col_format_default',
                'campaigns_cols_default',
                'campaigns_col_format_default',
                'daily_summary_cols_default',
                'owner_breakeven_target_pct_default',
            ])
            ->delete();
    }
};
