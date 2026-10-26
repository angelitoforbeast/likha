<?php

namespace Tests\Feature\MetaAds;

use Illuminate\Support\Facades\DB;

/** Compare with uploads: API laban sa ads_manager_reports (day + ad_id), kada ad account; walang isinusulat. */
class MetaAdsCompareTest extends MetaAdsTestCase
{
    private const DAY = '2026-10-20';

    private function upload(string $ad, float $spend): void
    {
        DB::table('ads_manager_reports')->insert([
            'day' => self::DAY, 'ad_id' => $ad, 'page_name' => 'Page P1', 'amount_spent_php' => $spend,
            'results' => 12, 'reach' => 900, 'link_clicks' => 33, 'messaging_conversations_started' => 12,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedTwoAccounts(): void
    {
        $ana = $this->user('Marketing', 'ana@example.test');
        $ben = $this->user('Marketing - OIC', 'ben@example.test');
        // Si Ana ay may 111 at 222; si Ben ay may 222 din: isang beses lang binabasa ang 222.
        $this->connect($ana, self::TOKEN_ANA, ['111', '222']);
        $this->connect($ben, self::TOKEN_BEN, ['222']);

        $this->upload('a1', 90.00);   // API 100 → +11.1%
        $this->upload('b1', 70.00);   // pareho
        $this->upload('u9', 15.00);   // nasa upload lang (ad ng account 111 ayon sa creatives)
        DB::table('ad_campaign_creatives')->insert(['ad_id' => 'u9', 'account_id' => '111', 'created_at' => now(), 'updated_at' => now()]);

        $this->fakeGraph(insights: [
            '111' => [[self::insight('111', 'a1', self::DAY, 100.00)], [self::insight('111', 'a2', self::DAY, 50.00)]],
            '222' => [self::insight('222', 'b1', self::DAY, 70.00)],
        ], paged: ['111']);
        $this->actingAs($ana);
    }

    public function test_S_154_3_compare_reports_only_in_api_only_in_upload_and_differences_per_account_and_writes_nothing(): void
    {
        $this->seedTwoAccounts();
        $before = $this->reportRows();

        $res = $this->post('/ads_manager/meta/compare', ['from' => self::DAY, 'to' => self::DAY]);

        $res->assertRedirect('/ads_manager/report');
        $report = session('meta_compare');
        $byId = collect($report['accounts'])->keyBy('account_id');
        $this->assertSame(['111', '222'], array_column($report['accounts'], 'account_id'));

        $a = $byId['111'];
        $this->assertSame([2, 2, 0], [$a['api_rows'], $a['upload_rows'], $a['matched']]);
        $this->assertSame([['day' => self::DAY, 'ad_id' => 'a2', 'spend' => 50.0]], $a['only_api']);
        $this->assertSame([['day' => self::DAY, 'ad_id' => 'u9', 'spend' => 15.0]], $a['only_upload']);
        $this->assertSame([['day' => self::DAY, 'ad_id' => 'a1', 'fields' => ['amount_spent_php' => ['api' => 100.0, 'upload' => 90.0, 'pct' => 11.1]]]], $a['differ']);

        $b = $byId['222'];
        $this->assertSame([1, 1, 1, 0, 0, 0], [$b['api_rows'], $b['upload_rows'], $b['matched'], $b['only_api_count'], $b['only_upload_count'], $b['differ_count']]);

        // Isang beses lang ang 222 (kahit dalawa ang may access); walang /ads (detalye) sa Compare.
        $this->assertSame(1, count(array_filter($this->graphCalls, fn ($p) => $p === 'act_222/insights')));
        $this->assertNotContains('act_111/ads', $this->graphCalls);
        $this->assertEquals($before, $this->reportRows());

        $this->get('/ads_manager/report')->assertSee('Only in API: 1')->assertSee('amount_spent_php API 100 vs upload 90 (+11.1%)', false);
    }

    public function test_S_154_3_the_compare_command_prints_the_same_report_and_writes_nothing(): void
    {
        $this->seedTwoAccounts();
        $before = $this->reportRows();

        $this->artisan('meta-ads:compare', ['--from' => self::DAY, '--to' => self::DAY])
            ->expectsOutputToContain('2026-10-20 ad a1: amount_spent_php 100 vs 90 (11.1%)')
            ->expectsOutputToContain('only in API 1, only in upload 1, differing 1')
            ->assertSuccessful();

        $this->assertEquals($before, $this->reportRows());
    }
}
