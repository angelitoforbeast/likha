<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add page_name + (page_name, day) composite indexes sa ads_manager_reports.
 *
 * Why:
 *   /owner/private's "Expand all" + /ads_manager/campaigns filter heavily by
 *   page_name and day. Existing indexes cover (campaign_id, ad_set_id, day) and
 *   (day, campaign_id, ad_set_id, ad_id) UNIQUE — but NONE on page_name. Every
 *   page-scoped query does full-table scan → expand-all turns into 40+ heavy
 *   scans.
 *
 *   Adding (page_name, day) composite + plain (page_name) lets MySQL use index
 *   range scans for `WHERE page_name = ? AND day BETWEEN ?` — the dominant
 *   pattern. Expected 3-10× speedup on per-page aggregations after this.
 *
 * Safe to run on a large table:
 *   MySQL adds indexes online by default (no table lock). For Postgres, uses
 *   CREATE INDEX IF NOT EXISTS so re-runs are no-ops.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('ads_manager_reports')) return;

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('CREATE INDEX IF NOT EXISTS ads_report_page_day_idx ON ads_manager_reports (page_name, day);');
            DB::statement('CREATE INDEX IF NOT EXISTS ads_report_page_idx     ON ads_manager_reports (page_name);');
            return;
        }

        // MySQL — guard with information_schema check to avoid duplicate-index errors.
        $exists = function (string $name): bool {
            $row = DB::selectOne(
                'SELECT COUNT(*) AS c FROM information_schema.statistics
                 WHERE table_schema = DATABASE()
                   AND table_name   = ?
                   AND index_name   = ?',
                ['ads_manager_reports', $name]
            );
            return (int) ($row->c ?? 0) > 0;
        };

        if (!$exists('ads_report_page_day_idx')) {
            DB::statement('CREATE INDEX ads_report_page_day_idx ON ads_manager_reports (page_name, day);');
        }
        if (!$exists('ads_report_page_idx')) {
            DB::statement('CREATE INDEX ads_report_page_idx ON ads_manager_reports (page_name);');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ads_manager_reports')) return;

        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS ads_report_page_day_idx;');
            DB::statement('DROP INDEX IF EXISTS ads_report_page_idx;');
        } else {
            // MySQL — wrap in try since older versions don't have IF EXISTS.
            try { DB::statement('DROP INDEX ads_report_page_day_idx ON ads_manager_reports;'); } catch (\Throwable $e) {}
            try { DB::statement('DROP INDEX ads_report_page_idx     ON ads_manager_reports;'); } catch (\Throwable $e) {}
        }
    }
};
