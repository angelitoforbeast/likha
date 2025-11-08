<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ads_manager_reports', function (Blueprint $table) {
            // If you had an older unique index, drop it first.
            // Adjust the index name to your actual one if different.
            // Example old name used here:
            // $table->dropUnique('ads_report_day_campaign_adset_unique');

            // New composite unique (day + campaign_id + ad_set_id + ad_id)
            $table->unique(
                ['day', 'campaign_id', 'ad_set_id', 'ad_id'],
                'ads_report_day_campaign_adset_ad_unique'
            );

            // Helpful secondary indexes
            $table->index(['ad_id'], 'ads_report_adid_idx');
            $table->index(['campaign_id', 'ad_set_id', 'day'], 'ads_report_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ads_manager_reports', function (Blueprint $table) {
            $table->dropUnique('ads_report_day_campaign_adset_ad_unique');
            $table->dropIndex('ads_report_adid_idx');
            $table->dropIndex('ads_report_lookup_idx');

            // Optionally re-create your old unique here if you dropped one in up():
            // $table->unique(['day', 'campaign_id', 'ad_set_id'], 'ads_report_day_campaign_adset_unique');
        });
    }
};
