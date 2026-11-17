<?php

namespace Tests\Feature\CPP;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Chatblast\ChatblastTestCase;

/**
 * Spec 288 TL-1/TL-2: /ads_manager/cpp/timeline Hourly view. CPP per page per hour (00–23 Manila) of one day, each
 * figure cumulative to the end of the hour: spend, CPI and CPM from cpp_snapshots H00..H23, orders live from
 * macro_output by order time. The 24 columns fit the box (fixed table layout, no horizontal scroll); the cells take
 * the CPP colours by amount; the bucket view keeps its own rows.
 */
class CppHourlyTimelineTest extends ChatblastTestCase
{
    private const DAY = '2026-11-09';

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

    private function hourRow(string $page, int $h, float $spend, ?float $cpi = null, ?float $cpm = null, string $day = self::DAY): void
    {
        DB::table('cpp_snapshots')->insert([
            'snapshot_date' => $day, 'snapshot_bucket' => sprintf('H%02d', $h), 'snapshot_at' => $day . ' 23:10:00',
            'page_name' => $page, 'page_key' => strtolower(str_replace(' ', '', $page)), 'amount_spent' => $spend,
            'orders' => 0, 'proceed_orders' => 0, 'cpi' => $cpi, 'cpm' => $cpm, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function sale(string $page, string $hm, string $day = self::DAY): void
    {
        DB::table('macro_output')->insert(['PAGE' => $page, 'TIMESTAMP' => $hm . ' ' . Carbon::parse($day)->format('d-m-Y'),
            'ts_date' => $day, 'STATUS' => 'PROCEED', 'ITEM_NAME' => 'Tripod', 'COD' => '499']);
    }

    private function spendInReport(string $page, string $day = self::DAY): void
    {
        DB::table('ads_manager_reports')->insert(['day' => $day, 'page_name' => $page, 'ad_id' => 'ad-' . uniqid(), 'amount_spent_php' => 500, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** P1: ₱100 to 09:59 and ₱180 to 10:59 (then flat); 2 orders at 9, 1 at 10. P2: orders only (spend not stored yet). */
    private function seedDay(): void
    {
        $this->spendInReport('Page P1');
        $this->spendInReport('Page P2');
        for ($h = 9; $h < 24; $h++) $this->hourRow('Page P1', $h, $h === 9 ? 100 : 180, 100, $h === 9 ? 20 : 22.5);
        $this->sale('Page P1', '09:15');
        $this->sale('Page P1', '09:40');
        $this->sale('Page P1', '10:05');
        $this->sale('Page P2', '11:30');
        $this->sale('Organic Page', '09:00'); // no ad spend that day: not counted
    }

    public function test_TL1_the_hourly_data_has_24_cumulative_cells_per_page_and_a_total(): void
    {
        $this->seedDay();

        $j = $this->getJson('/ads_manager/cpp/timeline/hourly?date=' . self::DAY)->assertOk()->json();

        $this->assertSame([self::DAY, range(0, 23), 23], [$j['date'], $j['hours'], $j['last_hour']]);
        $this->assertSame(['Page P1', 'Page P2'], array_column($j['pages'], 'page'));
        $p1 = $j['pages'][0]['cells'];
        $this->assertCount(24, $p1);
        $this->assertNull($p1[8], 'no spend and no orders before 09:00');
        $this->assertEquals(['spend' => 100, 'orders' => 2, 'cpp' => 50, 'cpi' => 100, 'cpm' => 20, 'add_spend' => 100, 'add_orders' => 2, 'source' => 'meta'], $p1[9]);
        $this->assertEquals(['spend' => 180, 'orders' => 3, 'cpp' => 60, 'cpi' => 100, 'cpm' => 22.5, 'add_spend' => 80, 'add_orders' => 1, 'source' => 'meta'], $p1[10]);
        $this->assertEquals(['spend' => 180, 'orders' => 3, 'cpp' => 60, 'add_spend' => 0, 'add_orders' => 0], array_intersect_key($p1[23], array_flip(['spend', 'orders', 'cpp', 'add_spend', 'add_orders'])));

        $p2 = $j['pages'][1]['cells'];
        $this->assertNull($p2[10]);
        $this->assertEquals(['spend' => null, 'orders' => 1, 'cpp' => null, 'source' => 'orders'], array_intersect_key($p2[11], array_flip(['spend', 'orders', 'cpp', 'source'])));

        $this->assertEquals(['spend' => 180, 'orders' => 4, 'cpp' => 45, 'cpi' => 100, 'cpm' => 22.5], array_intersect_key($j['total'][11], array_flip(['spend', 'orders', 'cpp', 'cpi', 'cpm'])),
            'the Total adds the pages (orders of the orders-only page included, as the bucket view counts them)');
    }

    public function test_TL1_today_leaves_the_hours_after_now_empty(): void
    {
        $this->travelTo(Carbon::parse(self::DAY . ' 10:20:00', 'Asia/Manila'));
        $this->seedDay();

        $j = $this->getJson('/ads_manager/cpp/timeline/hourly?date=' . self::DAY)->assertOk()->json();

        $this->assertSame(10, $j['last_hour']);
        $this->assertNotNull($j['pages'][0]['cells'][10]);
        $this->assertSame(array_fill(0, 13, null), array_slice($j['pages'][0]['cells'], 11));
    }

    public function test_TL1_the_page_renders_24_hour_columns_that_fit_without_horizontal_scroll(): void
    {
        $html = $this->get('/ads_manager/cpp/timeline')->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<table class="tl-hour" id="tl-hour-table">(.*?)</table>#s', $html, $t));
        preg_match_all('#<th class="tl-hour-h"[^>]*>(\d\d)</th>#', $t[1], $hours);
        $this->assertSame(array_map(fn ($h) => sprintf('%02d', $h), range(0, 23)), $hours[1], '24 columns, 00 to 23');
        $this->assertSame(1, substr_count($t[1], '<col class="tl-hour-page">'));
        $this->assertSame(24, substr_count($t[1], '<col class="tl-hour-col">'));

        // The CSS that keeps it inside the box: fixed layout at 100% width, no overflow-x scroll on the wrapper.
        $css = preg_replace('/\s+/', ' ', $html);
        $this->assertStringContainsString('.tl-hour-wrap { width: 100%; overflow-x: hidden; }', $css);
        $this->assertStringContainsString('.tl-hour { table-layout: fixed; width: 100%;', $css);
        $this->assertStringContainsString('.tl-hour col.tl-hour-page { width: 128px; }', $css);
        $this->assertStringContainsString('white-space: nowrap; }', $css);
        $this->assertStringContainsString('text-overflow: ellipsis;', $css);
        $this->assertSame(1, preg_match('#<div class="tl-hour-wrap">\s*<table class="tl-hour"#', $html), 'the hourly table sits in the wrapper without a scroll bar');
        // At a 1280 px window the box is 1280 − 2×32 (page padding) − 2×16 (card padding) = 1184 px; the page column
        // takes 128, leaving 1056 for 24 hours = 44 px each, enough for a 3-digit CPP in 10 px monospace (~19 px).
        $this->assertSame(44, intdiv(1280 - 64 - 32 - 128, 24));
    }

    public function test_TL1_cpp_colours_by_amount_and_the_tooltip_in_the_browser(): void
    {
        $this->seedDay();
        $data = $this->getJson('/ads_manager/cpp/timeline/hourly?date=' . self::DAY)->json();

        $out = $this->js(json_encode($data), <<<'JS'
            out.tones = [null, 0, 29.99, 30, 49.99, 50, 69.99, 70, 250].map(v => c.cppTone(v));
            c.hourDate = '2026-11-09';
            c.setView('hourly');
            await new Promise(r => setTimeout(r, 0));
            out.view = c.view;
            out.pages = c.hourly.pages.map(p => p.page);
            out.class9 = c.hourClass(c.hourly.pages[0].cells[9]);
            out.class10 = c.hourClass(c.hourly.pages[0].cells[10]);
            out.classP2 = c.hourClass(c.hourly.pages[1].cells[11]);
            out.empty = c.hourClass(null);
            out.tip = c.hourTip('Page P1', c.hourly.pages[0].cells[10], 10);
            out.fetched = fetched;
            JS);

        $this->assertSame(['cpp-n', 'cpp-g', 'cpp-g', 'cpp-c', 'cpp-c', 'cpp-o', 'cpp-o', 'cpp-r', 'cpp-r'], $out['tones'],
            'green below ₱30, cyan ₱30 to under ₱50, orange ₱50 to under ₱70, red ₱70 and up, grey without orders');
        $this->assertSame(['hourly', ['Page P1', 'Page P2']], [$out['view'], $out['pages']]);
        $this->assertSame(['cpp-o', 'cpp-o', 'cpp-n tl-hour-orders', 'tl-hour-empty'], [$out['class9'], $out['class10'], $out['classP2'], $out['empty']],
            'the fill is the CPP colour; orders without stored spend get a thin amber edge, not a fill');
        $this->assertSame(implode("\n", [
            'Page P1 · 00:00–10:59 (cumulative)', 'CPP: ₱60.00', 'Spend: ₱180.00', 'Orders: 3',
            'CPI (per 1k impressions): ₱100.00', 'CPM (per message): ₱22.50', 'This hour: +₱80.00 spend, +1 orders',
        ]), $out['tip']);
        $this->assertStringContainsString('/ads_manager/cpp/timeline/hourly?date=2026-11-09', $out['fetched']);
    }

    public function test_TL1_the_bucket_view_stays_and_takes_cpp_colours_with_the_state_as_an_edge(): void
    {
        $out = $this->js('{}', <<<'JS'
            c.cells = {'7PM': {'2026-11-09': {cpp: 45, inferred: false}}, '3PM': {'2026-11-09': {cpp: null, inferred: true}}, '11:59PM': {'2026-11-10': {cpp: 75, is_estimate: true}}};
            out.saved = c.cellClass('7PM', '2026-11-09');
            out.inferred = c.cellClass('3PM', '2026-11-09');
            out.estimate = c.cellClass('11:59PM', '2026-11-10');
            out.tip = c.cellState('3PM', '2026-11-09');
            out.view = c.view;
            JS);

        $this->assertSame(['buckets', 'tl-cell cpp-c', 'tl-cell tl-cell-inferred cpp-n', 'tl-cell tl-cell-estimate cpp-r', 'Inferred: orders from macro_output'],
            [$out['view'], $out['saved'], $out['inferred'], $out['estimate'], $out['tip']]);
        $css = preg_replace('/\s+/', ' ', $this->get('/ads_manager/cpp/timeline')->getContent());
        $this->assertStringContainsString('.tl-cell-inferred { box-shadow: inset 0 0 0 2px #f59e0b; }', $css, 'the state is an edge, not a fill');
        $this->assertStringNotContainsString('.tl-cell-inferred { background', $css);
    }

    public function test_TL2_the_bucket_view_and_its_popup_ignore_the_hourly_rows(): void
    {
        $this->seedDay();
        DB::table('cpp_snapshots')->insert([
            'snapshot_date' => self::DAY, 'snapshot_bucket' => '7PM', 'snapshot_at' => self::DAY . ' 19:00:00', 'page_name' => 'Page P1',
            'page_key' => 'pagep1', 'amount_spent' => 300, 'orders' => 6, 'proceed_orders' => 6, 'cpp' => 50, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // The bucket data query uses the MySQL HOUR() and MINUTE() of the upload times; sqlite gets the same two here.
        $pdo = DB::connection()->getPdo();
        $pdo->sqliteCreateFunction('HOUR', fn ($v) => $v === null ? null : (int) substr((string) $v, 11, 2), 1);
        $pdo->sqliteCreateFunction('MINUTE', fn ($v) => $v === null ? null : (int) substr((string) $v, 14, 2), 1);

        $j = $this->getJson('/ads_manager/cpp/timeline/data?start=' . self::DAY . '&end=' . self::DAY)->assertOk()->json();
        $this->assertSame(['10AM', '3PM', '7PM', '11:59PM'], array_keys($j['cells']));
        $this->assertEquals(['spent' => 300, 'orders' => 6, 'cpp' => 50], array_intersect_key($j['cells']['7PM'][self::DAY], array_flip(['spent', 'orders', 'cpp'])));

        $this->getJson('/ads_manager/cpp/timeline/detail?date=' . self::DAY . '&bucket=H10')->assertStatus(422);
        $this->assertSame(['Page P1'], array_column($this->getJson('/ads_manager/cpp/timeline/detail?date=' . self::DAY . '&bucket=7PM')->json('rows'), 'page_name'));
    }

    public function test_TL1_the_hourly_route_has_the_timeline_permission(): void
    {
        $this->actingAs($this->user('Data Encoder', 'de@example.test'));
        $this->getJson('/ads_manager/cpp/timeline/hourly?date=' . self::DAY)->assertNotFound();
    }

    /** Runs the page's cppTimeline() in Node; fetch answers $json and records the URL in `fetched`. */
    private function js(string $json, string $script): array
    {
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) $this->markTestSkipped('Node is not installed.');
        $html = $this->get('/ads_manager/cpp/timeline')->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<script>\s*(function cppTimeline\(\)[\s\S]*?)</script>#', $html, $m));
        $js = "require('vm').runInThisContext(require('fs').readFileSync(" . json_encode(public_path('js/cpp-sort.js')) . ", 'utf8'));\n"
            . "require('vm').runInThisContext(" . json_encode($m[1]) . ");\n"
            . "let fetched = '';\nglobalThis.fetch = async (u) => { fetched = String(u); return {ok: true, json: async () => (" . $json . ")}; };\n"
            . "const c = cppTimeline();\nconst out = {};\n"
            . "(async () => {\n" . $script . "\n})().then(() => console.log(JSON.stringify(out)));\n";
        $file = tempnam(sys_get_temp_dir(), 'cpphr') . '.cjs';
        file_put_contents($file, $js);
        $p = new Process([$node, $file]);
        $p->run();
        @unlink($file);
        $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());

        return json_decode($p->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }
}
