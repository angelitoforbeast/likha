<?php

namespace Tests\Feature\MetaAds;

use App\Services\MetaAds\Insights;
use App\Services\MetaAds\Sync;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/** Ang page_name, delivery at result type ng API row: pareho ang salita sa export na inu-upload. */
class MetaAdsFieldsTest extends MetaAdsTestCase
{
    private const DAY = '2026-10-26';

    private function sync(array $ads): \App\Models\UploadLog
    {
        Sync::setWriteMode(true);
        $this->connect($this->user('Marketing', 'ana@example.test'), self::TOKEN_ANA, ['111']);
        $this->fakeGraph(
            insights: ['111' => array_map(fn ($id) => self::insight('111', $id, self::DAY, 10.00), array_keys($ads))],
            ads: ['111' => array_map(fn ($id, $extra) => self::adDetail($id, $extra), array_keys($ads), $ads)],
        );

        return app(Sync::class)->run(Sync::startLog(self::DAY, self::DAY, null), self::DAY, self::DAY);
    }

    public function test_S_173_4_page_name_comes_from_page_settings_by_actor_id_or_story_id_without_asking_meta_for_the_page(): void
    {
        $this->pageSetting('Joy Villaluna', '602349066290691');
        $this->pageSetting('Sienna Lopez', '970868019448492');

        $this->sync([
            'a1' => ['creative' => ['object_story_spec' => ['page_id' => null], 'actor_id' => '602349066290691']],
            'a2' => ['creative' => ['object_story_spec' => ['page_id' => null], 'effective_object_story_id' => '970868019448492_1221334455']],
        ]);

        $this->assertSame(['a1' => 'Joy Villaluna', 'a2' => 'Sienna Lopez'], DB::table('ads_manager_reports')->orderBy('ad_id')->pluck('page_name', 'ad_id')->all());
        $this->assertNotContains('', $this->graphCalls);
        $adsQuery = collect($this->graphRequests)->firstWhere('path', 'act_111/ads')['query']['fields'];
        $this->assertStringContainsString('actor_id', $adsQuery);
        $this->assertStringContainsString('effective_object_story_id', $adsQuery);
    }

    public function test_S_173_4_an_unknown_page_takes_the_earlier_upload_name_else_the_row_is_skipped_and_listed_on_the_run_log(): void
    {
        $this->pageSetting('Old Page', '666', false); // naka-replace na: hindi binibilang
        DB::table('ads_manager_reports')->insert(['day' => '2026-10-20', 'ad_id' => 'a3', 'page_name' => 'From Upload', 'created_at' => now(), 'updated_at' => now()]);

        $log = $this->sync([
            'a3' => ['creative' => ['object_story_spec' => ['page_id' => '555']]],
            'a4' => ['creative' => ['object_story_spec' => ['page_id' => null], 'actor_id' => '666']],
        ]);

        $this->assertSame('From Upload', DB::table('ads_manager_reports')->where('day', self::DAY)->where('ad_id', 'a3')->value('page_name'));
        $this->assertFalse(DB::table('ads_manager_reports')->where('ad_id', 'a4')->exists());
        $this->assertSame(1, (int) $log->skipped);
        $this->assertStringContainsString('ad a4 (page 666)', (string) $log->error_message);
        $this->assertSame('done', $log->status);
    }

    public static function deliveryPairs(): array
    {
        // campaign effective_status, ad set effective_status => campaign_delivery, ad_set_delivery (export)
        return [
            '158 rows: PAUSED | CAMPAIGN_PAUSED' => ['PAUSED', 'CAMPAIGN_PAUSED', 'not_delivering', 'not_delivering'],
            '43 rows: ACTIVE | ACTIVE'           => ['ACTIVE', 'ACTIVE', 'active', 'active'],
            '2 rows: PAUSED | PAUSED'            => ['PAUSED', 'PAUSED', 'not_delivering', 'inactive'],
            '2 rows: ACTIVE | PAUSED'            => ['ACTIVE', 'PAUSED', 'not_delivering', 'inactive'],
            'other status: ARCHIVED | WITH_ISSUES' => ['ARCHIVED', 'WITH_ISSUES', 'not_delivering', 'not_delivering'],
        ];
    }

    #[DataProvider('deliveryPairs')]
    public function test_S_173_5_delivery_uses_the_export_wording(string $campaign, string $adSet, string $campaignDelivery, string $adSetDelivery): void
    {
        $ad = self::adDetail('a1', ['campaign' => ['effective_status' => $campaign], 'adset' => ['effective_status' => $adSet]]);

        $row = app(Insights::class)->map('111', self::insight('111', 'a1', self::DAY, 10.00), $ad, []);

        $this->assertSame([$campaignDelivery, $adSetDelivery], [$row['campaign_delivery'], $row['ad_set_delivery']]);
    }

    public static function resultTypes(): array
    {
        return [
            'MESSAGING_PURCHASE_CONVERSION' => ['MESSAGING_PURCHASE_CONVERSION', 'Meta purchases'],
            'OFFSITE_CONVERSIONS'           => ['OFFSITE_CONVERSIONS', 'Meta purchases'],
            'CONVERSATIONS'                 => ['CONVERSATIONS', 'Messaging conversations started'],
            'VALUE'                         => ['VALUE', 'Meta purchase conversion value'],
            'LINK_CLICKS (unchanged)'       => ['LINK_CLICKS', 'Link clicks'],
        ];
    }

    #[DataProvider('resultTypes')]
    public function test_S_173_6_result_type_uses_the_export_wording(string $goal, string $expected): void
    {
        $ad = self::adDetail('a1', ['adset' => ['optimization_goal' => $goal]]);

        $row = app(Insights::class)->map('111', self::insight('111', 'a1', self::DAY, 10.00), $ad, []);

        $this->assertSame($expected, $row['result_type']);
    }
}
