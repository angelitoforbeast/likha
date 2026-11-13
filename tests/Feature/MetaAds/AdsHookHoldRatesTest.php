<?php

namespace Tests\Feature\MetaAds;

use App\Http\Controllers\OwnerColumnSettingsController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\OwnerPrivate\OwnerPrivateTestCase;

/**
 * Spec 246: Hook Rate (3-second plays / impressions), Hold Rate (ThruPlays / 3-second plays) and Hold Rate 50%
 * (50% views / 3-second plays), computed from summed numbers on the ads level of the Campaigns Manager, its totals and
 * its CSV, shown through the campaigns column catalog (Campaigns Manager and the ads rows of /owner/private).
 */
class AdsHookHoldRatesTest extends OwnerPrivateTestCase
{
    private const VIDEO = ['video_plays_3s', 'video_thruplays', 'video_p50'];
    private const RATES = ['hook_rate', 'hold_rate', 'hold_rate_50'];
    private const QUERY = ['level' => 'ads', 'start_date' => '2026-10-01', 'end_date' => '2026-10-10', 'page_name' => 'Page Uno'];

    private ?\App\Models\User $ceo = null;

    protected function migrationPaths(): array
    {
        return array_merge(parent::migrationPaths(), ['database/migrations/2026_05_22_140000_create_ad_catalog_table.php']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The table before spec 244; its migration (live) adds the three video columns the rates are computed from.
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
        Schema::create('tasks', function (Blueprint $t) { // counted by the layout's view composer
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('status')->nullable();
            $t->timestamps();
        });
        (require base_path('database/migrations/2026_11_20_100000_add_video_views_to_ads_manager_reports.php'))->up();
    }

    private function adRow(string $adId, string $day, int $impressions, array $video): void
    {
        DB::table('ads_manager_reports')->insert(array_merge([
            'day' => $day, 'page_name' => 'Page Uno', 'campaign_id' => 'c1', 'campaign_name' => 'Campaign Uno',
            'ad_set_id' => 's1', 'ad_set_name' => 'Set s1', 'ad_set_delivery' => 'active', 'campaign_delivery' => 'active',
            'ad_id' => $adId, 'headline' => 'Ad ' . $adId, 'amount_spent_php' => 100, 'impressions' => $impressions, 'reach' => 900,
            'messaging_conversations_started' => 10, 'purchases' => 2, 'results' => 10, 'link_clicks' => 20,
        ], $video ? array_combine(self::VIDEO, $video) : []));
    }

    /**
     * a1: two video days, 2000 impressions, 150 / 50 / 35 → 7.5%, 33.3%, 23.3%.
     * a2: an image ad (no video numbers). a3: 0 impressions and 0 plays.
     * a4: a day stored before the video sync (null) and a video day of 500 impressions, 100 / 20 / 10 → 20%, 20%, 10%.
     * a5: no ThruPlays number, 800 impressions, 200 / null / 50 → 25%, —, 25%.
     */
    private function seedAds(): void
    {
        $this->adRow('a1', '2026-10-05', 1000, [100, 40, 30]);
        $this->adRow('a1', '2026-10-06', 1000, [50, 10, 5]);
        $this->adRow('a2', '2026-10-05', 1000, []);
        $this->adRow('a3', '2026-10-05', 0, [0, 0, 0]);
        $this->adRow('a4', '2026-10-04', 1000, []);
        $this->adRow('a4', '2026-10-05', 500, [100, 20, 10]);
        $this->adRow('a5', '2026-10-05', 800, [200, null, 50]);
    }

    private function data(array $extra = []): \Illuminate\Testing\TestResponse
    {
        $this->ceo ??= $this->user();

        return $this->actingAs($this->ceo)->getJson('/ads_manager/campaigns/data?' . http_build_query(array_merge(self::QUERY, $extra)))->assertOk();
    }

    private function rates(array $row): array
    {
        return array_map(fn ($id) => $row[$id] ?? null, self::RATES);
    }

    private function assertRates(array $expected, array $actual, string $what): void
    {
        foreach ($expected as $i => $want) {
            if ($want === null) $this->assertNull($actual[$i], self::RATES[$i] . " of $what");
            else $this->assertEqualsWithDelta($want, $actual[$i], 0.0001, self::RATES[$i] . " of $what");
        }
    }

    public function test_S1_1_the_ads_level_returns_the_three_rates_per_ad_from_summed_numbers(): void
    {
        $this->seedAds();
        $rows = collect($this->data()->json('rows'))->keyBy('ad_id');

        $this->assertRates([7.5, 100 * 50 / 150, 100 * 35 / 150], $this->rates($rows['a1']), 'a1');
        $this->assertRates([20.0, 20.0, 10.0], $this->rates($rows['a4']), 'a4 (the pre-sync day does not dilute)');
        $this->assertRates([25.0, null, 25.0], $this->rates($rows['a5']), 'a5');
        $this->assertSame([150, 50, 35], [$rows['a1']['video_plays_3s'], $rows['a1']['video_thruplays'], $rows['a1']['video_p50']]);
    }

    public function test_S1_1_the_totals_compute_the_rates_from_the_summed_numbers_not_an_average_of_the_ad_rates(): void
    {
        $this->seedAds();
        $totals = $this->data()->json('totals');

        // Hook: (150 + 0 + 100 + 200) / (2000 + 0 + 500 + 800). Hold: (50 + 0 + 20) / (150 + 0 + 100), a5 has no ThruPlays.
        // Hold 50%: (35 + 0 + 10 + 50) / (150 + 0 + 100 + 200). The average of the ad hold rates would be 26.67, not 28.
        $this->assertRates([100 * 450 / 3300, 28.0, 100 * 95 / 450], $this->rates($totals), 'the totals');
    }

    public function test_S1_2_a_zero_or_null_divisor_or_a_null_numerator_gives_null_never_zero(): void
    {
        $this->seedAds();
        $rows = collect($this->data()->json('rows'))->keyBy('ad_id');

        $this->assertRates([null, null, null], $this->rates($rows['a2']), 'the image ad');
        $this->assertRates([null, null, null], $this->rates($rows['a3']), 'the ad with 0 impressions and 0 plays');
        foreach (self::RATES as $id) {
            $this->assertArrayHasKey($id, $rows['a2']);
        }

        DB::table('ads_manager_reports')->whereIn('ad_id', ['a1', 'a3', 'a4', 'a5'])->delete();
        $this->assertRates([null, null, null], $this->rates($this->data()->json('totals')), 'totals of image ads only');
    }

    public function test_S1_3_each_rate_sorts_the_ads_level(): void
    {
        $this->seedAds();
        $order = fn (string $by, string $dir) => collect($this->data(['sort_by' => $by, 'sort_dir' => $dir])->json('rows'))
            ->filter(fn ($r) => $r[$by] !== null)->pluck('ad_id')->values()->all();

        $this->assertSame(['a5', 'a4', 'a1'], $order('hook_rate', 'desc'));
        $this->assertSame(['a1', 'a4', 'a5'], $order('hook_rate', 'asc'));
        $this->assertSame(['a1', 'a4'], $order('hold_rate', 'desc'));
        $this->assertSame(['a5', 'a1', 'a4'], $order('hold_rate_50', 'desc'));
    }

    public function test_S1_4_the_ads_csv_has_the_three_rates_after_50_percent_views_with_one_decimal_and_blank_for_null(): void
    {
        $this->seedAds();
        $res = $this->actingAs($this->user())->get('/ads_manager/campaigns/data?' . http_build_query(self::QUERY + ['export' => 'csv']))->assertOk();

        $lines  = array_map('str_getcsv', preg_split('/\r?\n/', trim(ltrim($res->streamedContent(), "\xEF\xBB\xBF"))));
        $header = $lines[0];
        $at     = array_search('50% views', $header, true);
        $this->assertSame(['50% views', 'Hook Rate (%)', 'Hold Rate (%)', 'Hold Rate 50% (%)', 'Welcome Msg Rate (%)'], array_slice($header, $at, 5));
        $byAd = [];
        foreach (array_slice($lines, 1) as $l) $byAd[$l[0]] = array_slice($l, $at + 1, 3);
        $this->assertSame(['7.5', '33.3', '23.3'], $byAd['Ad a1']);
        $this->assertSame(['', '', ''], $byAd['Ad a2']);
        $this->assertSame(['', '', ''], $byAd['Ad a3']);
        $this->assertSame(['25.0', '', '25.0'], $byAd['Ad a5']);
    }

    public function test_S1_4_the_campaign_and_ad_set_levels_are_unchanged_and_ignore_a_rate_sort(): void
    {
        $this->seedAds();
        foreach (['campaigns', 'adsets'] as $level) {
            $row = $this->data(['level' => $level, 'sort_by' => 'hook_rate'])->json('rows.0');
            $this->assertArrayNotHasKey('hook_rate', $row, $level);
        }
    }

    public function test_S2_1_the_campaigns_manager_has_the_three_percent_columns_after_the_video_columns_on_the_ads_tab(): void
    {
        $html = $this->actingAs($this->user())->get('/ads_manager/campaigns')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            "/\{ id:'video_p50',[^\n]*\n(?:\s*\/\/[^\n]*\n)?"
                . "\s*\{ id:'hook_rate',\s*label:'Hook Rate',\s*sort:'hook_rate',\s*type:'percent',[^\n]*tab:'ads' \},\n"
                . "\s*\{ id:'hold_rate',\s*label:'Hold Rate',\s*sort:'hold_rate',\s*type:'percent',[^\n]*tab:'ads' \},\n"
                . "\s*\{ id:'hold_rate_50',\s*label:'Hold Rate 50%',\s*sort:'hold_rate_50',\s*type:'percent',[^\n]*tab:'ads' \},\n"
                . "\s*\{ id:'welcome_msg_rate'/",
            $html,
        );
    }

    public function test_S2_1_owner_private_shows_the_three_rates_on_its_ads_rows_only(): void
    {
        foreach (['fee_settings' => ['setting_key', 'setting_value', 'effective_date', 'description', 'host_scope']] as $table => $columns) {
            Schema::create($table, function (Blueprint $t) use ($columns) {
                $t->id();
                foreach ($columns as $column) $t->string($column)->nullable();
                $t->timestamps();
            });
        }
        $html = $this->actingAs($this->user())->get('/owner/private')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            "/\{ id:'link_clicks',[^\n]*\n(?:\s*\/\/[^\n]*\n)?"
                . "\s*\{ id:'hook_rate',\s*label:'Hook Rate',\s*sort:'hook_rate',\s*type:'percent',[^\n]*tab:'ads' \},\n"
                . "\s*\{ id:'hold_rate',\s*label:'Hold Rate',\s*sort:'hold_rate',\s*type:'percent',[^\n]*tab:'ads' \},\n"
                . "\s*\{ id:'hold_rate_50',\s*label:'Hold Rate 50%',\s*sort:'hold_rate_50',\s*type:'percent',[^\n]*tab:'ads' \},\n"
                . "\s*\{ id:'welcome_msg_rate'/",
            $html,
        );
        // The campaign and ad set tables ask for their columns without a level, so the ads-only ones drop out there.
        $this->assertStringContainsString('visibleCampaignsCols(level){', $html);
        $this->assertStringContainsString('out.filter(c => !(c.tab && c.tab !== level))', $html);
        $partial = file_get_contents(resource_path('views/owner/_private_expand_inline.blade.php'));
        $this->assertSame(2, substr_count($partial, "visibleCampaignsCols('ads')"), 'the ads header and the ads cells');
        $this->assertStringContainsString(":key=\"'ed-h-'+col.id\"", $partial);
        $this->assertMatchesRegularExpression("/x-for=\"col in visibleCampaignsCols\('ads'\)\" :key=\"'ed-h-'\+col\.id\"/", $partial);
        $this->assertMatchesRegularExpression("/x-for=\"col in visibleCampaignsCols\('ads'\)\" :key=\"'ed-c-'\+col\.id\"/", $partial);
    }

    public function test_S2_2_column_settings_list_them_after_the_video_columns_and_the_ceo_sees_them_by_default(): void
    {
        $ids = array_column(OwnerColumnSettingsController::CATALOG['campaigns'], 'id');
        $at  = array_search('video_p50', $ids, true);
        $this->assertSame(self::RATES, array_slice($ids, $at + 1, 3));
        $labels = array_column(OwnerColumnSettingsController::CATALOG['campaigns'], 'label', 'id');
        $this->assertSame(['Hook Rate (Ads tab)', 'Hold Rate (Ads tab)', 'Hold Rate 50% (Ads tab)'], array_map(fn ($id) => $labels[$id], self::RATES));

        $ctl = new OwnerColumnSettingsController();
        $config = $ctl->loadConfig('campaigns', 'CEO');
        $this->assertSame(self::RATES, array_slice($config['order'], array_search('video_p50', $config['order'], true) + 1, 3));
        $this->assertSame([], array_values(array_intersect(self::RATES, $config['hidden'])));
        // Nothing saved yet: other roles start with nothing visible (opt-in).
        $this->assertSame(self::RATES, array_values(array_intersect(self::RATES, $ctl->loadConfig('campaigns', 'Marketing')['hidden'])));
    }

    public function test_S2_2_other_roles_see_them_only_when_ticked_in_column_settings(): void
    {
        $ctl = new OwnerColumnSettingsController();
        $save = fn (array $marketing) => DB::table('app_settings')->updateOrInsert(['key' => 'campaigns_cols'], ['value' => json_encode([
            'order' => array_column(OwnerColumnSettingsController::CATALOG['campaigns'], 'id'), 'hidden' => [],
            'visible_by_role' => ['Marketing' => $marketing, 'Marketing - OIC' => ['name']],
        ]), 'created_at' => now(), 'updated_at' => now()]);

        $save(['name', 'spend', 'video_plays_3s']);
        $this->assertSame(self::RATES, array_values(array_intersect(self::RATES, $ctl->loadConfig('campaigns', 'Marketing')['hidden'])));
        $this->assertSame([], array_values(array_intersect(self::RATES, $ctl->loadConfig('campaigns', 'CEO')['hidden'])));

        $save(['name', 'spend', 'hook_rate', 'hold_rate_50']);
        $this->assertSame(['hold_rate'], array_values(array_intersect(self::RATES, $ctl->loadConfig('campaigns', 'Marketing')['hidden'])));
        $this->assertSame(self::RATES, array_values(array_intersect(self::RATES, $ctl->loadConfig('campaigns', 'Marketing - OIC')['hidden'])));
    }

    public function test_S2_2_an_older_saved_order_gets_them_right_after_the_video_columns(): void
    {
        $ctl = new OwnerColumnSettingsController();
        $catalog = array_column(OwnerColumnSettingsController::CATALOG['campaigns'], 'id');
        $saveOrder = fn (array $order) => DB::table('app_settings')->updateOrInsert(['key' => 'campaigns_cols'],
            ['value' => json_encode(['order' => array_values($order), 'hidden' => ['reach']]), 'created_at' => now(), 'updated_at' => now()]);

        // Saved after spec 244, with the video columns moved last.
        $withVideo = array_values(array_diff($catalog, self::RATES, self::VIDEO));
        $saveOrder(array_merge($withVideo, self::VIDEO));
        foreach ([$ctl->loadConfig('campaigns', 'CEO')['order'], $ctl->loadConfigMatrix('campaigns')['order']] as $order) {
            $this->assertSame(array_merge(self::VIDEO, self::RATES), array_slice($order, -6));
        }

        // Saved before spec 244, with link clicks moved last: the video columns, then the rates, follow it.
        $saveOrder(array_merge(array_diff($withVideo, ['link_clicks']), ['link_clicks']));
        foreach ([$ctl->loadConfig('campaigns', 'CEO')['order'], $ctl->loadConfigMatrix('campaigns')['order']] as $order) {
            $this->assertSame(array_merge(['link_clicks'], self::VIDEO, self::RATES), array_slice($order, -7));
        }
        $this->assertSame([], array_values(array_intersect(self::RATES, $ctl->loadConfig('campaigns', 'CEO')['hidden'])));
    }
}
