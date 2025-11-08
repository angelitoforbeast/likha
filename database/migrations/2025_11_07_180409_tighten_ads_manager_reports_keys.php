<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Ensure no NULLs before making ad_id NOT NULL
        DB::table('ads_manager_reports')->whereNull('ad_id')->update(['ad_id' => DB::raw("''")]);
        // Optional cleanup
        DB::statement("UPDATE ads_manager_reports SET ad_id = TRIM(ad_id)");

        // NOTE: changing existing columns needs doctrine/dbal
        Schema::table('ads_manager_reports', function (Blueprint $table) {
            // FB-style IDs fit in 50 chars; keep campaign/adset nullable, ad_id required
            $table->string('campaign_id', 50)->nullable()->change();
            $table->string('ad_set_id',   50)->nullable()->change();
            $table->string('ad_id',       50)->default('')->change();
        });

        // Add composite unique + helper indexes
        Schema::table('ads_manager_reports', function (Blueprint $table) {
            // If you previously created a different unique, drop it first (uncomment and adjust name):
            // $table->dropUnique('ads_report_day_campaign_adset_unique');

            $table->unique(
                ['day', 'campaign_id', 'ad_set_id', 'ad_id'],
                'ads_report_day_campaign_adset_ad_unique'
            );

            $table->index(['ad_id'], 'ads_report_adid_idx');
            $table->index(['campaign_id','ad_set_id','day'], 'ads_report_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ads_manager_reports', function (Blueprint $table) {
            // Drop the indexes we added
        
            $table->dropUnique('ads_report_day_campaign_adset_ad_unique');
            $table->dropIndex('ads_report_adid_idx');
            $table->dropIndex('ads_report_lookup_idx');

            // Revert column definitions (back to 255 + nullable ad_id)
            $table->string('campaign_id', 255)->nullable()->change();
            $table->string('ad_set_id',   255)->nullable()->change();
            $table->string('ad_id',       255)->nullable()->default(null)->change();
        });
    }
};
