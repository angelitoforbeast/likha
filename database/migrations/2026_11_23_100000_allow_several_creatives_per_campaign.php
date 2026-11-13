<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 254: one ad_campaign_creatives row per ad. campaign_id was unique (2025_07_18_154633), so a campaign kept a
 * single row; now it keeps a plain index and every ad of a campaign can have its own row. ad_id stays unique.
 * About 3,200 rows in production: adding the plain index and dropping the unique one is quick.
 */
return new class extends Migration {
    private const TABLE = 'ad_campaign_creatives';

    private const PLAIN = 'ad_campaign_creatives_campaign_id_index';

    private const UNIQUE = 'ad_campaign_creatives_campaign_id_unique';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) return;

        // The plain index first, so lookups by campaign stay indexed while the unique one goes.
        if (!Schema::hasIndex(self::TABLE, self::PLAIN)) {
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->index('campaign_id', self::PLAIN));
        }
        foreach ($this->uniqueCampaignIndexes() as $name) {
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->dropUnique($name));
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE)) return;

        $campaigns = DB::query()->fromSub(
            DB::table(self::TABLE)->whereNotNull('campaign_id')->select('campaign_id')->groupBy('campaign_id')->havingRaw('COUNT(*) > 1'),
            'd'
        )->count();
        if ($campaigns > 0) {
            throw new \RuntimeException(sprintf(
                'Cannot restore the unique index on %s.campaign_id: %d campaign(s) have more than one row (one per ad since spec 254). '
                . 'Nothing was changed. Merge or delete the extra rows first, then roll back again.',
                self::TABLE, $campaigns
            ));
        }

        if (!$this->uniqueCampaignIndexes()) {
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->unique('campaign_id', self::UNIQUE));
        }
        if (Schema::hasIndex(self::TABLE, self::PLAIN)) {
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->dropIndex(self::PLAIN));
        }
    }

    /** The names of the unique indexes on exactly (campaign_id), whatever they are called. */
    private function uniqueCampaignIndexes(): array
    {
        return collect(Schema::getIndexes(self::TABLE))
            ->filter(fn ($i) => $i['unique'] && !$i['primary'] && $i['columns'] === ['campaign_id'])
            ->pluck('name')->values()->all();
    }
};
