<?php

namespace Tests\Feature\MetaAds;

use App\Http\Controllers\OwnerColumnSettingsController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\OwnerPrivate\OwnerPrivateTestCase;

/**
 * Spec 244: the migration that adds the three video columns, and the per-ad table where reach, impressions and link
 * clicks are read (the Campaigns Manager, /ads_manager/campaigns, its data endpoint and CSV, and its column settings).
 */
class AdsVideoViewsTableTest extends OwnerPrivateTestCase
{
    private const MIGRATION = 'database/migrations/2026_11_20_100000_add_video_views_to_ads_manager_reports.php';
    private const VIDEO = ['video_plays_3s', 'video_thruplays', 'video_p50'];
    private const QUERY = ['level' => 'ads', 'start_date' => '2026-10-01', 'end_date' => '2026-10-10', 'page_name' => 'Page Uno'];

    protected function migrationPaths(): array
    {
        return array_merge(parent::migrationPaths(), ['database/migrations/2026_05_22_140000_create_ad_catalog_table.php']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The table as it is before spec 244; the migration under test adds the video columns.
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
        $this->migration()->up();
    }

    private function migration(): object
    {
        return require base_path(self::MIGRATION);
    }

    private function adRow(string $adId, string $day, array $video): void
    {
        DB::table('ads_manager_reports')->insert(array_merge([
            'day' => $day, 'page_name' => 'Page Uno', 'campaign_id' => 'c1', 'campaign_name' => 'Campaign Uno',
            'ad_set_id' => 's1', 'ad_set_name' => 'Set s1', 'ad_set_delivery' => 'active', 'campaign_delivery' => 'active',
            'ad_id' => $adId, 'headline' => 'Ad ' . $adId, 'amount_spent_php' => 100, 'impressions' => 1000, 'reach' => 900,
            'messaging_conversations_started' => 10, 'purchases' => 2, 'results' => 10, 'link_clicks' => 20,
        ], $video ? array_combine(self::VIDEO, $video) : []));
    }

    private function seedAds(): void
    {
        $this->adRow('a1', '2026-10-05', [100, 40, 30]);
        $this->adRow('a1', '2026-10-06', [50, 10, 5]);
        $this->adRow('a2', '2026-10-05', []); // image ad: the sync leaves the three columns null
    }

    public function test_S1_5_the_migration_adds_three_nullable_columns_without_a_default_and_down_removes_them(): void
    {
        foreach (self::VIDEO as $c) $this->assertTrue(Schema::hasColumn('ads_manager_reports', $c), $c);
        $this->adRow('a1', '2026-10-05', []);
        $this->assertSame([null, null, null], array_values((array) DB::table('ads_manager_reports')->first(self::VIDEO)));

        $this->migration()->up(); // running it again changes nothing
        $this->migration()->down();
        foreach (self::VIDEO as $c) $this->assertFalse(Schema::hasColumn('ads_manager_reports', $c), $c);
        $this->assertSame(1, DB::table('ads_manager_reports')->count());
        $this->migration()->down(); // and down() twice is safe

        $source = file_get_contents(base_path(self::MIGRATION));
        $this->assertStringNotContainsString('->default(', $source);
        $this->assertStringNotContainsString('->after(', $source, 'appended last so MySQL 8 adds them INSTANT');
    }

    public function test_S2_1_the_ads_level_returns_the_three_sums_per_ad_null_for_an_image_ad_and_in_the_totals(): void
    {
        $this->seedAds();

        $res = $this->actingAs($this->user())->getJson('/ads_manager/campaigns/data?' . http_build_query(self::QUERY))->assertOk();

        $rows = collect($res->json('rows'))->keyBy('ad_id');
        $this->assertSame([150, 50, 35], [$rows['a1']['video_plays_3s'], $rows['a1']['video_thruplays'], $rows['a1']['video_p50']]);
        $this->assertSame([null, null, null], [$rows['a2']['video_plays_3s'], $rows['a2']['video_thruplays'], $rows['a2']['video_p50']]);
        $this->assertSame([150, 50, 35], [$res->json('totals.video_plays_3s'), $res->json('totals.video_thruplays'), $res->json('totals.video_p50')]);
        $this->assertSame([40, 20], [$rows['a1']['link_clicks'], $rows['a2']['link_clicks']]); // the other numbers as before
    }

    public function test_S2_1_the_ads_csv_has_the_three_columns_right_after_link_clicks_and_blank_for_null(): void
    {
        $this->seedAds();

        $res = $this->actingAs($this->user())->get('/ads_manager/campaigns/data?' . http_build_query(self::QUERY + ['export' => 'csv']))->assertOk();

        $lines  = array_map('str_getcsv', preg_split('/\r?\n/', trim(ltrim($res->streamedContent(), "\xEF\xBB\xBF"))));
        $header = $lines[0];
        $at     = array_search('Link Clicks', $header, true);
        // Spec 246 put its three rates right after "50% views".
        $this->assertSame(['Link Clicks', '3s plays', 'ThruPlays (15s)', '50% views', 'Hook Rate (%)'], array_slice($header, $at, 5));
        $byAd = [];
        foreach (array_slice($lines, 1) as $l) $byAd[$l[0]] = array_slice($l, $at, 4);
        $this->assertSame(['40', '150', '50', '35'], $byAd['Ad a1']);
        $this->assertSame(['20', '', '', ''], $byAd['Ad a2']);
    }

    public function test_S2_1_before_the_migration_runs_the_ads_level_still_loads(): void
    {
        $this->migration()->down();
        $this->adRow('a1', '2026-10-05', []);

        $res = $this->actingAs($this->user())->getJson('/ads_manager/campaigns/data?' . http_build_query(self::QUERY))->assertOk();

        $this->assertSame(['a1'], collect($res->json('rows'))->pluck('ad_id')->all());
        $this->assertNull($res->json('rows.0.video_plays_3s'));
        $this->actingAs($this->user('CEO', 'ceo2@example.test'))
            ->get('/ads_manager/campaigns/data?' . http_build_query(self::QUERY + ['export' => 'csv']))->assertOk();
    }

    public function test_S2_2_the_campaigns_manager_has_the_three_columns_after_link_clicks_on_the_ads_tab(): void
    {
        $html = $this->actingAs($this->user())->get('/ads_manager/campaigns')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            "/id:'link_clicks'[^\n]*\n(?:\s*\/\/[^\n]*\n)?\s*\{ id:'video_plays_3s',\s*label:'3s plays',[^\n]*tab:'ads' \},\n"
                . "\s*\{ id:'video_thruplays',\s*label:'ThruPlays \(15s\)',[^\n]*tab:'ads' \},\n"
                // Spec 246's three rates follow the video columns.
                . "\s*\{ id:'video_p50',\s*label:'50% views',[^\n]*tab:'ads' \},\n(?:\s*\/\/[^\n]*\n)?\s*\{ id:'hook_rate'/",
            $html,
        );
        $this->assertStringContainsString("c.tab && c.tab !== this.tab", $html, 'the video columns show on the Ads tab only');
    }

    public function test_S2_2_column_settings_list_them_after_link_clicks_and_an_older_saved_order_gets_them_there(): void
    {
        $ctl = new OwnerColumnSettingsController();
        $after = fn (array $order) => array_slice($order, array_search('link_clicks', $order, true) + 1, 3);

        $config = $ctl->loadConfig('campaigns', 'CEO');
        $this->assertSame(self::VIDEO, $after($config['order']));
        $this->assertSame([], array_intersect(self::VIDEO, $config['hidden']), 'the CEO sees them by default');

        $old = array_values(array_diff(array_column(OwnerColumnSettingsController::CATALOG['campaigns'], 'id'), self::VIDEO));
        $old = array_merge(array_diff($old, ['link_clicks']), ['link_clicks']); // a saved order with link clicks moved last
        DB::table('app_settings')->updateOrInsert(['key' => 'campaigns_cols'],
            ['value' => json_encode(['order' => array_values($old), 'hidden' => ['reach']]), 'created_at' => now(), 'updated_at' => now()]);

        foreach ([$ctl->loadConfig('campaigns', 'CEO')['order'], $ctl->loadConfigMatrix('campaigns')['order']] as $order) {
            $this->assertSame(self::VIDEO, $after($order));
            $this->assertSame('link_clicks', $order[count($order) - 4]);
        }
        $this->assertSame([], array_intersect(self::VIDEO, $ctl->loadConfig('campaigns', 'CEO')['hidden']));
    }
}
