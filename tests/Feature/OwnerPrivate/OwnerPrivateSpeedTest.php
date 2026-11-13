<?php

namespace Tests\Feature\OwnerPrivate;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 261: ang "Expand all" ng /owner/private (batch-data) at ang per-page data() ay pareho ang sagot,
 * pero ang page filter ay page_name IN (...) at ang latest status ay para lang sa mga campaign at ad set
 * na nasa resulta (walang LOWER(TRIM(page_name)) at walang GROUP BY sa buong table).
 */
class OwnerPrivateSpeedTest extends OwnerPrivateTestCase
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

        $this->seedReports();
    }

    private function row(?string $day, string $page, string $campaign, string $adSet, string $campaignDelivery, string $adSetDelivery, float $spend, int $purchases = 0, ?string $reportingStarts = null): void
    {
        DB::table('ads_manager_reports')->insert([
            'day' => $day, 'reporting_starts' => $reportingStarts, 'page_name' => $page,
            'campaign_id' => $campaign, 'campaign_name' => 'Campaign ' . $campaign,
            'ad_set_id' => $adSet, 'ad_set_name' => 'Set ' . $adSet, 'ad_id' => 'ad-' . $adSet . '-' . ($day ?? 'x'),
            'campaign_delivery' => $campaignDelivery, 'ad_set_delivery' => $adSetDelivery,
            'amount_spent_php' => $spend, 'purchases' => $purchases, 'impressions' => 1000, 'reach' => 800,
            'messaging_conversations_started' => 10, 'results' => 10, 'link_clicks' => 20,
        ]);
    }

    private function seedReports(): void
    {
        // Isang page, tatlong baybay: iba ang case, may space sa dulo, may space sa simula.
        $this->row('2026-10-03', 'Page Uno', 'c1', 's1', 'active', 'active', 100, 2);
        $this->row('2026-10-04', 'page uno ', 'c1', 's1', 'active', 'active', 40, 1);
        $this->row('2026-10-04', ' PAGE UNO', 'c2', 's2', 'active', 'active', 30, 3);
        // Ibang page: hindi kasama.
        $this->row('2026-10-04', 'Page Dos', 'c9', 's9', 'active', 'active', 500, 5);

        // Page Tres: mga campaign na naka-off sa pinakahuling araw kahit naka-on dati.
        $this->row('2026-10-02', 'Page Tres', 'c3', 's3a', 'active', 'active', 10, 1);
        $this->row('2026-10-06', 'Page Tres', 'c3', 's3b', 'inactive', 'active', 5, 0);
        // Ang pinakahuling araw ng c3 at s3a ay nasa ibang page: off.
        $this->row('2026-10-08', 'Page Dos', 'c3', 's3a', 'inactive', 'inactive', 0, 0);
        // c4: off sa loob ng range, pero ang pinakahuling araw (lampas sa end date) ay on.
        $this->row('2026-10-05', 'Page Tres', 'c4', 's4', 'inactive', 'inactive', 20, 1);
        $this->row('2026-10-15', 'Page Tres', 'c4', 's4', 'active', 'active', 0, 0);
        // c5: on bago ang start, off sa loob ng range.
        $this->row('2026-09-20', 'Page Tres', 'c5', 's5', 'active', 'active', 3, 0);
        $this->row('2026-10-05', 'Page Tres', 'c5', 's5', 'inactive', 'inactive', 7, 0);
        // c6: on sa range, pero may row na walang day (reporting_starts lang) na mas huli at off.
        $this->row('2026-10-05', 'Page Tres', 'c6', 's6', 'active', 'active', 9, 1);
        $this->row(null, 'Page Tres', 'c6', 's6', 'inactive', 'inactive', 4, 0, '2026-10-20');
    }

    private ?\App\Models\User $ceo = null;

    private function batch(array $pages): array
    {
        $this->ceo ??= $this->user();
        $res = $this->actingAs($this->ceo)->postJson('/ads_manager/campaigns/batch-data', [
            'end_date' => '2026-10-10', 'pages' => $pages, 'only_with_spend' => true, 'include_windows' => true, 'limit_per_page' => 1000,
        ])->assertOk();

        return $res->json();
    }

    private static function byCampaign(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[$r['campaign_id']][] = $r;
        }
        ksort($out);

        return $out;
    }

    public function test_names_that_differ_only_in_case_or_spaces_return_the_same_rows(): void
    {
        $j = $this->batch([['name' => 'PAGE UNO', 'start_date' => '2026-10-01']]);

        $this->assertTrue($j['ok']);
        $this->assertSame(['PAGE UNO'], array_keys($j['by_page']));
        $bucket = $j['by_page']['PAGE UNO'];
        $camps = self::byCampaign($bucket['rows']);
        $this->assertSame(['c1', 'c2'], array_keys($camps));
        $this->assertEquals(140.0, array_sum(array_column($camps['c1'], 'spend')));
        $this->assertEquals(30.0, array_sum(array_column($camps['c2'], 'spend')));
        foreach ($bucket['rows'] as $r) {
            $this->assertTrue($r['on'], $r['campaign_id']);
            $this->assertSame(1, $r['active_subcount'], $r['campaign_id']);
        }
        $this->assertEquals(170.0, $bucket['totals']['spend']);
        $this->assertSame(6, $bucket['totals']['purchases']);
        $this->assertEquals(170.0 / 6, $bucket['totals']['cpp']);
        // Windowed CPP (end-6 .. end): c2 ay 30 / 3.
        $this->assertEquals(10.0, $camps['c2'][0]['cpp_7d']);
    }

    public function test_the_latest_day_decides_on_or_off_even_outside_the_range(): void
    {
        $j = $this->batch([['name' => 'page tres', 'start_date' => '2026-10-01']]);

        $camps = self::byCampaign($j['by_page']['page tres']['rows']);
        $this->assertSame(['c3', 'c4', 'c5', 'c6'], array_keys($camps));
        $on = array_map(fn ($rows) => $rows[0]['on'], $camps);
        $this->assertSame(['c3' => false, 'c4' => true, 'c5' => false, 'c6' => false], $on);
        $sub = array_map(fn ($rows) => $rows[0]['active_subcount'], $camps);
        $this->assertSame(['c3' => 1, 'c4' => 1, 'c5' => 0, 'c6' => 0], $sub);
        $this->assertEquals(15.0, $camps['c3'][0]['spend']);
        $this->assertEquals(7.0, $camps['c5'][0]['spend']);
        // Ang naka-on ang nauuna.
        $this->assertSame('c4', $j['by_page']['page tres']['rows'][0]['campaign_id']);
    }

    public function test_a_page_with_no_rows_gets_an_empty_bucket(): void
    {
        $j = $this->batch([
            ['name' => 'Page Wala', 'start_date' => '2026-10-01'],
            ['name' => 'Page Uno', 'start_date' => '2026-10-04'],
        ]);
        $this->assertSame([], $j['by_page']['Page Wala']['rows']);
        $this->assertEquals(0, $j['by_page']['Page Wala']['totals']['spend']);
        $this->assertNull($j['by_page']['Page Wala']['totals']['cpp']);
        // Ang ibang page sa parehong request ay may sariling start date (10-04): 40 + 30.
        $this->assertEquals(70.0, $j['by_page']['Page Uno']['totals']['spend']);

        // Lahat ng page ay walang row.
        $j = $this->batch([['name' => 'Page Wala', 'start_date' => '2026-10-01']]);
        $this->assertTrue($j['ok']);
        $this->assertSame([], $j['by_page']['Page Wala']['rows']);
    }

    public function test_the_per_page_data_endpoint_gives_the_same_status_and_sums(): void
    {
        $res = $this->actingAs($this->user())->getJson('/ads_manager/campaigns/data?' . http_build_query([
            'level' => 'campaigns', 'start_date' => '2026-10-01', 'end_date' => '2026-10-10', 'page_name' => 'page tres',
            'only_with_spend' => '1', 'include_windows' => '1', 'limit' => 1000,
        ]))->assertOk();
        $rows = collect($res->json('rows'))->keyBy('campaign_id');
        $this->assertSame(['c3', 'c4', 'c5', 'c6'], $rows->keys()->sort()->values()->all());
        $this->assertSame(['c3' => false, 'c4' => true, 'c5' => false, 'c6' => false], $rows->map(fn ($r) => $r['on'])->sortKeys()->all());
        $this->assertSame(['c3' => 1, 'c4' => 1, 'c5' => 0, 'c6' => 0], $rows->map(fn ($r) => $r['active_subcount'])->sortKeys()->all());
        // data() ay COALESCE(day, reporting_starts) ang range: kasama ang row ng c6 na walang day? Hindi: 10-20 ay lampas sa end.
        $this->assertEquals(9.0, $rows['c6']['spend']);

        $res = $this->actingAs($this->user('CEO', 'ceo2@example.test'))->getJson('/ads_manager/campaigns/data?' . http_build_query([
            'level' => 'adsets', 'start_date' => '2026-10-01', 'end_date' => '2026-10-10', 'page_name' => 'page tres', 'campaign_id' => 'c3',
        ]))->assertOk();
        $sets = collect($res->json('rows'))->keyBy('ad_set_id');
        $this->assertSame(['s3a' => false, 's3b' => true], $sets->map(fn ($r) => $r['on'])->sortKeys()->all());

        $res = $this->actingAs($this->user('CEO', 'ceo3@example.test'))->getJson('/ads_manager/campaigns/data?' . http_build_query([
            'level' => 'campaigns', 'start_date' => '2026-10-01', 'end_date' => '2026-10-10', 'page_name' => 'PAGE UNO',
        ]))->assertOk();
        $rows = collect($res->json('rows'))->keyBy('campaign_id');
        $this->assertEquals(140.0, $rows['c1']['spend']);
        $this->assertEquals(30.0, $rows['c2']['spend']);
    }

    /** Ang hugis ng SQL: hindi LOWER(TRIM(page_name)) at walang latest-day GROUP BY sa buong table. */
    public function test_no_trimmed_page_filter_and_no_full_table_group_by(): void
    {
        $user = $this->user();
        $calls = [
            fn () => $this->actingAs($user)->postJson('/ads_manager/campaigns/batch-data', [
                'end_date' => '2026-10-10', 'pages' => [['name' => 'PAGE UNO', 'start_date' => '2026-10-01'], ['name' => 'page tres', 'start_date' => '2026-10-01']],
            ])->assertOk(),
            fn () => $this->actingAs($user)->getJson('/ads_manager/campaigns/data?level=campaigns&start_date=2026-10-01&end_date=2026-10-10&page_name=page%20tres&include_windows=1')->assertOk(),
            fn () => $this->actingAs($user)->getJson('/ads_manager/campaigns/data?level=adsets&start_date=2026-10-01&end_date=2026-10-10&page_name=page%20tres&campaign_id=c3')->assertOk(),
            fn () => $this->actingAs($user)->getJson('/ads_manager/campaigns/data?level=ads&start_date=2026-10-01&end_date=2026-10-10&page_name=page%20tres&campaign_id=c3&ad_set_id=s3b')->assertOk(),
        ];
        $sql = [];
        DB::listen(function ($q) use (&$sql) { $sql[] = $q->sql; });
        foreach ($calls as $i => $call) {
            $sql = [];
            $call();
            $all = implode("\n", $sql);
            $this->assertTrue(str_contains($all, 'ads_manager_reports'), "call $i ran no report query");
            $this->assertSame(0, preg_match('/lower\(trim\(page_name\)\)/i', $all), "call $i: LOWER(TRIM(page_name)) page filter");
            // Ang latest-day subquery ay may WHERE (mga id sa resulta), hindi buong table.
            $this->assertSame(0, preg_match('/from\s+["`]?ads_manager_reports["`]?\s+group\s+by/i', $all), "call $i: full-table GROUP BY");
        }
    }
}
