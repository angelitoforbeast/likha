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
    Schema::table('ad_campaign_creatives', function (Blueprint $table) {
        $table->string('ad_id')->nullable()->unique()->after('id');
    });
}

public function down(): void
{
    Schema::table('ad_campaign_creatives', function (Blueprint $table) {
        $table->dropColumn('ad_id');
    });
}

};
