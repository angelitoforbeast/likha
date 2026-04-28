<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Architectural cleanup: account_id is a campaign-level attribute (1 campaign
 * belongs to exactly 1 ad account), so it has no business in the daily fact
 * table `ads_manager_reports` where it gets duplicated across hundreds of
 * rows per campaign. Move it to `ad_campaign_creatives` (1 row per campaign/ad).
 *
 * Steps (all atomic in one migration):
 *   1) Add nullable `account_id` column to `ad_campaign_creatives`
 *   2) Backfill: copy distinct (campaign_id → account_id) from
 *      `ads_manager_reports` into `ad_campaign_creatives`
 *   3) Drop `account_id` from `ads_manager_reports`
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Add column
        if (!Schema::hasColumn('ad_campaign_creatives', 'account_id')) {
            Schema::table('ad_campaign_creatives', function (Blueprint $table) {
                $table->string('account_id', 191)->nullable()->after('campaign_id');
                $table->index('account_id');
            });
        }

        // 2) Backfill from ads_manager_reports → ad_campaign_creatives.
        //    For each campaign_id, pick any non-null account_id from the fact
        //    table and write it into the creatives row.
        if (Schema::hasColumn('ads_manager_reports', 'account_id')) {
            // Build (campaign_id → account_id) map (one row per campaign).
            $pairs = DB::table('ads_manager_reports')
                ->whereNotNull('campaign_id')
                ->whereNotNull('account_id')
                ->where('account_id', '!=', '')
                ->selectRaw('campaign_id, MAX(account_id) AS account_id')
                ->groupBy('campaign_id')
                ->get();

            foreach ($pairs as $p) {
                DB::table('ad_campaign_creatives')
                    ->where('campaign_id', $p->campaign_id)
                    ->whereNull('account_id')
                    ->update([
                        'account_id' => $p->account_id,
                        'updated_at' => now(),
                    ]);
            }

            // 3) Drop the old column from the fact table.
            Schema::table('ads_manager_reports', function (Blueprint $table) {
                // Drop the index first if present, then the column.
                try { $table->dropIndex(['account_id']); } catch (\Throwable $e) { /* index may not exist */ }
                $table->dropColumn('account_id');
            });
        }
    }

    public function down(): void
    {
        // Re-add to fact table
        if (!Schema::hasColumn('ads_manager_reports', 'account_id')) {
            Schema::table('ads_manager_reports', function (Blueprint $table) {
                $table->string('account_id', 191)->nullable()->after('campaign_id');
                $table->index('account_id');
            });
        }

        // Copy back from creatives table
        if (Schema::hasColumn('ad_campaign_creatives', 'account_id')) {
            $pairs = DB::table('ad_campaign_creatives')
                ->whereNotNull('campaign_id')
                ->whereNotNull('account_id')
                ->where('account_id', '!=', '')
                ->select('campaign_id', 'account_id')
                ->get();
            foreach ($pairs as $p) {
                DB::table('ads_manager_reports')
                    ->where('campaign_id', $p->campaign_id)
                    ->update(['account_id' => $p->account_id]);
            }

            Schema::table('ad_campaign_creatives', function (Blueprint $table) {
                try { $table->dropIndex(['account_id']); } catch (\Throwable $e) {}
                $table->dropColumn('account_id');
            });
        }
    }
};
