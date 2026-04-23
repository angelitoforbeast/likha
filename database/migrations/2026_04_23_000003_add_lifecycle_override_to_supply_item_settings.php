<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supply_item_settings', function (Blueprint $table) {
            $table->string('lifecycle_override', 30)->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('supply_item_settings', function (Blueprint $table) {
            $table->dropColumn('lifecycle_override');
        });
    }
};
