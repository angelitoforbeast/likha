<?php

namespace Tests\Feature\MetaAds;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** Spec 254 S2: ads:backfill-creatives-per-ad creates the missing per-ad rows from each ad's latest report row. */
class BackfillCreativesPerAdTest extends MetaAdsTestCase
{
    private function report(string $ad, string $day, ?string $campaign, array $extra = []): void
    {
        DB::table('ads_manager_reports')->insert($extra + [
            'day' => $day, 'ad_id' => $ad, 'campaign_id' => $campaign, 'campaign_name' => 'Campaign ' . $campaign,
            'page_name' => 'Page P1', 'headline' => "Headline {$ad} {$day}", 'body_ad_settings' => "Body {$ad} {$day}",
            'welcome_message' => "Welcome {$ad} {$day}", 'quick_reply_1' => "QR1 {$ad}", 'quick_reply_2' => null, 'quick_reply_3' => null,
            'amount_spent_php' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedAds(): void
    {
        // a1: two days, the latest wins. a2: already has its own row. a3: no campaign id (cannot be stored).
        $this->report('a1', '2026-11-07', 'c1');
        $this->report('a1', '2026-11-08', 'c1', ['ad_set_delivery' => 'active']);
        $this->report('a2', '2026-11-08', 'c1');
        $this->report('a3', '2026-11-08', null);
        $this->report('a4', '2026-11-08', 'c2', ['account_id' => '222']);
        DB::table('ad_campaign_creatives')->insert([
            ['ad_id' => 'a2', 'campaign_id' => 'c1', 'account_id' => '111', 'headline' => 'Staff headline', 'welcome_message' => null,
                'ad_link' => 'https://www.facebook.com/1', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()],
            ['ad_id' => null, 'campaign_id' => 'c1', 'account_id' => '111', 'headline' => 'Campaign headline', 'welcome_message' => 'Campaign welcome',
                'ad_link' => null, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()],
        ]);
    }

    private function backfill(array $options = []): string
    {
        Artisan::call('ads:backfill-creatives-per-ad', $options);

        return Artisan::output();
    }

    public function test_S2_dry_run_counts_without_writing(): void
    {
        $this->seedAds();

        $out = $this->backfill(['--dry-run' => true]);

        $this->assertStringContainsString('Ads without their own creative row: 3', $out);
        $this->assertStringContainsString('Would create: 2', $out);
        $this->assertStringContainsString('Skipped (no campaign id): 1', $out);
        $this->assertSame(2, DB::table('ad_campaign_creatives')->count());
    }

    public function test_S2_creates_rows_from_the_latest_report_and_never_changes_existing_rows(): void
    {
        $this->seedAds();
        $before = DB::table('ad_campaign_creatives')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $out = $this->backfill();

        $this->assertStringContainsString('Created: 2', $out);
        $a1 = DB::table('ad_campaign_creatives')->where('ad_id', 'a1')->first();
        $this->assertSame('c1', $a1->campaign_id);
        $this->assertSame('Campaign c1', $a1->campaign_name);
        $this->assertSame('Page P1', $a1->page_name);
        $this->assertSame('Headline a1 2026-11-08', $a1->headline);
        $this->assertSame('Body a1 2026-11-08', $a1->body_ad_settings);
        $this->assertSame('Welcome a1 2026-11-08', $a1->welcome_message, 'the ad\'s own content, never the campaign row');
        $this->assertSame('QR1 a1', $a1->quick_reply_1);
        $this->assertNull($a1->ad_link);
        $this->assertSame('111', $a1->account_id, 'account from the campaign\'s rows');
        $this->assertSame('222', DB::table('ad_campaign_creatives')->where('ad_id', 'a4')->value('account_id'), 'account from the report row');
        $this->assertFalse(DB::table('ad_campaign_creatives')->where('ad_id', 'a3')->exists());

        $after = DB::table('ad_campaign_creatives')->whereIn('id', array_column($before, 'id'))->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $this->assertSame($before, $after);
    }

    public function test_S2_is_idempotent(): void
    {
        $this->seedAds();
        $this->backfill();

        $out = $this->backfill();

        $this->assertStringContainsString('Ads without their own creative row: 1', $out);
        $this->assertStringContainsString('Created: 0', $out);
        $this->assertSame(4, DB::table('ad_campaign_creatives')->count());
    }
}
