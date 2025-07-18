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
    Schema::table('ad_campaign_creatives', function ($table) {
        $table->string('page_name')->nullable();
    });
}


    /**
     * Reverse the migrations.
     */
    public function down(): void
{
    Schema::table('ad_campaign_creatives', function ($table) {
        $table->dropColumn('page_name');
    });
}

};
