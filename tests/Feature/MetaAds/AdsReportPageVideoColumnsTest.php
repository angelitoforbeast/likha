<?php

namespace Tests\Feature\MetaAds;

use App\Services\MetaAds\Sync;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Spec 246 amendment 1: the upload on /ads_manager/report (store → the queued job) requires the three video columns and
 * the page shows the missing-column message to the uploader (S3); the "Ad accounts to sync" section is collapsed (S4).
 */
class AdsReportPageVideoColumnsTest extends MetaAdsTestCase
{
    private const BASE = ['Day', 'Ad ID', 'Account ID', 'Campaign ID', 'Ad set ID', 'Page name', 'Campaign name', 'Ad set name',
        'Amount spent (PHP)', 'Impressions', 'Results', 'Result type', 'Link clicks'];
    private const ROW  = ['2026-10-20', 'a1', '111', 'c1', 'as1', 'Page P1', 'Camp', 'Adset', '100.50', '1500', '12', 'Messaging conversations started', '33'];

    /** An Ads Manager export (XLSX, as Ads Manager exports it) posted through the page's upload form. */
    private function uploadThroughThePage(array $headers, array $row): \Illuminate\Testing\TestResponse
    {
        $disk = Storage::fake(config('filesystems.default'));
        $disk->makeDirectory('in');
        $writer = new \OpenSpout\Writer\XLSX\Writer();
        $writer->openToFile($disk->path('in/report.xlsx'));
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($headers));
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($row));
        $writer->close();
        $this->actingAs($this->user('Marketing', 'uploader@example.test'));

        return $this->followingRedirects()
            ->post('/ads_manager/report', ['files' => [new UploadedFile($disk->path('in/report.xlsx'), 'report.xlsx', null, null, true)]])
            ->assertOk();
    }

    public function test_S_246_3_5_the_page_upload_without_the_video_columns_is_refused_and_the_uploader_sees_which_are_missing(): void
    {
        $html = $this->uploadThroughThePage(self::BASE, self::ROW)->getContent();

        $message = 'Missing required column(s): 3-second video plays, ThruPlays, Video plays at 50%';
        $this->assertStringContainsString(e($message), $html);
        $this->assertSame(0, DB::table('ads_manager_reports')->count());
        $this->assertSame(['failed', $message], [$this->getJson('/ads_manager/report/status')->json('logs.0.status'),
            $this->getJson('/ads_manager/report/status')->json('logs.0.error_message')]);
    }

    public function test_S_246_3_5_the_page_upload_with_the_video_columns_fills_them(): void
    {
        $this->uploadThroughThePage(array_merge(self::BASE, ['3-second video plays', 'ThruPlays', 'Video plays at 50%']), array_merge(self::ROW, ['4,210', '1375', 980]));

        $this->assertSame('done', $this->getJson('/ads_manager/report/status')->json('logs.0.status'));
        $row = DB::table('ads_manager_reports')->where('ad_id', 'a1')->first();
        $this->assertSame([4210, 1375, 980], [(int) $row->video_plays_3s, (int) $row->video_thruplays, (int) $row->video_p50]);
    }

    /** Marketing: 111, 222, 333; Marketing - OIC: 333, 444, 555; 111 and 333 checked. */
    private function seedAccounts(): void
    {
        $this->connect($this->user('Marketing', 'ana@example.test'), self::TOKEN_ANA, ['111', '222', '333']);
        $this->connect($this->user('Marketing - OIC', 'ben@example.test'), self::TOKEN_BEN, ['333', '444', '555']);
        Sync::setAccounts(['111', '333']);
        $this->actingAs($this->user('CEO', 'ceo@example.test'));
    }

    /** The <details> that holds the accounts form, from its opening tag to its closing tag. */
    private function section(string $html): string
    {
        $start = strpos($html, '<details id="meta-accounts-section"');
        $this->assertNotFalse($start, 'the accounts section is a <details>');

        return substr($html, $start, strpos($html, '</details>', $start) - $start);
    }

    public function test_S_246_4_1_the_accounts_section_is_one_collapsed_line_with_the_count_and_holds_the_checkboxes_and_save(): void
    {
        $this->seedAccounts();

        $section = $this->section($this->get('/ads_manager/report')->assertOk()->getContent());

        $this->assertStringNotContainsString(' open', substr($section, 0, strpos($section, '>')), 'collapsed by default');
        $this->assertMatchesRegularExpression('#<summary[^>]*>\s*Ad accounts to sync \(2 of 5 checked\)\s*<span[^>]*>▸</span>\s*</summary>#u', $section);
        $this->assertStringContainsString('id="meta-accounts"', $section);
        $this->assertSame(5, substr_count($section, 'name="accounts[]"'));
        $this->assertStringContainsString('Save accounts', $section);
    }

    public function test_S_246_4_2_it_stays_open_after_a_save_that_returned_an_error_and_closed_after_a_good_one(): void
    {
        $this->seedAccounts();

        $refused = $this->followingRedirects()->post('/ads_manager/meta/accounts', ['accounts' => ['111', '999']])->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#^<details id="meta-accounts-section"[^>]* open>#', $this->section($refused));
        $this->assertStringContainsString('Not in any connection: 999', $refused);

        $invalid = $this->followingRedirects()->post('/ads_manager/meta/accounts', ['accounts' => 'not-a-list'])->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#^<details id="meta-accounts-section"[^>]* open>#', $this->section($invalid));
        $this->assertStringContainsString('The accounts field must be an array.', $this->section($invalid), 'the error is shown inside the open section');

        $saved = $this->followingRedirects()->post('/ads_manager/meta/accounts', ['accounts' => ['222']])->assertOk()->getContent();
        $this->assertStringNotContainsString(' open', substr($this->section($saved), 0, strpos($this->section($saved), '>')));
        $this->assertStringContainsString('Ad accounts to sync (1 of 5 checked)', $saved);
        $this->assertSame(['222'], Sync::accounts(), 'what is saved is unchanged');
    }
}
