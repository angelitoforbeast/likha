<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Default window_days=30 for Y (age_lt) and X (catch_all) rows if null.
        //    These are non-bearing for classification but used by profit% compute.
        DB::table('item_class_rules')
            ->whereIn('class_key', ['Y', 'X'])
            ->whereNull('window_days')
            ->update(['window_days' => 30]);

        // 2. Excluded multi-item pages — affects profit% only.
        if (!Schema::hasTable('supply_excluded_pages')) {
            Schema::create('supply_excluded_pages', function (Blueprint $table) {
                $table->id();
                $table->string('page_name', 200)->unique();
                $table->string('reason', 255)->nullable();
                $table->timestamps();
                $table->index('page_name');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_excluded_pages');

        DB::table('item_class_rules')
            ->whereIn('class_key', ['Y', 'X'])
            ->where('window_days', 30)
            ->update(['window_days' => null]);
    }
};
