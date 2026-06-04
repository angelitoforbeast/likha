<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Converts the previously HARD-CODED Proj.% color bands into real conditional-
 * formatting rules stored sa app_settings.owner_private_col_format — para
 * consistent ang kulay sa /owner/private AT sa /owner/private/breakdown, at
 * editable na sa /owner/column-settings.
 *
 * Bands (mula sa dating rppStyle): <5 red · <10 orange · <20 cyan · ≥20 green.
 * Targets: proj_pct (1M) + proj_pct_1d/3d/7d.
 *
 * IDEMPOTENT: skip kapag may existing group na sa proj_pct (para hindi i-clobber
 * ang manually-set na CF, at hindi mag-double sa re-run). Net Profit (proj_profit)
 * ay iniwan na hard-coded per spec.
 */
return new class extends Migration
{
    private const KEY = 'owner_private_col_format';

    public function up(): void
    {
        $row  = DB::table('app_settings')->where('key', self::KEY)->first(['value']);
        $data = ($row && $row->value) ? json_decode($row->value, true) : null;
        if (!is_array($data)) $data = ['groups' => []];
        if (!isset($data['groups']) || !is_array($data['groups'])) $data['groups'] = [];

        // Skip kung may rule na sa proj_pct (manual o naseed na).
        foreach ($data['groups'] as $g) {
            if (in_array('proj_pct', (array) ($g['cols'] ?? []), true)) return;
        }

        $rule = fn (string $op, float $val, string $bg, string $label) => [
            'op' => $op, 'value' => $val, 'bg' => strtolower($bg), 'color' => '',
            'bold' => false, 'label' => $label, 'compare_col' => '', 'active_state' => 'any',
        ];

        // ORDER MATTERS — first hit wins sa evaluator.
        $data['groups'][] = [
            'cols'  => ['proj_pct', 'proj_pct_1d', 'proj_pct_3d', 'proj_pct_7d'],
            'rules' => [
                $rule('<',  5,  '#ff0000', '< 5%'),
                $rule('<',  10, '#ff6600', '< 10%'),
                $rule('<',  20, '#00ffff', '< 20%'),
                $rule('>=', 20, '#00ff00', '≥ 20%'),
            ],
        ];

        DB::table('app_settings')->updateOrInsert(
            ['key' => self::KEY],
            ['value' => json_encode($data, JSON_UNESCAPED_SLASHES), 'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function down(): void
    {
        // No-op — hindi binabawi para hindi masira ang user-edited CF.
    }
};
