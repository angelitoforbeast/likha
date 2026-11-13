<?php

namespace Tests\Feature\OwnerPrivate;

use App\Services\Chatbot\AdGroups;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 254 S3: the readers of ad_campaign_creatives with several rows per campaign (one per ad).
 * Campaign c1: ad set s1 = a1 (spend 100), a2 (spend 300); ad set s2 = a3 (spend 50, same content as a2); a4 (no own row).
 * Rows: a1, a2, a3 and a campaign-only row (no ad id).
 */
class CreativePerAdReadersTest extends OwnerPrivateTestCase
{
    protected function migrationPaths(): array
    {
        return array_merge(parent::migrationPaths(), ['database/migrations/2026_05_22_140000_create_ad_catalog_table.php']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tasks', function (Blueprint $t) { // counted by the layout's view composer (edit page)
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('status')->nullable();
            $t->timestamps();
        });
        Schema::create('ads_manager_reports', function (Blueprint $t) {
            $t->id();
            $t->date('day')->nullable();
            foreach (['page_name', 'campaign_name', 'ad_set_id', 'ad_set_name', 'campaign_id', 'campaign_delivery', 'ad_set_delivery',
                'ad_set_budget', 'ad_set_budget_type', 'attribution_setting', 'result_type', 'cost_per_result', 'starts', 'ends',
                'headline', 'body_ad_settings', 'ad_id', 'welcome_message', 'item_name', 'account_id',
                'quick_reply_1', 'quick_reply_2', 'quick_reply_3'] as $c) {
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
            $t->string('ad_id')->nullable()->unique();
            $t->string('campaign_id')->nullable()->index();
            foreach (['campaign_name', 'headline', 'body_ad_settings', 'welcome_message', 'page_name', 'ad_set_delivery',
                'ad_link', 'account_id', 'quick_reply_1', 'quick_reply_2', 'quick_reply_3'] as $c) {
                $t->string($c)->nullable();
            }
            $t->boolean('feedback')->default(0);
            $t->timestamps();
        });
        Schema::create('ad_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('ad_account_id')->nullable();
            $t->string('name')->nullable();
            $t->timestamps();
        });
        DB::table('ad_accounts')->insert(['ad_account_id' => '111', 'name' => 'Likha A']);
    }

    private function report(string $ad, string $set, float $spend, string $day = '2026-11-08', string $campaign = 'c1'): void
    {
        DB::table('ads_manager_reports')->insert([
            'day' => $day, 'page_name' => 'Page Uno', 'campaign_id' => $campaign, 'campaign_name' => 'Campaign ' . $campaign,
            'ad_set_id' => $set, 'ad_set_name' => 'Set ' . $set, 'ad_set_delivery' => 'active', 'campaign_delivery' => 'active',
            'ad_id' => $ad, 'headline' => 'Report headline ' . $ad, 'amount_spent_php' => $spend, 'impressions' => 1000, 'reach' => 900,
            'messaging_conversations_started' => 10, 'purchases' => 2, 'results' => 10, 'link_clicks' => 20,
        ]);
    }

    private function creative(?string $ad, string $welcome, ?string $link = null, string $campaign = 'c1', ?string $account = '111'): void
    {
        DB::table('ad_campaign_creatives')->insert([
            'ad_id' => $ad, 'campaign_id' => $campaign, 'campaign_name' => 'Campaign ' . $campaign, 'page_name' => 'Page Uno',
            'headline' => 'Headline ' . $welcome, 'body_ad_settings' => 'Body ' . $welcome, 'welcome_message' => $welcome,
            'quick_reply_1' => 'QR ' . $welcome, 'ad_link' => $link, 'account_id' => $account, 'ad_set_delivery' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedAds(): void
    {
        $this->report('a1', 's1', 100);
        $this->report('a2', 's1', 300);
        $this->report('a3', 's2', 50);
        $this->report('a4', 's2', 10);
        $this->creative(null, 'W0', null, 'c1', null);
        $this->creative('a1', 'W1', 'https://www.facebook.com/1');
        $this->creative('a2', 'W2');
        $this->creative('a3', 'W2');
        DB::table('ad_catalog')->insert(['ad_id' => 'a2', 'ad_name' => 'Video two', 'campaign_id' => 'c1', 'ad_set_id' => 's1']);
    }

    private function preview(string $level, string $id): array
    {
        $res = $this->actingAs($this->user('CEO', "ceo-{$level}-{$id}@example.test"))
            ->getJson('/ads_manager/creative-preview?' . http_build_query(['level' => $level, 'id' => $id]));
        $res->assertOk();

        return $res->json();
    }

    public function test_S3_campaign_lookup_returns_each_distinct_creative_of_its_ads_highest_spend_first(): void
    {
        $this->seedAds();

        $j = $this->preview('campaign', 'c1');

        $this->assertSame(['W2', 'W1'], array_column($j['creatives'], 'welcome_message'));
        $this->assertSame(['a2', 'a1'], array_column($j['creatives'], 'ad_id'));
        $this->assertSame(['a2', 'a3'], $j['creatives'][0]['ad_ids'], 'a3 has the same content as a2');
        $this->assertSame('Video two', $j['creatives'][0]['ad_name']);
        $this->assertSame('Ad a1', $j['creatives'][1]['ad_name']);
        $this->assertSame('https://www.facebook.com/1', $j['creatives'][1]['ad_link']);
        $this->assertSame('W2', $j['creative']['welcome_message'], 'creative = the first one, as before');
    }

    public function test_S3_adset_lookup_returns_only_the_creatives_of_its_ads(): void
    {
        $this->seedAds();

        $this->assertSame(['a2', 'a1'], array_column($this->preview('adset', 's1')['creatives'], 'ad_id'));
        // s2: a3 has its own row; a4 has none and is not shown with another ad's content.
        $s2 = $this->preview('adset', 's2');
        $this->assertSame(['a3'], array_column($s2['creatives'], 'ad_id'));
        $this->assertSame('W2', $s2['creative']['welcome_message']);
    }

    public function test_S3_ad_lookup_returns_the_ads_own_row_and_falls_back_to_the_campaign_row(): void
    {
        $this->seedAds();

        $a1 = $this->preview('ad', 'a1');
        $this->assertSame('W1', $a1['creative']['welcome_message']);
        $this->assertCount(1, $a1['creatives']);

        // a4 has no row of its own: the campaign-only row, never another ad's row.
        $this->assertSame('W0', $this->preview('ad', 'a4')['creative']['welcome_message']);
    }

    public function test_S3_a_campaign_with_only_its_campaign_row_shows_that_row(): void
    {
        $this->report('b1', 't1', 20, '2026-11-08', 'c9');
        $this->creative(null, 'Only one', null, 'c9');

        $j = $this->preview('campaign', 'c9');

        $this->assertCount(1, $j['creatives']);
        $this->assertSame('Only one', $j['creative']['welcome_message']);
        $this->assertNull($j['creatives'][0]['ad_id']);
    }

    public function test_S3_the_campaigns_list_shows_each_campaign_once_with_its_account(): void
    {
        $this->seedAds();

        $res = $this->actingAs($this->user())->getJson('/ads_manager/campaigns/data?' . http_build_query([
            'level' => 'campaigns', 'start_date' => '2026-11-01', 'end_date' => '2026-11-10', 'page_name' => 'Page Uno']));

        $res->assertOk();
        $rows = collect($res->json('rows') ?? $res->json('data') ?? [])->where('campaign_id', 'c1')->values();
        $this->assertCount(1, $rows);
        $this->assertSame('111', $rows[0]['account_id']);
        $this->assertSame('Likha A', $rows[0]['account_name']);
    }

    public function test_S3_an_ads_link_flag_comes_from_its_own_row_not_from_a_sibling(): void
    {
        $this->seedAds();

        $res = $this->actingAs($this->user())->getJson('/ads_manager/campaigns/data?' . http_build_query([
            'level' => 'ads', 'start_date' => '2026-11-01', 'end_date' => '2026-11-10', 'page_name' => 'Page Uno',
            'campaign_id' => 'c1', 'ad_set_id' => 's1']));

        $res->assertOk();
        $flags = collect($res->json('rows') ?? $res->json('data') ?? [])->pluck('has_ad_link', 'ad_id')->all();
        $this->assertSame(['a1' => true, 'a2' => false], array_intersect_key($flags, ['a1' => 1, 'a2' => 1]));
    }

    public function test_S3_history_ad_events_carry_the_ads_own_creative(): void
    {
        $this->seedAds();

        $res = $this->actingAs($this->user())->getJson('/ads_manager/campaigns/history/data?' . http_build_query([
            'level' => 'ads', 'start_date' => '2026-11-01', 'end_date' => '2026-11-10']));

        $res->assertOk();
        $byAd = collect($res->json('events'))->keyBy('entity_id');
        $this->assertSame('W1', $byAd['a1']['welcome_message']);
        $this->assertSame('W2', $byAd['a2']['welcome_message']);
        $this->assertSame('W0', $byAd['a4']['welcome_message'], 'no own row: the campaign fallback');
        $this->assertSame('111', $byAd['a2']['account_id']);
    }

    public function test_S3_ad_groups_preview_falls_back_to_the_campaign_only_row(): void
    {
        $this->report('a4', 's2', 10);
        $this->creative('a1', 'W1', 'https://www.facebook.com/1'); // lower id than the campaign row
        $this->creative(null, 'W0', 'https://www.facebook.com/0');

        $c = app(AdGroups::class)->creative('a4');

        $this->assertTrue($c['from_campaign']);
        $this->assertSame('https://www.facebook.com/0', $c['link']);
        $this->assertSame('Headline W0', $c['headline']);
    }

    public function test_S3_the_edit_page_lists_one_row_per_ad_and_finds_an_ad_by_its_id(): void
    {
        $this->seedAds();
        $user = $this->user();

        $all = $this->actingAs($user)->get('/ads-manager/edit-messaging-template');
        $all->assertOk();
        foreach (['a1', 'a2', 'a3'] as $ad) $all->assertSee('Ad ' . $ad, false);

        $one = $this->actingAs($user)->get('/ads-manager/edit-messaging-template?q=a3');
        $one->assertOk();
        $one->assertSee('Ad a3', false);
        $one->assertDontSee('Ad a1', false);
    }
}
