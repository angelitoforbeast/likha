<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 254 S2, one-off: every ad in ads_manager_reports without an ad_campaign_creatives row of its own gets one, from
 * that ad's most recent report row (never by copying the campaign's row, which holds another ad's content). Existing rows
 * are never changed; ad_link stays empty until the sync fills it. Idempotent; prints counts only.
 */
class BackfillCreativesPerAd extends Command
{
    protected $signature = 'ads:backfill-creatives-per-ad {--dry-run : Count only, write nothing}';

    protected $description = 'Create the missing per-ad ad_campaign_creatives rows from each ad\'s latest Ads Manager report row';

    private const CHUNK = 200;

    private const FIELDS = ['campaign_id', 'campaign_name', 'page_name', 'ad_set_delivery', 'headline', 'body_ad_settings',
        'welcome_message', 'quick_reply_1', 'quick_reply_2', 'quick_reply_3'];

    public function handle(): int
    {
        if (!Schema::hasTable('ads_manager_reports') || !Schema::hasTable('ad_campaign_creatives')) {
            $this->error('ads_manager_reports or ad_campaign_creatives is missing.');

            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');
        $reportAccount = Schema::hasColumn('ads_manager_reports', 'account_id');
        $creativeAccount = Schema::hasColumn('ad_campaign_creatives', 'account_id');

        $missing = DB::table('ads_manager_reports as r')
            ->whereNotNull('r.ad_id')->where('r.ad_id', '!=', '')
            ->whereNotExists(fn ($q) => $q->from('ad_campaign_creatives as c')->whereColumn('c.ad_id', 'r.ad_id')->selectRaw('1'))
            ->distinct()->orderBy('r.ad_id')->pluck('r.ad_id')->map(fn ($v) => (string) $v)->all();

        $created = $wouldCreate = $noCampaign = 0;
        foreach (array_chunk($missing, self::CHUNK) as $ads) {
            // The latest report row per ad: newest day, then newest id.
            $latest = [];
            $cols = array_merge(['ad_id', 'day', 'id'], self::FIELDS, $reportAccount ? ['account_id'] : []);
            foreach (DB::table('ads_manager_reports')->whereIn('ad_id', $ads)->orderByDesc('day')->orderByDesc('id')->get($cols) as $r) {
                $latest[(string) $r->ad_id] ??= $r;
            }

            // The account of a campaign, as the readers derive it today (MAX over the campaign's rows).
            $campaigns = array_values(array_unique(array_filter(array_map(fn ($r) => trim((string) $r->campaign_id), $latest))));
            $accountByCampaign = $creativeAccount && $campaigns ? DB::table('ad_campaign_creatives')->whereIn('campaign_id', $campaigns)
                ->whereNotNull('account_id')->where('account_id', '!=', '')
                ->selectRaw('campaign_id, MAX(account_id) AS account_id')->groupBy('campaign_id')->pluck('account_id', 'campaign_id')->all() : [];

            $now = now();
            $insert = [];
            foreach ($latest as $ad => $r) {
                $campaign = trim((string) $r->campaign_id);
                if ($campaign === '') { $noCampaign++; continue; }
                $row = ['ad_id' => $ad, 'created_at' => $now, 'updated_at' => $now];
                foreach (self::FIELDS as $f) {
                    $v = $r->{$f};
                    $row[$f] = is_string($v) && trim($v) === '' ? null : $v;
                }
                $row['campaign_id'] = $campaign;
                if ($creativeAccount) {
                    $acc = $reportAccount ? trim((string) $r->account_id) : '';
                    $row['account_id'] = $acc !== '' ? $acc : ($accountByCampaign[$campaign] ?? null);
                }
                $insert[] = $row;
            }

            if ($dry) {
                $wouldCreate += count($insert);
            } elseif ($insert) {
                $created += DB::table('ad_campaign_creatives')->insertOrIgnore($insert);
            }
        }

        $this->line('Ads without their own creative row: ' . count($missing));
        $this->line($dry ? 'Would create: ' . $wouldCreate : 'Created: ' . $created);
        $this->line('Skipped (no campaign id): ' . $noCampaign);

        return self::SUCCESS;
    }
}
