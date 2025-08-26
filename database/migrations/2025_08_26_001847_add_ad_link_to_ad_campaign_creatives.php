<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ad_campaign_creatives', function (Blueprint $table) {
            if (!Schema::hasColumn('ad_campaign_creatives', 'ad_link')) {
                $table->string('ad_link')->nullable()->after('ad_set_delivery');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ad_campaign_creatives', function (Blueprint $table) {
            if (Schema::hasColumn('ad_campaign_creatives', 'ad_link')) {
                $table->dropColumn('ad_link');
            }
        });
    }
};
