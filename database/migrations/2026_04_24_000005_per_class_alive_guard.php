<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Remove shared alive guard
        DB::table('supply_settings')->whereIn('key', [
            'class_abc_alive_window',
            'class_abc_alive_min',
        ])->delete();

        // Add per-class alive guards
        $now = now();
        $rows = [
            ['key'=>'class_a_alive_window', 'value'=>'7',  'label'=>'Class A — Alive-check window (days)',   'group'=>'class_a', 'data_type'=>'int',   'sort_order'=>12],
            ['key'=>'class_a_alive_min',    'value'=>'10', 'label'=>'Class A — Alive-check min vel (u/day)', 'group'=>'class_a', 'data_type'=>'float', 'sort_order'=>13],
            ['key'=>'class_b_alive_window', 'value'=>'7',  'label'=>'Class B — Alive-check window (days)',   'group'=>'class_b', 'data_type'=>'int',   'sort_order'=>22],
            ['key'=>'class_b_alive_min',    'value'=>'10', 'label'=>'Class B — Alive-check min vel (u/day)', 'group'=>'class_b', 'data_type'=>'float', 'sort_order'=>23],
            ['key'=>'class_c_alive_window', 'value'=>'7',  'label'=>'Class C — Alive-check window (days)',   'group'=>'class_c', 'data_type'=>'int',   'sort_order'=>32],
            ['key'=>'class_c_alive_min',    'value'=>'10', 'label'=>'Class C — Alive-check min vel (u/day)', 'group'=>'class_c', 'data_type'=>'float', 'sort_order'=>33],
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
            'class_a_alive_window', 'class_a_alive_min',
            'class_b_alive_window', 'class_b_alive_min',
            'class_c_alive_window', 'class_c_alive_min',
        ])->delete();
    }
};
