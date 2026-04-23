<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add Class F row to item_class_thresholds (if not exists)
        $exists = DB::table('item_class_thresholds')->where('class_key', 'F')->exists();
        if (!$exists) {
            DB::table('item_class_thresholds')->insert([
                'class_key'    => 'F',
                'label'        => 'Off / Hold-only',
                'min_velocity' => 0,
                'sort_order'   => 6,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        // 2. Update Class D / E labels to match new semantics
        DB::table('item_class_thresholds')->where('class_key', 'D')
            ->update(['label' => 'New Item']);
        DB::table('item_class_thresholds')->where('class_key', 'E')
            ->update(['label' => 'Clearing / No Profit']);

        // 3. Create supply_settings key-value table
        Schema::create('supply_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('value', 50);
            $table->string('label', 150);
            $table->string('group', 30)->default('general');
            $table->string('data_type', 10)->default('float');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        $rows = [
            ['key'=>'new_item_days',        'value'=>'30',  'label'=>'Class D — New item days (age threshold)',    'group'=>'class_d',  'data_type'=>'int',   'sort_order'=>10],
            ['key'=>'graduation_velocity',  'value'=>'20',  'label'=>'Class D — Graduation velocity (u/day)',       'group'=>'class_d',  'data_type'=>'float', 'sort_order'=>11],
            ['key'=>'f_order_window_days',  'value'=>'1',   'label'=>'Class F — Recent order window (days)',        'group'=>'class_f',  'data_type'=>'int',   'sort_order'=>20],
            ['key'=>'f_max_orders',         'value'=>'0',   'label'=>'Class F — Max orders in window (≤ to be F)',  'group'=>'class_f',  'data_type'=>'int',   'sort_order'=>21],
            ['key'=>'lifecycle_window_days','value'=>'14',  'label'=>'Lifecycle comparison window (days)',          'group'=>'lifecycle','data_type'=>'int',   'sort_order'=>30],
            ['key'=>'scaling_ratio',        'value'=>'1.5', 'label'=>'Scaling ratio (recent ÷ prev)',               'group'=>'lifecycle','data_type'=>'float', 'sort_order'=>31],
            ['key'=>'declining_ratio',      'value'=>'0.5', 'label'=>'Declining ratio (recent ÷ prev)',             'group'=>'lifecycle','data_type'=>'float', 'sort_order'=>32],
            ['key'=>'consistent_days',      'value'=>'90',  'label'=>'Consistent qualification days',               'group'=>'lifecycle','data_type'=>'int',   'sort_order'=>33],
            ['key'=>'velocity_period_days', 'value'=>'30',  'label'=>'Velocity period (default days)',              'group'=>'velocity', 'data_type'=>'int',   'sort_order'=>40],
            ['key'=>'running_threshold',    'value'=>'1',   'label'=>'Running threshold (u/day)',                   'group'=>'velocity', 'data_type'=>'float', 'sort_order'=>41],
        ];

        foreach ($rows as &$r) {
            $r['created_at'] = $now;
            $r['updated_at'] = $now;
        }
        DB::table('supply_settings')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_settings');
        DB::table('item_class_thresholds')->where('class_key', 'F')->delete();
    }
};
