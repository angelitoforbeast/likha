<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * supply_item_settings.palugit_override — palugit na itinakda mismo sa /item.
 * NULL = walang override, lifecycle default ang gamit. Hindi ginagalaw ang safety_days (para sa /jnt/supply).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('supply_item_settings') || Schema::hasColumn('supply_item_settings', 'palugit_override')) return;

        Schema::table('supply_item_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('palugit_override')->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('supply_item_settings') || !Schema::hasColumn('supply_item_settings', 'palugit_override')) return;

        Schema::table('supply_item_settings', function (Blueprint $table) {
            $table->dropColumn('palugit_override');
        });
    }
};
