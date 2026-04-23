<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_class_thresholds', function (Blueprint $table) {
            $table->id();
            $table->string('class_key', 2)->unique();
            $table->string('label', 50);
            $table->decimal('min_velocity', 8, 3)->default(0);
            $table->unsignedTinyInteger('sort_order');
            $table->timestamps();
        });

        // Seed defaults
        DB::table('item_class_thresholds')->insert([
            ['class_key' => 'A', 'label' => 'Hero',    'min_velocity' => 10.000, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['class_key' => 'B', 'label' => 'Solid',   'min_velocity' => 3.000,  'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['class_key' => 'C', 'label' => 'Average', 'min_velocity' => 0.500,  'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['class_key' => 'D', 'label' => 'At-Risk', 'min_velocity' => 0.001,  'sort_order' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['class_key' => 'E', 'label' => 'Dead',    'min_velocity' => 0.000,  'sort_order' => 5, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('item_class_thresholds');
    }
};
