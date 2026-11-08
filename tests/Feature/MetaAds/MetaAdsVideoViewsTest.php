<?php

namespace Tests\Feature\MetaAds;

use App\Jobs\ProcessAdsManagerReportsUpload;
use App\Models\UploadLog;
use App\Models\User;
use App\Services\MetaAds\Insights;
use App\Services\MetaAds\Sync;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Spec 244: the sync stores 3-second plays, ThruPlays and 50% views per ad and day. Every Meta answer here is recorded
 * (made up) and served by the fake Graph; nothing calls Meta.
 */
class MetaAdsVideoViewsTest extends MetaAdsTestCase
{
    private const DAY = '2026-10-20';
    private const VIDEO = ['video_plays_3s', 'video_thruplays', 'video_p50'];

    /** The video part of a video ad's insights row, in the shape the Insights API answers. */
    private static function video(array $extra = []): array
    {
        return array_replace([
            'actions' => [
                ['action_type' => 'link_click', 'value' => '33'],
                ['action_type' => 'onsite_conversion.messaging_conversation_started_7d', 'value' => '12'],
                ['action_type' => 'omni_purchase', 'value' => '2'],
                ['action_type' => 'video_play', 'value' => '5400'], // plays started, not the 3-second number
                ['action_type' => 'video_view', 'value' => '4210'],
            ],
            'video_thruplay_watched_actions' => [['action_type' => 'video_view', 'value' => '1375']],
            'video_p50_watched_actions'      => [['action_type' => 'video_view', 'value' => '980']],
        ], $extra);
    }

    private function sync(array $insights): UploadLog
    {
        Sync::setWriteMode(true);
        Sync::setAccounts(['111']);
        $ana = User::where('email', 'ana@example.test')->first() ?? $this->user('Marketing', 'ana@example.test');
        $this->connect($ana, self::TOKEN_ANA, ['111']);
        // A fresh fake per run: with stacked fakes the first one answers, and each run here has its own recorded answer.
        Http::swap(new HttpFactory());
        Http::preventStrayRequests();
        $this->fakeGraph(
            insights: ['111' => $insights],
            ads: ['111' => array_map(fn ($r) => self::adDetail($r['ad_id']), $insights)],
        );

        return app(Sync::class)->run(Sync::startLog(self::DAY, self::DAY, null), self::DAY, self::DAY)->fresh();
    }

    private function videoOf(string $ad): array
    {
        $row = (array) DB::table('ads_manager_reports')->where('day', self::DAY)->where('ad_id', $ad)->first(self::VIDEO);

        return array_map(fn ($v) => $v === null ? null : (int) $v, $row);
    }

    public function test_S1_1_the_sync_asks_for_the_video_fields_and_stores_3s_plays_thruplays_and_50_percent(): void
    {
        $log = $this->sync([self::insight('111', 'a1', self::DAY, 100.00, self::video())]);

        $fields = explode(',', collect($this->graphRequests)->firstWhere('path', 'act_111/insights')['query']['fields']);
        $this->assertContains('actions', $fields);
        $this->assertContains('video_thruplay_watched_actions', $fields);
        $this->assertContains('video_p50_watched_actions', $fields);
        $this->assertNotContains('video_15_sec_watched_actions', $fields, 'ThruPlay is the 15-second number (spec 244 decision)');
        $this->assertSame('done', $log->status);
        $this->assertSame(['video_plays_3s' => 4210, 'video_thruplays' => 1375, 'video_p50' => 980], $this->videoOf('a1'));
    }

    public function test_S1_2_an_image_ad_stores_null_not_zero_and_the_rest_of_the_row_as_before(): void
    {
        $this->sync([self::insight('111', 'a1', self::DAY, 100.00, self::video()), self::insight('111', 'a2', self::DAY, 40.00)]);

        $this->assertSame(['video_plays_3s' => null, 'video_thruplays' => null, 'video_p50' => null], $this->videoOf('a2'));
        $row = DB::table('ads_manager_reports')->where('ad_id', 'a2')->first();
        $this->assertSame([40.0, 900, 1500, 33, 12, 2], [(float) $row->amount_spent_php, (int) $row->reach, (int) $row->impressions,
            (int) $row->link_clicks, (int) $row->messaging_conversations_started, (int) $row->purchases]);
    }

    /** @return array<string, array{0: array, 1: array}> the video fields of an answer => the three columns */
    public static function oddShapes(): array
    {
        $none = [null, null, null];

        return [
            'image ad: no video fields at all'        => [[], $none],
            'only video_play in actions'              => [['actions' => [['action_type' => 'video_play', 'value' => '50']]], $none],
            'the fields are strings'                  => [['video_thruplay_watched_actions' => '1375', 'video_p50_watched_actions' => 'abc'], $none],
            'an object instead of a list'             => [['video_thruplay_watched_actions' => ['action_type' => 'video_view', 'value' => '7'],
                'video_p50_watched_actions' => ['video_view' => 7]], $none],
            'negative, empty or not a number'         => [['actions' => [['action_type' => 'video_view', 'value' => '-5']],
                'video_thruplay_watched_actions' => [['action_type' => 'video_view', 'value' => '']],
                'video_p50_watched_actions' => [['action_type' => 'video_view', 'value' => 'many']]], $none],
            'a list without a video_view entry'       => [['video_thruplay_watched_actions' => [['action_type' => 'video_play', 'value' => '9']],
                'video_p50_watched_actions' => [], 'actions' => []], $none],
            'zero is a number, not "no data"'         => [['actions' => [['action_type' => 'video_view', 'value' => '0']],
                'video_thruplay_watched_actions' => [['action_type' => 'video_view', 'value' => 0]],
                'video_p50_watched_actions' => [['action_type' => 'video_view', 'value' => '0']]], [0, 0, 0]],
            'each column reads only its own field'    => [['video_thruplay_watched_actions' => [['action_type' => 'video_view', 'value' => '20']]],
                [null, 20, null]],
        ];
    }

    #[DataProvider('oddShapes')]
    public function test_S1_2_missing_or_unknown_shapes_give_null_never_zero(array $video, array $expected): void
    {
        $row = app(Insights::class)->map('111', $video + self::insight('111', 'a1', self::DAY, 10.00), null, []);

        $this->assertSame(array_combine(self::VIDEO, $expected), array_intersect_key($row, array_flip(self::VIDEO)));
    }

    public function test_S1_3_every_other_column_keeps_its_value_for_the_same_recorded_answer(): void
    {
        $insights = app(Insights::class);
        $base     = self::insight('111', 'a1', self::DAY, 250.50);
        $ad       = self::adDetail('a1');
        $without  = $insights->map('111', $base, $ad, ['p1' => 'Page P1']);
        $with     = $insights->map('111', self::video() + $base, $ad, ['p1' => 'Page P1']);

        $this->assertSame(array_diff_key($without, array_flip(self::VIDEO)), array_diff_key($with, array_flip(self::VIDEO)));
        $this->assertSame([null, null, null], [$without['video_plays_3s'], $without['video_thruplays'], $without['video_p50']]);
        $this->assertSame([4210, 1375, 980], [$with['video_plays_3s'], $with['video_thruplays'], $with['video_p50']]);
        // The numbers of the recorded answer, as before spec 244.
        $this->assertSame(
            ['amount_spent_php' => 250.5, 'reach' => 900, 'impressions' => 1500, 'link_clicks' => 33, 'messaging_conversations_started' => 12,
                'purchases' => 2, 'results' => 12, 'cost_per_result' => 20.88, 'result_type' => 'Messaging conversations started'],
            array_intersect_key($with, array_flip(['amount_spent_php', 'reach', 'impressions', 'link_clicks', 'messaging_conversations_started',
                'purchases', 'results', 'cost_per_result', 'result_type'])),
        );
    }

    public function test_S1_4_the_update_path_fills_the_columns_and_a_later_answer_without_them_keeps_them(): void
    {
        DB::table('ads_manager_reports')->insert([
            'day' => self::DAY, 'ad_id' => 'a1', 'page_name' => 'Page P1', 'amount_spent_php' => 10, 'reach' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $first = $this->sync([self::insight('111', 'a1', self::DAY, 100.00, self::video())]);
        $this->assertSame([0, 1], [(int) $first->inserted, (int) $first->updated]);
        $this->assertSame(['video_plays_3s' => 4210, 'video_thruplays' => 1375, 'video_p50' => 980], $this->videoOf('a1'));
        $this->assertSame(900, (int) DB::table('ads_manager_reports')->where('ad_id', 'a1')->value('reach'));

        $this->sync([self::insight('111', 'a1', self::DAY, 100.00)]); // the same ad, this time answered without video fields
        $this->assertSame(['video_plays_3s' => 4210, 'video_thruplays' => 1375, 'video_p50' => 980], $this->videoOf('a1'));

        $later = $this->sync([self::insight('111', 'a1', self::DAY, 120.00, self::video([
            'video_thruplay_watched_actions' => [['action_type' => 'video_view', 'value' => '1500']],
        ]))]);
        $this->assertSame([0, 1], [(int) $later->inserted, (int) $later->updated]);
        $this->assertSame(['video_plays_3s' => 4210, 'video_thruplays' => 1500, 'video_p50' => 980], $this->videoOf('a1'));
        $this->assertSame(1, DB::table('ads_manager_reports')->where('ad_id', 'a1')->count());
    }

    /** An Ads Manager export with one row for a1; since spec 246 the three video columns are required. */
    private function uploadA1(array $video, string $spend = '100.50', string $impressions = '1600', string $clicks = '40', string $results = '12'): UploadLog
    {
        Storage::fake('local');
        $path = 'uploads/ads_manager_reports/t.xlsx';
        Storage::disk('local')->makeDirectory('uploads/ads_manager_reports');
        $writer = new \OpenSpout\Writer\XLSX\Writer();
        $writer->openToFile(Storage::disk('local')->path($path));
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Day', 'Ad ID', 'Account ID', 'Campaign ID', 'Ad set ID', 'Page name', 'Campaign name',
            'Ad set name', 'Amount spent (PHP)', 'Impressions', 'Results', 'Result type', 'Link clicks', '3-second video plays', 'ThruPlays', 'Video plays at 50%']));
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(array_merge([self::DAY, 'a1', '111', 'ca1', 'asa1', 'Page P1', 'Camp', 'Adset', $spend, $impressions, $results,
            'Messaging conversations started', $clicks], $video)));
        $writer->close();
        $log = UploadLog::create(['type' => 'ads_manager_reports', 'disk' => 'local', 'path' => $path, 'original_name' => 't.xlsx', 'status' => 'queued']);
        (new ProcessAdsManagerReportsUpload($path, null, $log->id))->handle();

        return $log->fresh();
    }

    public function test_S3_an_uploaded_export_with_blank_video_cells_leaves_the_synced_video_numbers_as_they_are(): void
    {
        $this->sync([self::insight('111', 'a1', self::DAY, 10.00, self::video())]);

        $log = $this->uploadA1(['', '', '']);

        $this->assertSame(['done', 0, 1], [$log->status, (int) $log->inserted, (int) $log->updated]);
        $this->assertSame([100.5, 40], [(float) DB::table('ads_manager_reports')->value('amount_spent_php'), (int) DB::table('ads_manager_reports')->value('link_clicks')]);
        $this->assertSame(['video_plays_3s' => 4210, 'video_thruplays' => 1375, 'video_p50' => 980], $this->videoOf('a1'));
    }

    public function test_S_246_3_the_upload_and_the_sync_write_the_same_columns_and_neither_overwrites_a_number_with_null(): void
    {
        $this->assertSame('done', $this->uploadA1(['300', '120', '90'], '1.00', '10', '1', '1')->status);
        $this->assertSame(['video_plays_3s' => 300, 'video_thruplays' => 120, 'video_p50' => 90], $this->videoOf('a1'));

        // A later sync of the same day and ad without video numbers updates the row and keeps the uploaded ones.
        $this->assertSame('done', $this->sync([self::insight('111', 'a1', self::DAY, 50.00)])->status);
        $this->assertSame(50.0, (float) DB::table('ads_manager_reports')->value('amount_spent_php'));
        $this->assertSame(['video_plays_3s' => 300, 'video_thruplays' => 120, 'video_p50' => 90], $this->videoOf('a1'));

        // And a sync with video numbers replaces them, as the upload would.
        $this->sync([self::insight('111', 'a1', self::DAY, 60.00, self::video())]);
        $this->assertSame(['video_plays_3s' => 4210, 'video_thruplays' => 1375, 'video_p50' => 980], $this->videoOf('a1'));
    }
}
