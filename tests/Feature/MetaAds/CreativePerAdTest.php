<?php

namespace Tests\Feature\MetaAds;

use App\Jobs\ProcessAdsManagerReportsUpload;
use App\Models\UploadLog;
use App\Services\MetaAds\Sync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/** Spec 254: one ad_campaign_creatives row per ad, through the sync and through the upload. */
class CreativePerAdTest extends MetaAdsTestCase
{
    private const DAY = '2026-11-08';

    private bool $connected = false;

    /** One sync of account 111. $ads: ad id => [insight extra, adDetail extra]. */
    private function sync(array $ads): UploadLog
    {
        Sync::setWriteMode(true);
        Sync::setAccounts(['111']);
        if (!$this->connected) $this->connect($this->user('Marketing', 'ana@example.test'), self::TOKEN_ANA, ['111']);
        $this->connected = true;
        $this->fakeGraph(
            insights: ['111' => array_map(fn ($id, $x) => self::insight('111', (string) $id, self::DAY, 10.00, $x[0] ?? []), array_keys($ads), $ads)],
            ads: ['111' => array_map(fn ($id, $x) => self::adDetail((string) $id, $x[1] ?? []), array_keys($ads), $ads)],
        );

        return app(Sync::class)->run(Sync::startLog(self::DAY, self::DAY, null), self::DAY, self::DAY);
    }

    private static function welcome(string $text): array
    {
        return ['creative' => ['page_welcome_message' => $text]];
    }

    /** One upload with the given rows (ad id => [campaign id, headline, welcome message]). */
    private function upload(array $rows): UploadLog
    {
        Storage::fake('local');
        $path = 'uploads/ads_manager_reports/per-ad.xlsx';
        Storage::disk('local')->makeDirectory('uploads/ads_manager_reports');
        $writer = new Writer();
        $writer->openToFile(Storage::disk('local')->path($path));
        $writer->addRow(Row::fromValues(['Day', 'Ad ID', 'Account ID', 'Campaign ID', 'Ad set ID', 'Page name', 'Campaign name',
            'Ad set name', 'Amount spent (PHP)', 'Impressions', 'Results', 'Result type', 'Link clicks', '3-second video plays',
            'ThruPlays', 'Video plays at 50%', 'Headline', 'Welcome message']));
        foreach ($rows as $ad => [$campaign, $headline, $welcome]) {
            $writer->addRow(Row::fromValues([self::DAY, (string) $ad, '111', $campaign, 'as1', 'Page P1', 'Camp', 'Adset', '100', '1500', '12',
                'Messaging conversations started', '33', '', '', '', $headline, $welcome]));
        }
        $writer->close();
        $log = UploadLog::create(['type' => 'ads_manager_reports', 'disk' => 'local', 'path' => $path, 'original_name' => 'per-ad.xlsx', 'status' => 'queued']);

        (new ProcessAdsManagerReportsUpload($path, null, $log->id))->handle();

        return $log->fresh();
    }

    private function row(string $ad): ?object
    {
        return DB::table('ad_campaign_creatives')->where('ad_id', $ad)->first();
    }

    public function test_S1_two_ads_of_one_campaign_keep_their_own_rows_through_the_sync(): void
    {
        $log = $this->sync([
            'a1' => [['campaign_id' => 'cX'], self::welcome('Hello from ad one')],
            'a2' => [['campaign_id' => 'cX'], self::welcome('Hello from ad two')],
        ]);

        $this->assertSame('done', $log->fresh()->status);
        $this->assertSame(2, DB::table('ad_campaign_creatives')->where('campaign_id', 'cX')->count());
        $this->assertSame('Hello from ad one', $this->row('a1')->welcome_message);
        $this->assertSame('Hello from ad two', $this->row('a2')->welcome_message);
        $this->assertSame('Headline a2', $this->row('a2')->headline);
        $this->assertSame('111', $this->row('a2')->account_id);
    }

    public function test_S1_an_ad_synced_after_its_sibling_gets_its_own_row(): void
    {
        // a1's row from an earlier sync (one fake Graph per test: Http::fake callbacks stack, the first one answers).
        DB::table('ad_campaign_creatives')->insert(['ad_id' => 'a1', 'campaign_id' => 'cX', 'welcome_message' => 'Hello from ad one',
            'created_at' => now(), 'updated_at' => now()]);
        $this->sync(['a2' => [['campaign_id' => 'cX'], self::welcome('Hello from ad two')]]);

        $this->assertSame(2, DB::table('ad_campaign_creatives')->where('campaign_id', 'cX')->count());
        $this->assertSame('Hello from ad one', $this->row('a1')->welcome_message);
        $this->assertSame('Hello from ad two', $this->row('a2')->welcome_message);
    }

    public function test_S1_an_ad_uploaded_after_its_sibling_gets_its_own_row(): void
    {
        $this->upload(['a1' => ['cX', 'Headline one', 'Welcome one']]);
        $this->upload(['a2' => ['cX', 'Headline two', 'Welcome two']]);

        $this->assertSame(2, DB::table('ad_campaign_creatives')->where('campaign_id', 'cX')->count());
        $this->assertSame('Welcome two', $this->row('a2')->welcome_message);
    }

    public function test_S1_the_sync_writes_the_non_empty_meta_values_into_the_ads_own_row(): void
    {
        DB::table('ad_campaign_creatives')->insert(['ad_id' => 'a1', 'campaign_id' => 'ca1', 'headline' => 'Staff headline',
            'body_ad_settings' => 'Staff body', 'welcome_message' => 'Staff welcome', 'quick_reply_1' => 'Staff QR1',
            'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);

        // Meta returns a title and a body, but no welcome message and no quick replies.
        $this->sync(['a1' => [[], []]]);

        $row = $this->row('a1');
        $this->assertSame('Headline a1', $row->headline);
        $this->assertSame('Body a1', $row->body_ad_settings);
        $this->assertSame('Staff welcome', $row->welcome_message, 'an empty Meta value never blanks a field');
        $this->assertSame('Staff QR1', $row->quick_reply_1);
        $this->assertSame(1, DB::table('ad_campaign_creatives')->count());
    }

    public function test_S1_the_sync_updates_the_welcome_message_and_quick_replies_when_meta_has_them(): void
    {
        DB::table('ad_campaign_creatives')->insert(['ad_id' => 'a1', 'campaign_id' => 'ca1', 'welcome_message' => 'Old welcome',
            'quick_reply_1' => 'Old QR1', 'quick_reply_3' => 'Old QR3', 'created_at' => now(), 'updated_at' => now()]);
        $json = json_encode(['type' => 'VISUAL_EDITOR', 'version' => 2, 'text_format' => ['message' => [
            'text' => 'New welcome', 'quick_replies' => ['New QR1', 'New QR2']]]]);

        $this->sync(['a1' => [[], ['creative' => ['page_welcome_message' => $json]]]]);

        $row = $this->row('a1');
        $this->assertSame('New welcome', $row->welcome_message);
        $this->assertSame('New QR1', $row->quick_reply_1);
        $this->assertSame('New QR2', $row->quick_reply_2);
        $this->assertSame('Old QR3', $row->quick_reply_3);
    }

    public function test_S1_a_campaign_only_row_stays_the_campaign_fallback_and_the_ad_gets_its_own_row(): void
    {
        DB::table('ad_campaign_creatives')->insert(['ad_id' => null, 'campaign_id' => 'cX', 'welcome_message' => 'Campaign welcome',
            'created_at' => now(), 'updated_at' => now()]);

        $this->sync(['a1' => [['campaign_id' => 'cX'], self::welcome('Ad one welcome')]]);

        $this->assertSame(2, DB::table('ad_campaign_creatives')->where('campaign_id', 'cX')->count());
        $this->assertSame('Campaign welcome', DB::table('ad_campaign_creatives')->where('campaign_id', 'cX')->whereNull('ad_id')->value('welcome_message'));
        $this->assertSame('Ad one welcome', $this->row('a1')->welcome_message);
    }

    public function test_S1_a_second_sync_does_not_duplicate_the_ad_rows(): void
    {
        $ads = ['a1' => [['campaign_id' => 'cX'], []], 'a2' => [['campaign_id' => 'cX'], []]];
        $this->sync($ads);
        $this->sync($ads);

        $this->assertSame(2, DB::table('ad_campaign_creatives')->count());
    }

    public function test_S1_two_ads_of_one_campaign_keep_their_own_rows_through_the_upload(): void
    {
        $log = $this->upload(['a1' => ['cX', 'Headline one', 'Welcome one'], 'a2' => ['cX', 'Headline two', 'Welcome two']]);

        $this->assertSame('done', $log->status);
        $this->assertSame(2, DB::table('ad_campaign_creatives')->where('campaign_id', 'cX')->count());
        $this->assertSame('Welcome one', $this->row('a1')->welcome_message);
        $this->assertSame('Welcome two', $this->row('a2')->welcome_message);
        $this->assertSame('111', $this->row('a2')->account_id);
    }

    public function test_S1_the_upload_keeps_staff_text_in_an_existing_ad_row_and_adds_the_missing_ad(): void
    {
        DB::table('ad_campaign_creatives')->insert(['ad_id' => 'a1', 'campaign_id' => 'cX', 'headline' => 'Staff headline',
            'welcome_message' => 'Staff welcome', 'created_at' => now(), 'updated_at' => now()]);

        $this->upload(['a1' => ['cX', 'File headline', 'File welcome'], 'a2' => ['cX', 'Headline two', 'Welcome two']]);

        $this->assertSame('Staff headline', $this->row('a1')->headline);
        $this->assertSame('Staff welcome', $this->row('a1')->welcome_message);
        $this->assertSame('Welcome two', $this->row('a2')->welcome_message);
        $this->assertSame(2, DB::table('ad_campaign_creatives')->count());
    }
}
