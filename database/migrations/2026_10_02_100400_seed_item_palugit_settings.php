<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * supply_settings group 'item_palugit' — default palugit (araw) bawat lifecycle sa /item.
 * Mga key lang na wala pa ang ini-insert; hindi ino-overwrite ang na-edit na ng CEO.
 */
return new class extends Migration
{
    private const ROWS = [
        ['palugit_new',        '3',  'Palugit (araw) — New item',                       60],
        ['palugit_scaling',    '14', 'Palugit (araw) — Scaling',                        61],
        ['palugit_consistent', '10', 'Palugit (araw) — Consistent',                     62],
        ['palugit_active',     '7',  'Palugit (araw) — Active',                         63],
        ['palugit_declining',  '0',  'Palugit (araw) — Declining',                      64],
        ['palugit_lugi',       '3',  'Palugit (araw) — Scaling/Consistent na lugi',     65],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('supply_settings')) return;

        $have = DB::table('supply_settings')->whereIn('key', array_column(self::ROWS, 0))->pluck('key')->all();

        foreach (self::ROWS as [$key, $value, $label, $sort]) {
            if (in_array($key, $have, true)) continue;

            DB::table('supply_settings')->insert([
                'key'        => $key,
                'value'      => $value,
                'label'      => $label,
                'group'      => 'item_palugit',
                'data_type'  => 'int',
                'sort_order' => $sort,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('supply_settings')) return;

        DB::table('supply_settings')->whereIn('key', array_column(self::ROWS, 0))->delete();
    }
};
