<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('ads_manager_reports')) {
            return;
        }

        // OPTIONAL: kung kailangan mo talaga yung change() part, pwede mong ibalik dito sa taas.
        // For now, focus tayo sa UNIQUE + INDEX na nag-e-error.

        // 🔒 UNIQUE CONSTRAINT (safe kung existing na)
        DB::statement("
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM pg_constraint
                    WHERE conname = 'ads_report_day_campaign_adset_ad_unique'
                ) THEN
                    ALTER TABLE ads_manager_reports
                    ADD CONSTRAINT ads_report_day_campaign_adset_ad_unique
                    UNIQUE (day, campaign_id, ad_set_id, ad_id);
                END IF;
            END
            $$;
        ");

        // 📌 INDEX ON ad_id
        DB::statement("
            CREATE INDEX IF NOT EXISTS ads_report_adid_idx
            ON ads_manager_reports (ad_id);
        ");

        // 📌 Helper lookup index
        DB::statement("
            CREATE INDEX IF NOT EXISTS ads_report_lookup_idx
            ON ads_manager_reports (campaign_id, ad_set_id, day);
        ");
    }

    public function down(): void
    {
        if (! Schema::hasTable('ads_manager_reports')) {
            return;
        }

        // Safe DROP (hindi mag-e-error kahit wala)
        DB::statement("
            ALTER TABLE ads_manager_reports
            DROP CONSTRAINT IF EXISTS ads_report_day_campaign_adset_ad_unique;
        ");

        DB::statement("DROP INDEX IF EXISTS ads_report_adid_idx;");
        DB::statement("DROP INDEX IF EXISTS ads_report_lookup_idx;");
    }
};
