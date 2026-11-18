<?php

namespace Tests\Feature\CPP;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Chatblast\ChatblastTestCase;

/**
 * Spec 291: /ads_manager/cpp/timeline "Hourly totals" view. Rows = date, columns = hour 00–23 (Manila), each cell the
 * day's cumulative total Adspent, Orders and CPP: the Hourly view's Total row for that date, from the same code.
 */
class CppHourlyTotalsTest extends ChatblastTestCase
{
    private const DAY  = '2026-11-09';
    private const PREV = '2026-11-08';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::table('macro_output', function (Blueprint $t) {
            $t->string('TIMESTAMP')->nullable();
            $t->string('STATUS')->nullable();
        });
        Schema::create('ads_manager_reports', function (Blueprint $t) {
            $t->id();
            $t->date('day')->nullable();
            $t->string('page_name');
            $t->string('ad_id')->nullable();
            $t->decimal('amount_spent_php', 12, 2)->nullable();
            $t->timestamps();
        });
        Schema::create('upload_logs', function (Blueprint $t) {
            $t->id();
            $t->string('type');
            $t->timestamps();
        });
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_05_13_120000_create_cpp_snapshots_table.php']]);
        $this->travelTo(Carbon::parse('2026-11-10 09:00:00', 'Asia/Manila'));
        $this->actingAs($this->user('Marketing', 'mk@example.test'));
    }

    private function hourRow(string $page, int $h, float $spend, ?float $cpi = null, ?float $cpm = null, string $day = self::DAY, string $at = '23:10:00'): void
    {
        DB::table('cpp_snapshots')->insert([
            'snapshot_date' => $day, 'snapshot_bucket' => sprintf('H%02d', $h), 'snapshot_at' => $day . ' ' . $at,
            'page_name' => $page, 'page_key' => strtolower(str_replace(' ', '', $page)), 'amount_spent' => $spend,
            'orders' => 0, 'proceed_orders' => 0, 'cpi' => $cpi, 'cpm' => $cpm, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function sale(string $page, string $hm, string $day = self::DAY, ?string $ts = null): void
    {
        DB::table('macro_output')->insert(['PAGE' => $page, 'TIMESTAMP' => $ts ?? ($hm . ' ' . Carbon::parse($day)->format('d-m-Y')),
            'ts_date' => $day, 'STATUS' => 'PROCEED', 'ITEM_NAME' => 'Tripod', 'COD' => '499']);
    }

    private function spendInReport(string $page, string $day = self::DAY, float $amount = 500): void
    {
        DB::table('ads_manager_reports')->insert(['day' => $day, 'page_name' => $page, 'ad_id' => 'ad-' . uniqid('', true), 'amount_spent_php' => $amount, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Two days with the cases the rules care about: spend carried over an hour without a row, a page with hourly spend
     * but no report spend (Unknown ad), a page spelled differently in each table, an orders-only page, an organic page,
     * a page whose report spend is 0, and order times the parser must skip.
     */
    private function seedTwoDays(): void
    {
        $this->spendInReport('Page P1');
        $this->spendInReport('Page P2');
        $this->spendInReport('Page  Q');
        $this->spendInReport('Zero Page', self::DAY, 0);
        $this->hourRow('Page P1', 9, 100, 100, 20);
        $this->hourRow('Page P1', 10, 180, 100, 22.5);
        $this->hourRow('Page P1', 12, 250, 125, 25);            // no H11 row: 11 carries 10's figures
        for ($h = 13; $h < 24; $h++) $this->hourRow('Page P1', $h, 260, 130, 26);
        $this->hourRow('Unknown ad', 10, 30, 50, null, self::DAY, '23:12:00');
        $this->hourRow('page q', 8, 40, 80, 10);
        $this->sale('Page P1', '7:30');                          // one-digit hour
        $this->sale('Page P1', '09:15');
        $this->sale('Page P1', '09:40');
        $this->sale('PAGE P1', '10:05');                         // other spelling, same page
        $this->sale('Page P2', '11:30');
        $this->sale('Page P2', '', self::DAY, ' 13:00 ' . Carbon::parse(self::DAY)->format('d-m-Y') . ' ');
        $this->sale('PageQ', '08:20');
        $this->sale('Organic Page', '09:00');                    // no ad spend: not counted
        $this->sale('Zero Page', '09:00');                       // report spend 0: not counted
        $this->sale('Page P1', '', self::DAY, '25:00 09-11-2026'); // no such hour
        $this->sale('Page P1', '', self::DAY, '10:00 08-11-2026'); // ts_date and TIMESTAMP disagree
        $this->sale('Page P1', '', self::DAY, 'garbage');

        $this->spendInReport('Page P1', self::PREV);
        $this->hourRow('Page P1', 0, 10, 100, 5, self::PREV);
        $this->hourRow('Page P1', 23, 900, 110, 30, self::PREV);
        $this->sale('Page P1', '00:10', self::PREV);
        $this->sale('Page P1', '22:59', self::PREV);
    }

    /**
     * The Hourly endpoint's output did not change when its rules moved to shared code: the fixtures were recorded from
     * the code before spec 291 on this same data (a past day with every case, the day before, an empty day, and the
     * first day as "today" at 10:20).
     */
    public function test_the_hourly_endpoint_output_is_byte_for_byte_unchanged(): void
    {
        $this->seedTwoDays();
        foreach ([self::DAY, self::PREV, '2026-11-07'] as $d) {
            $this->assertSame(file_get_contents(__DIR__ . "/fixtures/291-hourly-golden-$d.json"),
                $this->get('/ads_manager/cpp/timeline/hourly?date=' . $d)->assertOk()->getContent(), $d);
        }
        $this->travelTo(Carbon::parse(self::DAY . ' 10:20:00', 'Asia/Manila'));
        $this->assertSame(file_get_contents(__DIR__ . '/fixtures/291-hourly-golden-today.json'),
            $this->get('/ads_manager/cpp/timeline/hourly?date=' . self::DAY)->assertOk()->getContent(), 'today');
    }

    private function totals(string $qs = ''): array
    {
        return $this->getJson('/ads_manager/cpp/timeline/hourly-totals' . ($qs !== '' ? '?' . $qs : ''))->assertOk()->json();
    }

    /** HT-1: every cell of every date equals the Hourly view's Total row for that date and hour. */
    public function test_HT1_each_row_is_the_hourly_total_row_of_that_date(): void
    {
        $this->seedTwoDays();

        $j = $this->totals('start=2026-11-07&end=' . self::DAY);

        $this->assertSame([self::DAY, self::PREV, '2026-11-07'], array_column($j['days'], 'date'), 'newest date on top, as the bucket grid');
        $this->assertSame(['Nov 9 Mon', 'Nov 8 Sun', 'Nov 7 Sat'], array_column($j['days'], 'label'));
        foreach ($j['days'] as $row) {
            $hourly = $this->getJson('/ads_manager/cpp/timeline/hourly?date=' . $row['date'])->json();
            $this->assertCount(24, $row['total']);
            $this->assertSame($hourly['total'], $row['total'], $row['date']);
            $this->assertSame($hourly['last_hour'], $row['last_hour']);
        }

        // Hour 15 of the seeded day: P1 ₱260 + page q ₱40 + Unknown ad ₱30 = ₱330; orders P1 4 + P2 2 + page q 1 = 7.
        $this->assertEquals(['spend' => 330, 'orders' => 7, 'cpp' => 47.14, 'source' => 'meta'],
            array_intersect_key($j['days'][0]['total'][15], array_flip(['spend', 'orders', 'cpp', 'source'])));
        $this->assertSame('2026-11-09 23:12:00', $j['stored_at'], 'the latest stored hourly row in the range');
        $this->assertSame([range(0, 23), false, null, false], [$j['hours'], $j['clamped'], $j['today'], $j['stale']]);
    }

    public function test_HT1_orders_without_hourly_spend_show_the_orders_and_no_spend_or_cpp(): void
    {
        $this->seedTwoDays();
        // A day with report spend and orders but no hourly spend stored (Meta's hourly breakdown blocked or not synced).
        $this->spendInReport('Page P1', '2026-11-07');
        $this->sale('Page P1', '10:30', '2026-11-07');

        $j = $this->totals('start=2026-11-07&end=' . self::DAY);

        $blocked = $j['days'][2]['total'];
        $this->assertSame(array_fill(0, 10, null), array_slice($blocked, 0, 10));
        foreach (array_slice($blocked, 10) as $c) {
            $this->assertEquals(['spend' => null, 'orders' => 1, 'cpp' => null, 'cpi' => null, 'cpm' => null, 'source' => 'orders'],
                array_intersect_key($c, array_flip(['spend', 'orders', 'cpp', 'cpi', 'cpm', 'source'])));
        }
        // Before the first stored hour of the seeded day: orders only, no CPP.
        $this->assertEquals(['spend' => null, 'orders' => 1, 'cpp' => null, 'source' => 'orders'],
            array_intersect_key($j['days'][0]['total'][7], array_flip(['spend', 'orders', 'cpp', 'source'])));
    }

    public function test_HT1_a_date_without_data_still_has_its_row(): void
    {
        $this->seedTwoDays();

        $j = $this->totals('start=2026-11-05&end=' . self::DAY);

        $this->assertSame([self::DAY, self::PREV, '2026-11-07', '2026-11-06', '2026-11-05'], array_column($j['days'], 'date'));
        $this->assertSame(array_fill(0, 24, null), $j['days'][4]['total']);
        $this->assertSame(23, $j['days'][4]['last_hour']);
    }

    public function test_HT1_today_leaves_the_hours_after_the_current_manila_hour_empty(): void
    {
        $this->travelTo(Carbon::parse(self::DAY . ' 10:20:00', 'Asia/Manila'));
        $this->seedTwoDays();

        $j = $this->totals('start=' . self::PREV . '&end=' . self::DAY);

        $today = $j['days'][0];
        $this->assertSame([self::DAY, 10], [$today['date'], $today['last_hour']]);
        $this->assertEquals(['spend' => 250, 'orders' => 5, 'cpp' => 50], array_intersect_key($today['total'][10], array_flip(['spend', 'orders', 'cpp'])),
            'the current hour shows what is stored so far');
        $this->assertSame(array_fill(0, 13, null), array_slice($today['total'], 11));
        $this->assertSame($this->getJson('/ads_manager/cpp/timeline/hourly?date=' . self::DAY)->json('total'), $today['total']);
        $this->assertSame(23, $j['days'][1]['last_hour'], 'yesterday is complete');
    }

    public function test_HT1_the_default_range_is_the_7_days_ending_today_like_the_buckets(): void
    {
        $j = $this->totals();

        $this->assertSame(['2026-11-04', '2026-11-10'], [$j['start'], $j['end']]);
        $this->assertSame(['2026-11-10', '2026-11-09', '2026-11-08', '2026-11-07', '2026-11-06', '2026-11-05', '2026-11-04'], array_column($j['days'], 'date'));
        $this->assertSame('Nov 10 Tue', $j['days'][0]['label']);
        $this->assertSame([9, 23], [$j['days'][0]['last_hour'], $j['days'][1]['last_hour']]);

        $this->assertSame(['2026-11-03', '2026-11-09'], [($r = $this->totals('end=2026-11-09'))['start'], $r['end']], 'start defaults to end − 6');
        $this->assertSame(['2026-11-01', '2026-11-05'], [($r = $this->totals('start=2026-11-05&end=2026-11-01'))['start'], $r['end']], 'a reversed range is swapped, as the buckets do');
    }

    public function test_HT1_a_range_over_31_days_is_clamped_to_the_31_days_ending_at_end(): void
    {
        $j = $this->totals('start=2026-09-01&end=2026-11-09');
        $this->assertSame(['2026-10-10', '2026-11-09', true, 31], [$j['start'], $j['end'], $j['clamped'], $j['max_days']]);
        $this->assertCount(31, $j['days']);
        $this->assertSame(['2026-11-09', '2026-10-10'], [$j['days'][0]['date'], $j['days'][30]['date']]);

        $j = $this->totals('start=2026-10-10&end=2026-11-09');
        $this->assertSame(['2026-10-10', false], [$j['start'], $j['clamped']], 'exactly 31 days is not clamped');
        $this->assertCount(31, $j['days']);
    }

    public function test_HT1_invalid_dates_are_refused(): void
    {
        foreach (['start=2026-13-01', 'end=abc', 'start=2026-02-30', 'end=2026-1-5', 'start=2026-11-01%27%20OR%201=1', 'end=2026-11-09T00:00', 'start[]=2026-11-01'] as $qs) {
            $this->getJson('/ads_manager/cpp/timeline/hourly-totals?' . $qs)->assertStatus(422)->assertJson(['ok' => false]);
        }
        $this->assertSame('Invalid start date: use YYYY-MM-DD.', $this->getJson('/ads_manager/cpp/timeline/hourly-totals?start=2026-02-30')->json('message'));
    }

    public function test_HT1_the_route_has_the_timeline_permission(): void
    {
        $this->actingAs($this->user('Data Encoder', 'de@example.test'));
        $this->getJson('/ads_manager/cpp/timeline/hourly-totals')->assertNotFound();
    }

    /** HT-3: today's hourly spend older than 2 hours is flagged, so a blocked or failing sync shows. */
    public function test_HT3_stale_hourly_spend_today_is_flagged(): void
    {
        $today = '2026-11-10';
        $this->spendInReport('Page P1', $today);
        $this->hourRow('Page P1', 9, 50, 100, 10, $today, '10:10:00');

        $this->travelTo(Carbon::parse("$today 12:05:00", 'Asia/Manila'));
        $this->assertSame([$today, '2026-11-10 10:10:00', false], array_values(array_intersect_key($this->totals(), array_flip(['today', 'today_stored_at', 'stale']))), '1 h 55 min old');

        $this->travelTo(Carbon::parse("$today 12:15:00", 'Asia/Manila'));
        $j = $this->totals();
        $this->assertTrue($j['stale'], '2 h 5 min old');
        $this->assertSame('2026-11-10 10:10:00', $j['stored_at']);

        $this->assertFalse($this->totals('start=2026-11-01&end=2026-11-09')['stale'], 'a range without today is never flagged');

        DB::table('cpp_snapshots')->delete();
        $this->travelTo(Carbon::parse("$today 01:30:00", 'Asia/Manila'));
        $this->assertFalse($this->totals()['stale'], 'nothing stored yet at 01:30');
        $this->travelTo(Carbon::parse("$today 02:30:00", 'Asia/Manila'));
        $this->assertSame([null, true], [($j = $this->totals())['today_stored_at'], $j['stale']], 'nothing stored today by 02:30');
    }

    /**
     * MySQL counts orders per (date, page, hour) in the query instead of reading every order row. That query, run here
     * with sqlite standing in for MySQL's REGEXP and SUBSTRING_INDEX, gives the same figures as the PHP parse path.
     */
    public function test_the_mysql_orders_query_gives_the_same_figures_as_the_php_path(): void
    {
        $this->seedTwoDays();
        $pdo = DB::connection()->getPdo();
        $pdo->sqliteCreateFunction('REGEXP', fn ($pattern, $value) => $value !== null && preg_match('/' . str_replace('/', '\/', $pattern) . '/u', (string) $value) ? 1 : 0, 2);
        $pdo->sqliteCreateFunction('SUBSTRING_INDEX', function ($s, $delim, $count) {
            $parts = explode($delim, (string) $s);
            return implode($delim, $count >= 0 ? array_slice($parts, 0, $count) : array_slice($parts, $count));
        }, 3);
        $dates = ['2026-11-10', self::DAY, self::PREV, '2026-11-07'];

        $queries = [];
        DB::listen(function ($q) use (&$queries) { $queries[] = $q->sql; });
        $inSql = (new \App\Services\MetaAds\HourlyTimeline(Carbon::now('Asia/Manila'), true))->days($dates);
        $reads = array_values(array_filter($queries, fn ($s) => preg_match('/from "(cpp_snapshots|ads_manager_reports|macro_output)"/', $s)));
        $php = (new \App\Services\MetaAds\HourlyTimeline(Carbon::now('Asia/Manila'), false))->days($dates);

        $this->assertSame($php, $inSql);
        $this->assertNotNull($inSql[self::DAY]['total'][15]);
        $this->assertCount(3, $reads, 'one query each for spend, ad-spend pages and orders over the whole range');
        $this->assertStringContainsString('group by ts_date, `PAGE`, SUBSTRING_INDEX(TRIM(`TIMESTAMP`), \':\', 1)', $reads[2]);
        $this->assertStringContainsString('"ts_date" between ? and ?', $reads[2]);
    }

    /** HT-2: the page has the third view, 24 hour columns and the fixed layout that fits a 1280 px window. */
    public function test_HT2_the_page_renders_the_hourly_totals_view_that_fits_without_page_scroll(): void
    {
        $html = $this->get('/ads_manager/cpp/timeline')->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<div class="flex items-center gap-2 mb-3" id="tl-view-toggle">(.*?)<span class="tl-legend#s', $html, $toggle));
        preg_match_all('#>(Buckets|Hourly|Hourly totals)</button>#', $toggle[1], $buttons);
        $this->assertSame(['Buckets', 'Hourly', 'Hourly totals'], $buttons[1]);
        $this->assertStringContainsString("@click=\"setView('totals')\"", $toggle[1]);

        $this->assertSame(1, preg_match('#<div class="tl-tot-wrap">\s*<table class="tl-hour tl-tot" id="tl-tot-table">(.*?)</table>#s', $html, $t));
        // Spec 297: the columns read "as of" 1AM … 12AM (were 00 … 23).
        preg_match_all('#<th class="tl-tot-h"[^>]*>(\d{1,2}[AP]M)</th>#', $t[1], $hours);
        $this->assertSame(['1AM', '2AM', '3AM', '4AM', '5AM', '6AM', '7AM', '8AM', '9AM', '10AM', '11AM', '12PM',
            '1PM', '2PM', '3PM', '4PM', '5PM', '6PM', '7PM', '8PM', '9PM', '10PM', '11PM', '12AM'], $hours[1]);
        $this->assertSame(1, substr_count($t[1], '<col class="tl-tot-date">'));
        $this->assertSame(24, substr_count($t[1], '<col class="tl-tot-col">'));

        $css = preg_replace('/\s+/', ' ', $html);
        $this->assertStringContainsString('.tl-hour { table-layout: fixed; width: 100%;', $css, 'the totals table takes the hourly table\'s fixed layout');
        $this->assertStringContainsString('.tl-tot-wrap { width: 100%; overflow-x: auto; }', $css, 'below the min-width only the wrapper scrolls');
        // Spec 292 moved 30 px from the date column to the label column and raised the minimum so a 7-character figure
        // is never cut; spec 297 set it to 43 px per hour: the arithmetic is in test_HL1_the_label_column_and_the_width_at_1280_px.
        $this->assertStringContainsString('.tl-tot { min-width: 1122px; }', $css);
        $this->assertStringContainsString('.tl-tot col.tl-tot-date { width: 46px; }', $css);
        $box = 1280 - 17 - 32 - 32 - 2;
        $this->assertGreaterThanOrEqual(1122, $box);
    }

    /**
     * HL-1: one label column between the date and hour 00, once per date row, its three lines in the order of the
     * cell's three figures; the 24 hours still fit a 1280 px window.
     */
    public function test_HL1_the_label_column_and_the_width_at_1280_px(): void
    {
        $html = $this->get('/ads_manager/cpp/timeline')->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<table class="tl-hour tl-tot" id="tl-tot-table">(.*?)</table>#s', $html, $t));

        // colgroup: date, labels, then the 24 hours.
        $this->assertSame(1, preg_match('#<colgroup>\s*<col class="tl-tot-date">\s*<col class="tl-tot-lbl">\s*(<col class="tl-tot-col">){24}\s*</colgroup>#', $t[1]));
        // Header: Date, the (empty) label header, 1AM … 12AM (spec 297; was 00 … 23).
        $this->assertSame(1, preg_match('#<tr>\s*<th class="tl-hour-name">Date</th>\s*<th class="tl-tot-lbls"[^>]*></th>\s*(?:\{\{--.*?--\}\}\s*)?<th class="tl-tot-h"[^>]*>1AM</th>#s', $t[1]));
        // The row template (x-for over the dates): one label cell, written once, with the three labels in cell order,
        // then the hour cells, whose figures carry no label.
        $this->assertSame(1, preg_match('#<template x-for="row in totals.days" :key="row.date">(.*?)</template>\s*<template x-if="!totalsLoading#s', $t[1], $row));
        $this->assertSame(1, substr_count($row[1], 'class="tl-tot-lbls"'));
        $this->assertSame(1, preg_match('#<th class="tl-tot-lbls"><div title="Adspent \(₱\)">Adspent</div><div>Orders</div><div title="CPP \(₱\)">CPP</div></th>#u', $row[1]));
        $this->assertSame(1, preg_match('#<div x-text="figSpend\(c\)"></div>\s*<div x-text="figOrders\(c\)"></div>\s*<div x-text="figCpp\(c\)"></div>#', $row[1]));
        $this->assertStringNotContainsString('Adspent', preg_replace('#<th class="tl-tot-lbls">.*?</th>#s', '', $row[1]), 'no label inside the hour cells');
        $this->assertStringContainsString('colspan="26"', $t[1], 'the empty-range row spans date + labels + 24 hours');

        // The label lines and the figure lines share font size and line height, so each label sits on its figure's line.
        $css = preg_replace('/\s+/', ' ', $html);
        $this->assertStringContainsString('.tl-tot col.tl-tot-lbl { width: 44px; }', $css);
        $this->assertStringContainsString('.tl-tot th.tl-tot-lbls { font-size: 10px; font-weight: 600; color: #4b5563; text-align: left; padding: 2px 1px; line-height: 1.2; vertical-align: top;', $css);
        $this->assertStringContainsString('.tl-tot td { padding: 2px 0; line-height: 1.2; vertical-align: top; }', $css);
        $this->assertStringContainsString('.tl-hour td { font-family: \'SFMono-Regular\', Consolas, monospace; font-size: 10px;', $css);

        // Width (spec 297, full width with a 16 px gutter) at a 1280 px window without a sidebar: 1280 − 17 scroll bar
        // − 2×16 gutter − 2×16 card padding − 2 border = 1197 px. Date 46 + labels 44 = 90 px, so each of the 24 hours
        // gets (1197 − 90) / 24 = 46.1 px; with no side padding and the 1 px border a cell keeps 45.1 px, and a
        // 7-character figure is at most 7 × 6 px = 42 px of 10 px monospace (SFMono / Menlo 0.6 em; Consolas 0.55 em is
        // 38.5 px). The min-width keeps 43 px per hour (46 + 44 + 24 × 43 = 1122), 42 px inside the border.
        $box = 1280 - 17 - 32 - 32 - 2;
        $this->assertSame(1197, $box);
        $perHour = ($box - 46 - 44) / 24;
        $this->assertEqualsWithDelta(46.1, $perHour, 0.05);
        $this->assertGreaterThanOrEqual(7 * 6, $perHour - 1);
        $this->assertSame(1122, 46 + 44 + 24 * 43);
        $this->assertGreaterThanOrEqual(7 * 6, 43 - 1);
        $this->assertLessThanOrEqual($box, 1122);
    }

    /**
     * HL-2: Adspent in whole pesos and Orders with thousands separators, CPP with 2 decimals; a figure over 7 characters
     * takes the compact form for that value only. The 291 rules stay ("—" without hourly spend).
     */
    public function test_HL2_the_cell_figures_read_like_money_and_counts(): void
    {
        $out = $this->js('{}', <<<'JS'
            const cell = (spend, orders, cpp) => [c.figSpend({spend}), c.figOrders({orders}), c.figCpp({cpp})];
            out.normal = cell(42100.51, 1144, 36.8);
            out.small = cell(850.4, 3, 283.47);
            out.zero = cell(0, 0, null);
            out.ordersOnly = cell(null, 7, null);
            out.edge = cell(999999.49, 99999, 999.99);
            out.big = cell(1023456.78, 1234567, 1234.56);
            out.huge = cell(12345678.9, 5, 12345.67);
            out.hours = [0, 9, 11, 12, 15, 23].map(h => c.hourLabel(h));
            out.days = ['Nov 18 Wed', 'Nov 9 Mon'].map(l => c.dayLines(l));
            JS);

        $this->assertSame(['42,101', '1,144', '36.80'], $out['normal'], 'the Buckets figures: ₱42,100.51, 1,144 orders, ₱36.80');
        $this->assertSame(['850', '3', '283.47'], $out['small']);
        $this->assertSame(['0', '0', '—'], $out['zero']);
        $this->assertSame(['—', '7', '—'], $out['ordersOnly'], 'no hourly spend: "—" for spend and CPP, never a CPP from missing spend');
        $this->assertSame(['999,999', '99,999', '999.99'], $out['edge'], 'the longest figures that still fit (7 characters)');
        $this->assertSame(['1.02M', '1.23M', '1.2k'], $out['big'], 'over 7 characters: compact, for that value only');
        $this->assertSame(['12.35M', '5', '12.3k'], $out['huge']);
        $this->assertSame(['12AM', '9AM', '11AM', '12PM', '3PM', '11PM'], $out['hours']);
        $this->assertSame([['Nov 18', 'Wed'], ['Nov 9', 'Mon']], $out['days']);
        foreach (array_merge($out['normal'], $out['edge'], $out['big'], $out['huge']) as $s) {
            $this->assertLessThanOrEqual(7, mb_strlen($s), $s);
        }
    }

    /**
     * HL-3: a click on a filled cell opens the detail of that date and hour from the Hourly endpoint. Title, range,
     * totals equal to the cell, per-page rows that add up to them, sort, close; empty cells and bad input open nothing.
     */
    public function test_HL3_a_click_on_an_hour_cell_opens_its_detail(): void
    {
        $this->seedTwoDays();
        $totals = $this->getJson('/ads_manager/cpp/timeline/hourly-totals?start=' . self::PREV . '&end=' . self::DAY)->json();
        $hourly = $this->getJson('/ads_manager/cpp/timeline/hourly?date=' . self::DAY)->json();

        $out = $this->js(json_encode($hourly), <<<'JS'
            c.totals = { days: JSON.parse(JSON.stringify(TOTALS.days)) };
            const row = c.totals.days[0];
            out.cell = row.total[9];
            await c.openHour(row, 9);
            out.fetched = fetched.slice();
            out.open = c.hm.open; out.loading = c.hm.loading; out.error = c.hm.error;
            out.title = c.hmTitle(); out.range = c.hmRange(); out.line = c.hmTotalsLine();
            out.total = c.hm.total; out.rows = c.hmRows();
            out.cells = out.rows.map(r => [r.page].concat(c.hmCells(r)));
            out.foot = c.hmFoot();
            out.rowTotalAfter = row.total;
            c.sortHour(1); out.sorted1 = c.hmRows().map(r => r.page); out.aria1 = c.hmAria(1);
            c.sortHour(1); out.sorted2 = c.hmRows().map(r => r.page);
            c.closeHour(); out.closed = c.hm.open;

            await c.openHour(row, 11);
            out.h11 = { total: c.hm.total, rows: c.hmRows(), cells: c.hmRows().map(r => [r.page].concat(c.hmCells(r))), line: c.hmTotalsLine() };
            c.closeHour();

            const n = fetched.length;
            await c.openHour(row, 23);                       // filled (a past day)
            out.h23 = [c.hm.open, c.hmTitle(), c.hmRange(), c.hm.rows.length];
            c.closeHour();
            await c.openHour(row, 0);                        // empty cell: nothing opens, nothing is fetched
            await c.openHour(row, 24);
            await c.openHour(row, '9');
            await c.openHour(row, 9.5);
            await c.openHour({ date: "2026-11-09' OR 1=1", total: row.total }, 9);
            await c.openHour({ date: '2026-11-09' }, 9);
            out.badOpen = c.hm.open; out.badFetches = fetched.length - n - 1;
            JS, ['TOTALS' => json_encode($totals)]);

        $cell = $totals['days'][0]['total'][9];
        $this->assertSame(['/ads_manager/cpp/timeline/hourly?date=2026-11-09'], array_map(fn ($u) => parse_url($u, PHP_URL_PATH) . '?' . parse_url($u, PHP_URL_QUERY), $out['fetched']), 'the existing Hourly endpoint, no new query');
        $this->assertSame([true, false, ''], [$out['open'], $out['loading'], $out['error']]);
        // Spec 297: cell 9 is the column "as of 10AM" (00:00 – 9:59 AM); was "9AM" / "Cumulative 00:00 – 9:59 AM".
        $this->assertSame('Nov 9, 2026 · as of 10AM', $out['title']);
        $this->assertSame('00:00 – 9:59 AM', $out['range']);
        $this->assertEquals($cell, $out['total'], 'the modal total is the cell');
        // At 09: Page P1 ₱100 (H09) with 3 orders (7:30, 09:15, 09:40) and page q ₱40 (H08) with 1 (08:20). Page P2 has
        // no order before 11:30 and Unknown ad no spend before H10, so they are not in the table yet.
        $this->assertEquals(['spend' => 140, 'orders' => 4, 'cpp' => 35], array_intersect_key($cell, array_flip(['spend', 'orders', 'cpp'])));
        $this->assertSame('Adspent ₱140.00 · Orders 4 · CPP ₱35.00', $out['line']);
        $this->assertSame([['Page P1', '₱100.00', '3', '₱33.33', '₱100.00', '2'], ['page q', '₱40.00', '1', '₱40.00', '₱0.00', '0']], $out['cells']);
        $this->assertSame(['₱140.00', '4', '₱35.00', '₱100.00', '2'], $out['foot'], 'TOTAL row = the cell');
        $this->assertSame($totals['days'][0]['total'], $out['rowTotalAfter'], 'the row takes the same Total row the modal read');

        // At 11, with an orders-only page: the rows still add up to the cell, "—" for that page's spend and CPP.
        $c11 = $totals['days'][0]['total'][11];
        $this->assertEquals($c11, $out['h11']['total']);
        $this->assertEqualsWithDelta($c11['spend'], array_sum(array_map(fn ($r) => $r['spend'] ?? 0, $out['h11']['rows'])), 0.001, 'page spend adds up to the cell');
        $this->assertSame($c11['orders'], array_sum(array_column($out['h11']['rows'], 'orders')), 'page orders add up to the cell');
        $this->assertSame($c11['add_orders'], array_sum(array_column($out['h11']['rows'], 'add_orders')), 'what the hour added adds up too');
        $this->assertSame([
            ['Page P1', '₱180.00', '4', '₱45.00', '₱0.00', '0'],
            ['Page P2', '—', '1', '—', '—', '1'],
            ['page q', '₱40.00', '1', '₱40.00', '₱0.00', '0'],
            ['Unknown ad', '₱30.00', '0', '—', '₱0.00', '0'],
        ], $out['h11']['cells']);
        $this->assertSame('Adspent ₱250.00 · Orders 6 · CPP ₱41.67', $out['h11']['line']);

        // Sortable like the Buckets modal: Adspent high to low, then low to high.
        $this->assertSame('descending', $out['aria1']);
        $this->assertSame(['Page P1', 'page q'], $out['sorted1']);
        $this->assertSame(['page q', 'Page P1'], $out['sorted2']);
        $this->assertFalse($out['closed']);

        $this->assertSame([true, 'Nov 9, 2026 · as of 12AM', '00:00 – 11:59 PM'], array_slice($out['h23'], 0, 3));
        $this->assertSame([false, 0], [$out['badOpen'], $out['badFetches']], 'empty cell, hour 24, a string or fractional hour, a bad date: no modal, no fetch');
    }

    /**
     * HL-3, as of spec 297: today's last filled column ("as of 10AM" at 10:20) opens equal to its cell; the column of the
     * running hour ("as of 11AM") is empty and opens nothing. (In 292 this test opened the current hour 10 with what was
     * stored so far.)
     */
    public function test_HL3_todays_last_filled_column_opens_and_the_running_hour_does_not(): void
    {
        $this->travelTo(Carbon::parse(self::DAY . ' 10:20:00', 'Asia/Manila'));
        $this->seedTwoDays();
        $totals = $this->getJson('/ads_manager/cpp/timeline/hourly-totals?start=' . self::DAY . '&end=' . self::DAY)->json();
        $hourly = $this->getJson('/ads_manager/cpp/timeline/hourly?date=' . self::DAY)->json();
        $this->assertSame(10, $totals['days'][0]['asof_hours']);

        $out = $this->js(json_encode($hourly), <<<'JS'
            c.totals = { days: TOTALS.days };
            const row = c.totals.days[0];
            await c.openHour(row, 9);
            out.a = [c.hmTitle(), c.hm.total, c.hm.rows, c.hmRange()];
            c.closeHour();
            const n = fetched.length;
            await c.openHour(row, 10);
            out.current = [c.hm.open, fetched.length - n];
            JS, ['TOTALS' => json_encode($totals)]);

        $this->assertSame(['Nov 9, 2026 · as of 10AM', '00:00 – 9:59 AM'], [$out['a'][0], $out['a'][3]]);
        $this->assertEquals($totals['days'][0]['total'][9], $out['a'][1]);
        $this->assertEquals(['spend' => 140, 'orders' => 4, 'cpp' => 35], array_intersect_key($out['a'][1], array_flip(['spend', 'orders', 'cpp'])));
        $this->assertSame(4, array_sum(array_column($out['a'][2], 'orders')));
        $this->assertEqualsWithDelta(140, array_sum(array_map(fn ($r) => $r['spend'] ?? 0, $out['a'][2])), 0.001);
        $this->assertSame([false, 0], $out['current'], 'as of 11AM has not passed: empty cell, no modal, no fetch');
    }

    /** HL-3: the served markup: a click only on a filled cell, the Buckets modal's classes, and the three ways to close. */
    public function test_HL3_the_modal_markup_reuses_the_buckets_modal_and_closes_three_ways(): void
    {
        $html = $this->get('/ads_manager/cpp/timeline')->assertOk()->getContent();

        $this->assertStringContainsString('@click="c && openHour(row, h)"', $html, 'empty cells (c null) open nothing');
        $this->assertStringContainsString(':class="totClass(row, c, h) + (c ? \' tl-tot-click\' : \'\')"', $html);
        $this->assertSame(1, preg_match('#<template x-if="hm.open">\s*<div class="tl-modal-backdrop" id="tl-hm" @click.self="closeHour\(\)" @keydown.escape.window="closeHour\(\)">\s*<div class="tl-modal">\s*<div class="tl-modal-head">#', $html),
            'click outside and Esc close it; same classes as the snapshot modal');
        $this->assertStringContainsString('<button @click="closeHour()" class="tl-modal-close">✕ Close</button>', $html);
        $this->assertSame(1, preg_match('#<table class="tl-detail-table" id="tlHourTable">\s*<thead>\s*<tr>(.*?)</tr>#s', $html, $head));
        preg_match_all('#<th[^>]*@click="sortHour\((\d)\)"[^>]*>([^<]+)</th>#', $head[1], $ths);
        $this->assertSame(['0', '1', '2', '3', '4', '5'], $ths[1]);
        // Spec 297: "+ … last hour" (the hour before the as-of time; was "+ … this hour").
        $this->assertSame(['Page', 'Adspent', 'Orders', 'CPP', '+ Adspent last hour', '+ Orders last hour'], $ths[2]);
        $this->assertStringContainsString('<td>TOTAL</td>', substr($html, strpos($html, 'id="tlHourTable"')));
    }

    /** HT-1..HT-3 in the browser script: cell text, colours, the last hour's edge, tooltip, refresh, stale note. */
    public function test_HT_the_view_script_draws_the_cells_and_refreshes_every_5_minutes(): void
    {
        $this->travelTo(Carbon::parse(self::DAY . ' 10:20:00', 'Asia/Manila'));
        $this->seedTwoDays();
        $this->hourRow('Big Page', 10, 1234567.891, 90, 12);
        $this->spendInReport('Big Page');
        $data = $this->getJson('/ads_manager/cpp/timeline/hourly-totals?start=' . self::PREV . '&end=' . self::DAY)->json();
        $data['stale'] = true;
        $data['today_stored_at'] = '2026-11-09 08:10:00';

        $out = $this->js(json_encode($data), <<<'JS'
            out.compact = [0, 849.6, 999.4, 12400, 99949, 245300, 999499, 1234567.89].map(v => c.compact(v));
            c.start = '2026-11-08'; c.end = '2026-11-09';
            c.setView('totals');
            await new Promise(r => setTimeout(r, 0));
            out.fetched = fetched.slice();
            out.timerMs = timers.map(t => t.ms);
            const today = c.totals.days[0], prev = c.totals.days[1];
            out.labels = c.totals.days.map(d => d.label);
            const tc = c.totCells(today), pc = c.totCells(prev);
            out.cell7 = c.totClass(today, tc[7], 7);
            out.cell8 = c.totClass(today, tc[8], 8);
            out.cell9 = c.totClass(today, tc[9], 9);
            out.cell10 = c.totClass(today, tc[10], 10);
            out.prev23 = c.totClass(prev, pc[23], 23);
            out.text10 = [c.compact(today.total[10].spend), String(today.total[10].orders), Math.round(today.total[10].cpp)];
            out.tip9 = c.totTip(today.label, tc[9], 9);
            out.tip10 = c.totTip(today.label, tc[10], 10);
            out.tip7 = c.totTip(today.label, tc[7], 7);
            out.stale = c.totals.stale; out.note = c.staleNote();
            timers[0].fn();
            await new Promise(r => setTimeout(r, 0));
            out.refetched = fetched.length;
            c.setView('buckets');
            out.cleared = cleared; out.timerAfter = c.totalsTimer;
            JS);

        $this->assertSame(['0', '850', '999', '12.4k', '99.9k', '245k', '999k', '1.23M'], $out['compact']);
        $this->assertSame(['/ads_manager/cpp/timeline/hourly-totals?start=2026-11-08&end=2026-11-09'], array_map(fn ($u) => parse_url($u, PHP_URL_PATH) . '?' . parse_url($u, PHP_URL_QUERY), $out['fetched']));
        $this->assertSame([300000], $out['timerMs'], 'one refresh timer, every 5 minutes');
        $this->assertSame(['Nov 9 Mon', 'Nov 8 Sun'], $out['labels']);
        // Spec 297: at 10:20 today shows the columns as of 1AM … 10AM (cells 0–9); cell 10 (as of 11AM) is empty, so the
        // last-filled edge moved from cell 10 to cell 9. (292: cell 10 was the running hour, filled, with the edge.)
        $this->assertSame(['cpp-n tl-hour-orders', 'cpp-g', 'cpp-c tl-tot-last', 'tl-hour-empty', 'cpp-r tl-tot-last'],
            [$out['cell7'], $out['cell8'], $out['cell9'], $out['cell10'], $out['prev23']],
            'CPP colours of the hourly view; amber edge for orders without spend; a stronger edge on the last filled column');
        $this->assertSame(['1.23M', '5', 246964], $out['text10'], '₱1,234,817.89 ÷ 5 orders in the stored data; its column is not open yet');
        $this->assertSame(implode("\n", [
            'Nov 9 Mon · all pages · as of 10AM', '00:00 – 9:59 AM (cumulative)', 'CPP: ₱35.00', 'Spend: ₱140.00', 'Orders: 4',
            'CPI (per 1k impressions): ₱93.33', 'CPM (per message): ₱15.56', 'Last hour (9:00 – 9:59 AM): +₱100.00 spend, +2 orders',
        ]), $out['tip9']);
        $this->assertSame('Nov 9 Mon · as of 11AM · no data', $out['tip10']);
        $this->assertStringContainsString("CPP: — (no hourly spend yet)\nSpend: no hourly spend stored yet\nOrders: 1", $out['tip7']);
        $this->assertStringEndsWith('Orders only: Meta hourly spend not stored yet', $out['tip7']);
        $this->assertSame([true, '⚠ Hourly spend not updated since Nov 9, 8:10 AM'], [$out['stale'], $out['note']]);
        $this->assertSame(2, $out['refetched'], 'the timer reloads the data without a page reload');
        $this->assertSame([1, null], [$out['cleared'], $out['timerAfter']], 'leaving the view stops the refresh');
    }

    /**
     * Spec 297: a day stored by the hourly runs the way meta-ads:hourly stores it (HourlySpend::store, snapshot_at = the
     * run's time). Alpha (ad a1) spends ₱100 and Beta (ad b1) ₱50 in every hour from 00:00; $partial = the last hour has
     * only ₱10 and ₱5 (a run at :10 sees about ten minutes of it).
     */
    private function run297(string $day, int $lastHour, bool $partial = false): void
    {
        $rows = [];
        for ($h = 0; $h <= $lastHour; $h++) {
            $cut = $partial && $h === $lastHour;
            $rows[] = ['ad_id' => 'a1', 'hour' => $h, 'spend' => $cut ? 10.0 : 100.0, 'impressions' => 1000, 'messages' => 5];
            $rows[] = ['ad_id' => 'b1', 'hour' => $h, 'spend' => $cut ? 5.0 : 50.0, 'impressions' => 500, 'messages' => 2];
        }
        app(\App\Services\MetaAds\HourlySpend::class)->store($day, $rows, true);
    }

    private function pages297(string $day): void
    {
        foreach (['a1' => 'Alpha', 'b1' => 'Beta'] as $ad => $page) {
            DB::table('ads_manager_reports')->insert(['day' => $day, 'page_name' => $page, 'ad_id' => $ad, 'amount_spent_php' => 500, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * AS-1 H: Nov 17's column "8AM" is the 291/292 cell of hour 07 for every figure (spend H07, orders before 8:00:
     * 07:59 counts, 08:00 does not), and "12AM" is the old hour 23. Header, cell figures, tooltip and modal speak "as of".
     */
    public function test_AS1_column_8AM_is_the_old_hour_07_and_12AM_the_old_hour_23(): void
    {
        $day = '2026-11-17';
        $this->pages297($day);
        $this->travelTo(Carbon::parse('2026-11-17 23:10:00', 'Asia/Manila'));
        $this->run297($day, 23, true);
        $this->travelTo(Carbon::parse('2026-11-18 00:10:00', 'Asia/Manila'));
        $this->run297($day, 23);                                  // the 00:10 run closes yesterday
        foreach (['01:15', '06:40', '07:59', '08:00', '12:30', '23:59'] as $i => $hm) $this->sale($i % 2 ? 'Beta' : 'Alpha', $hm, $day);
        $this->travelTo(Carbon::parse('2026-11-18 15:00:00', 'Asia/Manila'));

        $totals = $this->totals('start=2026-11-17&end=2026-11-17');
        $hourly = $this->getJson('/ads_manager/cpp/timeline/hourly?date=' . $day)->json();
        $row = $totals['days'][0];
        $this->assertSame(24, $row['asof_hours'], 'a past day: every column');
        $this->assertSame($hourly['total'], $row['total'], 'the stored figures are the 291 Total row, unchanged');

        $html = $this->get('/ads_manager/cpp/timeline')->assertOk()->getContent();
        preg_match_all('#<th class="tl-tot-h" title="([^"]*)">([^<]*)</th>#', $html, $th);
        $this->assertSame(['8AM', 'As of 8AM: 00:00 – 7:59 AM'], [$th[2][7], $th[1][7]]);
        $this->assertSame(['12AM', 'As of 12AM: 00:00 – 11:59 PM (end of day)'], [$th[2][23], $th[1][23]]);
        $this->assertSame(['1AM', 'As of 1AM: 00:00 – 0:59 AM'], [$th[2][0], $th[1][0]]);
        $this->assertSame('12PM', $th[2][11]);

        $out = $this->js(json_encode($hourly), <<<'JS'
            c.totals = { days: TOTALS.days };
            const row = c.totals.days[0], cells = c.totCells(row);
            out.c7 = cells[7]; out.c23 = cells[23];
            out.fig7 = [c.figSpend(cells[7]), c.figOrders(cells[7]), c.figCpp(cells[7])];
            out.labels = [c.asofLabel(0), c.asofLabel(7), c.asofLabel(11), c.asofLabel(12), c.asofLabel(23)];
            out.ranges = [0, 7, 11, 12, 23].map(h => c.asofRange(h));
            out.tip7 = c.totTip(row.label, cells[7], 7).split('\n').slice(0, 5);
            await c.openHour(row, 7);
            out.m7 = { title: c.hmTitle(), range: c.hmRange(), total: c.hm.total, line: c.hmTotalsLine(), rows: c.hmRows(), foot: c.hmFoot(), last: c.hmLastHour() };
            c.closeHour();
            await c.openHour(row, 23);
            out.m23 = { title: c.hmTitle(), range: c.hmRange(), total: c.hm.total };
            JS, ['TOTALS' => json_encode($totals)]);

        // Every figure of the old hour-07 cell (spend, orders, CPP, CPI, CPM, what the hour added, source).
        $this->assertSame($hourly['total'][7], $out['c7']);
        $this->assertSame($hourly['total'][23], $out['c23']);
        $this->assertEquals(['spend' => 1200, 'orders' => 3, 'cpp' => 400], array_intersect_key($out['c7'], array_flip(['spend', 'orders', 'cpp'])),
            'H07 = 8 hours × ₱150; orders 01:15, 06:40, 07:59 (08:00 is the 9AM column\'s)');
        $this->assertSame(4, $hourly['total'][8]['orders'], 'the 08:00 order counts as of 9AM');
        $this->assertEquals(['spend' => 3600, 'orders' => 6], array_intersect_key($out['c23'], array_flip(['spend', 'orders'])), 'end of day: H23 written by the 00:10 run');
        $this->assertSame(['1,200', '3', '400.00'], $out['fig7']);
        $this->assertSame(['1AM', '8AM', '12PM', '1PM', '12AM'], $out['labels']);
        $this->assertSame(['00:00 – 0:59 AM', '00:00 – 7:59 AM', '00:00 – 11:59 AM', '00:00 – 12:59 PM', '00:00 – 11:59 PM'], $out['ranges']);
        $this->assertSame(['Nov 17 Tue · all pages · as of 8AM', '00:00 – 7:59 AM (cumulative)', 'CPP: ₱400.00', 'Spend: ₱1,200.00', 'Orders: 3'], $out['tip7']);

        // AS-1 E: the modal speaks "as of" and its rows and TOTAL are the same bucket and order cut as the cell.
        $this->assertSame(['Nov 17, 2026 · as of 8AM', '00:00 – 7:59 AM', '7:00 – 7:59 AM'], [$out['m7']['title'], $out['m7']['range'], $out['m7']['last']]);
        $this->assertSame($out['c7'], $out['m7']['total'], 'modal totals = the cell');
        $this->assertSame('Adspent ₱1,200.00 · Orders 3 · CPP ₱400.00', $out['m7']['line']);
        $this->assertEqualsWithDelta(1200, array_sum(array_column($out['m7']['rows'], 'spend')), 0.001);
        $this->assertSame(3, array_sum(array_column($out['m7']['rows'], 'orders')));
        $this->assertSame(['₱1,200.00', '3', '₱400.00', '₱150.00', '1'], $out['m7']['foot']);
        $this->assertSame(['Nov 17, 2026 · as of 12AM', '00:00 – 11:59 PM'], [$out['m23']['title'], $out['m23']['range']]);
        $this->assertSame($out['c23'], $out['m23']['total']);
    }

    /**
     * AS-1 E: today a column fills only once its as-of time has passed and the day's hourly spend was stored at or after
     * it. At 8:05 the 7:10 run's H07 is ten minutes of spend, so "8AM" is empty; the 8:10 run fills it. Orders alone never
     * fill a column; nothing stored today fills nothing.
     */
    public function test_AS1_today_a_column_fills_only_after_the_run_that_covers_its_full_hour(): void
    {
        $today = '2026-11-18';
        $this->pages297($today);
        $this->sale('Alpha', '06:30', $today);
        $this->sale('Beta', '07:45', $today);
        $this->travelTo(Carbon::parse("$today 07:10:00", 'Asia/Manila'));
        $this->run297($today, 7, true);

        $snap = [];
        $this->travelTo(Carbon::parse("$today 08:05:00", 'Asia/Manila'));
        $snap['0805'] = $this->totals("start=$today&end=$today");
        $this->travelTo(Carbon::parse("$today 08:10:00", 'Asia/Manila'));
        $this->run297($today, 8, true);
        $this->travelTo(Carbon::parse("$today 08:15:00", 'Asia/Manila'));
        $snap['0815'] = $this->totals("start=$today&end=$today");
        $hourly0815 = $this->getJson('/ads_manager/cpp/timeline/hourly?date=' . $today)->json();
        $this->sale('Alpha', '08:30', $today);
        $this->travelTo(Carbon::parse("$today 09:30:00", 'Asia/Manila'));
        $snap['0930'] = $this->totals("start=$today&end=$today");   // the 9:10 run did not store
        DB::table('cpp_snapshots')->delete();
        $snap['none'] = $this->totals("start=$today&end=$today");

        $this->assertSame(['2026-11-18 07:10:00', 7], [$snap['0805']['today_stored_at'], $snap['0805']['days'][0]['asof_hours']]);
        $this->assertSame(['2026-11-18 08:10:00', 8], [$snap['0815']['today_stored_at'], $snap['0815']['days'][0]['asof_hours']]);
        $this->assertSame(8, $snap['0930']['days'][0]['asof_hours'], 'orders came in at 08:30, but no run stored after 9:00');
        $this->assertSame(0, $snap['none']['days'][0]['asof_hours'], 'nothing stored today');
        $this->assertNotNull($snap['none']['days'][0]['total'][7], 'the orders are there, but they do not open a column');
        $this->assertSame([24, 0], [
            $this->totals('start=2026-11-17&end=2026-11-17')['days'][0]['asof_hours'],
            $this->totals('start=2026-11-19&end=2026-11-19')['days'][0]['asof_hours'],
        ], 'a past day shows every column, a future day none');

        $out = $this->js('{}', <<<'JS'
            for (const [k, j] of Object.entries(SNAP)) {
              const row = j.days[0], cells = c.totCells(row);
              out[k] = { filled: cells.map((x, i) => x ? i : null).filter(i => i !== null), c6: cells[6], c7: cells[7], c8: cells[8],
                         edge: cells.map((x, i) => c.totClass(row, x, i)).findIndex(s => s.includes('tl-tot-last')) };
            }
            c.totals = { days: SNAP['0805'].days };
            const n = fetched.length;
            await c.openHour(c.totals.days[0], 7);
            out.open0805 = [c.hm.open, fetched.length - n];
            JS, ['SNAP' => json_encode($snap)]);

        $this->assertSame(range(0, 6), $out['0805']['filled'], 'at 8:05: 1AM … 7AM');
        $this->assertNull($out['0805']['c7'], '8AM is empty at 8:05 (the 7:10 run\'s H07 is partial)');
        $this->assertSame(6, $out['0805']['edge']);
        $this->assertSame([false, 0], $out['open0805'], 'an empty column opens nothing');
        $this->assertSame(range(0, 7), $out['0815']['filled'], 'after the 8:10 run: 8AM fills');
        $this->assertSame($hourly0815['total'][7], $out['0815']['c7']);
        $this->assertEquals(['spend' => 1200, 'orders' => 2, 'cpp' => 600], array_intersect_key($out['0815']['c7'], array_flip(['spend', 'orders', 'cpp'])),
            'H07 as stored by the 8:10 run: the full hour (₱150), not the ₱15 the 7:10 run saw');
        $this->assertNull($out['0815']['c8'], '9AM waits for the 9:10 run');
        $this->assertSame(range(0, 7), $out['0930']['filled']);
        $this->assertNull($out['0930']['c8']);
        $this->assertSame([], $out['none']['filled']);
    }

    /**
     * AS-1 E: a past day whose stored hourly spend stops early (H00..H21, the sync blocked from 22:10): the columns after
     * the last stored bucket (11PM, 12AM) show the orders, "—" for Adspent and CPP and the amber marking, in the cell and
     * in the modal; the Hourly endpoint gives the same cells (no spend carried past the last stored hour).
     */
    public function test_AS1_a_past_day_that_stops_early_shows_orders_only_after_its_last_stored_bucket(): void
    {
        $day = '2026-11-17';
        $this->pages297($day);
        $this->travelTo(Carbon::parse("$day 21:10:00", 'Asia/Manila'));
        $this->run297($day, 21);
        foreach (['09:00', '21:30', '22:30', '23:30'] as $hm) $this->sale('Alpha', $hm, $day);
        $this->travelTo(Carbon::parse('2026-11-18 10:00:00', 'Asia/Manila'));

        $totals = $this->totals('start=2026-11-17&end=2026-11-18');
        $hourly = $this->getJson('/ads_manager/cpp/timeline/hourly?date=' . $day)->json();
        $row = $totals['days'][1];
        $this->assertSame([$day, 24], [$row['date'], $row['asof_hours']]);
        $this->assertSame($hourly['total'], $row['total']);
        $this->assertEquals(['spend' => 3300, 'orders' => 2, 'source' => 'meta'], array_intersect_key($row['total'][21], array_flip(['spend', 'orders', 'source'])), '10PM = H21');
        foreach ([22 => 3, 23 => 4] as $h => $orders) {
            $this->assertEquals(['spend' => null, 'orders' => $orders, 'cpp' => null, 'cpi' => null, 'cpm' => null, 'add_spend' => null, 'source' => 'orders'],
                array_intersect_key($row['total'][$h], array_flip(['spend', 'orders', 'cpp', 'cpi', 'cpm', 'add_spend', 'source'])), "column $h: no spend after H21");
        }
        foreach ($hourly['pages'] as $p) {
            $this->assertNull($p['cells'][22]['spend'] ?? null, $p['page'] . ': no spend carried past H21');
        }
        $this->assertSame(['2026-11-17 21:10:00', true], [$totals['stored_at'], $totals['stale']],
            '"Meta hourly spend as of" and the amber "not updated since" note keep working');

        $out = $this->js(json_encode($hourly), <<<'JS'
            c.totals = { days: TOTALS.days, stale: true, today_stored_at: null, stored_at: TOTALS.stored_at };
            const row = c.totals.days[1], cells = c.totCells(row);
            out.cls = [21, 22, 23].map(h => c.totClass(row, cells[h], h));
            out.fig = [22, 23].map(h => [c.figSpend(cells[h]), c.figOrders(cells[h]), c.figCpp(cells[h])]);
            out.tip = c.totTip(row.label, cells[23], 23);
            await c.openHour(row, 22);
            out.modal = { title: c.hmTitle(), total: c.hm.total, cells: c.hmRows().map(r => [r.page].concat(c.hmCells(r))), foot: c.hmFoot() };
            out.note = c.staleNote();
            JS, ['TOTALS' => json_encode($totals)]);

        $this->assertSame(['cpp-r', 'cpp-n tl-hour-orders', 'cpp-n tl-hour-orders tl-tot-last'], $out['cls'], 'amber "no hourly spend" marking after H21');
        $this->assertSame([['—', '3', '—'], ['—', '4', '—']], $out['fig']);
        $this->assertStringContainsString("as of 12AM\n00:00 – 11:59 PM (cumulative)\nCPP: — (no hourly spend yet)\nSpend: no hourly spend stored yet\nOrders: 4", $out['tip']);
        $this->assertStringEndsWith('Orders only: Meta hourly spend not stored yet', $out['tip']);
        $this->assertSame('Nov 17, 2026 · as of 11PM', $out['modal']['title']);
        $this->assertSame($row['total'][22], $out['modal']['total'], 'modal totals = the cell');
        $this->assertSame([['Alpha', '—', '3', '—', '—', '1']], $out['modal']['cells']);
        $this->assertSame(['—', '3', '—', '—', '1'], $out['modal']['foot']);
        $this->assertSame('⚠ Hourly spend not updated since Nov 17, 9:10 PM', $out['note']);
    }

    /**
     * AS-2: the Hourly totals view drops the 1280 px box for the window width with a 16 px gutter, from inside the page
     * (the layout is not changed); Buckets and Hourly keep the box. The 24 columns fit a 1280 px window.
     */
    public function test_AS2_the_hourly_totals_view_uses_the_full_window_width(): void
    {
        $html = $this->get('/ads_manager/cpp/timeline')->assertOk()->getContent();

        // The page root keeps max-w-7xl and gets tl-wide only while the Hourly totals view is shown.
        $this->assertStringContainsString('<div class="max-w-7xl mx-auto" id="tl-root" :class="view === \'totals\' ? \'tl-wide\' : \'\'" x-data="cppTimeline()" x-init="init()">', $html);
        $css = preg_replace('/\s+/', ' ', $html);
        $this->assertStringContainsString('#tl-root.tl-wide { max-width: none; }', $css);
        $this->assertStringContainsString('main > div:has(> #tl-root.tl-wide) { max-width: none; padding-left: 16px; padding-right: 16px; }', $css);

        // The rule's target: the layout's content box is a direct child of <main> and the direct parent of the page root.
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        $root = $dom->getElementById('tl-root');
        $this->assertNotNull($root);
        $box = $root->parentNode;
        $this->assertSame('div', $box->nodeName);
        $this->assertStringContainsString('max-w-7xl', $box->getAttribute('class'));
        $this->assertSame('main', $box->parentNode->nodeName);

        // Width math, 17 px scroll bar, 16 px gutters, card padding 2×16 and a 2 px border; date 46 + labels 44.
        $boxAt = fn (int $window, int $sidebar) => $window - 17 - $sidebar - 32 - 32 - 2;
        $this->assertSame([949, 1133, 1197], [$boxAt(1280, 248), $boxAt(1280, 64), $boxAt(1280, 0)], 'at 1280: sidebar open, rail, no sidebar');
        $this->assertSame([1589, 1773, 1837], [$boxAt(1920, 248), $boxAt(1920, 64), $boxAt(1920, 0)], 'at 1920');
        $this->assertGreaterThanOrEqual(1122, $boxAt(1280, 64), 'fits a 1280 px window with the rail');
        $this->assertGreaterThanOrEqual(1122, $boxAt(1280, 0), 'and without a sidebar (a site without spec 295)');
        $this->assertLessThan(1122, $boxAt(1280, 248), 'with the full sidebar open only .tl-tot-wrap scrolls');
        $this->assertEqualsWithDelta(62.5, ($boxAt(1920, 248) - 90) / 24, 0.05);
        $this->assertEqualsWithDelta(72.8, ($boxAt(1920, 0) - 90) / 24, 0.05);
        // Before 297 the box never grew past the 1280 px max-w-7xl less its 2×32 padding and the card (1182 px) at any width.
        $this->assertSame(1182, 1280 - 64 - 32 - 2);
    }

    /** Runs the page's cppTimeline() in Node (Manila time zone); fetch answers $json and records each URL. */
    private function js(string $json, string $script, array $globals = []): array
    {
        $node = (new \Symfony\Component\Process\ExecutableFinder())->find('node');
        if ($node === null) $this->markTestSkipped('Node is not installed.');
        $html = $this->get('/ads_manager/cpp/timeline')->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<script>\s*(function cppTimeline\(\)[\s\S]*?)</script>#', $html, $m));
        $js = "require('vm').runInThisContext(require('fs').readFileSync(" . json_encode(public_path('js/cpp-sort.js')) . ", 'utf8'));\n"
            . "require('vm').runInThisContext(" . json_encode($m[1]) . ");\n"
            . "const fetched = [];\nglobalThis.fetch = async (u) => { fetched.push(String(u)); return {ok: true, status: 200, json: async () => (" . $json . ")}; };\n"
            . "const timers = []; let cleared = 0;\n"
            . "globalThis.setInterval = (fn, ms) => { timers.push({fn, ms}); return timers.length; };\n"
            . "globalThis.clearInterval = () => { cleared++; };\n"
            . implode('', array_map(fn ($k, $v) => "const $k = $v;\n", array_keys($globals), $globals))
            . "const c = cppTimeline();\nconst out = {};\n"
            . "(async () => {\n" . $script . "\n})().then(() => console.log(JSON.stringify(out)));\n";
        $file = tempnam(sys_get_temp_dir(), 'cppht') . '.cjs';
        file_put_contents($file, $js);
        $p = new \Symfony\Component\Process\Process([$node, $file], null, ['TZ' => 'Asia/Manila']);
        $p->run();
        @unlink($file);
        $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());

        return json_decode($p->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }
}
