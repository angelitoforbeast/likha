<?php

namespace Tests\Feature\MetaAds;

use App\Jobs\ProcessAdsManagerReportsUpload;
use App\Models\UploadLog;
use App\Services\MetaAds\Insights;
use App\Services\MetaAds\Sync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Spec 252: the sync fills ad_campaign_creatives.ad_link. Spec 257: every ad, video or not, links to
 * /<page id>/posts/<post id> from effective_object_story_id, so the preview embeds; video_data.video_id is not used.
 */
class MetaAdsAdLinkTest extends MetaAdsTestCase
{
    private const DAY = '2026-10-20';

    private const STAFF_LINK = 'https://www.facebook.com/reel/999';

    /** One sync of account 111 with the given ads (ad id => adDetail overrides). */
    private function sync(array $ads): UploadLog
    {
        Sync::setWriteMode(true);
        Sync::setAccounts(['111']);
        $this->connect($this->user('Marketing', 'ana@example.test'), self::TOKEN_ANA, ['111']);
        $this->fakeGraph(
            insights: ['111' => array_map(fn ($id) => self::insight('111', $id, self::DAY, 10.00), array_keys($ads))],
            ads: ['111' => array_map(fn ($id, $extra) => self::adDetail($id, $extra), array_keys($ads), $ads)],
        );

        return app(Sync::class)->run(Sync::startLog(self::DAY, self::DAY, null), self::DAY, self::DAY);
    }

    private static function story(string $id): array
    {
        return ['creative' => ['effective_object_story_id' => $id]];
    }

    private static function video(mixed $videoId, ?string $story = null): array
    {
        $creative = ['object_story_spec' => ['video_data' => ['video_id' => $videoId]]];
        if ($story !== null) $creative['effective_object_story_id'] = $story;

        return ['creative' => $creative];
    }

    private function creative(string $ad, ?string $link, ?string $campaign = null): void
    {
        DB::table('ad_campaign_creatives')->insert([
            'ad_id' => $ad, 'campaign_id' => $campaign ?? 'c' . $ad, 'ad_link' => $link, 'headline' => 'Staff headline',
            'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);
    }

    private function link(string $ad): ?string
    {
        return DB::table('ad_campaign_creatives')->where('ad_id', $ad)->value('ad_link');
    }

    public function test_S1_a_new_ad_gets_the_post_link_on_insert(): void
    {
        $log = $this->sync(['a1' => self::story('123_456')]);

        $this->assertSame('done', $log->fresh()->status);
        $this->assertSame('https://www.facebook.com/123/posts/456', $this->link('a1'));
    }

    public function test_S1_the_sync_overwrites_a_link_staff_typed(): void
    {
        $this->creative('a1', self::STAFF_LINK);

        $this->sync(['a1' => self::story('123_456')]);

        $this->assertSame('https://www.facebook.com/123/posts/456', $this->link('a1'));
        // Spec 254: Meta's non-empty headline also replaces the staff value (Meta is the source of the ad's content).
        $this->assertSame('Headline a1', DB::table('ad_campaign_creatives')->where('ad_id', 'a1')->value('headline'));
    }

    public function test_S1_a_campaign_row_without_an_ad_stays_and_the_ad_gets_its_own_row_and_link(): void
    {
        DB::table('ad_campaign_creatives')->insert(['ad_id' => null, 'campaign_id' => 'ca1', 'ad_link' => self::STAFF_LINK,
            'created_at' => now(), 'updated_at' => now()]);

        $this->sync(['a1' => self::story('123_456')]);

        // Spec 254: the campaign-only row stays the campaign fallback; the ad has a row of its own.
        $this->assertSame(2, DB::table('ad_campaign_creatives')->count());
        $this->assertSame('https://www.facebook.com/123/posts/456', $this->link('a1'));
        $this->assertSame(self::STAFF_LINK, DB::table('ad_campaign_creatives')->whereNull('ad_id')->value('ad_link'));
    }

    public static function badStories(): array
    {
        return [
            'missing'          => [null],
            'empty'            => [''],
            'no underscore'    => ['123456'],
            'empty post part'  => ['123_'],
            'post not digits'  => ['123_45x6'],
            'post with a path' => ['123_456/../evil'],
            'three parts'      => ['123_456_789'],
            'trailing newline' => ["123_456\n"],
        ];
    }

    #[DataProvider('badStories')]
    public function test_S1_a_missing_or_malformed_story_id_leaves_the_link_as_it_is(?string $story): void
    {
        $this->creative('a1', self::STAFF_LINK);

        $this->sync(['a1' => $story === null ? [] : self::story($story), 'a2' => $story === null ? [] : self::story($story)]);

        $this->assertSame(self::STAFF_LINK, $this->link('a1'));
        $this->assertNull($this->link('a2'));
    }

    public function test_S1_the_mapped_row_carries_the_link_only_for_a_valid_story_id(): void
    {
        $insights = app(Insights::class);
        $base     = self::insight('111', 'a1', self::DAY, 10.00);

        $this->assertSame('https://www.facebook.com/123/posts/456', $insights->map('111', $base, self::adDetail('a1', self::story('123_456')), [])['ad_link']);
        $this->assertNull($insights->map('111', $base, self::adDetail('a1', self::story('123_')), [])['ad_link']);
        $this->assertNull($insights->map('111', $base, self::adDetail('a1'), [])['ad_link']);
        $this->assertArrayNotHasKey('ad_link', $insights->map('111', $base, null, []));
    }

    public function test_257_S1_a_video_ad_gets_the_page_posts_link_not_a_reel_link(): void
    {
        $this->creative('a1', 'https://www.facebook.com/reel/789'); // a broken reel link from an earlier sync is rewritten

        $this->sync(['a1' => self::video('789', '123_456'), 'a2' => self::video('790', '124_457')]);

        $this->assertSame('https://www.facebook.com/123/posts/456', $this->link('a1'));
        $this->assertSame('https://www.facebook.com/124/posts/457', $this->link('a2'));
        $this->assertSame('https://www.facebook.com/123/posts/456', Insights::adLink(self::video('789', '123_456')['creative']));
    }

    public function test_257_S2_a_video_ad_without_a_valid_story_id_leaves_the_link_as_it_is(): void
    {
        $this->creative('a1', self::STAFF_LINK);

        $this->sync(['a1' => self::video('789'), 'a2' => self::video('790', '124_')]);

        $this->assertSame(self::STAFF_LINK, $this->link('a1'));
        $this->assertNull($this->link('a2'));
        $this->assertNull(Insights::adLink(self::video('789')['creative']));
        $this->assertNull(Insights::adLink(self::video('789', '12x_456')['creative']));
    }

    public function test_256_S2_an_image_or_link_ad_gets_the_page_posts_link(): void
    {
        $insights = app(Insights::class);
        $base     = self::insight('111', 'a1', self::DAY, 10.00);
        $image    = ['creative' => ['effective_object_story_id' => '123_456', 'object_story_spec' => ['link_data' => ['message' => 'Hi']]]];

        $this->assertSame('https://www.facebook.com/123/posts/456', $insights->map('111', $base, self::adDetail('a1', $image), [])['ad_link']);
    }

    public static function badVideoIds(): array
    {
        return [
            'empty'            => [''],
            'not digits'       => ['78x9'],
            'with a path'      => ['789/../evil'],
            'trailing newline' => ["789\n"],
            'an array'         => [['789']],
            'null'             => [null],
        ];
    }

    #[DataProvider('badVideoIds')]
    public function test_256_S3_the_video_id_never_decides_the_link(mixed $videoId): void
    {
        $this->assertSame('https://www.facebook.com/123/posts/456', Insights::adLink(self::video($videoId, '123_456')['creative']));
        $this->assertNull(Insights::adLink(self::video($videoId)['creative']));
    }

    public function test_256_S3_no_video_and_no_story_id_is_null(): void
    {
        $this->assertNull(Insights::adLink(null));
        $this->assertNull(Insights::adLink([]));
        $this->assertNull(Insights::adLink(['object_story_spec' => ['page_id' => '123', 'video_data' => []]]));
        $this->assertNull(Insights::adLink(['object_story_spec' => 'x', 'effective_object_story_id' => ['123_456']]));
        $this->assertNull(Insights::adLink(['effective_object_story_id' => '12x_456']));
    }

    public function test_S2_the_link_is_not_written_to_ads_manager_reports(): void
    {
        $this->sync(['a1' => self::story('123_456')]);

        $this->assertSame(['a1'], array_column($this->reportRows(), 'ad_id'));
        $this->assertArrayNotHasKey('ad_link', $this->reportRows()[0]);
    }

    public function test_S1_an_upload_row_without_a_link_leaves_the_link_as_it_is(): void
    {
        $this->creative('a1', self::STAFF_LINK, 'c1');
        Storage::fake('local');
        $path = 'uploads/ads_manager_reports/t.xlsx';
        Storage::disk('local')->makeDirectory('uploads/ads_manager_reports');
        $writer = new \OpenSpout\Writer\XLSX\Writer();
        $writer->openToFile(Storage::disk('local')->path($path));
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Day', 'Ad ID', 'Account ID', 'Campaign ID', 'Ad set ID', 'Page name', 'Campaign name',
            'Ad set name', 'Amount spent (PHP)', 'Impressions', 'Results', 'Result type', 'Link clicks', '3-second video plays', 'ThruPlays', 'Video plays at 50%']));
        foreach (['a1', 'a2'] as $ad) {
            $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues([self::DAY, $ad, '111', $ad === 'a1' ? 'c1' : 'c2', 'as1', 'Page P1', 'Camp',
                'Adset', '100', '1500', '12', 'Messaging conversations started', '33', '', '', '']));
        }
        $writer->close();
        $log = UploadLog::create(['type' => 'ads_manager_reports', 'disk' => 'local', 'path' => $path, 'original_name' => 't.xlsx', 'status' => 'queued']);

        (new ProcessAdsManagerReportsUpload($path, null, $log->id))->handle();

        $this->assertSame('done', $log->fresh()->status);
        $this->assertSame(self::STAFF_LINK, $this->link('a1'));
        $this->assertTrue(DB::table('ad_campaign_creatives')->where('ad_id', 'a2')->exists());
        $this->assertNull($this->link('a2'));
    }
}
