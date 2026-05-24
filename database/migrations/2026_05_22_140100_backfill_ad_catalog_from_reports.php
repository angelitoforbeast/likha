<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill `ad_catalog` from existing data:
 *   1) Hierarchy + dates from `ads_manager_reports` (GROUP BY ad_id)
 *   2) account_id stitched from `ad_campaign_creatives` (campaign_id → account_id)
 *
 * Idempotent — uses ON DUPLICATE KEY UPDATE with LEAST() so re-runs only ever
 * make first_started EARLIER (never later). Safe to run multiple times.
 *
 * One-time operation. Estimated time: few seconds to ~1 min depending on size
 * of ads_manager_reports table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ad_catalog')) return;
        if (!Schema::hasTable('ads_manager_reports')) return;

        $driver = DB::getDriverName();

        // ── Step 1: Backfill from ads_manager_reports ──
        // ONE INSERT statement per ad (via GROUP BY ad_id). MAX() picks
        // arbitrary non-null values for hierarchy fields (campaign/adset names
        // sometimes vary over time; latest wins via MAX which sorts strings).
        //
        // first_started   = MIN(day) — earliest appearance, including no-spend days
        // first_spend_day = MIN(day WHERE adspent > 0) — earliest day with spend
        if ($driver === 'mysql') {
            DB::statement("
                INSERT INTO ad_catalog (
                    ad_id, ad_set_id, ad_set_name, campaign_id, campaign_name,
                    page_name, ad_name,
                    first_started, first_spend_day, created_at, updated_at
                )
                SELECT
                    ad_id,
                    MAX(ad_set_id)     AS ad_set_id,
                    MAX(ad_set_name)   AS ad_set_name,
                    MAX(campaign_id)   AS campaign_id,
                    MAX(campaign_name) AS campaign_name,
                    MAX(page_name)     AS page_name,
                    MAX(headline)      AS ad_name,
                    MIN(`day`) AS first_started,
                    MIN(CASE WHEN amount_spent_php > 0 THEN `day` END) AS first_spend_day,
                    NOW(), NOW()
                FROM ads_manager_reports
                WHERE ad_id IS NOT NULL AND ad_id != ''
                GROUP BY ad_id
                ON DUPLICATE KEY UPDATE
                    ad_set_id       = COALESCE(ad_catalog.ad_set_id,     VALUES(ad_set_id)),
                    ad_set_name     = COALESCE(ad_catalog.ad_set_name,   VALUES(ad_set_name)),
                    campaign_id     = COALESCE(ad_catalog.campaign_id,   VALUES(campaign_id)),
                    campaign_name   = COALESCE(ad_catalog.campaign_name, VALUES(campaign_name)),
                    page_name       = COALESCE(ad_catalog.page_name,     VALUES(page_name)),
                    ad_name         = COALESCE(ad_catalog.ad_name,       VALUES(ad_name)),
                    first_started   = LEAST(COALESCE(ad_catalog.first_started, VALUES(first_started)), VALUES(first_started)),
                    first_spend_day = LEAST(
                                        COALESCE(ad_catalog.first_spend_day, VALUES(first_spend_day), '9999-12-31'),
                                        COALESCE(VALUES(first_spend_day),    ad_catalog.first_spend_day, '9999-12-31')
                                      ),
                    updated_at = NOW()
            ");
        } elseif ($driver === 'pgsql') {
            // Postgres equivalent — uses ON CONFLICT instead of ON DUPLICATE KEY
            DB::statement("
                INSERT INTO ad_catalog (
                    ad_id, ad_set_id, ad_set_name, campaign_id, campaign_name,
                    page_name, ad_name,
                    first_started, first_spend_day, created_at, updated_at
                )
                SELECT
                    ad_id,
                    MAX(ad_set_id),
                    MAX(ad_set_name),
                    MAX(campaign_id),
                    MAX(campaign_name),
                    MAX(page_name),
                    MAX(headline),
                    MIN(day),
                    MIN(CASE WHEN amount_spent_php > 0 THEN day END),
                    NOW(), NOW()
                FROM ads_manager_reports
                WHERE ad_id IS NOT NULL AND ad_id != ''
                GROUP BY ad_id
                ON CONFLICT (ad_id) DO UPDATE SET
                    ad_set_id       = COALESCE(ad_catalog.ad_set_id,     EXCLUDED.ad_set_id),
                    ad_set_name     = COALESCE(ad_catalog.ad_set_name,   EXCLUDED.ad_set_name),
                    campaign_id     = COALESCE(ad_catalog.campaign_id,   EXCLUDED.campaign_id),
                    campaign_name   = COALESCE(ad_catalog.campaign_name, EXCLUDED.campaign_name),
                    page_name       = COALESCE(ad_catalog.page_name,     EXCLUDED.page_name),
                    ad_name         = COALESCE(ad_catalog.ad_name,       EXCLUDED.ad_name),
                    first_started   = LEAST(COALESCE(ad_catalog.first_started, EXCLUDED.first_started), EXCLUDED.first_started),
                    first_spend_day = LEAST(
                                        COALESCE(ad_catalog.first_spend_day, EXCLUDED.first_spend_day, '9999-12-31'),
                                        COALESCE(EXCLUDED.first_spend_day,   ad_catalog.first_spend_day, '9999-12-31')
                                      ),
                    updated_at = NOW()
            ");
        }

        // ── Step 2: Stitch account_id from ad_campaign_creatives ──
        // account_id ay nakatira sa ad_campaign_creatives (per-campaign 1:1).
        // Copy lang into ad_catalog para isang JOIN lang sa reads.
        if (Schema::hasTable('ad_campaign_creatives') && Schema::hasColumn('ad_campaign_creatives', 'account_id')) {
            DB::statement("
                UPDATE ad_catalog ac
                INNER JOIN (
                    SELECT campaign_id, MAX(account_id) AS account_id
                    FROM ad_campaign_creatives
                    WHERE account_id IS NOT NULL AND account_id != ''
                    GROUP BY campaign_id
                ) src ON src.campaign_id = ac.campaign_id
                SET ac.account_id = src.account_id, ac.updated_at = NOW()
                WHERE ac.account_id IS NULL OR ac.account_id = ''
            ");
        }
    }

    public function down(): void
    {
        // Truncate so re-running 'up' produces clean state. Doesn't drop table
        // (that's the previous migration's responsibility).
        if (Schema::hasTable('ad_catalog')) {
            DB::table('ad_catalog')->truncate();
        }
    }
};
