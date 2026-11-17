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
        preg_match_all('#<th class="tl-tot-h"[^>]*>(\d\d)</th>#', $t[1], $hours);
        $this->assertSame(array_map(fn ($h) => sprintf('%02d', $h), range(0, 23)), $hours[1]);
        $this->assertSame(1, substr_count($t[1], '<col class="tl-tot-date">'));
        $this->assertSame(24, substr_count($t[1], '<col class="tl-tot-col">'));

        $css = preg_replace('/\s+/', ' ', $html);
        $this->assertStringContainsString('.tl-hour { table-layout: fixed; width: 100%;', $css, 'the totals table takes the hourly table\'s fixed layout');
        $this->assertStringContainsString('.tl-tot-wrap { width: 100%; overflow-x: auto; }', $css, 'below the min-width only the wrapper scrolls');
        $this->assertStringContainsString('.tl-tot { min-width: 1060px; }', $css);
        $this->assertStringContainsString('.tl-tot col.tl-tot-date { width: 76px; }', $css);
        // A 1280 px window minus the scroll bar (17), the page padding (2×32), the card padding (2×16) and border (2)
        // leaves 1165 px: wider than the 1060 px minimum, so no scroll; 1165 − 76 = 1089 for 24 hours, 45 px each.
        $box = 1280 - 17 - 64 - 32 - 2;
        $this->assertGreaterThan(76 + 24 * 41, $box);
        $this->assertSame(45, intdiv($box - 76, 24));
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
            out.cell7 = c.totClass(today, today.total[7], 7);
            out.cell9 = c.totClass(today, today.total[9], 9);
            out.cell10 = c.totClass(today, today.total[10], 10);
            out.cell11 = c.totClass(today, today.total[11], 11);
            out.prev23 = c.totClass(prev, prev.total[23], 23);
            out.text10 = [c.compact(today.total[10].spend), String(today.total[10].orders), Math.round(today.total[10].cpp)];
            out.tip10 = c.totTip(today.label, today.total[10], 10);
            out.tip7 = c.totTip(today.label, today.total[7], 7);
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
        $this->assertSame(['cpp-n tl-hour-orders', 'cpp-c', 'cpp-r tl-tot-last', 'tl-hour-empty', 'cpp-r tl-tot-last'],
            [$out['cell7'], $out['cell9'], $out['cell10'], $out['cell11'], $out['prev23']],
            'CPP colours of the hourly view; amber edge for orders without spend; a stronger edge on the last filled hour');
        $this->assertSame(['1.23M', '5', 246964], $out['text10'], '₱1,234,817.89 ÷ 5 orders');
        $this->assertSame(implode("\n", [
            'Nov 9 Mon · all pages · 00:00–10:59 (cumulative)', 'CPP: ₱246,963.58', 'Spend: ₱1,234,817.89', 'Orders: 5',
            'CPI (per 1k impressions): ₱90.00', 'CPM (per message): ₱12.00', 'This hour: +₱1,234,677.89 spend, +1 orders',
        ]), $out['tip10']);
        $this->assertStringContainsString("CPP: — (no hourly spend yet)\nSpend: no hourly spend stored yet\nOrders: 1", $out['tip7']);
        $this->assertStringEndsWith('Orders only: Meta hourly spend not stored yet', $out['tip7']);
        $this->assertSame([true, '⚠ Hourly spend not updated since Nov 9, 8:10 AM'], [$out['stale'], $out['note']]);
        $this->assertSame(2, $out['refetched'], 'the timer reloads the data without a page reload');
        $this->assertSame([1, null], [$out['cleared'], $out['timerAfter']], 'leaving the view stops the refresh');
    }

    /** Runs the page's cppTimeline() in Node (Manila time zone); fetch answers $json and records each URL. */
    private function js(string $json, string $script): array
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
