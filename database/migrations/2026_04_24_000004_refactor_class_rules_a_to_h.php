<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ----------------------------------------------------------
        // 1. item_class_thresholds — keep as label/sort lookup only
        //    (classification now uses supply_settings KV fully)
        // ----------------------------------------------------------
        DB::table('item_class_thresholds')->whereIn('class_key', ['E', 'F'])->delete();

        // Update remaining labels
        DB::table('item_class_thresholds')->where('class_key', 'A')->update([
            'label' => 'A · Hero', 'sort_order' => 1, 'updated_at' => now(),
        ]);
        DB::table('item_class_thresholds')->where('class_key', 'B')->update([
            'label' => 'B · Solid', 'sort_order' => 2, 'updated_at' => now(),
        ]);
        DB::table('item_class_thresholds')->where('class_key', 'C')->update([
            'label' => 'C · Active', 'sort_order' => 3, 'updated_at' => now(),
        ]);
        DB::table('item_class_thresholds')->where('class_key', 'D')->update([
            'label' => 'D · New Item', 'sort_order' => 4, 'updated_at' => now(),
        ]);

        // Add H row if missing
        $hExists = DB::table('item_class_thresholds')->where('class_key', 'H')->exists();
        if (!$hExists) {
            DB::table('item_class_thresholds')->insert([
                'class_key'    => 'H',
                'label'        => 'H · Review',
                'min_velocity' => 0,
                'sort_order'   => 5,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        // ----------------------------------------------------------
        // 2. supply_settings — remove obsolete, add new class rules
        // ----------------------------------------------------------

        // Drop obsolete F/graduation keys
        DB::table('supply_settings')->whereIn('key', [
            'graduation_velocity',
            'f_order_window_days',
            'f_max_orders',
        ])->delete();

        // Update new_item_days default (now 7, scoped to Class D only)
        DB::table('supply_settings')->where('key', 'new_item_days')->update([
            'value'      => '7',
            'label'      => 'Class D — New item days (age threshold)',
            'group'      => 'class_d',
            'sort_order' => 1,
            'updated_at' => now(),
        ]);

        // Add new class rule settings
        $now = now();
        $rows = [
            ['key'=>'class_a_min_velocity',   'value'=>'200', 'label'=>'Class A — Min velocity (u/day)',       'group'=>'class_a',   'data_type'=>'float', 'sort_order'=>10],
            ['key'=>'class_a_window_days',    'value'=>'30',  'label'=>'Class A — Required window (days)',     'group'=>'class_a',   'data_type'=>'int',   'sort_order'=>11],
            ['key'=>'class_b_min_velocity',   'value'=>'100', 'label'=>'Class B — Min velocity (u/day)',       'group'=>'class_b',   'data_type'=>'float', 'sort_order'=>20],
            ['key'=>'class_b_window_days',    'value'=>'15',  'label'=>'Class B — Required window (days)',     'group'=>'class_b',   'data_type'=>'int',   'sort_order'=>21],
            ['key'=>'class_c_min_velocity',   'value'=>'50',  'label'=>'Class C — Min velocity (u/day)',       'group'=>'class_c',   'data_type'=>'float', 'sort_order'=>30],
            ['key'=>'class_c_window_days',    'value'=>'7',   'label'=>'Class C — Required window (days)',     'group'=>'class_c',   'data_type'=>'int',   'sort_order'=>31],
            ['key'=>'class_abc_alive_window', 'value'=>'7',   'label'=>'A/B/C — Alive-check window (days)',    'group'=>'class_abc', 'data_type'=>'int',   'sort_order'=>40],
            ['key'=>'class_abc_alive_min',    'value'=>'10',  'label'=>'A/B/C — Alive-check min vel (u/day)',  'group'=>'class_abc', 'data_type'=>'float', 'sort_order'=>41],
        ];
        foreach ($rows as &$r) {
            $existing = DB::table('supply_settings')->where('key', $r['key'])->exists();
            if ($existing) continue;
            $r['created_at'] = $now;
            $r['updated_at'] = $now;
            DB::table('supply_settings')->insert($r);
        }
    }

    public function down(): void
    {
        DB::table('supply_settings')->whereIn('key', [
            'class_a_min_velocity', 'class_a_window_days',
            'class_b_min_velocity', 'class_b_window_days',
            'class_c_min_velocity', 'class_c_window_days',
            'class_abc_alive_window', 'class_abc_alive_min',
        ])->delete();

        DB::table('item_class_thresholds')->where('class_key', 'H')->delete();
    }
};
