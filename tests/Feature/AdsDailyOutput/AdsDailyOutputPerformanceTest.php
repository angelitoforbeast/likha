<?php

namespace Tests\Feature\AdsDailyOutput;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Spec 266 S3: the page reads live tables, so every query is a plain SELECT, ads_manager_reports is always read by a
 * `day` range (the first column of its unique index): D-1..D for the day and D-14..D for the 14-day table, grouped in
 * SQL; and a month of live volume renders in under 2 seconds.
 */
class AdsDailyOutputPerformanceTest extends AdsDailyOutputTestCase
{
    private const TABLES = ['ads_manager_reports', 'ad_catalog', 'page_item_settings', 'daily_page_primary_item', 'page_settings'];

    /** @return array<int, array{sql: string, bindings: array}> the request's queries that touch the page's tables */
    private function pageQueries(string $url): array
    {
        $user = $this->user('CEO');
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            foreach (self::TABLES as $t) {
                if (str_contains($q->sql, '"'.$t.'"') || str_contains($q->sql, "'".$t."'")) {
                    $queries[] = ['sql' => $q->sql, 'bindings' => $q->bindings];

                    return;
                }
            }
        });
        $this->actingAs($user)->get($url)->assertOk();

        return $queries;
    }

    private function seedSmall(): void
    {
        $c = ['c1', 'Page A | SIDEMIRROR | FB'];
        $s = ['s1', 'Set 1'];
        $this->report('2026-11-11', 'Page A', $c, $s, 'a1', 100, 500);
        $this->report('2026-11-12', 'Page A', $c, $s, 'a1', 0, 600);
        $this->report('2026-11-12', 'Page A', $c, $s, 'a2', 50, 600);
        $this->report('2026-11-08', 'Page A', $c, $s, 'a3', 20);
        $this->report('2026-11-09', 'Page A', $c, $s, 'a3', 0);
        $this->catalog('a2', '2026-11-12', 'Page A', $c, $s);
        $this->item('Page A', '1 x SIDE MIRROR', '199', '2026-11-01');
        $this->primary('2026-11-12', 'Page A', '1 x SIDE MIRROR');
        $this->picture('Page A', 'https://botcake.pro/i/a.jpg', '2026-11-01 09:00:00');
    }

    public function test_S3_1_every_query_is_a_plain_select_and_reports_are_read_by_a_day_range(): void
    {
        $this->seedSmall();

        $queries = $this->pageQueries(self::URL);
        $other = $this->pageQueries(self::URL.'?day=2026-11-09');

        foreach (array_merge($queries, $other) as $q) {
            $this->assertMatchesRegularExpression('/^\s*select\b/i', $q['sql'], 'only SELECT: '.$q['sql']);
            $this->assertDoesNotMatchRegularExpression('/\b(create|insert|update|delete|drop|alter|replace|temporary)\b/i', $q['sql']);
        }

        // Data: the day's ads, the 14-day spend moves, the 14-day budget moves, ad_catalog, page_item_settings, and
        // (spec 268) daily_page_primary_item and page_settings. Plus the table checks. The same number for another day.
        $isSchema = fn ($q) => str_contains($q['sql'], 'sqlite_master') || str_contains($q['sql'], 'pragma') || str_contains($q['sql'], 'information_schema');
        $data = array_values(array_filter($queries, fn ($q) => ! $isSchema($q)));
        $this->assertCount(7, $data, implode("\n", array_column($data, 'sql')));
        $this->assertCount(count($queries), $other);

        // Spec 268: the main items are read for the listed pages by page_key and a bounded ts_date range (D-14..D,
        // the lookback when a day has no row yet); the pictures by provider, key, page and time.
        $primary = array_values(array_filter($data, fn ($q) => str_contains($q['sql'], '"daily_page_primary_item"')));
        $this->assertCount(1, $primary);
        $this->assertStringContainsString('"page_key" in (?)', $primary[0]['sql']);
        $this->assertStringContainsString('"ts_date" between ? and ?', $primary[0]['sql']);
        $this->assertSame(['page a', '2026-10-29', '2026-11-12'], $primary[0]['bindings']);
        $pictures = array_values(array_filter($data, fn ($q) => str_contains($q['sql'], '"page_settings"')));
        $this->assertCount(1, $pictures);
        $this->assertSame(['chatbot', 'picture', false, 'page a', '2026-11-12 19:00:00', '2026-11-11 23:59:59'], $pictures[0]['bindings']);
        foreach ([[$primary[0], 'daily_page_primary_item'], [$pictures[0], 'page_settings']] as [$q, $table]) {
            $plan = collect(DB::select('EXPLAIN QUERY PLAN '.$q['sql'], $q['bindings']))->pluck('detail')->implode("\n");
            $this->assertStringContainsString('SEARCH '.$table.' USING INDEX', $plan);
            $this->assertStringNotContainsString('SCAN '.$table, $plan);
            fwrite(STDERR, "\n[268 S3] EXPLAIN QUERY PLAN (sqlite):\n".$plan."\n");
        }

        // Every read of ads_manager_reports is bounded by day: D-1..D, and D-13..D with D-14..D-1 for the table.
        $reports = array_values(array_filter($data, fn ($q) => str_contains($q['sql'], '"ads_manager_reports"')));
        $this->assertCount(3, $reports);
        foreach ($reports as $q) {
            $whole = substr_count($q['sql'], 'from "ads_manager_reports"');
            $this->assertSame($whole, substr_count($q['sql'], '"day" between ? and ?'), $q['sql']);
            $this->assertStringContainsString('group by', $q['sql']);
        }
        $this->assertSame(['2026-11-11', '2026-11-12'], array_slice($reports[0]['bindings'], -3, 2));
        foreach ([1, 2] as $i) {
            $b = $reports[$i]['bindings'];
            $this->assertContains('2026-10-30', $b); // first day of the window
            $this->assertContains('2026-10-29', $b); // its "yesterday"
            $this->assertNotContains('2026-10-28', $b);
            $this->assertNotContains('2026-11-13', $b);
        }

        // The plan: each read of ads_manager_reports is a range search on the day index, never a full scan.
        foreach ($reports as $q) {
            $plan = collect(DB::select('EXPLAIN QUERY PLAN '.$q['sql'], $q['bindings']))->pluck('detail')->implode("\n");
            $this->assertSame(
                substr_count($q['sql'], 'from "ads_manager_reports"'),
                preg_match_all('/SEARCH ads_manager_reports USING INDEX ads_report_day_campaign_adset_ad_unique \(day>\? AND day<\?\)/', $plan),
                $plan,
            );
            $this->assertStringNotContainsString('SCAN ads_manager_reports', $plan);
            fwrite(STDERR, "\n[266 S3.1] EXPLAIN QUERY PLAN (sqlite):\n".$plan."\n");
        }
    }

    public function test_S3_2_a_month_of_live_volume_renders_in_under_two_seconds(): void
    {
        // Live, 2026-11-12: ~680-740 rows a day, ~70% with 0 spend, 184 ads running on 66 pages. Here: 30 days x 740
        // ads (22,200 rows), spend switching on and off often (more moves than live), 3,000 ad_catalog rows and
        // 66 pages x 5 items x 6 promo dates.
        $rows = [];
        $flush = function () use (&$rows) {
            foreach (array_chunk($rows, 500) as $c) {
                DB::table('ads_manager_reports')->insert($c);
            }
            $rows = [];
        };
        DB::beginTransaction();
        for ($day = 0; $day < 30; $day++) {
            $d = Carbon::parse('2026-10-14')->addDays($day)->toDateString();
            for ($a = 0; $a < 740; $a++) {
                $page = 'Page '.($a % 66);
                $rows[] = [
                    'day' => $d, 'page_name' => $page, 'campaign_id' => 'c'.($a % 250), 'campaign_name' => $page.' | SALES VID 1-1-3 | 1000 | ITEM'.($a % 5).' | FB',
                    'ad_set_id' => 's'.($a % 400), 'ad_set_name' => 'Set '.($a % 400), 'ad_id' => 'ad'.$a,
                    'ad_set_budget' => 500 + (($a + $day) % 7 === 0 ? 100 : 0), 'ad_set_budget_type' => 'daily',
                    'amount_spent_php' => (($a * 7 + $day) % 10) < 3 ? 150.5 : 0, 'results' => 0,
                    'created_at' => $d.' 18:46:00', 'updated_at' => $d.' 18:46:00',
                ];
            }
            $flush();
        }
        $catalog = [];
        for ($a = 0; $a < 3000; $a++) {
            $catalog[] = [
                'ad_id' => 'ad'.$a, 'first_started' => Carbon::parse('2026-08-15')->addDays($a % 90)->toDateString(),
                'page_name' => 'Page '.($a % 66), 'campaign_id' => 'c'.($a % 250), 'campaign_name' => 'Page '.($a % 66).' | SALES VID 1-1-3 | 1000 | ITEM'.($a % 5).' | FB',
                'ad_set_id' => 's'.($a % 400), 'ad_set_name' => 'Set '.($a % 400),
            ];
        }
        foreach (array_chunk($catalog, 500) as $c) {
            DB::table('ad_catalog')->insert($c);
        }
        $items = [];
        for ($p = 0; $p < 66; $p++) {
            for ($i = 0; $i < 5; $i++) {
                for ($e = 0; $e < 6; $e++) {
                    $items[] = ['page_name' => 'Page '.$p, 'item_name' => '1 x ITEM'.$i, 'promo' => 'PROMO '.$i, 'rts_pct' => 20,
                        'effective_date' => Carbon::parse('2026-06-01')->addDays($e * 30)->toDateString()];
                }
            }
        }
        foreach (array_chunk($items, 500) as $c) {
            DB::table('page_item_settings')->insert($c);
        }
        // Spec 268: a main item per page and day (66 x 30) and three main flow pictures per page (two replaced).
        $primary = [];
        for ($day = 0; $day < 30; $day++) {
            for ($p = 0; $p < 66; $p++) {
                $primary[] = ['ts_date' => Carbon::parse('2026-10-14')->addDays($day)->toDateString(), 'page_label' => 'Page '.$p, 'page_key' => 'page '.$p,
                    'primary_item' => '1 x ITEM'.($p % 5), 'primary_item_key' => 'item'.($p % 5), 'primary_orders' => 10, 'total_orders_all' => 12];
            }
        }
        foreach (array_chunk($primary, 500) as $c) {
            DB::table('daily_page_primary_item')->insert($c);
        }
        for ($p = 0; $p < 66; $p++) {
            $this->picture('Page '.$p, 'https://botcake.pro/i/'.$p.'-1.jpg', '2026-09-01 10:00:00', '2026-10-01 10:00:00');
            $this->picture('Page '.$p, 'https://botcake.pro/i/'.$p.'-2.jpg', '2026-10-01 10:00:00', '2026-11-12 11:05:57');
            $this->picture('Page '.$p, 'https://botcake.pro/i/'.$p.'-3.jpg', '2026-11-12 11:05:57');
        }
        DB::commit();
        $this->assertSame(22200, DB::table('ads_manager_reports')->count());

        $user = $this->user('CEO');
        $this->actingAs($user)->get(self::URL)->assertOk(); // warm-up: view compile and the layout's first queries

        $start = microtime(true);
        $response = $this->actingAs($user)->get(self::URL);
        $seconds = microtime(true) - $start;

        $response->assertOk();
        $this->assertCount(14, $response->viewData('mini'));
        $this->assertGreaterThan(100, $response->viewData('tiles')['running_ads']);
        $this->assertSame(66, $response->viewData('tiles')['running_pages']);
        $campaigns = 0;
        foreach ($response->viewData('lists') as $pages) {
            foreach ($pages as $byCampaign) {
                foreach ($byCampaign as $name => $c) {
                    $campaigns++;
                    $this->assertSame('PROMO '.preg_replace('/^.*\| ITEM(\d) \|.*$/', '$1', $name), $c['promo']);
                    $this->assertMatchesRegularExpression('#^https://botcake\.pro/i/\d+-[23]\.jpg$#', (string) $c['picture']);
                }
            }
        }
        $this->assertGreaterThan(50, $campaigns);
        fwrite(STDERR, sprintf("\n[266 S3.2] %d report rows: page rendered in %.3f s\n", DB::table('ads_manager_reports')->count(), $seconds));
        $this->assertLessThan(2.0, $seconds);
    }
}
