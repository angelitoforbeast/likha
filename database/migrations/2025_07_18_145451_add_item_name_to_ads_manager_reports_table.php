<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::table('ads_manager_reports', function (Blueprint $table) {
        $table->string('item_name')->nullable();
    });
}

public function down(): void
{
    Schema::table('ads_manager_reports', function (Blueprint $table) {
        $table->dropColumn('item_name');
    });
}

};
