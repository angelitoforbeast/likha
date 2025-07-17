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
        $table->string('headline')->nullable()->after('campaign_name');
        $table->text('body')->nullable()->after('headline'); // body = "Body (ad settings)"
        $table->string('ad_id')->nullable()->after('body');  // Ad ID
    });
}

public function down(): void
{
    Schema::table('ads_manager_reports', function (Blueprint $table) {
        $table->dropColumn(['headline', 'body', 'ad_id']);
    });
}

};
