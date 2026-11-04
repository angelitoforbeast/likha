<?php

namespace Tests\Feature\CPP;

use App\Services\Chatbot\AdGroups;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Chatblast\ChatblastTestCase;

/**
 * /ads_manager/cpp: ang Ad-based na page ay may row kada ad group sa ilalim ng row nito. Ang order ay sa group ng ad na
 * nakatala sa order (macro_output.botcake_ad_id); ang gastos ay sa group ng ad (o ad set, o campaign) ng report row.
 * Ang mga row ay nagdaragdag hanggang sa row ng page ("Not in a group" at "Unknown ad" para sa natitira).
 */
class CppAdGroupRowsTest extends ChatblastTestCase
{
    private const DAY = '2026-10-05';

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
        });
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_10_23_100000_create_chatbot_tables.php']]);
        $this->actingAs($this->user());
    }

    private function spend(string $page, ?string $ad, float $php, string $day = self::DAY, ?string $adSet = null, ?string $campaign = null, int $msgs = 10, int $imps = 1000): void
    {
        DB::table('ads_manager_reports')->insert(['day' => $day, 'page_name' => $page, 'ad_id' => $ad, 'ad_set_id' => $adSet, 'campaign_id' => $campaign,
            'amount_spent_php' => $php, 'messaging_conversations_started' => $msgs, 'impressions' => $imps]);
    }

    private function sale(string $page, ?string $ad, string $status = 'PROCEED', string $day = self::DAY, string $item = 'Tripod'): void
    {
        DB::table('macro_output')->insert(['PAGE' => $page, 'TIMESTAMP' => '10:00 ' . date('d-m-Y', strtotime($day)), 'ts_date' => $day,
            'STATUS' => $status, 'ITEM_NAME' => $item, 'COD' => '499', 'botcake_ad_id' => $ad]);
    }

    private function adBased(string $page, array $groups): array
    {
        $g = app(AdGroups::class);
        $g->setMode($page, true);
        $ids = [];
        foreach ($groups as $name => $ads) $ids[$name] = $g->save($page, null, $name, $ads, [])['id'];

        return $ids;
    }

    private function rows(string $start = self::DAY, string $end = self::DAY): array
    {
        return $this->get('/ads_manager/cpp?start=' . $start . '&end=' . $end)->assertOk()->viewData('adGroupRows');
    }

    /** Ang kabuuan ng isang row sa buong range (gaya ng sa screen: ang order ay sa araw na may gastos ang page). */
    private static function total(array $row, array $matrixPage): array
    {
        $out = ['spent' => 0.0, 'orders' => 0, 'tcpr_fail' => 0];
        foreach ($row['cells'] as $date => $c) {
            $out['spent'] += $c['spent'];
            if (($matrixPage[$date]['spent'] ?? 0) > 0) { $out['orders'] += $c['orders']; $out['tcpr_fail'] += $c['tcpr_fail']; }
        }

        return $out;
    }

    public function test_S_225_3_1_two_ad_groups_and_the_rest_add_up_to_the_page_row(): void
    {
        $this->adBased('Page P', ['MOBILE TRIPOD' => ['111', '112'], 'RING LIGHT' => ['222']]);
        $this->spend('Page P', '111', 100); $this->spend('Page P', '112', 50); $this->spend('Page P', '222', 80); $this->spend('Page P', '999', 20);
        $this->sale('Page P', '111'); $this->sale('Page P', '112', 'CANNOT PROCEED'); $this->sale('Page P', '222');
        $this->sale('Page P', '999'); $this->sale('Page P', null); $this->sale('Page P', '');

        $response = $this->get('/ads_manager/cpp?start=' . self::DAY . '&end=' . self::DAY)->assertOk();
        $rows = $response->viewData('adGroupRows')['Page P'];
        $page = $response->viewData('matrix')['Page P'];

        $this->assertSame(['Ad group: MOBILE TRIPOD', 'Ad group: RING LIGHT', 'Not in a group', 'Unknown ad'], array_column($rows, 'label'));
        $tripod = $rows[0]['cells'][self::DAY];
        $this->assertSame([150.0, 2, 75.0, 1, 1], [$tripod['spent'], $tripod['orders'], $tripod['cpp'], $tripod['tcpr_fail'], $tripod['proceed']]);
        $this->assertSame([7.5, 75.0], [$tripod['cpm'], $tripod['cpi']], 'CPM and CPI on the group numbers: 150 / 20 messages, 150 * 1000 / 2000');
        $this->assertSame([80.0, 1], [$rows[1]['cells'][self::DAY]['spent'], $rows[1]['cells'][self::DAY]['orders']]);
        $this->assertSame([20.0, 1], [$rows[2]['cells'][self::DAY]['spent'], $rows[2]['cells'][self::DAY]['orders']]);
        $this->assertSame([0.0, 2, 0.0], [$rows[3]['cells'][self::DAY]['spent'], $rows[3]['cells'][self::DAY]['orders'], $rows[3]['cells'][self::DAY]['cpp']], 'no spend of its own: same formula');

        $sum = ['spent' => 0.0, 'orders' => 0, 'tcpr_fail' => 0];
        foreach ($rows as $r) foreach (self::total($r, $page) as $k => $v) $sum[$k] += $v;
        $this->assertSame(['spent' => 250.0, 'orders' => 6, 'tcpr_fail' => 1], $sum);
        $this->assertSame([250.0, 6, 1], [$page[self::DAY]['spent'], $page[self::DAY]['orders'], $page[self::DAY]['tcpr_fail']]);
    }

    public function test_S_225_3_2_a_page_based_page_has_no_group_rows(): void
    {
        app(AdGroups::class)->save('Page Q', null, 'Kept but unused', ['333'], []); // groups without Ad-based mode
        $this->spend('Page Q', '333', 40);
        $this->sale('Page Q', '333');

        $this->assertSame([], $this->rows());
        $this->get('/ads_manager/cpp?start=' . self::DAY . '&end=' . self::DAY)->assertViewHas('matrix', fn ($m) => $m['Page Q'][self::DAY]['orders'] === 1);
    }

    public function test_S_225_3_3_a_group_with_spend_and_no_orders_has_no_cpp(): void
    {
        $this->adBased('Page P', ['MOBILE TRIPOD' => ['111'], 'RING LIGHT' => ['222']]);
        $this->spend('Page P', '111', 100);
        $this->spend('Page P', '222', 60);
        $this->sale('Page P', '111');

        $rows = $this->rows()['Page P'];

        $ring = $rows[1]['cells'][self::DAY];
        $this->assertSame(['Ad group: RING LIGHT', 60.0, 0, null], [$rows[1]['label'], $ring['spent'], $ring['orders'], $ring['cpp']]);
        $this->assertCount(2, $rows, 'no remainder rows when everything is in a group');
    }

    public function test_S_225_3_4_ad_set_and_campaign_ids_in_a_group_take_their_ads_and_the_ad_itself_wins(): void
    {
        $this->adBased('Page P', ['BY SET' => ['5000'], 'BY CAMPAIGN' => ['9000'], 'ONE AD' => ['113']]);
        $this->spend('Page P', '111', 10, adSet: '5000', campaign: '9000');
        $this->spend('Page P', '112', 20, adSet: '5001', campaign: '9000');
        $this->spend('Page P', '113', 30, adSet: '5000', campaign: '9000'); // the ad itself is in ONE AD
        $this->sale('Page P', '111'); $this->sale('Page P', '112'); $this->sale('Page P', '113');

        $rows = collect($this->rows()['Page P'])->keyBy('label');

        $this->assertSame([10.0, 1], [$rows['Ad group: BY SET']['cells'][self::DAY]['spent'], $rows['Ad group: BY SET']['cells'][self::DAY]['orders']]);
        $this->assertSame([20.0, 1], [$rows['Ad group: BY CAMPAIGN']['cells'][self::DAY]['spent'], $rows['Ad group: BY CAMPAIGN']['cells'][self::DAY]['orders']]);
        $this->assertSame([30.0, 1], [$rows['Ad group: ONE AD']['cells'][self::DAY]['spent'], $rows['Ad group: ONE AD']['cells'][self::DAY]['orders']]);
    }

    public function test_S_225_3_5_the_page_draws_the_group_rows_under_the_page_and_keeps_them_out_of_the_copy(): void
    {
        $this->adBased('Page P', ['<b>MOBILE</b> TRIPOD' => ['111']]);
        $this->spend('Page P', '111', 100);
        $this->sale('Page P', '111');

        $html = $this->get('/ads_manager/cpp?start=' . self::DAY . '&end=' . self::DAY)->assertOk()->getContent();

        $this->assertStringContainsString('const adGroupRows = ', $html);
        $this->assertStringContainsString('cpp-ag-row', $html);
        $this->assertStringContainsString("withAdGroups || !row.classList.contains('cpp-ag-row')", $html, 'Copy Table keeps the sheet shape');
        $this->assertStringContainsString('onclick="copySummaryOfAds()"', $html, 'Copy Table: one row per page');
        $this->assertStringContainsString('onclick="copySummaryOfAds(true)"', $html, 'a second button copies the ad group rows too');
        $this->assertStringContainsString('Copy with ad groups', $html);
        $this->assertStringContainsString('esc(g.label)', $html, 'group names are escaped');
        $this->assertStringNotContainsString('<b>MOBILE</b>', $html);
    }

    public function test_S_225_4_2_the_cpp_group_rows_follow_the_same_grouping_rule(): void
    {
        $html = $this->get('/ads_manager/cpp?start=' . self::DAY . '&end=' . self::DAY)->assertOk()->getContent();

        $this->assertStringContainsString('#summaryOfAdsTable tr.cpp-page-row:not(:first-child) > td { border-top: 2px solid #94a3b8; }', $html, 'thick line between pages');
        $this->assertStringContainsString('#summaryOfAdsTable tr.cpp-ag-row + tr.cpp-ag-row > td { border-top: 1px solid #cbd5e1; }', $html, 'thin lighter line between ad groups');
        $this->assertStringContainsString('#summaryOfAdsTable tr.cpp-ag-row > td:first-child { padding-left: 1.75rem;', $html, 'indented under the page');
        $this->assertStringContainsString('<tr class="cpp-page-row">', $html);
    }
}
