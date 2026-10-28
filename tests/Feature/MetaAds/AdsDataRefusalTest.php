<?php

namespace Tests\Feature\MetaAds;

use App\Jobs\ProcessAdsManagerReportsUpload;
use App\Models\UploadLog;
use App\Services\MetaAds\DataHealth;
use App\Services\MetaAds\Sync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Ang mas mababang numero (spend, impressions, reach, …) para sa nakatabing (day, ad_id) ay tinatanggihan para sa buong
 * row, mula sa upload man o sa Sync, at itinatala (sino, anong row, anong numero: nakatabi vs dumating).
 */
class AdsDataRefusalTest extends MetaAdsTestCase
{
    private const DAY = '2026-10-20';

    private function stored(array $extra = []): void
    {
        DB::table('ads_manager_reports')->insert($extra + [
            'day' => self::DAY, 'ad_id' => 'a1', 'page_name' => 'Page P1', 'campaign_name' => 'Old camp', 'amount_spent_php' => 100,
            'impressions' => 1500, 'reach' => 900, 'results' => 12, 'link_clicks' => 33, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function upload(string $spend, string $impressions, ?int $userId = null, string $clicks = '33'): UploadLog
    {
        Storage::fake('local');
        $path = 'uploads/ads_manager_reports/t.xlsx';
        Storage::disk('local')->makeDirectory('uploads/ads_manager_reports');
        $writer = new \OpenSpout\Writer\XLSX\Writer();
        $writer->openToFile(Storage::disk('local')->path($path));
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Day', 'Ad ID', 'Account ID', 'Campaign ID', 'Ad set ID', 'Page name', 'Campaign name',
            'Ad set name', 'Amount spent (PHP)', 'Impressions', 'Reach', 'Results', 'Result type', 'Link clicks']));
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues([self::DAY, 'a1', '111', 'c1', 'as1', 'Page P1', 'New camp', 'Adset', $spend,
            $impressions, '900', '12', 'Messaging conversations started', $clicks]));
        $writer->close();
        $log = UploadLog::create(['type' => 'ads_manager_reports', 'disk' => 'local', 'path' => $path, 'original_name' => 't.xlsx',
            'status' => 'queued', 'user_id' => $userId]);
        (new ProcessAdsManagerReportsUpload($path, $userId, $log->id))->handle();

        return $log->fresh();
    }

    private function row(): array
    {
        $r = DB::table('ads_manager_reports')->where('ad_id', 'a1')->first();

        return [(float) $r->amount_spent_php, (int) $r->impressions, $r->campaign_name];
    }

    public function test_S_178_3_an_upload_with_lower_impressions_keeps_the_whole_row_and_records_the_refusal(): void
    {
        $this->stored();
        $staff = $this->user('Marketing', 'staff@example.test');

        $log = $this->upload('120', '1400', $staff->id);

        $this->assertSame([100.0, 1500, 'Old camp'], $this->row(), 'walang kalahating update: luma pa rin ang spend at pangalan');
        $this->assertSame(['done', 0, 0], [$log->status, (int) $log->inserted, (int) $log->updated]);
        $this->assertSame([[
            'day' => self::DAY, 'ad_id' => 'a1', 'account' => '111', 'metrics' => ['impressions' => ['stored' => 1500, 'incoming' => 1400]],
        ]], DataHealth::refusals([$log->id])[$log->id]['rows']);
        $ref = UploadLog::where('type', DataHealth::REFUSED_TYPE)->first();
        $this->assertSame([1, $staff->id, 'upload', $log->id], [(int) $ref->error_rows, (int) $ref->user_id, $ref->protected_details['source'], $ref->protected_details['log_id']]);

        $this->actingAs($this->user('CEO'))->get('/ads_manager/report')->assertSee('1 row refused (lower values)')->assertSee('impressions 1,500 → 1,400');
    }

    public static function notLower(): array
    {
        return ['equal' => ['100', '1500', 100.0, 1500], 'higher' => ['120', '1600', 120.0, 1600]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notLower')]
    public function test_S_178_3_an_upload_with_equal_or_higher_values_updates_the_row(string $spend, string $impr, float $wantSpend, int $wantImpr): void
    {
        $this->stored();

        $log = $this->upload($spend, $impr);

        $this->assertSame([$wantSpend, $wantImpr, 'New camp'], $this->row());
        $this->assertSame(1, (int) $log->updated);
        $this->assertSame([], DataHealth::refusals([$log->id]));
    }

    public function test_S_178_3_a_zero_against_a_stored_value_refuses_the_row(): void
    {
        $this->stored();

        $log = $this->upload('120', '1600', null, '0');

        $this->assertSame([100.0, 1500, 'Old camp'], $this->row());
        $this->assertSame(['link_clicks' => ['stored' => 33, 'incoming' => 0]], DataHealth::refusals([$log->id])[$log->id]['rows'][0]['metrics']);
    }

    public function test_S_178_3_a_blank_metric_cell_keeps_that_column_and_updates_the_rest_without_a_refusal(): void
    {
        $this->stored();

        $log = $this->upload('120', '1600', null, '');

        $this->assertSame([120.0, 1600, 'New camp'], $this->row());
        $this->assertSame(33, (int) DB::table('ads_manager_reports')->where('ad_id', 'a1')->value('link_clicks'));
        $this->assertSame([], DataHealth::refusals([$log->id]));
    }

    public function test_S_178_3_a_sync_row_with_lower_impressions_keeps_the_whole_row_and_the_run_shows_one_row_refused(): void
    {
        $this->stored();
        Sync::setWriteMode(true);
        Sync::setAccounts(['111']);
        $ana = $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111']);
        $this->fakeGraph(
            insights: ['111' => [self::insight('111', 'a1', self::DAY, 130.00, ['impressions' => '1200'])]],
            ads: ['111' => [self::adDetail('a1')]],
        );
        $this->actingAs($ana);

        $this->post('/ads_manager/meta/sync', ['from' => self::DAY, 'to' => self::DAY])->assertRedirect('/ads_manager/report');

        $this->assertSame([100.0, 1500, 'Old camp'], $this->row());
        $log = UploadLog::where('type', Sync::LOG_TYPE)->first();
        $this->assertSame(0, (int) $log->updated);
        $this->assertSame(['impressions' => ['stored' => 1500, 'incoming' => 1200]], DataHealth::refusals([$log->id])[$log->id]['rows'][0]['metrics']);
        $this->assertSame([$ana->id, 'sync'], [(int) UploadLog::where('type', DataHealth::REFUSED_TYPE)->value('user_id'),
            UploadLog::where('type', DataHealth::REFUSED_TYPE)->first()->protected_details['source']]);
        $this->get('/ads_manager/report')->assertSee('1 row refused (lower values)');
    }
}
