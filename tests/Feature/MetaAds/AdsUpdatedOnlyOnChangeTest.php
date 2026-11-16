<?php

namespace Tests\Feature\MetaAds;

use App\Http\Controllers\CPPController;
use App\Jobs\ProcessAdsManagerReportsUpload;
use App\Models\UploadLog;
use App\Services\MetaAds\DataHealth;
use App\Services\MetaAds\Sync;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Spec 280. Ang updated_at ng ads_manager_reports row ay gumagalaw lang kapag may isinulat na value na iba sa nakatabi,
 * kaya ang "Ads spend updated <oras>" sa CPP ay ang oras na huling nagbago ang numero, hindi ang huling sync o upload.
 */
class AdsUpdatedOnlyOnChangeTest extends MetaAdsTestCase
{
    private const DAY = '2026-11-12';
    private const T1  = '2026-11-12 18:45:00';
    private const T2  = '2026-11-16 15:03:00';

    private function at(string $time): void
    {
        $this->travelTo(Carbon::parse($time, 'Asia/Manila'));
    }

    private function upload(string $spend = '100', string $campaign = 'Camp', string $impressions = '1500'): UploadLog
    {
        Storage::fake('local');
        $path = 'uploads/ads_manager_reports/t.xlsx';
        Storage::disk('local')->makeDirectory('uploads/ads_manager_reports');
        $writer = new \OpenSpout\Writer\XLSX\Writer();
        $writer->openToFile(Storage::disk('local')->path($path));
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Day', 'Ad ID', 'Account ID', 'Campaign ID', 'Ad set ID', 'Page name', 'Campaign name',
            'Ad set name', 'Amount spent (PHP)', 'Impressions', 'Reach', 'Results', 'Result type', 'Link clicks', 'Cost per result',
            'Reporting starts', 'Reporting ends', 'Ad set budget', '3-second video plays', 'ThruPlays', 'Video plays at 50%']));
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues([self::DAY, 'a1', '111', 'c1', 'as1', 'Page P1', $campaign, 'Adset', $spend,
            $impressions, '900', '12', 'Messaging conversations started', '33', '8.33', self::DAY, self::DAY, '500', '', '', '']));
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues([self::DAY, 'a2', '111', 'c1', 'as1', 'Page P1', 'Other camp', 'Adset', '250.50',
            '3000', '1800', '20', 'Messaging conversations started', '40', '12.53', self::DAY, self::DAY, '500', '', '', '']));
        $writer->close();
        $log = UploadLog::create(['type' => 'ads_manager_reports', 'disk' => 'local', 'path' => $path, 'original_name' => 't.xlsx', 'status' => 'queued']);
        (new ProcessAdsManagerReportsUpload($path, null, $log->id))->handle();

        return $log->fresh();
    }

    private function sync(float $spend): void
    {
        Sync::setWriteMode(true);
        Sync::setAccounts(['111']);
        $ana = \App\Models\User::where('email', 'ana@example.test')->first();
        if ($ana === null) {
            $ana = $this->user('Marketing', 'ana@example.test');
            $this->connect($ana, self::TOKEN_ANA, ['111']);
        }
        // Bagong pekeng Graph kada sync: ang naunang fake ay mauunang tumugma kung hindi ito papalitan.
        \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $this->fakeGraph(
            insights: ['111' => [self::insight('111', 'a1', self::DAY, $spend), self::insight('111', 'a2', self::DAY, 250.50)]],
            ads: ['111' => [self::adDetail('a1'), self::adDetail('a2')]],
        );
        $this->actingAs($ana)->post('/ads_manager/meta/sync', ['from' => self::DAY, 'to' => self::DAY])->assertRedirect('/ads_manager/report');
    }

    /** ad_id => [created_at, updated_at] */
    private function times(): array
    {
        return DB::table('ads_manager_reports')->orderBy('ad_id')->get()
            ->mapWithKeys(fn ($r) => [$r->ad_id => [(string) $r->created_at, (string) $r->updated_at]])->all();
    }

    public function test_spec280_S1_the_same_upload_twice_keeps_updated_at_of_every_row(): void
    {
        $this->at(self::T1);
        $first = $this->upload();
        $this->assertSame(2, (int) $first->inserted);
        $this->assertSame(['a1' => [self::T1, self::T1], 'a2' => [self::T1, self::T1]], $this->times());

        $this->at(self::T2);
        $this->upload();

        $this->assertSame(['a1' => [self::T1, self::T1], 'a2' => [self::T1, self::T1]], $this->times());
        $this->assertSame('100.00', number_format((float) DB::table('ads_manager_reports')->where('ad_id', 'a1')->value('amount_spent_php'), 2));
    }

    public function test_spec280_S1_a_sync_that_returns_the_same_numbers_keeps_updated_at(): void
    {
        $this->at(self::T1);
        $this->sync(100.00);
        $this->assertSame(['a1' => [self::T1, self::T1], 'a2' => [self::T1, self::T1]], $this->times());

        $this->at(self::T2);
        $this->sync(100.00);

        $this->assertSame(['a1' => [self::T1, self::T1], 'a2' => [self::T1, self::T1]], $this->times());
    }

    public function test_spec280_S1_stored_decimal_strings_and_nulls_match_the_incoming_values(): void
    {
        // Nakatabi na parang galing sa MySQL: decimal bilang string na may trailing zero, ang hindi isinusulat ay null.
        DB::table('ads_manager_reports')->insert([
            'day' => self::DAY, 'ad_id' => 'a1', 'account_id' => null, 'campaign_id' => 'c1', 'ad_set_id' => 'as1', 'page_name' => 'Page P1',
            'campaign_name' => 'Camp', 'ad_set_name' => 'Adset', 'amount_spent_php' => '100.00', 'impressions' => '1500', 'reach' => '900',
            'results' => '12', 'result_type' => 'Messaging conversations started', 'link_clicks' => '33', 'cost_per_result' => '8.330',
            'reporting_starts' => self::DAY . ' 00:00:00', 'reporting_ends' => self::DAY . ' 00:00:00', 'ad_set_budget' => '500.00',
            'created_at' => self::T1, 'updated_at' => self::T1,
        ]);

        $this->at(self::T2);
        $this->upload();

        $this->assertSame([self::T1, self::T1], $this->times()['a1']);
    }

    public static function changes(): array
    {
        return [
            'spend'         => ['120', 'Camp', '1500'],
            'impressions'   => ['100', 'Camp', '1600'],
            'campaign name' => ['100', 'Renamed camp', '1500'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('changes')]
    public function test_spec280_S2_a_changed_value_moves_updated_at_only_on_that_row(string $spend, string $campaign, string $impressions): void
    {
        $this->at(self::T1);
        $this->upload();

        $this->at(self::T2);
        $log = $this->upload($spend, $campaign, $impressions);

        $this->assertSame(['a1' => [self::T1, self::T2], 'a2' => [self::T1, self::T1]], $this->times());
        $row = DB::table('ads_manager_reports')->where('ad_id', 'a1')->first();
        $this->assertSame([(float) $spend, $campaign, (int) $impressions], [(float) $row->amount_spent_php, $row->campaign_name, (int) $row->impressions]);
        // Ang counter ay gaya ng dati: bawat nakatabing row na dumaan (nagbago man o hindi) ay "updated".
        $this->assertSame([0, 2], [(int) $log->inserted, (int) $log->updated]);
    }

    public function test_spec280_S2_a_sync_with_new_spend_moves_updated_at(): void
    {
        $this->at(self::T1);
        $this->sync(100.00);

        $this->at(self::T2);
        $this->sync(130.00);

        $this->assertSame(['a1' => [self::T1, self::T2], 'a2' => [self::T1, self::T1]], $this->times());
        $this->assertEquals(130.0, (float) DB::table('ads_manager_reports')->where('ad_id', 'a1')->value('amount_spent_php'));
    }

    public function test_spec280_S2_the_same_ad_twice_in_one_file_ends_with_the_last_value_as_before(): void
    {
        $this->at(self::T1);
        $this->upload();

        // Isang chunk: a1 = 120 (nagbago) at a1 = 100 ulit (ang luma). Gaya ng dati, ang huli ang naiiwan, at nagbago ang row.
        $this->at(self::T2);
        $importer = new ProcessAdsManagerReportsUpload('');
        $row = ['day' => self::DAY, 'ad_id' => 'a1', 'page_name' => 'Page P1', 'amount_spent_php' => 120.0];
        (fn () => $this->upsertChunk([$row, ['amount_spent_php' => 100.0] + $row]))->call($importer);

        $this->assertEquals(100.0, (float) DB::table('ads_manager_reports')->where('ad_id', 'a1')->value('amount_spent_php'));
        $this->assertSame([self::T1, self::T2], $this->times()['a1']);
    }

    public function test_spec280_S3_the_cpp_hover_time_is_when_the_numbers_last_changed(): void
    {
        $this->at(self::T1);
        $this->sync(100.00);
        $this->at(self::T2);
        $this->sync(100.00); // ang sync ng Nov 16 ay muling binasa ang Nov 12: parehong spend

        $fresh = new \ReflectionMethod(CPPController::class, 'freshnessByKey');
        [$adsAt] = $fresh->invoke(new CPPController(), self::DAY, self::DAY, collect(), fn ($p) => strtolower(trim((string) $p)));

        $this->assertSame([self::DAY . '__page p1' => '2026-11-12 18:45'], $adsAt);
    }

    public function test_spec280_S4_a_refused_lower_row_keeps_its_values_and_updated_at_and_is_recorded(): void
    {
        $this->at(self::T1);
        $this->upload();

        $this->at(self::T2);
        $log = $this->upload('100', 'Renamed camp', '1400');

        $this->assertSame(['a1' => [self::T1, self::T1], 'a2' => [self::T1, self::T1]], $this->times());
        $this->assertSame('Camp', DB::table('ads_manager_reports')->where('ad_id', 'a1')->value('campaign_name'));
        $this->assertSame(['impressions' => ['stored' => 1500, 'incoming' => 1400]], DataHealth::refusals([$log->id])[$log->id]['rows'][0]['metrics']);
        $this->assertSame([0, 1], [(int) $log->inserted, (int) $log->updated], 'a2 lang ang dumaan; ang a1 ay tinanggihan');
    }
}
