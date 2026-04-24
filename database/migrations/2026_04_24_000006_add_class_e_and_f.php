<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // ------------------------------------------------------------------
        // 1. item_class_thresholds — add E + F label rows, shift H sort_order
        // ------------------------------------------------------------------
        // Re-number existing: D=4, keep; H move to 7
        DB::table('item_class_thresholds')->where('class_key', 'H')->update([
            'label'      => 'H · Review',
            'sort_order' => 7,
            'updated_at' => $now,
        ]);

        $labelRows = [
            ['class_key' => 'E', 'label' => 'E · Low-Vel Running', 'sort_order' => 5],
            ['class_key' => 'F', 'label' => 'F · Dead / No Ads',    'sort_order' => 6],
        ];
        foreach ($labelRows as $r) {
            if (DB::table('item_class_thresholds')->where('class_key', $r['class_key'])->exists()) continue;
            DB::table('item_class_thresholds')->insert(array_merge($r, [
                'min_velocity' => 0,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]));
        }

        // ------------------------------------------------------------------
        // 2. supply_settings — alive guards for E + F (CEO editable)
        // ------------------------------------------------------------------
        $rows = [
            ['key'=>'class_e_alive_window', 'value'=>'14', 'label'=>'Class E — Alive window (days)',       'group'=>'class_e', 'data_type'=>'int',   'sort_order'=>50],
            ['key'=>'class_e_alive_min',    'value'=>'5',  'label'=>'Class E — Alive min vel (u/day)',     'group'=>'class_e', 'data_type'=>'float', 'sort_order'=>51],
            ['key'=>'class_f_alive_window', 'value'=>'30', 'label'=>'Class F — Alive window (days)',       'group'=>'class_f', 'data_type'=>'int',   'sort_order'=>60],
            ['key'=>'class_f_alive_min',    'value'=>'1',  'label'=>'Class F — Dead threshold (u/day, below=F)', 'group'=>'class_f', 'data_type'=>'float', 'sort_order'=>61],
        ];
        foreach ($rows as $r) {
            if (DB::table('supply_settings')->where('key', $r['key'])->exists()) continue;
            $r['created_at'] = $now;
            $r['updated_at'] = $now;
            DB::table('supply_settings')->insert($r);
        }
    }

    public function down(): void
    {
        DB::table('supply_settings')->whereIn('key', [
            'class_e_alive_window', 'class_e_alive_min',
            'class_f_alive_window', 'class_f_alive_min',
        ])->delete();

        DB::table('item_class_thresholds')->whereIn('class_key', ['E', 'F'])->delete();
    }
};
