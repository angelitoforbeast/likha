<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `ad_catalog` — denormalized catalog ng all FB ads + hierarchy + lifecycle dates.
 *
 * Purpose: replace yung slow per-request subqueries sa AdsManagerCampaignsController
 * (campaignStartedDates, adSetStartedDates, adStartedDates, *FreshStart) na nag-fu-full
 * table scan sa ads_manager_reports.
 *
 * Design:
 *   - ISANG row per AD (the most unique level)
 *   - Each row contains full hierarchy context (campaign + adset + ad IDs + names)
 *   - Plus first_started + first_spend_day (the dates we used to compute on every request)
 *   - For campaign-level / adset-level start dates → aggregate via MIN() (small table = fast)
 *
 * Maintained by:
 *   - One-time backfill migration (next file)
 *   - ProcessAdsManagerReportsUpload upsert per chunk (incremental updates on uploads)
 *
 * Read by:
 *   - AdsManagerCampaignsController::data() — replaces 6 slow subqueries
 *   - AdsManagerCampaignsController::batchData() — same
 *
 * Non-destructive: ad_campaign_creatives untouched. ads_manager_reports untouched.
 * Easy to revert: drop this table → reads fall back to old subqueries (kept in code).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ad_catalog')) return;

        Schema::create('ad_catalog', function (Blueprint $t) {
            $t->id();

            // The most unique level — globally unique per FB ad
            $t->string('ad_id', 191)->unique();

            // Parent hierarchy (nullable in case of legacy data; populated by upload)
            $t->string('ad_set_id', 191)->nullable();
            $t->string('ad_set_name', 255)->nullable();

            $t->string('campaign_id', 191)->nullable();
            $t->string('campaign_name', 255)->nullable();

            // Where this ad lives + who owns it
            $t->string('page_name', 255)->nullable();
            $t->string('account_id', 191)->nullable();

            // Display label (from headline column sa reports)
            $t->string('ad_name', 500)->nullable();

            // Lifecycle dates — yung replaces yung slow subqueries
            $t->date('first_started')->nullable();
            $t->date('first_spend_day')->nullable();

            $t->timestamps();

            // Indexes for the hot JOIN paths
            $t->index('ad_set_id');
            $t->index('campaign_id');
            $t->index('page_name');
            $t->index('first_started');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_catalog');
    }
};
