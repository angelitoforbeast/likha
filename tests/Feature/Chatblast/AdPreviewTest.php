<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Services\Chatbot\AdGroups;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * Ang preview ng isang ad ng page (spec 181): ang video o larawan (ang ad_link na naka-save, gaya ng /owner/private) at
 * ang headline at body. Walang tawag sa Meta. Parehong access ng page values; ang ad lang ng page na ito.
 */
class AdPreviewTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2025_07_15_232022_create_ads_manager_reports_table.php',
            'database/migrations/2025_07_17_222429_add_headline_and_body_to_ads_manager_reports_table.php',
        ]]);
        Schema::create('ad_campaign_creatives', function (Blueprint $t) {
            $t->id();
            $t->string('ad_id')->nullable()->unique();
            $t->string('campaign_id')->unique();
            $t->string('campaign_name')->nullable();
            $t->string('page_name')->nullable();
            $t->text('headline')->nullable();
            $t->text('body_ad_settings')->nullable();
            $t->string('ad_link')->nullable();
            $t->timestamps();
        });
        $this->travelTo('2026-10-20 10:00:00');
        $this->page('Precious Lopez', '1001');
        $this->page('Page Q', '2002');
        $this->actingAs($this->user());
        // 111 ay nasa group ng page; 222 ay nakita lang sa chat ng page; 999 ay sa ibang page lang.
        app(AdGroups::class)->save('Precious Lopez', null, 'Serum', ['111'], []);
        $this->chat('1001', 'p1', '222');
        $this->chat('2002', 'p2', '999');
    }

    private function chat(string $pageId, string $psid, string $ad): void
    {
        BotcakeChat::create(['page_id' => $pageId, 'page_name' => $pageId === '1001' ? 'Precious Lopez' : 'Page Q', 'psid' => $psid, 'name' => 'C',
            'message' => 'Hi', 'payload' => ['ad_id' => $ad], 'received_at' => now()]);
    }

    private function report(string $ad, string $campaign): void
    {
        DB::table('ads_manager_reports')->insert(['page_name' => 'Precious Lopez', 'day' => '2026-10-19', 'campaign_name' => 'Serum Sale', 'ad_set_name' => 'Women 25+',
            'headline' => 'Report headline ' . $ad, 'ad_id' => $ad, 'campaign_id' => $campaign, 'ad_set_id' => 's' . $ad, 'amount_spent_php' => 10]);
    }

    private function preview(string $ad, string $page = 'Precious Lopez')
    {
        return $this->getJson('/chatbot/ad-groups/ad?page=' . rawurlencode($page) . '&ad=' . $ad);
    }

    public function test_S_181_3_an_ad_with_stored_media_returns_its_link_and_text_and_one_without_says_no_preview(): void
    {
        DB::table('ad_campaign_creatives')->insert(['ad_id' => '111', 'campaign_id' => 'c111', 'headline' => 'Glow in 7 days', 'body_ad_settings' => "Serum\nPromo",
            'ad_link' => 'https://www.facebook.com/reel/123456', 'created_at' => now(), 'updated_at' => now()]);
        $this->report('111', 'c111');

        $this->preview('111')->assertOk()->assertJson(['ad_id' => '111', 'link' => 'https://www.facebook.com/reel/123456', 'headline' => 'Glow in 7 days',
            'body' => "Serum\nPromo", 'name' => 'Serum Sale / Women 25+ / Report headline 111', 'from_campaign' => false]);

        // Walang naka-save na creative: walang link (No preview); ang headline ay mula sa report kung mayroon.
        $this->report('222', 'c222');
        $this->preview('222')->assertOk()->assertJson(['ad_id' => '222', 'link' => null, 'headline' => 'Report headline 222', 'from_campaign' => false]);
    }

    public function test_S_181_3_the_campaign_creative_is_the_fallback_and_a_link_that_is_not_facebook_is_not_embedded(): void
    {
        // Gaya ng /owner/private: kapag walang creative ang ad, ang creative ng campaign nito, na may tanda.
        DB::table('ad_campaign_creatives')->insert(['ad_id' => null, 'campaign_id' => 'c222', 'headline' => 'Campaign headline',
            'ad_link' => 'https://fb.watch/abc123/', 'created_at' => now(), 'updated_at' => now()]);
        $this->report('222', 'c222');
        $this->preview('222')->assertOk()->assertJson(['link' => 'https://fb.watch/abc123/', 'headline' => 'Campaign headline', 'from_campaign' => true]);

        // Ang body na wala sa creative ay mula sa report; ang link na hindi https na Facebook ay hindi ini-embed.
        DB::table('ads_manager_reports')->where('ad_id', '222')->update(['body' => 'Report body']);
        $this->preview('222')->assertOk()->assertJson(['body' => 'Report body']);
        DB::table('ad_campaign_creatives')->insert(['ad_id' => '111', 'campaign_id' => 'c111', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['javascript:alert(1)', 'https://evilfacebook.com/x', 'https://facebook.com.evil.com/x', 'http://www.facebook.com/x', 'https://www.facebook.com/reel/1'] as $link) {
            DB::table('ad_campaign_creatives')->where('ad_id', '111')->update(['ad_link' => $link]);
            $this->preview('111')->assertOk()->assertJson(['link' => $link === 'https://www.facebook.com/reel/1' ? $link : null], $link);
        }
    }

    public function test_S_181_5_only_editors_and_only_ads_of_the_page(): void
    {
        $this->preview('999')->assertNotFound();                  // ad ng ibang page
        $this->preview('12a')->assertStatus(422);
        $this->preview('111', 'No Such Page')->assertNotFound();
        $this->preview('999', 'Page Q')->assertOk();
        $this->get('/chatbot/pages')->assertOk()->assertSee('data-ag-ad-url', false);

        $this->actingAs($this->user('Data Encoder', 'enc@example.test'));
        $this->preview('111')->assertForbidden();
        $this->getJson('/chatbot/ad-groups?page=Precious%20Lopez')->assertForbidden();
    }
}
