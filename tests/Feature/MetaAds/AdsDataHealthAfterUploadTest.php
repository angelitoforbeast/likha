<?php

namespace Tests\Feature\MetaAds;

use App\Jobs\ProcessAdsManagerReportsUpload;
use App\Models\UploadLog;
use App\Services\MetaAds\DataHealth;
use App\Services\MetaAds\Sync;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Ang naitatalang data health check ay tumatakbo rin pagkatapos ng bawat natapos na Ads Manager upload (trigger "upload"),
 * hindi lang sa 12:00 (trigger "scheduled"). Ang sariling importer ng Meta sync ay hindi nagtatala.
 */
class AdsDataHealthAfterUploadTest extends MetaAdsTestCase
{
    private const TODAY = '2026-10-28';
    private const YESTERDAY = '2026-10-27';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::TODAY . ' 10:15', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param list<array{0:string, 1:string, 2:string}> $rows [day, ad_id, account] */
    private function upload(array $rows): UploadLog
    {
        Storage::fake('local');
        $path = 'uploads/ads_manager_reports/t.xlsx';
        Storage::disk('local')->makeDirectory('uploads/ads_manager_reports');
        $writer = new \OpenSpout\Writer\XLSX\Writer();
        $writer->openToFile(Storage::disk('local')->path($path));
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Day', 'Ad ID', 'Account ID', 'Campaign ID', 'Ad set ID', 'Page name', 'Campaign name',
            'Ad set name', 'Amount spent (PHP)', 'Impressions', 'Reach', 'Results', 'Result type', 'Link clicks', '3-second video plays', 'ThruPlays', 'Video plays at 50%']));
        foreach ($rows as [$day, $ad, $account]) {
            $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues([$day, $ad, $account, 'c-' . $ad, 'as-' . $ad, 'Page P1', 'Camp', 'Adset', '25',
                '1000', '900', '3', 'Messaging conversations started', '10', '', '', '']));
        }
        $writer->close();
        $log = UploadLog::create(['type' => 'ads_manager_reports', 'disk' => 'local', 'path' => $path, 'original_name' => 't.xlsx', 'status' => 'queued']);
        (new ProcessAdsManagerReportsUpload($path, null, $log->id))->handle();

        return $log->fresh();
    }

    public function test_S_253_2_a_finished_upload_records_the_check_with_the_upload_trigger(): void
    {
        $this->connect($this->user('Marketing', 'ana@example.test'), self::TOKEN_ANA, ['111']);
        $this->assertNull(DataHealth::last());

        $log = $this->upload([[self::YESTERDAY, 'a1', '111'], [self::YESTERDAY, 'u1', '333']]);

        $this->assertSame('done', $log->status);
        $saved = DataHealth::last();
        $this->assertNotNull($saved, 'the upload recorded a check');
        $this->assertSame([self::YESTERDAY, 'upload', '2026-10-28 10:15'], [$saved['day'], $saved['trigger'], $saved['checked_at']]);
        $status = array_column($saved['accounts'], 'status', 'id');
        $this->assertSame(DataHealth::NOT_READABLE, $status['333']);
        $this->actingAs($this->user('CEO'))->get('/ads_manager/report')
            ->assertSee('id="ads-data-notice"', false)->assertSee('Last recorded check: 2026-10-28 10:15 (after an upload)');
    }

    public function test_S_253_2_each_finished_upload_records_again_and_the_scheduled_run_still_records(): void
    {
        $this->upload([[self::YESTERDAY, 'u1', '333']]);
        $this->assertSame(1, DataHealth::last()['attention']);

        Carbon::setTestNow(Carbon::parse(self::TODAY . ' 11:00', 'Asia/Manila'));
        $this->connect($this->user('Marketing', 'ana@example.test'), self::TOKEN_ANA, ['333']);
        $this->upload([[self::YESTERDAY, 'u1', '333']]);
        $this->assertSame(['2026-10-28 11:00', 'upload'], [DataHealth::last()['checked_at'], DataHealth::last()['trigger']]);
        $this->assertNotSame(DataHealth::NOT_READABLE, array_column(DataHealth::last()['accounts'], 'status', 'id')['333']);

        Carbon::setTestNow(Carbon::parse(self::TODAY . ' 12:00', 'Asia/Manila'));
        $this->artisan('ads:data-health', ['--record' => true])->assertSuccessful();
        $this->assertSame(['2026-10-28 12:00', 'scheduled'], [DataHealth::last()['checked_at'], DataHealth::last()['trigger']]);
    }

    public function test_S_253_2_a_failed_upload_does_not_record_a_check(): void
    {
        Storage::fake('local');
        $log = UploadLog::create(['type' => 'ads_manager_reports', 'disk' => 'local', 'path' => 'uploads/none.xlsx', 'original_name' => 'none.xlsx', 'status' => 'queued']);

        (new ProcessAdsManagerReportsUpload('uploads/none.xlsx', null, $log->id))->handle();

        $this->assertSame('failed', $log->fresh()->status);
        $this->assertNull(DataHealth::last());
    }

    public function test_S_253_2_the_meta_sync_import_does_not_record_a_check(): void
    {
        Sync::setWriteMode(true);
        Sync::setAccounts(['111']);
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111']);
        $this->fakeGraph(
            insights: ['111' => [self::insight('111', 'a1', self::YESTERDAY, 130.00)]],
            ads: ['111' => [self::adDetail('a1')]],
        );

        $this->actingAs($ana)->post('/ads_manager/meta/sync', ['from' => self::YESTERDAY, 'to' => self::YESTERDAY])->assertRedirect('/ads_manager/report');

        $this->assertSame('done', UploadLog::where('type', Sync::LOG_TYPE)->value('status'));
        $this->assertNull(DataHealth::last());
    }
}
