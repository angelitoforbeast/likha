<?php

namespace Tests\Feature\OwnerPrivate;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * /ads_manager/campaigns/data?level=ads: ang mga ad sa ilalim ng isang ad set (ang expand ng /owner/private).
 * Ang ls subquery ay may sariling ad_set_id, kaya ang hindi naka-qualify na ad_set_id ay "ambiguous" sa MySQL
 * at sa sqlite: HTTP 500 sa bawat bukas ng ad set.
 */
class AdsLevelCampaignsDataTest extends OwnerPrivateTestCase
{
    protected function migrationPaths(): array
    {
        return array_merge(parent::migrationPaths(), ['database/migrations/2026_05_22_140000_create_ad_catalog_table.php']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('ads_manager_reports', function (Blueprint $t) {
            $t->id();
            $t->date('day')->nullable();
            foreach (['page_name', 'campaign_name', 'ad_set_id', 'ad_set_name', 'campaign_id', 'campaign_delivery', 'ad_set_delivery',
                'ad_set_budget', 'ad_set_budget_type', 'attribution_setting', 'result_type', 'cost_per_result', 'starts', 'ends',
                'headline', 'body_ad_settings', 'ad_id', 'welcome_message', 'item_name', 'account_id'] as $c) {
                $t->string($c)->nullable();
            }
            foreach (['reach', 'impressions', 'results', 'messaging_conversations_started', 'purchases', 'link_clicks'] as $c) {
                $t->integer($c)->nullable();
            }
            $t->decimal('amount_spent_php', 14, 2)->nullable();
            $t->date('reporting_starts')->nullable();
            $t->date('reporting_ends')->nullable();
            $t->timestamps();
        });
        Schema::create('ad_campaign_creatives', function (Blueprint $t) {
            $t->id();
            foreach (['campaign_id', 'campaign_name', 'headline', 'body_ad_settings', 'welcome_message', 'page_name', 'ad_set_delivery',
                'ad_id', 'ad_link', 'feedback', 'account_id', 'quick_reply_1', 'quick_reply_2', 'quick_reply_3'] as $c) {
                $t->string($c)->nullable();
            }
            $t->timestamps();
        });
        Schema::create('ad_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('ad_account_id')->nullable();
            $t->string('name')->nullable();
            $t->timestamps();
        });
    }

    private function adRow(string $adId, string $adSetId, float $spend): void
    {
        DB::table('ads_manager_reports')->insert([
            'day' => '2026-10-05', 'page_name' => 'Page Uno', 'campaign_id' => 'c1', 'campaign_name' => 'Campaign Uno',
            'ad_set_id' => $adSetId, 'ad_set_name' => 'Set ' . $adSetId, 'ad_set_delivery' => 'active', 'campaign_delivery' => 'active',
            'ad_id' => $adId, 'headline' => 'Ad ' . $adId, 'amount_spent_php' => $spend, 'impressions' => 1000, 'reach' => 900,
            'messaging_conversations_started' => 10, 'purchases' => 2, 'results' => 10, 'link_clicks' => 20,
        ]);
    }

    public function test_the_ads_of_one_ad_set_load_without_an_ambiguous_column_error(): void
    {
        $this->adRow('a1', 's1', 100);
        $this->adRow('a2', 's1', 50);
        $this->adRow('a3', 's2', 70); // ibang ad set: hindi kasama

        $query = ['level' => 'ads', 'start_date' => '2026-10-01', 'end_date' => '2026-10-10', 'page_name' => 'Page Uno', 'campaign_id' => 'c1'];

        foreach ([['ad_set_id' => 's1'], ['ad_set_ids' => 's1']] as $filter) {
            $res = $this->actingAs($this->user('CEO', 'ceo' . key($filter) . '@example.test'))
                ->getJson('/ads_manager/campaigns/data?' . http_build_query($query + $filter));
            $res->assertOk();
            $rows = collect($res->json('rows') ?? $res->json('data') ?? []);
            $this->assertEqualsCanonicalizing(['a1', 'a2'], $rows->pluck('ad_id')->all(), key($filter));
            $this->assertSame(['s1'], $rows->pluck('ad_set_id')->unique()->values()->all(), key($filter));
        }
    }
}
