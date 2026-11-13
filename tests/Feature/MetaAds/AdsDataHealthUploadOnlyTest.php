<?php

namespace Tests\Feature\MetaAds;

use App\Services\MetaAds\DataHealth;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Incepxion: walang Meta Ads API connection (upload lang ang ads data). Ang data health ay hindi nagpapakita ng "Not
 * readable by API" sa mga upload account, kaya walang pulang paalala dahil lang walang API. May connection: gaya ng 253.
 */
class AdsDataHealthUploadOnlyTest extends MetaAdsTestCase
{
    private const TODAY = '2026-10-28';
    private const YESTERDAY = '2026-10-27';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::TODAY . ' 13:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function report(string $ad, string $account, string $day, float $spend): void
    {
        DB::table('ads_manager_reports')->insert(['day' => $day, 'ad_id' => $ad, 'page_name' => 'Page P1', 'amount_spent_php' => $spend,
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('ad_campaign_creatives')->updateOrInsert(['ad_id' => $ad], ['account_id' => $account]);
    }

    public function test_without_any_api_connection_uploaded_accounts_are_ok_and_no_red_notice_shows(): void
    {
        $this->report('u1', '333', '2026-10-10', 10);
        $this->report('u1', '333', self::YESTERDAY, 15);
        $this->report('u2', '334', self::YESTERDAY, 20);

        $s = array_column(DataHealth::evaluate()['accounts'], 'status', 'id');

        $this->assertSame([DataHealth::OK, DataHealth::OK], [$s['333'], $s['334']]);
        $saved = DataHealth::record(null, DataHealth::TRIGGER_UPLOAD);
        $this->assertSame(0, $saved['attention']);
        $this->actingAs($this->user('CEO'))->get('/ads_manager/report')->assertOk()->assertDontSee('id="ads-data-notice"', false);
    }

    public function test_without_any_api_connection_an_account_that_stopped_is_still_missing(): void
    {
        $this->report('m1', '444', '2026-10-26', 50);

        $s = array_column(DataHealth::evaluate()['accounts'], 'status', 'id');

        $this->assertSame(DataHealth::MISSING, $s['444']);
    }

    public function test_with_an_api_connection_an_unreadable_uploaded_account_is_still_flagged(): void
    {
        $this->connect($this->user('Marketing', 'ana@example.test'), self::TOKEN_ANA, ['111'], ['connected_at' => '2026-10-01T09:00:00+08:00']);
        $this->report('u1', '333', self::YESTERDAY, 15);

        $s = array_column(DataHealth::evaluate()['accounts'], 'status', 'id');

        $this->assertSame(DataHealth::NOT_READABLE, $s['333']);
    }
}
