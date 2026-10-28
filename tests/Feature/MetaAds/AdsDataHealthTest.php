<?php

namespace Tests\Feature\MetaAds;

use App\Models\UploadLog;
use App\Services\MetaAds\DataHealth;
use App\Services\MetaAds\Sync;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

/**
 * Data health ng /ads_manager/report: bawat ad account (connection, /ads_manager/ad_account, upload) at kung may data
 * kahapon; ang 12:00 na check na itinatala, ang pulang paalala, at `ads:data-health --json`.
 */
class AdsDataHealthTest extends MetaAdsTestCase
{
    private const TODAY = '2026-10-28';
    private const YESTERDAY = '2026-10-27';

    protected function migrationPaths(): array
    {
        return array_merge(parent::migrationPaths(), [
            'database/migrations/2025_10_19_154416_create_ad_accounts_table.php',
            'database/migrations/2026_10_31_100000_add_history_to_ad_accounts_table.php',
        ]);
    }

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

    /** Isang row sa ads_manager_reports; $account = ang ad_campaign_creatives.account_id ng ad (null = hindi alam). */
    private function report(string $ad, ?string $account, string $day, float $spend): void
    {
        DB::table('ads_manager_reports')->insert(['day' => $day, 'ad_id' => $ad, 'page_name' => 'Page P1', 'amount_spent_php' => $spend,
            'created_at' => now(), 'updated_at' => now()]);
        if ($account !== null) DB::table('ad_campaign_creatives')->updateOrInsert(['ad_id' => $ad], ['account_id' => $account]);
    }

    private const OLD = ['connected_at' => '2026-10-01T09:00:00+08:00'];

    /**
     * 111 API, may data kahapon; 333 upload lang, may data kahapon; 444 API, may spend noong 26 at wala kahapon; 555 bagong
     * lumabas sa connection ngayon; 666 inalisan ng check; 777 nasa /ads_manager/ad_account lang; at isang ad na hindi alam
     * ang account.
     */
    private function world(): void
    {
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111', '444', '666'], self::OLD);
        Sync::setAccounts(['111', '444']);
        DataHealth::evaluate(); // ang unang tingin: kilala na ang 111, 444, 666
        $this->connect($ana, self::TOKEN_ANA, ['111', '444', '555', '666'], self::OLD);

        $this->report('a1', '111', '2026-10-20', 40);
        $this->report('a1', '111', self::YESTERDAY, 120.5);
        $this->report('a2', '111', self::YESTERDAY, 30);
        $this->report('u1', '333', '2026-10-10', 10);
        $this->report('u1', '333', self::YESTERDAY, 15);
        $this->report('m1', '444', '2026-10-26', 50);
        $this->report('z1', null, self::YESTERDAY, 5);
        DB::table('ad_accounts')->insert(['ad_account_id' => '777', 'name' => 'Old Shop', 'active' => true, 'action' => 'added',
            'created_at' => '2026-09-01 10:00:00', 'updated_at' => '2026-09-01 10:00:00']);
    }

    public function test_S_178_1_the_coverage_list_gives_each_account_its_source_and_status(): void
    {
        $this->world();

        $got = [];
        foreach (DataHealth::evaluate()['accounts'] as $a) $got[$a['id']] = [$a['source'], $a['status']];
        ksort($got, SORT_STRING);

        $this->assertSame([
            ''    => ['upload only', 'OK'],
            '111' => ['API selected', 'OK'],
            '333' => ['upload only', 'OK'],
            '444' => ['API selected', 'Missing yesterday'],
            '555' => ['API selected', 'New'],
            '666' => ['API unticked', 'Not synced (unticked)'],
            '777' => ['none', 'Not readable by API'],
        ], $got);
        $a111 = collect(DataHealth::evaluate()['accounts'])->firstWhere('id', '111');
        $this->assertSame([self::YESTERDAY, 2, 150.5, 'Account 111'], [$a111['latest_day'], $a111['rows'], $a111['spend'], $a111['name']]);
        $this->assertSame('Old Shop', collect(DataHealth::evaluate()['accounts'])->firstWhere('id', '777')['name']);
    }

    public function test_S_178_1_an_uploaded_ad_without_its_own_creatives_row_takes_its_campaigns_account(): void
    {
        // Sa production, unique ang ad_campaign_creatives.campaign_id: isang ad lang kada campaign ang may row.
        DB::table('ad_campaign_creatives')->insert(['ad_id' => 'a1', 'campaign_id' => 'c9', 'account_id' => '111']);
        DB::table('ads_manager_reports')->insert([
            ['day' => self::YESTERDAY, 'ad_id' => 'a1', 'campaign_id' => 'c9', 'page_name' => 'P', 'amount_spent_php' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['day' => self::YESTERDAY, 'ad_id' => 'a2', 'campaign_id' => 'c9', 'page_name' => 'P', 'amount_spent_php' => 5, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $got = collect(DataHealth::evaluate()['accounts'])->keyBy('id');

        $this->assertSame([2, 15.0], [$got['111']['rows'], $got['111']['spend']]);
        $this->assertFalse($got->has(''), 'walang "Account unknown"');
    }

    public function test_S_178_2_the_noon_check_records_the_result_shows_the_red_notice_and_prints_json(): void
    {
        $this->world();

        $this->artisan('ads:data-health', ['--record' => true])->assertSuccessful();

        $saved = DataHealth::last();
        $this->assertSame([self::YESTERDAY, 3], [$saved['day'], $saved['attention']]);
        $this->actingAs($this->user('CEO'))->get('/ads_manager/report')
            ->assertSee('id="ads-data-notice"', false)->assertSee('3 ad accounts need attention')->assertSee('Old Shop');

        Artisan::call('ads:data-health', ['--json' => true]);
        $this->assertSame($saved, json_decode(Artisan::output(), true));

        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'ads:data-health --record'));
        $this->assertSame(['0 12 * * *', 'Asia/Manila'], [$event->expression, $event->timezone]);
    }

    public function test_S_178_2_no_red_notice_when_every_account_is_ok(): void
    {
        $this->connect($this->user('Marketing', 'ana@example.test'), self::TOKEN_ANA, ['111'], self::OLD);
        $this->report('a1', '111', '2026-10-20', 40);
        $this->report('a1', '111', self::YESTERDAY, 120.5);

        $this->artisan('ads:data-health', ['--record' => true])->assertSuccessful();

        $this->assertSame(0, DataHealth::last()['attention']);
        $this->actingAs($this->user('CEO'))->get('/ads_manager/report')->assertSee('Data health')->assertDontSee('id="ads-data-notice"', false);
    }

    public function test_S_178_3_the_box_shows_the_daily_total_of_refused_rows(): void
    {
        $src = UploadLog::create(['type' => 'ads_manager_reports', 'disk' => 'local', 'path' => 'x', 'original_name' => 'x.xlsx', 'status' => 'done']);
        DataHealth::recordRefusals($src, 'upload', [
            ['day' => self::YESTERDAY, 'ad_id' => 'a1', 'account' => '111', 'metrics' => ['reach' => ['stored' => 9, 'incoming' => 8]]],
            ['day' => self::YESTERDAY, 'ad_id' => 'a2', 'account' => '111', 'metrics' => ['reach' => ['stored' => 9, 'incoming' => 8]]],
        ]);

        $this->assertSame(['yesterday' => 0, 'today' => 2], DataHealth::evaluate()['refused']);
        $this->actingAs($this->user('CEO'))->get('/ads_manager/report')->assertSee('Refused (lower values): today 2 rows · yesterday 0 rows');
    }

    public function test_S_178_4_a_role_without_the_ads_page_does_not_see_the_box_or_the_notice(): void
    {
        $this->world();
        $this->artisan('ads:data-health', ['--record' => true])->assertSuccessful();
        $src = UploadLog::create(['type' => 'ads_manager_reports', 'disk' => 'local', 'path' => 'x', 'original_name' => 'x.xlsx', 'status' => 'done']);
        DataHealth::recordRefusals($src, 'upload', [['day' => self::YESTERDAY, 'ad_id' => 'a1', 'account' => '111', 'metrics' => ['reach' => ['stored' => 9, 'incoming' => 8]]]]);

        $this->actingAs($this->user('Logistics', 'log@example.test'))->get('/ads_manager/report')->assertOk()
            ->assertDontSee('Data health')->assertDontSee('id="ads-data-notice"', false)->assertDontSee('Old Shop')
            ->assertDontSee('refused (lower values)');
        $this->actingAs($this->user('Marketing', 'mk@example.test'))->get('/ads_manager/report')->assertSee('Data health')->assertSee('1 row refused (lower values)');
    }
}
