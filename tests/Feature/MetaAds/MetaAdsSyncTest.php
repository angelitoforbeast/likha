<?php

namespace Tests\Feature\MetaAds;

use App\Models\UploadLog;
use App\Services\MetaAds\Connections;
use App\Services\MetaAds\Sync;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;

/** Sync now / schedule / write mode: API → ads_manager_reports sa parehong upsert ng upload. */
class MetaAdsSyncTest extends MetaAdsTestCase
{
    private const DAY = '2026-10-20';

    private function existing(string $ad, float $spend): void
    {
        DB::table('ads_manager_reports')->insert([
            'day' => self::DAY, 'ad_id' => $ad, 'page_name' => 'Page P1', 'amount_spent_php' => $spend, 'reach' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_S_154_4_each_column_is_filled_from_the_api_as_the_upload_would(): void
    {
        Sync::setWriteMode(true);
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111']);
        $welcome = json_encode(['type' => 'VISUAL_EDITOR', 'text_format' => ['message' => [
            'text' => 'Hi po! Ano ang order ninyo?',
            'ice_breakers' => [['title' => 'Magkano?'], ['title' => 'COD po ba?'], ['title' => 'Saan location?'], ['title' => 'Ika-apat']],
        ]]]);
        $this->fakeGraph(
            insights: ['111' => [self::insight('111', 'a1', self::DAY, 250.50)]],
            ads: ['111' => [self::adDetail('a1', [
                'campaign' => ['effective_status' => 'PAUSED'],
                'adset'    => ['end_time' => '2026-10-31T23:59:00+0800'],
                'creative' => ['page_welcome_message' => $welcome],
            ])]],
        );
        $this->actingAs($ana);

        $this->post('/ads_manager/meta/sync', ['from' => self::DAY, 'to' => self::DAY])->assertRedirect('/ads_manager/report');

        $expected = [
            'day' => self::DAY, 'ad_id' => 'a1', 'page_name' => 'Page P1',
            'campaign_id' => 'ca1', 'campaign_name' => 'Campaign a1', 'ad_set_id' => 'asa1', 'ad_set_name' => 'Adset a1',
            'campaign_delivery' => 'not_delivering', 'ad_set_delivery' => 'active',
            'amount_spent_php' => '250.5', 'reach' => '900', 'impressions' => '1500', 'link_clicks' => '33',
            'messaging_conversations_started' => '12', 'purchases' => '2', 'results' => '12', 'cost_per_result' => '20.88',
            'result_type' => 'Messaging conversations started', 'attribution_setting' => '7-day click or 1-day view',
            'ad_set_budget' => '500', 'ad_set_budget_type' => 'Daily',
            'starts' => '2026-10-01 08:00:00', 'ends' => '2026-10-31 23:59:00',
            'reporting_starts' => self::DAY . ' 00:00:00', 'reporting_ends' => self::DAY . ' 00:00:00',
            'headline' => 'Headline a1', 'body_ad_settings' => 'Body a1',
            'welcome_message' => 'Hi po! Ano ang order ninyo?', 'quick_reply_1' => 'Magkano?', 'quick_reply_2' => 'COD po ba?', 'quick_reply_3' => 'Saan location?',
        ];
        $row = $this->reportRows()[0];
        $actual = [];
        foreach ($expected as $col => $_) {
            $v = (string) $row[$col];
            $actual[$col] = is_numeric($v) && str_contains($v, '.') && !str_contains($v, '-') ? rtrim(rtrim($v, '0'), '.') : $v;
        }
        $this->assertSame($expected, $actual);
        $this->assertSame('111', DB::table('ad_campaign_creatives')->where('ad_id', 'a1')->value('account_id'));
    }

    public function test_S_154_5_sync_now_with_write_mode_on_inserts_new_and_updates_existing_by_day_and_ad_id_and_logs_the_run(): void
    {
        Sync::setWriteMode(true);
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111']);
        $this->existing('a1', 80.00);
        $this->fakeGraph(
            insights: ['111' => [self::insight('111', 'a1', self::DAY, 100.00), self::insight('111', 'a2', self::DAY, 40.00)]],
            ads: ['111' => [self::adDetail('a1'), self::adDetail('a2')]],
        );
        $this->actingAs($ana);

        $this->post('/ads_manager/meta/sync', ['from' => self::DAY, 'to' => self::DAY])->assertRedirect('/ads_manager/report');

        $this->assertSame(['a1' => 100.0, 'a2' => 40.0], DB::table('ads_manager_reports')->orderBy('ad_id')->pluck('amount_spent_php', 'ad_id')
            ->map(fn ($v) => (float) $v)->all());
        $this->assertSame(1, DB::table('ads_manager_reports')->where('ad_id', 'a1')->count());
        $log = DB::table('upload_logs')->where('type', 'ads_manager_api')->first();
        $this->assertSame(['done', 1, 1, 0, $ana->id], [$log->status, (int) $log->inserted, (int) $log->updated, (int) $log->skipped, (int) $log->user_id]);
        $this->assertNotNull(app(Connections::class)->get($ana->id)['last_sync_at']);
    }

    public function test_S_154_5_sync_now_with_write_mode_off_is_refused_and_writes_nothing(): void
    {
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111']);
        $this->fakeGraph(insights: ['111' => [self::insight('111', 'a1', self::DAY, 100.00)]]);
        $this->actingAs($ana);

        $this->post('/ads_manager/meta/sync')->assertRedirect('/ads_manager/report')->assertSessionHasErrors('meta');

        $this->assertSame([], $this->reportRows());
        $this->assertSame([], $this->graphCalls);
        $this->assertFalse(DB::table('upload_logs')->where('type', 'ads_manager_api')->exists());
    }

    public function test_S_154_6_error_190_marks_that_token_expired_shows_reconnect_and_the_others_still_sync(): void
    {
        Sync::setWriteMode(true);
        $ana = $this->user('Marketing', 'ana@example.test');
        $ben = $this->user('Marketing - OIC', 'ben@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111']);
        $this->connect($ben, self::TOKEN_BEN, ['222']);
        $this->fakeGraph(
            insights: ['222' => [self::insight('222', 'b1', self::DAY, 70.00)]],
            ads: ['222' => [self::adDetail('b1')]],
            errors: [self::TOKEN_ANA => 190],
        );
        $this->actingAs($ana);

        $this->post('/ads_manager/meta/sync', ['from' => self::DAY, 'to' => self::DAY]);

        $this->assertSame('expired', app(Connections::class)->get($ana->id)['status']);
        $this->assertSame(['b1'], array_column($this->reportRows(), 'ad_id'));
        $log = DB::table('upload_logs')->where('type', 'ads_manager_api')->first();
        $this->assertSame('partial', $log->status);
        $this->assertStringContainsString('token expired', $log->error_message);
        $this->get('/ads_manager/report')->assertSee('Reconnect')->assertSee('Token expired');
    }

    public static function rateCodes(): array
    {
        return ['code 4' => [4], 'code 17' => [17], 'code 613' => [613]];
    }

    #[DataProvider('rateCodes')]
    public function test_S_154_6_rate_limits_back_off_then_log_a_partial_run_without_crashing(int $code): void
    {
        Sync::setWriteMode(true);
        $ana = $this->user('Marketing', 'ana@example.test');
        $ben = $this->user('Marketing - OIC', 'ben@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111']);
        $this->connect($ben, self::TOKEN_BEN, ['222']);
        $this->fakeGraph(
            insights: ['222' => [self::insight('222', 'b1', self::DAY, 70.00)]],
            ads: ['222' => [self::adDetail('b1')]],
            errors: [self::TOKEN_ANA => $code],
        );
        $this->actingAs($ana);

        $this->post('/ads_manager/meta/sync', ['from' => self::DAY, 'to' => self::DAY])->assertRedirect('/ads_manager/report');

        Sleep::assertSleptTimes(3);
        $this->assertSame(4, count(array_filter($this->graphCalls, fn ($p) => $p === 'act_111/insights')));
        $this->assertSame(['b1'], array_column($this->reportRows(), 'ad_id'));
        $log = DB::table('upload_logs')->where('type', 'ads_manager_api')->first();
        $this->assertSame(['partial', 'act_111: rate limited, skipped'], [$log->status, $log->error_message]);
        $this->assertSame('ok', app(Connections::class)->get($ana->id)['status']);
    }

    public static function scheduleCases(): array
    {
        return [
            'write mode off at a slot' => [false, '10:00', 0],
            'write mode on at a slot'  => [true, '18:45', 1],
            'write mode on off-slot'   => [true, '10:01', 0],
        ];
    }

    #[DataProvider('scheduleCases')]
    public function test_S_154_7_the_schedule_runs_only_when_write_mode_is_on_and_at_a_slot(bool $on, string $time, int $runs): void
    {
        Sync::setWriteMode($on);
        $this->connect($this->user('Marketing', 'ana@example.test'), self::TOKEN_ANA, ['111']);
        $this->fakeGraph(insights: ['111' => [self::insight('111', 'a1', self::DAY, 10.00)]], ads: ['111' => [self::adDetail('a1')]]);
        Carbon::setTestNow(Carbon::parse('2026-10-26 ' . $time, 'Asia/Manila'));

        $this->artisan('meta-ads:sync', ['--scheduled' => true])->assertSuccessful();

        $this->assertSame($runs, DB::table('upload_logs')->where('type', 'ads_manager_api')->count());
        $this->assertSame($runs > 0, $this->graphCalls !== []);
        Carbon::setTestNow();
    }

    public function test_S_154_7_the_scheduler_registers_the_meta_ads_sync_every_minute_only_behind_write_mode(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'meta-ads:sync --scheduled'));

        $this->assertSame('* * * * *', $event->expression);
        $this->assertFalse($event->filtersPass($this->app));
        Sync::setWriteMode(true);
        $this->assertTrue($event->filtersPass($this->app));
    }

    public static function notCeo(): array
    {
        return ['Marketing' => ['Marketing'], 'Marketing - OIC' => ['Marketing - OIC']];
    }

    #[DataProvider('notCeo')]
    public function test_S_154_9_only_the_ceo_turns_write_mode_on_or_off_and_it_is_logged(string $role): void
    {
        $this->actingAs($this->user($role, 'm@example.test'));
        $this->post('/ads_manager/meta/write-mode', ['write_mode' => 'on'])->assertForbidden();
        $this->assertFalse(Sync::writeMode());
        $this->get('/ads_manager/report')->assertDontSee('Write mode (CEO)');

        $ceo = $this->user('CEO', 'ceo@example.test');
        $this->actingAs($ceo);
        $this->post('/ads_manager/meta/write-mode', ['write_mode' => 'on', 'schedule' => '09:30, 14:00'])->assertRedirect('/ads_manager/report');

        $this->assertTrue(Sync::writeMode());
        $this->assertSame(['09:30', '14:00'], Sync::schedule());
        $this->assertSame([(string) 'write mode on (09:30, 14:00)', $ceo->id], [
            DB::table('upload_logs')->where('type', 'ads_manager_api_event')->value('original_name'),
            (int) DB::table('upload_logs')->where('type', 'ads_manager_api_event')->value('user_id'),
        ]);
    }

    public function test_S_154_9_an_uploader_role_can_compare_and_sync(): void
    {
        Sync::setWriteMode(true);
        $oic = $this->user('Marketing - OIC', 'oic@example.test');
        $this->connect($oic, self::TOKEN_ANA, ['111']);
        $this->fakeGraph(insights: ['111' => [self::insight('111', 'a1', self::DAY, 10.00)]], ads: ['111' => [self::adDetail('a1')]]);
        $this->actingAs($oic);

        $this->post('/ads_manager/meta/compare', ['from' => self::DAY, 'to' => self::DAY])->assertSessionHasNoErrors();
        $this->post('/ads_manager/meta/sync', ['from' => self::DAY, 'to' => self::DAY])->assertSessionHasNoErrors();

        $this->assertSame(['done', $oic->id], [
            DB::table('upload_logs')->where('type', 'ads_manager_api_compare')->value('status'),
            (int) DB::table('upload_logs')->where('type', 'ads_manager_api_compare')->value('user_id'),
        ]);
        $this->assertSame(['a1'], array_column($this->reportRows(), 'ad_id'));
    }

    public static function foreignHosts(): array
    {
        return [
            'other host'      => ['https://attacker.example/v25.0/act_111/insights?after=p2'],
            'look-alike host' => ['https://graph.facebook.com.evil.com/v25.0/act_111/insights?after=p2'],
            'userinfo trick'  => ['https://graph.facebook.com@evil.com/v25.0/act_111/insights?after=p2'],
            'plain http'      => ['http://graph.facebook.com/v25.0/act_111/insights?after=p2'],
        ];
    }

    #[DataProvider('foreignHosts')]
    public function test_S_154_8_a_paging_next_to_another_host_is_not_followed_and_the_run_is_partial(string $next): void
    {
        Sync::setWriteMode(true);
        $this->connect($this->user('Marketing', 'ana@example.test'), self::TOKEN_ANA, ['111']);
        Http::fake([
            'graph.facebook.com/v25.0/act_111/insights*' => Http::response(['data' => [self::insight('111', 'a1', self::DAY, 10.00)], 'paging' => ['next' => $next]]),
            '*' => Http::response(['error' => ['message' => 'should not be called', 'code' => 100]], 400),
        ]);

        $log = app(Sync::class)->run(Sync::startLog(self::DAY, self::DAY, null), self::DAY, self::DAY);

        Http::assertSentCount(1);
        $this->assertSame('partial', $log->status);
        $this->assertStringContainsString('paging link to another host', $log->error_message);
        $this->assertSame([], $this->reportRows());
    }

    public static function jobs(): array
    {
        return ['Sync now' => [\App\Jobs\SyncMetaAdsReports::class, 'ads_manager_api'], 'Compare' => [\App\Jobs\CompareMetaAds::class, 'ads_manager_api_compare']];
    }

    #[DataProvider('jobs')]
    public function test_S_154_6_a_run_that_throws_or_is_killed_ends_failed_not_processing(string $job, string $type): void
    {
        Sync::setWriteMode(true);
        $this->app->instance(Connections::class, \Mockery::mock(Connections::class, fn ($m) => $m->shouldReceive('all')->andThrow(new \RuntimeException('db gone'))));
        $thrown = UploadLog::create(['type' => $type, 'disk' => 'api', 'path' => 'meta', 'original_name' => 'x', 'status' => 'queued']);
        $killed = UploadLog::create(['type' => $type, 'disk' => 'api', 'path' => 'meta', 'original_name' => 'y', 'status' => 'processing']);

        try {
            app()->call([new $job($thrown->id, self::DAY, self::DAY), 'handle']);
        } catch (\RuntimeException) {
        }
        (new $job($killed->id, self::DAY, self::DAY))->failed(new \RuntimeException('timed out'));

        $this->assertSame(['failed', 'failed'], [$thrown->fresh()->status, $killed->fresh()->status]);
        $this->assertStringContainsString('db gone', $thrown->fresh()->error_message);
    }
}
