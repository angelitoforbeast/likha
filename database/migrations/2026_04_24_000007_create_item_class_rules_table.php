<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // 1. Create item_class_rules — CRUD-managed classification rules
        // ------------------------------------------------------------------
        Schema::create('item_class_rules', function (Blueprint $table) {
            $table->id();
            $table->string('class_key', 10)->unique();            // immutable after create
            $table->string('label', 80);
            $table->string('badge_tailwind', 120)->default('bg-gray-500 text-white');
            $table->integer('sort_order')->default(100);

            // rule_type: 'age_lt' | 'velocity_tier' | 'catch_all'
            $table->string('rule_type', 20)->default('velocity_tier');

            // velocity_tier fields
            $table->float('min_velocity')->nullable();
            $table->integer('window_days')->nullable();
            $table->float('alive_min')->nullable();
            $table->integer('alive_window')->nullable();

            // age_lt fields
            $table->integer('age_threshold')->nullable();

            $table->boolean('is_fixed')->default(false);          // Y + X can't be deleted
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('sort_order');
        });

        // ------------------------------------------------------------------
        // 2. Seed from existing supply_settings values + defaults
        // ------------------------------------------------------------------
        $kv = DB::table('supply_settings')->pluck('value', 'key')->all();

        $v = fn(string $key, $default) => array_key_exists($key, $kv) ? $kv[$key] : $default;
        $now = now();

        $seed = [
            // Y — New Item (fixed, age-based)
            [
                'class_key'      => 'Y',
                'label'          => 'Y · New Item',
                'badge_tailwind' => 'bg-orange-400 text-white',
                'sort_order'     => 1,
                'rule_type'      => 'age_lt',
                'age_threshold'  => (int) $v('new_item_days', 7),
                'is_fixed'       => true,
            ],
            // A — Hero
            [
                'class_key'      => 'A',
                'label'          => 'A · Hero',
                'badge_tailwind' => 'bg-purple-600 text-white',
                'sort_order'     => 10,
                'rule_type'      => 'velocity_tier',
                'min_velocity'   => (float) $v('class_a_min_velocity', 200),
                'window_days'    => (int)   $v('class_a_window_days', 30),
                'alive_min'      => (float) $v('class_a_alive_min', 10),
                'alive_window'   => (int)   $v('class_a_alive_window', 7),
            ],
            // B — Solid
            [
                'class_key'      => 'B',
                'label'          => 'B · Solid',
                'badge_tailwind' => 'bg-blue-500 text-white',
                'sort_order'     => 20,
                'rule_type'      => 'velocity_tier',
                'min_velocity'   => (float) $v('class_b_min_velocity', 100),
                'window_days'    => (int)   $v('class_b_window_days', 15),
                'alive_min'      => (float) $v('class_b_alive_min', 10),
                'alive_window'   => (int)   $v('class_b_alive_window', 7),
            ],
            // C — Active
            [
                'class_key'      => 'C',
                'label'          => 'C · Active',
                'badge_tailwind' => 'bg-yellow-400 text-gray-900',
                'sort_order'     => 30,
                'rule_type'      => 'velocity_tier',
                'min_velocity'   => (float) $v('class_c_min_velocity', 50),
                'window_days'    => (int)   $v('class_c_window_days', 7),
                'alive_min'      => (float) $v('class_c_alive_min', 10),
                'alive_window'   => (int)   $v('class_c_alive_window', 7),
            ],
            // E — Low-Vel Running (remapped as velocity_tier with low thresholds)
            [
                'class_key'      => 'E',
                'label'          => 'E · Low-Vel Running',
                'badge_tailwind' => 'bg-amber-600 text-white',
                'sort_order'     => 40,
                'rule_type'      => 'velocity_tier',
                'min_velocity'   => 1.0,
                'window_days'    => 14,
                'alive_min'      => (float) $v('class_e_alive_min', 5),
                'alive_window'   => (int)   $v('class_e_alive_window', 14),
            ],
            // F — Dead/No Ads (remapped as very-low velocity_tier)
            [
                'class_key'      => 'F',
                'label'          => 'F · Fading',
                'badge_tailwind' => 'bg-rose-700 text-white',
                'sort_order'     => 50,
                'rule_type'      => 'velocity_tier',
                'min_velocity'   => 0.1,
                'window_days'    => 30,
                'alive_min'      => (float) $v('class_f_alive_min', 0.5),
                'alive_window'   => (int)   $v('class_f_alive_window', 30),
            ],
            // X — Catch-all (fixed)
            [
                'class_key'      => 'X',
                'label'          => 'X · Review',
                'badge_tailwind' => 'bg-gray-500 text-white',
                'sort_order'     => 999,
                'rule_type'      => 'catch_all',
                'is_fixed'       => true,
            ],
        ];

        foreach ($seed as $row) {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            // upsert (won't duplicate if re-run)
            if (!DB::table('item_class_rules')->where('class_key', $row['class_key'])->exists()) {
                DB::table('item_class_rules')->insert($row);
            }
        }

        // ------------------------------------------------------------------
        // 3. Migrate existing class_override values in supply_item_settings
        //    D → Y  (was "New Item"), H → X  (was catch-all)
        // ------------------------------------------------------------------
        if (Schema::hasTable('supply_item_settings')) {
            DB::table('supply_item_settings')->where('class_override', 'D')->update(['class_override' => 'Y']);
            DB::table('supply_item_settings')->where('class_override', 'H')->update(['class_override' => 'X']);
        }
    }

    public function down(): void
    {
        // Restore class_override values
        if (Schema::hasTable('supply_item_settings')) {
            DB::table('supply_item_settings')->where('class_override', 'Y')->update(['class_override' => 'D']);
            DB::table('supply_item_settings')->where('class_override', 'X')->update(['class_override' => 'H']);
        }
        Schema::dropIfExists('item_class_rules');
    }
};
