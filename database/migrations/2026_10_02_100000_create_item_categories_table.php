<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * item_categories — mga category ng item sa /item (CEO ang nagtatakda). May 8 na seed;
 * ang mga bagong pangalan ay idinadagdag lang kung wala pa.
 */
return new class extends Migration
{
    private const SEEDS = [
        'Bahay at Paglilinis',
        'Ilaw at Kuryente',
        'Sasakyan at Motor',
        'Repair at DIY',
        'Health at Beauty',
        'Fashion at Accessories',
        'Office at School',
        'Iba pa',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('item_categories')) {
            Schema::create('item_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name', 60)->unique();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        $existing = DB::table('item_categories')->pluck('name')->all();
        $now = now();
        foreach (self::SEEDS as $i => $name) {
            if (in_array($name, $existing, true)) continue;
            DB::table('item_categories')->insert([
                'name'       => $name,
                'sort_order' => $i + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('item_categories');
    }
};
