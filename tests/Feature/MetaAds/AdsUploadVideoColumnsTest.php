<?php

namespace Tests\Feature\MetaAds;

use App\Jobs\ProcessAdsManagerReportsUpload;
use App\Models\UploadLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Spec 246: the Ads Manager report upload requires "3-second video plays", "ThruPlays" and "Video plays at 50%" and
 * fills video_plays_3s, video_thruplays and video_p50 from them; a file without them is refused before any row is written.
 */
class AdsUploadVideoColumnsTest extends MetaAdsTestCase
{
    private const DAY = '2026-10-20';
    private const BASE = ['Day', 'Ad ID', 'Account ID', 'Campaign ID', 'Ad set ID', 'Page name', 'Campaign name', 'Ad set name',
        'Amount spent (PHP)', 'Impressions', 'Results', 'Result type', 'Link clicks'];
    private const VIDEO_HEADERS = ['3-second video plays', 'ThruPlays', 'Video plays at 50%'];

    /** One row per ad: [ad id, [3s, thruplays, 50%]] under the given header row; the video cells follow the base cells. */
    private function upload(array $headers, array $rows): UploadLog
    {
        Storage::fake('local');
        $path = 'uploads/ads_manager_reports/t.xlsx';
        Storage::disk('local')->makeDirectory('uploads/ads_manager_reports');
        $writer = new \OpenSpout\Writer\XLSX\Writer();
        $writer->openToFile(Storage::disk('local')->path($path));
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($headers));
        foreach ($rows as [$ad, $video]) {
            $base = [self::DAY, $ad, '111', 'c1', 'as1', 'Page P1', 'Camp', 'Adset', '100.50', '1500', '12', 'Messaging conversations started', '33'];
            $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(array_slice(array_merge($base, $video), 0, count($headers))));
        }
        $writer->close();
        $log = UploadLog::create(['type' => 'ads_manager_reports', 'disk' => 'local', 'path' => $path, 'original_name' => 't.xlsx', 'status' => 'queued']);
        (new ProcessAdsManagerReportsUpload($path, null, $log->id))->handle();

        return $log->fresh();
    }

    private function videoOf(string $ad): array
    {
        $row = (array) DB::table('ads_manager_reports')->where('day', self::DAY)->where('ad_id', $ad)->first(['video_plays_3s', 'video_thruplays', 'video_p50']);

        return array_values(array_map(fn ($v) => $v === null ? null : (int) $v, $row));
    }

    public function test_S3_1_an_upload_with_the_three_columns_fills_them_on_the_rows_it_writes(): void
    {
        $log = $this->upload(array_merge(self::BASE, self::VIDEO_HEADERS), [['a1', ['4,210', '1375', 980]], ['a2', ['0', '0', '0']]]);

        $this->assertSame(['done', 2, 0, null], [$log->status, (int) $log->inserted, (int) $log->updated, $log->error_message]);
        $this->assertSame([4210, 1375, 980], $this->videoOf('a1'));
        $this->assertSame([0, 0, 0], $this->videoOf('a2'), 'a real 0 stays 0');
        $this->assertSame(100.5, (float) DB::table('ads_manager_reports')->where('ad_id', 'a1')->value('amount_spent_php'), 'the other columns as before');
    }

    public function test_S3_1_a_blank_dash_text_or_negative_cell_is_null_and_keeps_a_stored_number(): void
    {
        $this->upload(array_merge(self::BASE, self::VIDEO_HEADERS), [['a1', ['4210', '1375', '980']]]);

        $log = $this->upload(array_merge(self::BASE, self::VIDEO_HEADERS), [['a1', ['', '—', 'n/a']], ['a2', ['-5', '', '—']]]);

        $this->assertSame(['done', 1, 1], [$log->status, (int) $log->inserted, (int) $log->updated]);
        $this->assertSame([4210, 1375, 980], $this->videoOf('a1'), 'null never overwrites a number');
        $this->assertSame([null, null, null], $this->videoOf('a2'));
    }

    /** @return array<string, array{0: list<string>, 1: string}> */
    public static function missingColumns(): array
    {
        $without = fn (string ...$drop) => array_values(array_diff(array_merge(self::BASE, self::VIDEO_HEADERS), $drop));

        return [
            'no 3-second video plays' => [$without('3-second video plays'), 'Missing required column(s): 3-second video plays'],
            'no ThruPlays'            => [$without('ThruPlays'), 'Missing required column(s): ThruPlays'],
            'no Video plays at 50%'   => [$without('Video plays at 50%'), 'Missing required column(s): Video plays at 50%'],
            'none of the three'       => [self::BASE, 'Missing required column(s): 3-second video plays, ThruPlays, Video plays at 50%'],
            'with an older missing'   => [$without('Link clicks', 'ThruPlays'), 'Missing required column(s): Link Clicks, ThruPlays'],
        ];
    }

    #[DataProvider('missingColumns')]
    public function test_S3_2_a_file_missing_a_video_column_is_refused_before_any_row_is_written(array $headers, string $message): void
    {
        $log = $this->upload($headers, [['a1', ['4210', '1375', '980']]]);

        $this->assertSame(['failed', $message], [$log->status, $log->error_message]);
        $this->assertSame(0, DB::table('ads_manager_reports')->count());
        $this->assertSame(0, DB::table('ad_campaign_creatives')->count());
    }

    /** @return array<string, array{0: list<string>}> */
    public static function headerVariants(): array
    {
        return [
            'upper case'             => [['3-SECOND VIDEO PLAYS', 'THRUPLAYS', 'VIDEO PLAYS AT 50%']],
            'extra spaces'           => [['  3-second   video plays ', ' ThruPlays ', 'Video  plays at 50% ']],
            'title case'             => [['3-Second Video Plays', 'Thruplays', 'Video Plays At 50%']],
            'older names'            => [['3-second video views', 'ThruPlay', 'Video watches at 50%']],
        ];
    }

    #[DataProvider('headerVariants')]
    public function test_S3_3_the_export_header_variants_are_accepted(array $video): void
    {
        $log = $this->upload(array_merge(self::BASE, $video), [['a1', ['4210', '1375', '980']]]);

        $this->assertSame(['done', null], [$log->status, $log->error_message]);
        $this->assertSame([4210, 1375, 980], $this->videoOf('a1'));
    }
}
