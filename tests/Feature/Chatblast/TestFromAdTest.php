<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Models\PageSetting;
use App\Services\Chatbot\AdGroups;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\FlowGraph;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Spec 217: ang Test (/chatbot/test) bilang customer na galing sa isang ad: ang group ng ad gaya ng sa takbo (ad, ad set,
 * campaign; walang tawag sa Meta), ang linya sa Test chat, at ang Ad dropdown ng Ad-based na page.
 */
class TestFromAdTest extends ChatblastTestCase
{
    private const TEXT = '{{Main Flow}}|{{PRICE}}';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_22_100000_widen_source_on_botcake_psids.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_25_100000_create_chatbot_waits_table.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
            'database/migrations/2025_07_15_232022_create_ads_manager_reports_table.php',
            'database/migrations/2025_07_17_222429_add_headline_and_body_to_ads_manager_reports_table.php',
            'database/migrations/2026_05_22_140000_create_ad_catalog_table.php',
        ]]);
        Http::fake();
        $this->travelTo('2026-10-20 10:00:00');
        $this->page('Page P', '1001');
        foreach (['main_flow' => 'Page MF', 'price' => '199', 'seq_1' => 'Page Seq'] as $k => $v) app(PageSettings::class)->set('Page P', 'chatbot', $k, $v);
        app(AdGroups::class)->setMode('Page P', true);
    }

    private function group(string $name, array $ads, array $values = []): int
    {
        $r = app(AdGroups::class)->save('Page P', null, $name, $ads, $values);
        $this->assertTrue($r['ok'], $r['error'] ?? '');

        return $r['id'];
    }

    private function panel(): array
    {
        Cache::flush();
        PageSettings::forget('Page P');

        return $this->getJson('/chatbot/ad-groups?page=Page%20P')->assertOk()->json();
    }

    /** $n customers of the ad in the last days (each its own psid). */
    private function customers(string $ad, int $n, int $daysAgo = 1): void
    {
        for ($i = 0; $i < $n; $i++) {
            BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page P', 'psid' => $ad . $i, 'name' => 'C', 'message' => 'Hi',
                'payload' => ['ad_id' => $ad], 'received_at' => now()->subDays($daysAgo)]);
        }
    }

    /** The ad in the reports: On (spends on the last day, 2026-10-19), or Off since a few days; $since = its first day. */
    private function report(string $ad, bool $on, string $since = '2026-09-01'): void
    {
        DB::table('ads_manager_reports')->insert(['page_name' => 'Page P', 'day' => $since, 'campaign_name' => 'C', 'ad_set_name' => 'S', 'headline' => 'Ad ' . $ad,
            'ad_id' => $ad, 'amount_spent_php' => 50, 'campaign_delivery' => 'active', 'ad_set_delivery' => 'active']);
        DB::table('ads_manager_reports')->insert(['page_name' => 'Page P', 'day' => '2026-10-19', 'campaign_name' => 'C', 'ad_set_name' => 'S', 'headline' => 'Ad ' . $ad,
            'ad_id' => $ad, 'amount_spent_php' => $on ? 80 : 0, 'campaign_delivery' => $on ? 'active' : 'inactive', 'ad_set_delivery' => $on ? 'active' : 'inactive']);
    }

    // ---- S-217.4: Test as a customer from an ad ----

    private function testFlow(): void
    {
        $flow = ChatbotFlow::create(['name' => 'Flow T', 'graph' => FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => self::TEXT]]]],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]])]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);
        app(ChatbotPage::class)->link('Page P', $flow->fresh());
    }

    private function send(array $extra): array
    {
        return $this->postJson('/chatbot/test/send', ['page_name' => 'Page P', 'version' => 'published', 'message' => 'Hi'] + $extra)->assertOk()->json();
    }

    public function test_S_217_4_2_an_ad_in_a_group_by_ad_ad_set_or_campaign_uses_the_group_and_an_ad_in_no_group_uses_default(): void
    {
        $this->testFlow();
        DB::table('ad_catalog')->insert(['ad_id' => '40001', 'ad_set_id' => '50001', 'campaign_id' => '60001', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ad_catalog')->insert(['ad_id' => '40002', 'ad_set_id' => '50002', 'campaign_id' => '60002', 'created_at' => now(), 'updated_at' => now()]);
        $this->group('Serum', ['111', '50001'], ['main_flow' => 'Group MF', 'price' => '499']);
        $this->group('Soap', ['60002'], ['main_flow' => 'Soap MF']);
        $this->actingAs($this->user());

        $r = $this->send(['ad' => '111']);
        $this->assertSame('Group MF|499', $r['sends'][0]['messages'][0]['text']);
        $this->assertSame('Ad 111 → group Serum', $r['ad_note']);
        $this->assertSame('Ad 40001 → group Serum', $this->send(['ad' => '40001'])['ad_note'], 'by its ad set');
        $soap = $this->send(['ad' => '40002']);
        $this->assertSame(['Ad 40002 → group Soap', 'Soap MF|199'], [$soap['ad_note'], $soap['sends'][0]['messages'][0]['text']], 'by its campaign');
        $none = $this->send(['ad' => '777']);
        $this->assertSame(['Ad 777 → Default', 'Page MF|199'], [$none['ad_note'], $none['sends'][0]['messages'][0]['text']]);
        $this->assertSame(0, count(Http::recorded()), 'no call outside (no Meta lookup)');
        $this->assertArrayNotHasKey('ad_note', $this->send(['ad' => 'x1']), 'not an ID: tested as today');
    }

    public function test_S_217_4_3_a_page_based_page_ignores_the_ad_and_tests_as_today(): void
    {
        $this->testFlow();
        $this->group('Serum', ['111'], ['main_flow' => 'Group MF', 'price' => '499']);
        app(AdGroups::class)->setMode('Page P', false);
        $this->actingAs($this->user());
        $r = $this->send(['ad' => '111']);
        $this->assertSame('Page MF|199', $r['sends'][0]['messages'][0]['text']);
        $this->assertArrayNotHasKey('ad_note', $r);
    }

    public function test_S_217_4_4_the_ad_group_picker_still_works_and_the_ad_wins_when_both_are_set(): void
    {
        $this->testFlow();
        $serum = $this->group('Serum', ['111'], ['main_flow' => 'Group MF', 'price' => '499']);
        $soap = $this->group('Soap', ['222'], ['main_flow' => 'Soap MF']);
        $this->actingAs($this->user());
        $this->assertSame('Soap MF|199', $this->send(['ad_group' => $soap])['sends'][0]['messages'][0]['text'], 'the picker alone, as today');

        $both = $this->send(['ad_group' => $soap, 'ad' => '111']);
        $this->assertSame('Group MF|499', $both['sends'][0]['messages'][0]['text']);
        $this->assertSame('Ad 111 → group Serum (the Ad is used, not the Ad group picked)', $both['ad_note']);
        $this->assertSame('Ad 333 → Default (the Ad is used, not the Ad group picked)', $this->send(['ad_group' => $serum, 'ad' => '333'])['ad_note']);
    }

    /** The Ad dropdown of /chatbot/test (adOptions in the page) on the real /chatbot/ad-groups JSON. */
    public function test_S_217_4_1_the_ad_dropdown_lists_grouped_ads_by_group_then_the_rest_on_first_with_name_status_and_id(): void
    {
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) $this->markTestSkipped('Node is not installed.');
        $this->report('701', false);
        $this->report('702', true);
        $this->report('801', false);
        $this->report('802', true);
        $this->customers('801', 3);
        $this->customers('802', 1);
        $this->group('Serum', ['701', '702']);
        $this->actingAs($this->user());
        $data = $this->panel();
        $html = $this->get('/chatbot/test')->assertOk()->getContent();
        $this->assertSame(1, preg_match('~// ad-options:start(.*?)// ad-options:end~s', $html, $m), 'adOptions is in the page');
        $file = tempnam(sys_get_temp_dir(), 'adopt') . '.mjs';
        file_put_contents($file, html_entity_decode($m[1], ENT_QUOTES) . "\nconsole.log(JSON.stringify([adOptions(" . json_encode($data) . "), adOptions(" . json_encode(['mode' => 'page'] + $data) . ")]));");
        $p = new Process([$node, $file]);
        $p->run();
        @unlink($file);
        $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());
        [$ad, $page] = json_decode($p->getOutput(), true);

        $this->assertSame([
            ['label' => 'Serum', 'ads' => [['value' => '702', 'text' => 'C / S / Ad 702 · On · 702'], ['value' => '701', 'text' => 'C / S / Ad 701 · Off since 2026-09-02 (48 days) · 701']]],
            ['label' => 'Not in a group', 'ads' => [['value' => '802', 'text' => 'C / S / Ad 802 · On · 802'], ['value' => '801', 'text' => 'C / S / Ad 801 · Off since 2026-09-02 (48 days) · 801']]],
        ], $ad);
        $this->assertSame([], $page, 'a Page-based page has no Ad dropdown');
    }
}
