<?php

namespace Tests\Feature\AdAccount;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\OwnerPrivate\OwnerPrivateTestCase;

/** /ads_manager/campaigns/data: ang pangalan ng ad account ay galing sa active row lang, hindi sa lumang pangalan. */
class CampaignAccountNameTest extends OwnerPrivateTestCase
{
    protected function migrationPaths(): array
    {
        return array_merge(parent::migrationPaths(), [
            'database/migrations/2026_05_22_140000_create_ad_catalog_table.php',
            'database/migrations/2025_10_19_154416_create_ad_accounts_table.php',
            'database/migrations/2026_10_31_100000_add_history_to_ad_accounts_table.php',
        ]);
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
    }

    public function test_S_103_9_campaign_data_names_the_account_from_the_active_row_only(): void
    {
        DB::table('ads_manager_reports')->insert([
            'day' => '2026-10-05', 'page_name' => 'Page Uno', 'campaign_id' => 'c1', 'campaign_name' => 'Campaign Uno',
            'ad_set_id' => 's1', 'ad_set_name' => 'Set s1', 'ad_set_delivery' => 'active', 'campaign_delivery' => 'active',
            'ad_id' => 'a1', 'headline' => 'Ad a1', 'amount_spent_php' => 100, 'impressions' => 1000, 'reach' => 900,
        ]);
        DB::table('ad_campaign_creatives')->insert(['campaign_id' => 'c1', 'account_id' => '1001']);
        // Ang lumang pangalan ay mas mataas ang id: ito ang mananalo sa pluck kung walang active filter.
        DB::table('ad_accounts')->insert([
            ['ad_account_id' => '1001', 'name' => 'Pangalang Bago', 'active' => true, 'action' => 'changed'],
            ['ad_account_id' => '1001', 'name' => 'Pangalang Luma', 'active' => false, 'action' => 'added'],
        ]);

        $query = ['level' => 'ads', 'start_date' => '2026-10-01', 'end_date' => '2026-10-10', 'page_name' => 'Page Uno', 'campaign_id' => 'c1', 'ad_set_id' => 's1'];
        $res = $this->actingAs($this->user())->getJson('/ads_manager/campaigns/data?' . http_build_query($query))->assertOk();

        $rows = collect($res->json('rows') ?? $res->json('data') ?? []);
        $this->assertSame(['Pangalang Bago'], $rows->pluck('account_name')->unique()->values()->all());
    }
}
