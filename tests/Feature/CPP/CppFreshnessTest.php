<?php

namespace Tests\Feature\CPP;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Chatblast\ChatblastTestCase;

/**
 * /ads_manager/cpp: when the ads spend and the orders were last updated (spec 250).
 * The server embeds `freshness` (page => date => {ads, order}, Manila wall clock 'Y-m-d H:i'); the page builds the
 * line above the table and the per-page tooltips from it in the browser. The browser part is run in Node from the
 * rendered page itself (the block between the cpp-freshness markers and the rendered `const freshness` line).
 */
class CppFreshnessTest extends ChatblastTestCase
{
    private const DAY = '2026-11-09';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::table('macro_output', function (Blueprint $t) {
            $t->string('TIMESTAMP')->nullable();
            $t->string('STATUS')->nullable();
            if (!Schema::hasColumn('macro_output', 'botcake_ad_id')) $t->string('botcake_ad_id', 64)->nullable();
        });
        Schema::create('ads_manager_reports', function (Blueprint $t) {
            $t->id();
            $t->date('day')->nullable();
            $t->string('page_name');
            $t->string('campaign_id')->nullable();
            $t->string('ad_set_id')->nullable();
            $t->string('ad_id')->nullable();
            $t->decimal('amount_spent_php', 12, 2)->nullable();
            $t->bigInteger('messaging_conversations_started')->nullable();
            $t->bigInteger('impressions')->nullable();
            $t->timestamps();
        });
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_10_23_100000_create_chatbot_tables.php']]);
        $this->actingAs($this->user());
    }

    /** A spend row written at $updatedAt (stored Manila wall clock, as the upload and the sync write it). */
    private function spend(string $page, string $updatedAt, string $day = self::DAY, float $php = 500): void
    {
        DB::table('ads_manager_reports')->insert(['day' => $day, 'page_name' => $page, 'ad_id' => 'ad-' . uniqid(),
            'amount_spent_php' => $php, 'messaging_conversations_started' => 10, 'impressions' => 1000,
            'created_at' => $updatedAt, 'updated_at' => $updatedAt]);
    }

    /** An order with macro_output.TIMESTAMP 'H:i d-m-Y' (Manila wall clock). */
    private function sale(string $page, string $timestamp): void
    {
        $day = \DateTime::createFromFormat('H:i d-m-Y', $timestamp)->format('Y-m-d');
        DB::table('macro_output')->insert(['PAGE' => $page, 'TIMESTAMP' => $timestamp, 'ts_date' => $day,
            'STATUS' => 'PROCEED', 'ITEM_NAME' => 'Tripod', 'COD' => '499']);
    }

    private function cpp(string $start = self::DAY, string $end = self::DAY)
    {
        return $this->get('/ads_manager/cpp?start=' . $start . '&end=' . $end)->assertOk();
    }

    /**
     * Runs the page's freshness block in Node with the rendered `freshness` data. $script fills `out`.
     * Available: cppFreshStamp(s), cppFreshLine(pages, dates), cppFreshTip(page, dates).
     */
    private function js(string $html, string $script): array
    {
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) $this->markTestSkipped('Node is not installed.');
        $this->assertSame(1, preg_match('/^\s*const freshness\s*=.*;$/m', $html, $data), 'the page embeds const freshness');
        $this->assertSame(1, preg_match('#// cpp-freshness:begin(.*?)// cpp-freshness:end#s', $html, $block), 'the page has the freshness block');
        $file = tempnam(sys_get_temp_dir(), 'cppfresh') . '.cjs';
        file_put_contents($file, trim($data[0]) . "\n" . $block[1] . "\nconst out = {};\n" . $script . "\nconsole.log(JSON.stringify(out));\n");
        $p = new Process([$node, $file]);
        $p->run();
        @unlink($file);
        $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());

        return json_decode($p->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_spec250_S1_happy_the_server_times_and_the_line_for_one_day(): void
    {
        $this->spend('Mobile Tripod', '2026-11-09 10:00:05');
        $this->spend('Mobile Tripod', '2026-11-09 18:45:12');
        $this->sale('Mobile Tripod', '10:12 09-11-2026');
        $this->sale('Mobile Tripod', '21:58 09-11-2026');
        $this->sale('Mobile Tripod', '09:30 09-11-2026');

        $res = $this->cpp();
        $this->assertSame(['Mobile Tripod' => [self::DAY => ['ads' => '2026-11-09 18:45', 'order' => '2026-11-09 21:58']]], $res->viewData('freshness'));

        $out = $this->js($res->getContent(), "out.line = cppFreshLine(null, null); out.stamp = cppFreshStamp('2026-11-09 18:45');");
        $this->assertSame('Nov 9, 6:45 PM', $out['stamp']);
        $this->assertStringStartsWith('Ads spend as of Nov 9, 6:45 PM · Last order Nov 9, 9:58 PM', $out['line']['text']);
        // 6:45 PM to 9:58 PM is 3 h 13 min, more than 60 minutes: amber with the note.
        $this->assertSame('Ads spend as of Nov 9, 6:45 PM · Last order Nov 9, 9:58 PM (ads spend is 3 h 13 min behind orders; CPP may look low)', $out['line']['text']);
        $this->assertTrue($out['line']['warn']);
    }

    public function test_spec250_S1_lag_warning_only_above_60_minutes(): void
    {
        $this->spend('Exactly', '2026-11-09 18:45:00');
        $this->sale('Exactly', '19:45 09-11-2026');
        $this->spend('Over', '2026-11-09 18:45:00');
        $this->sale('Over', '19:46 09-11-2026');
        $this->spend('Ahead', '2026-11-09 18:45:00');
        $this->sale('Ahead', '15:00 09-11-2026');

        $out = $this->js($this->cpp()->getContent(),
            "out.exact = cppFreshLine(['Exactly'], null); out.over = cppFreshLine(['Over'], null); out.ahead = cppFreshLine(['Ahead'], null);");

        $this->assertSame(['text' => 'Ads spend as of Nov 9, 6:45 PM · Last order Nov 9, 7:45 PM', 'warn' => false], $out['exact']);
        $this->assertSame(['text' => 'Ads spend as of Nov 9, 6:45 PM · Last order Nov 9, 7:46 PM (ads spend is 1 h 1 min behind orders; CPP may look low)', 'warn' => true], $out['over']);
        $this->assertSame(['text' => 'Ads spend as of Nov 9, 6:45 PM · Last order Nov 9, 3:00 PM', 'warn' => false], $out['ahead']);
    }

    public function test_spec250_S1_empty_no_ads_or_no_orders_and_no_warning(): void
    {
        $this->sale('Orders Only', '21:58 09-11-2026');
        $this->spend('Ads Only', '2026-11-09 18:45:00');

        $res = $this->cpp();
        $this->assertSame([
            'Ads Only'    => [self::DAY => ['ads' => '2026-11-09 18:45', 'order' => null]],
            'Orders Only' => [self::DAY => ['ads' => null, 'order' => '2026-11-09 21:58']],
        ], $res->viewData('freshness'));

        $out = $this->js($res->getContent(),
            "out.noAds = cppFreshLine(['Orders Only'], null); out.noOrders = cppFreshLine(['Ads Only'], null); out.none = cppFreshLine(['Nobody'], null);");
        $this->assertSame(['text' => 'Ads spend: none for this range · Last order Nov 9, 9:58 PM', 'warn' => false], $out['noAds']);
        $this->assertSame(['text' => 'Ads spend as of Nov 9, 6:45 PM · Last order: none', 'warn' => false], $out['noOrders']);
        $this->assertSame(['text' => 'Ads spend: none for this range · Last order: none', 'warn' => false], $out['none']);
    }

    public function test_spec250_S1_an_empty_range_embeds_no_times(): void
    {
        $res = $this->cpp();
        $this->assertSame([], $res->viewData('freshness'));
        $out = $this->js($res->getContent(), 'out.line = cppFreshLine(null, null);');
        $this->assertSame(['text' => 'Ads spend: none for this range · Last order: none', 'warn' => false], $out['line']);
    }

    public function test_spec250_S1_multi_day_range_takes_the_latest_across_the_range_with_dates(): void
    {
        $this->spend('Mobile Tripod', '2026-11-07 18:45:00', '2026-11-07');
        $this->spend('Mobile Tripod', '2026-11-08 15:00:00', '2026-11-08');
        $this->spend('mobile  tripod', '2026-11-08 15:02:00', '2026-11-08'); // same page, other spelling
        $this->spend('Bag', '2026-11-07 10:00:00', '2026-11-07');
        $this->sale('Mobile Tripod', '21:58 07-11-2026');
        $this->sale('Bag', '08:15 09-11-2026');
        $this->sale('Bag', '23:59 06-11-2026'); // before the range
        $this->spend('Bag', '2026-11-10 10:00:00', '2026-11-10'); // after the range

        $res = $this->cpp('2026-11-07', '2026-11-09');
        $f = $res->viewData('freshness');
        $this->assertSame(['ads' => '2026-11-08 15:02', 'order' => null], $f['Mobile Tripod']['2026-11-08']);
        $this->assertSame(['ads' => '2026-11-07 18:45', 'order' => '2026-11-07 21:58'], $f['Mobile Tripod']['2026-11-07']);
        $this->assertSame(['ads' => null, 'order' => '2026-11-09 08:15'], $f['Bag']['2026-11-09']);
        $this->assertArrayNotHasKey('2026-11-06', $f['Bag']);
        $this->assertArrayNotHasKey('2026-11-10', $f['Bag']);

        $out = $this->js($res->getContent(), "out.all = cppFreshLine(null, null); out.tripod = cppFreshLine(['Mobile Tripod'], null);");
        $this->assertSame(['text' => 'Ads spend as of Nov 8, 3:02 PM · Last order Nov 9, 8:15 AM (ads spend is 17 h 13 min behind orders; CPP may look low)', 'warn' => true], $out['all']);
        // One page (the page picker): only its times. Its last order (Nov 7) is before its ads time: no warning.
        $this->assertSame(['text' => 'Ads spend as of Nov 8, 3:02 PM · Last order Nov 7, 9:58 PM', 'warn' => false], $out['tripod']);
    }

    public function test_spec250_S2_per_page_and_per_date_tooltips_with_and_without_orders(): void
    {
        $this->spend('Mobile Tripod', '2026-11-08 18:45:00', '2026-11-08');
        $this->spend('Mobile Tripod', '2026-11-09 10:00:00', '2026-11-09');
        $this->sale('Mobile Tripod', '20:01 08-11-2026');
        $this->spend('Ads Only', '2026-11-09 18:45:00');
        $this->sale('Orders Only', '21:58 09-11-2026');

        $res = $this->cpp('2026-11-08', '2026-11-09');
        $out = $this->js($res->getContent(), <<<'JS'
            out.tripod  = cppFreshTip('Mobile Tripod', null);
            out.day8    = cppFreshTip('Mobile Tripod', ['2026-11-08']);
            out.day9    = cppFreshTip('Mobile Tripod', ['2026-11-09']);
            out.adsOnly = cppFreshTip('Ads Only', null);
            out.ordOnly = cppFreshTip('Orders Only', null);
            JS);

        $this->assertSame('Ads spend updated Nov 9, 10:00 AM · Last order Nov 8, 8:01 PM', $out['tripod']);
        $this->assertSame('Ads spend updated Nov 8, 6:45 PM · Last order Nov 8, 8:01 PM', $out['day8']);
        $this->assertSame('Ads spend updated Nov 9, 10:00 AM · Last order none', $out['day9']);
        $this->assertSame('Ads spend updated Nov 9, 6:45 PM · Last order none', $out['adsOnly']);
        $this->assertSame('Ads spend updated none · Last order Nov 9, 9:58 PM', $out['ordOnly']);
    }

    public function test_spec250_the_page_places_the_line_outside_the_tables_and_keeps_the_matrix_keys(): void
    {
        $this->spend('Mobile Tripod', '2026-11-09 18:45:00');
        $this->sale('Mobile Tripod', '21:58 09-11-2026');

        $res = $this->cpp();
        $html = $res->getContent();

        // The line is its own element above the tables (outside #rightTableContainer / #multiPageTables, so Copy Table
        // never copies it), grey and small by default.
        $this->assertMatchesRegularExpression('#<div id="cppFreshness" class="text-xs text-gray-500[^"]*"[^>]*></div>#', $html);
        $this->assertLessThan(strpos($html, 'id="singlePageLayout"'), strpos($html, 'id="cppFreshness"'));
        // Filled by textContent; the tooltips go through esc() into title attributes; amber for the warning.
        $this->assertStringContainsString('freshEl.textContent = line.text;', $html);
        $this->assertStringContainsString('title="${esc(cppFreshTip(page, filteredDates))}"', $html);
        $this->assertStringContainsString('title="${esc(cppFreshTip(pageFilter, [date]))}"', $html);
        $this->assertStringContainsString('text-amber-700', $html);

        // The existing cell keys are unchanged.
        $cell = $res->viewData('matrix')['Mobile Tripod'][self::DAY];
        $this->assertSame(['cpp', 'orders', 'cpm', 'cpi', 'spent', 'item_names', 'item_pairs', 'cods', 'tcpr_fail', 'proceed'], array_keys($cell));
    }

    public function test_spec250_the_copy_snapshot_is_unchanged(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-09 19:00:00', 'Asia/Manila'));
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_05_13_120000_create_cpp_snapshots_table.php']]);
        $this->spend('Mobile Tripod', '2026-11-09 18:45:00');
        $this->sale('Mobile Tripod', '18:58 09-11-2026');

        $this->postJson('/ads_manager/cpp/snapshot')->assertOk()->assertJson(['pages_saved' => 1]);
        $row = DB::table('cpp_snapshots')->first();
        $this->assertSame('Mobile Tripod', $row->page_name);
        $this->assertEquals(1, $row->orders);
    }
}
