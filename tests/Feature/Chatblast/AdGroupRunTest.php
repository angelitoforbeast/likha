<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\BotcakePsid;
use App\Models\ChatbotFlow;
use App\Models\ChatbotSequence;
use App\Services\Chatbot\AdGroups;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotRunner;
use App\Services\Chatbot\ChatbotSequences;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowGraph;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ang takbo para sa isang customer: ang pinakabagong ad niya sa page ang pumipili ng group; ang value na wala sa group
 * (o walang group ang ad, o walang ad) ay ang page value. Ang sequence ay nananatili sa group nang pumasok ang customer.
 */
class AdGroupRunTest extends ChatblastTestCase
{
    private const SEND = 'https://botcake.io/api/public_api/v1/pages/1001/flows/send_content';
    private const TEXT = '{{Main Flow}}|{{Main Flow 2}}|{{PRICE}}|{{SHOP DETAILS}}';

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
        config(['services.chatbot.replies_enabled' => true, 'services.botcake.async_sends' => false, 'services.chatbot.ai_async' => false]);
        Http::fake([self::SEND => Http::response(['success' => true])]);
        $this->travelTo('2026-10-20 10:00:00');
        $this->page('Page P', '1001');
        $s = app(PageSettings::class);
        foreach (['main_flow' => 'Page MF', 'main_flow_2' => 'Page MF2', 'price' => '199', 'item_name_in_jnt_dashboard' => 'Cream', 'quantity' => '1'] as $k => $v) {
            $s->set('Page P', 'chatbot', $k, $v);
        }
        app(ChatbotPage::class)->link('Page P', $this->flow(self::TEXT));
        // Ang mga group ay ginagamit lang ng page na Ad-based (spec 174); ang 172 cases ay sa ganoong page.
        app(AdGroups::class)->setMode('Page P', true);
    }

    private function flow(string $text): ChatbotFlow
    {
        $flow = ChatbotFlow::create(['name' => 'Flow ' . uniqid(), 'graph' => FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => $text]]]],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]])]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);

        return $flow->fresh();
    }

    private function itemA(array $values = []): int
    {
        return app(AdGroups::class)->save('Page P', null, 'Item A', ['111', '222'],
            $values + ['item_name_in_jnt_dashboard' => 'Serum', 'price' => '499', 'main_flow' => 'X'])['id'];
    }

    private function chat(?string $ad, string $psid = '7001', bool $run = false): BotcakeChat
    {
        $chat = BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page P', 'psid' => $psid, 'name' => 'Ana Cruz', 'message' => 'Hi',
            'payload' => $ad === null ? [] : ['ad_id' => $ad], 'received_at' => now()]);
        if ($run) app(ChatbotRunner::class)->handle($chat->id);

        return $chat;
    }

    /** @return array<int, string> */
    private function sent(string $psid = '7001'): array
    {
        return Http::recorded(fn (HttpRequest $r) => $r->url() === self::SEND && ($r->data()['psid'] ?? null) === $psid)
            ->map(fn ($p) => $p[0]->data()['data']['content']['messages'][0]['text'] ?? '')->values()->all();
    }

    private const PAGE_TEXT = "Page MF|Page MF2|199|PAGE: Page P\nITEM: Cream\nPRICE: ₱199\nQUANTITY: 1";
    private const GROUP_TEXT = "X|Page MF2|499|PAGE: Page P\nITEM: Serum\nPRICE: ₱499\nQUANTITY: 1";

    public function test_S_172_1_a_page_with_no_groups_runs_on_the_page_values(): void
    {
        $this->chat('222', run: true);
        $this->assertSame([self::PAGE_TEXT], $this->sent());
    }

    public function test_S_172_2_the_customers_ad_in_a_group_gets_the_groups_values_and_the_page_value_for_the_rest(): void
    {
        $this->itemA();
        $this->chat('222', run: true);
        $this->assertSame([self::GROUP_TEXT], $this->sent());
    }

    public static function latestAd(): array
    {
        return [
            'ad in no group' => [['333'], null, false],
            'no ad at all' => [[null], null, false],
            '111 then 333: the latest ad wins' => [['111', '333'], null, false],
            '333 then 111' => [['333', '111'], null, true],
            'a later chat without an ad keeps the last ad' => [['111', null], null, true],
            'no ad in the chats: Botcake\'s ad of the customer' => [[null], '222', true],
            'the chat\'s ad wins over Botcake\'s' => [['333'], '222', false],
        ];
    }

    #[DataProvider('latestAd')]
    public function test_S_172_3_and_4_the_latest_known_ad_picks_the_group(array $chatAds, ?string $psidAd, bool $group): void
    {
        $this->itemA();
        if ($psidAd !== null) BotcakePsid::create(['page' => 'Page P', 'psid' => '7001', 'fb_name' => 'Ana Cruz', 'status' => 'matched', 'ad_id' => $psidAd]);
        foreach ($chatAds as $i => $ad) {
            $this->travel(1)->minutes();
            $last = $this->chat($ad);
        }
        app(ChatbotRunner::class)->handle($last->id);
        $this->assertSame([$group ? self::GROUP_TEXT : self::PAGE_TEXT], $this->sent());
    }

    public function test_S_172_5_a_group_with_its_own_flow_runs_that_flow_for_its_ads(): void
    {
        $this->itemA(['flow_id' => (string) $this->flow('Group flow {{PRICE}}')->id]);
        $this->chat('111', '7001', true);
        $this->chat('333', '7002', true);
        $this->assertSame(['Group flow 499'], $this->sent('7001'));
        $this->assertSame([self::PAGE_TEXT], $this->sent('7002'));

        // Na-delete ang flow ng group: ang flow ng page ang tumatakbo, sa values ng group (hindi tahimik na walang sagot).
        $groupFlow = ChatbotFlow::where('name', '!=', '')->orderByDesc('id')->first();
        $groupFlow->delete();
        \App\Services\Chatbot\BotConfigCache::forgetFlow($groupFlow->id);
        $this->chat('111', '7005', true);
        $this->assertSame([self::GROUP_TEXT], $this->sent('7005'), 'ang flow ng page, ang values ng group');
        $this->assertTrue(app(ChatbotPage::class)->runs('Page P'));

        // Walang flow ang page pero may flow ang group: ang bagong chat ay pinapatakbo pa rin (ChatbotInbound).
        app(AdGroups::class)->save('Page P', app(AdGroups::class)->groupForAd('Page P', '111'), 'Item A', ['111', '222'], ['flow_id' => (string) $this->flow('Group flow {{PRICE}}')->id]);
        app(ChatbotPage::class)->link('Page P', null);
        $this->assertTrue(app(ChatbotPage::class)->runs('Page P'));
        $this->chat('111', '7004', true);
        $this->assertSame(['Group flow 499'], $this->sent('7004'));

        // Ang page na naka-Off ay walang takbo, kahit may sariling flow ang group.
        app(PageSettings::class)->set('Page P', 'chatbot', 'active', '0', false, null, true);
        $this->chat('111', '7003', true);
        $this->assertSame([], $this->sent('7003'));
    }

    public function test_S_174_2_a_page_based_page_ignores_its_groups_and_switching_to_ad_based_applies_them(): void
    {
        $groups = app(AdGroups::class);
        $groups->setMode('Page P', false);
        $this->itemA();
        $this->chat('222', '7001', true);
        $this->assertSame([self::PAGE_TEXT], $this->sent('7001'), 'Page-based: page values kahit nasa group ang ad');

        $groups->setMode('Page P', true);
        $this->chat('222', '7002', true);
        $this->assertSame([self::GROUP_TEXT], $this->sent('7002'), 'Ad-based: ang values ng group (172)');

        $groups->setMode('Page P', false);
        $this->chat('222', '7003', true);
        $this->assertSame([self::PAGE_TEXT], $this->sent('7003'), 'balik sa Page-based');
        $this->assertSame(['Item A'], array_column($groups->groups('Page P'), 'name'), 'naiiwan ang mga group');

        // Ang flow ng group ay hindi rin tumatakbo sa Page-based na page na walang sariling flow.
        $groups->save('Page P', $groups->groupForAd('Page P', '111'), 'Item A', ['111', '222'], ['flow_id' => (string) $this->flow('Group flow')->id]);
        app(ChatbotPage::class)->link('Page P', null);
        $this->assertFalse(app(ChatbotPage::class)->runs('Page P'));
        $groups->setMode('Page P', true);
        $this->assertTrue(app(ChatbotPage::class)->runs('Page P'));
    }

    public function test_S_174_2_a_sequence_on_a_page_based_page_uses_page_values_and_pins_nothing(): void
    {
        app(AdGroups::class)->setMode('Page P', false);
        $this->itemA();
        $seq = ChatbotSequence::create(['name' => 'Drip', 'active' => true, 'exit_on_reply' => false]);
        $seq->steps()->create(['position' => 1, 'delay_amount' => 0, 'delay_unit' => 'minutes', 'flow_id' => $this->flow('Seq {{PRICE}}')->id, 'active' => true]);
        $this->chat('222', '7001');
        app(ChatbotSequences::class)->subscribe($seq->id, 1001, 7001, 'other');
        Artisan::call('chatbot:sequence-tick');

        $this->assertSame(['Seq 199'], $this->sent('7001'), 'page values kahit nasa group ang ad');
        $this->assertNull((new CustomerFields(1001, 7001))->get(AdGroups::SEQ_PIN . $seq->id), 'walang pin sa Page-based na page');
    }

    public function test_S_172_14_a_test_run_on_the_test_tab_can_use_a_chosen_group(): void
    {
        $id = $this->itemA();
        $this->actingAs($this->user());
        $text = fn (array $extra) => $this->postJson('/chatbot/test/send', ['page_name' => 'Page P', 'version' => 'published', 'message' => 'Hi'] + $extra)
            ->assertOk()->json('sends.0.messages.0.text');

        $this->assertSame(self::PAGE_TEXT, $text([]));
        $this->assertSame(self::GROUP_TEXT, $text(['ad_group' => $id]));
        $this->assertSame(self::PAGE_TEXT, $text(['ad_group' => 999]), 'walang ganoong group: page values');
        // Ang flow ng group ang tumatakbo kapag walang napiling flow sa Test.
        app(AdGroups::class)->save('Page P', $id, 'Item A', ['111', '222'], ['flow_id' => (string) $this->flow('Group flow {{PRICE}}')->id]);
        $this->assertSame('Group flow 499', $text(['ad_group' => $id]));
    }

    public function test_S_172_6_a_sequence_keeps_the_group_the_customer_entered_with(): void
    {
        $this->itemA();
        $seq = ChatbotSequence::create(['name' => 'Drip', 'active' => true, 'exit_on_reply' => false]);
        foreach ([1, 2] as $i) $seq->steps()->create(['position' => $i, 'delay_amount' => 0, 'delay_unit' => 'minutes', 'flow_id' => $this->flow('Seq {{PRICE}}')->id, 'active' => true]);
        foreach (['7001' => '222', '7002' => '333'] as $psid => $ad) {
            $this->chat($ad, $psid);
            app(ChatbotSequences::class)->subscribe($seq->id, 1001, (int) $psid, 'other');
        }
        // Lumipat ng ad ang dalawa pagkatapos pumasok sa sequence.
        $this->travel(1)->minutes();
        $this->chat('333', '7001');
        $this->chat('111', '7002');

        Artisan::call('chatbot:sequence-tick');
        $this->travel(1)->minutes();
        Artisan::call('chatbot:sequence-tick');
        $this->assertSame(['Seq 499', 'Seq 499'], $this->sent('7001'));
        $this->assertSame(['Seq 199', 'Seq 199'], $this->sent('7002'));
        // "Clear all fields" ay hindi nag-aalis ng group ng sequence.
        $fields = new CustomerFields(1001, 7001);
        $fields->clearAll();
        $this->assertNotNull((new CustomerFields(1001, 7001))->get(AdGroups::SEQ_PIN . $seq->id));
    }

    // ---- Spec 212: ang group ay may ad, ad set o campaign ID; ang hindi kilalang ad ay tinatanong sa Meta nang isang beses ----

    private int $graphCalls = 0;
    private const META_TOKEN = 'EAAmetatesttokenSECRETabcdefghijklmnop123';
    private const CAMPAIGN = '60001';
    private const ADSET = '50001';
    private const AD = '40001';

    /** Ang ad sa ad_catalog: ang ad set at campaign nito (gaya ng isinusulat ng upload o sync). */
    private function catalog(string $ad, ?string $adset, ?string $campaign): void
    {
        DB::table('ad_catalog')->insert(['ad_id' => $ad, 'ad_set_id' => $adset, 'campaign_id' => $campaign, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Ang hierarchy ng mga test: ad 40001 sa ad set 50001 sa campaign 60001; ang kapatid na 40002 sa ad set 50002 ng parehong campaign; 40003 sa ibang campaign. */
    private function hierarchy(): void
    {
        $this->catalog(self::AD, self::ADSET, self::CAMPAIGN);
        $this->catalog('40002', '50002', self::CAMPAIGN);
        $this->catalog('40003', '50003', '60002');
    }

    /** Isang Meta Ads connection (ang token ay naka-encrypt sa app_settings, gaya ng Connect). */
    private function metaConnection(): void
    {
        app(\App\Services\MetaAds\Connections::class)->save(1, self::META_TOKEN, ['fb_user_name' => 'Owner', 'ad_accounts' => [['id' => '777', 'name' => 'Acc']]]);
    }

    /** Ang sagot ng Meta sa GET /<ad>; $answer = callable(ad id) o ang JSON / [status, JSON]. */
    private function meta(mixed $answer): void
    {
        Http::fake(['graph.facebook.com/*' => function (HttpRequest $r) use ($answer) {
            $this->graphCalls++;
            $ad = basename(parse_url($r->url(), PHP_URL_PATH));
            $a = is_callable($answer) ? $answer($ad) : $answer;
            if ($a instanceof \Throwable) throw $a;
            [$status, $body] = is_array($a) && isset($a[0]) && is_int($a[0]) ? $a : [200, $a];

            return Http::response($body, $status);
        }, self::SEND => Http::response(['success' => true])]);
    }

    private function metaCalls(): int
    {
        return $this->graphCalls;
    }

    /** Isang group na may parehong values ng Item A (para pareho ang sagot), ang ID lang ang iba. */
    private function groupWith(string $name, array $ids, array $values = []): int
    {
        $r = app(AdGroups::class)->save('Page P', null, $name, $ids, $values + ['item_name_in_jnt_dashboard' => 'Serum', 'price' => '499', 'main_flow' => 'X']);
        $this->assertTrue($r['ok'], $r['error'] ?? '');

        return $r['id'];
    }

    public static function levels(): array
    {
        return ['campaign (1.1)' => [self::CAMPAIGN], 'ad set (1.2)' => [self::ADSET], 'the ad itself' => [self::AD]];
    }

    #[DataProvider('levels')]
    public function test_S_212_1_1_1_2_3_2_and_3_6_a_group_listing_the_ads_campaign_or_ad_set_answers_like_the_exact_ad(string $id): void
    {
        $this->hierarchy();
        $this->groupWith('Serum', [$id]);
        $this->chat(self::AD, run: true);
        $this->assertSame([self::GROUP_TEXT], $this->sent(), 'ang reply ng group, gaya ng sa eksaktong ad (hindi "Nothing to send")');
        $this->assertSame(0, $this->metaCalls(), 'kilala ang ad: walang tawag sa Meta');
    }

    public function test_S_212_1_3_and_1_4_the_most_specific_level_wins_whatever_the_group_number(): void
    {
        $this->hierarchy();
        $g = app(AdGroups::class);
        // Ang group ng campaign ang una (pinakamababang numero), ang group ng ad mismo ang huli.
        $a = $this->groupWith('By campaign', [self::CAMPAIGN], ['price' => '100']);
        $b = $this->groupWith('By ad set', [self::ADSET], ['price' => '200']);
        $c2 = $this->groupWith('By ad', [self::AD], ['price' => '300']);
        $this->assertTrue($c2 > $b && $b > $a);

        $this->assertSame($c2, $g->groupForAd('Page P', self::AD));
        $this->chat(self::AD, '7001', true);
        $this->assertSame(['X|Page MF2|300|PAGE: Page P' . "\nITEM: Serum\nPRICE: ₱300\nQUANTITY: 1"], $this->sent('7001'));
        $g->removeAd('Page P', $c2, self::AD);
        $this->assertSame($b, $g->groupForAd('Page P', self::AD), 'wala na ang ad: ang ad set');
        $g->removeAd('Page P', $b, self::ADSET);
        $this->assertSame($a, $g->groupForAd('Page P', self::AD), 'wala na ang ad set: ang campaign');
        $g->removeAd('Page P', $a, self::CAMPAIGN);
        $this->assertNull($g->groupForAd('Page P', self::AD), 'wala na: page values');
    }

    public function test_S_212_1_5_a_sibling_ad_set_or_another_campaign_does_not_match(): void
    {
        $this->hierarchy();
        $this->groupWith('By ad set', [self::ADSET]);
        $this->chat('40002', '7001', true);   // kapatid na ad set, parehong campaign
        $this->chat('40003', '7002', true);   // ibang campaign
        $this->assertSame([self::PAGE_TEXT], $this->sent('7001'));
        $this->assertSame([self::PAGE_TEXT], $this->sent('7002'));
    }

    public static function latestAdLevels(): array
    {
        return [
            'grouped campaign ad, then an ungrouped ad: the latest wins' => [[self::AD, '40003'], false],
            'ungrouped, then the grouped campaign ad' => [['40003', self::AD], true],
            'grouped campaign ad, then a chat without an ad' => [[self::AD, null], true],
        ];
    }

    #[DataProvider('latestAdLevels')]
    public function test_S_212_1_8_the_latest_ad_rule_holds_through_ad_set_and_campaign(array $ads, bool $group): void
    {
        $this->hierarchy();
        $this->groupWith('By campaign', [self::CAMPAIGN]);
        foreach ($ads as $ad) {
            $this->travel(1)->minutes();
            $last = $this->chat($ad);
        }
        app(ChatbotRunner::class)->handle($last->id);
        $this->assertSame([$group ? self::GROUP_TEXT : self::PAGE_TEXT], $this->sent());
    }

    public function test_S_212_1_9_2_9_and_3_7_page_based_pages_no_groups_and_exact_matches_never_look_up(): void
    {
        $this->metaConnection();
        $this->meta(['id' => '49999', 'adset_id' => self::ADSET, 'campaign_id' => self::CAMPAIGN]);
        $g = app(AdGroups::class);
        // Ad-based na walang group: walang lookup.
        $this->chat('49999', '7001', true);
        $this->assertSame([self::PAGE_TEXT], $this->sent('7001'));
        // Page-based na may group ng campaign: page values, walang lookup.
        $g->setMode('Page P', false);
        $this->groupWith('By campaign', [self::CAMPAIGN, '222']);
        $this->chat('49999', '7002', true);
        $this->assertSame([self::PAGE_TEXT], $this->sent('7002'));
        // Ad-based, eksaktong ad: walang lookup.
        $g->setMode('Page P', true);
        $this->chat('222', '7003', true);
        $this->assertSame([self::GROUP_TEXT], $this->sent('7003'));
        $this->assertSame(0, $this->metaCalls());
    }

    public function test_S_212_1_10_an_ad_id_that_is_not_digits_or_too_long_is_ignored(): void
    {
        $this->metaConnection();
        $this->meta(['id' => '1', 'adset_id' => self::ADSET, 'campaign_id' => self::CAMPAIGN]);
        $this->groupWith('By campaign', [self::CAMPAIGN]);
        $this->chat('12ab', '7001', true);
        $this->chat(str_repeat('9', 65), '7002', true);
        $this->assertSame([self::PAGE_TEXT], $this->sent('7001'));
        $this->assertSame([self::PAGE_TEXT], $this->sent('7002'));
        $this->assertNull(app(AdGroups::class)->groupForAd('Page P', str_repeat('9', 65)));
        $this->assertSame(0, $this->metaCalls());
    }

    private function sequence(int $steps = 2): ChatbotSequence
    {
        $seq = ChatbotSequence::create(['name' => 'Drip', 'active' => true, 'exit_on_reply' => false]);
        foreach (range(1, $steps) as $i) $seq->steps()->create(['position' => $i, 'delay_amount' => 0, 'delay_unit' => 'minutes', 'flow_id' => $this->flow('Seq {{PRICE}}')->id, 'active' => true]);

        return $seq;
    }

    public function test_S_212_1_11_a_sequence_keeps_the_group_matched_by_campaign(): void
    {
        $this->hierarchy();
        $this->groupWith('By campaign', [self::CAMPAIGN]);
        $seq = $this->sequence();
        $this->chat(self::AD, '7001');
        app(ChatbotSequences::class)->subscribe($seq->id, 1001, 7001, 'other');
        $this->travel(1)->minutes();
        $this->chat('40003', '7001');   // lumipat sa ad na wala sa group
        Artisan::call('chatbot:sequence-tick');
        $this->travel(1)->minutes();
        Artisan::call('chatbot:sequence-tick');
        $this->assertSame(['Seq 499', 'Seq 499'], $this->sent('7001'));
        $this->assertNotNull((new CustomerFields(1001, 7001))->get(AdGroups::SEQ_PIN . $seq->id));
    }

    public function test_S_212_2_1_and_2_2_an_unknown_ad_is_looked_up_once_stored_and_never_asked_again(): void
    {
        $this->metaConnection();
        $this->meta(fn ($ad) => ['id' => $ad, 'adset_id' => '50005', 'campaign_id' => self::CAMPAIGN]);
        $this->groupWith('By campaign', [self::CAMPAIGN]);
        $this->chat('40005', '7001', true);
        $this->assertSame([self::GROUP_TEXT], $this->sent('7001'));
        $this->assertSame(1, $this->metaCalls());
        $this->assertSame(['40005', '50005', self::CAMPAIGN], array_values((array) DB::table('ad_catalog')->where('ad_id', '40005')->first(['ad_id', 'ad_set_id', 'campaign_id'])));
        // Ang token ay nasa header lang, hindi sa URL.
        $req = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'graph.facebook.com'))->first()[0];
        $this->assertStringNotContainsString(self::META_TOKEN, $req->url());
        $this->assertSame('Bearer ' . self::META_TOKEN, $req->header('Authorization')[0]);

        $g = app(AdGroups::class);
        foreach (range(1, 100) as $i) {
            $this->chat('40005', (string) (8000 + $i));
            $this->assertNotNull($g->forCustomer('1001', (string) (8000 + $i), 'Page P'));
        }
        \Illuminate\Support\Facades\Cache::flush();
        $this->chat('40005', '7002', true);
        $this->assertSame([self::GROUP_TEXT], $this->sent('7002'));
        $this->assertSame(1, $this->metaCalls(), '100 pa at pagkatapos ng cache flush: walang bagong tawag');
        $this->assertSame(1, DB::table('ad_catalog')->count(), 'isang row kada ad');
    }

    public function test_S_212_2_3_an_ad_already_in_the_reports_is_never_looked_up(): void
    {
        $this->metaConnection();
        $this->meta(['id' => '40006', 'adset_id' => '1', 'campaign_id' => '2']);
        DB::table('ads_manager_reports')->insert(['page_name' => 'Page P', 'day' => '2026-10-19', 'ad_id' => '40006', 'ad_set_id' => self::ADSET, 'campaign_id' => self::CAMPAIGN]);
        $this->groupWith('By campaign', [self::CAMPAIGN]);
        $this->chat('40006', '7001', true);
        $this->assertSame([self::GROUP_TEXT], $this->sent('7001'));
        $this->assertSame(0, $this->metaCalls());
    }

    public function test_S_212_2_4_simultaneous_customers_of_one_new_ad_make_one_meta_call(): void
    {
        $this->metaConnection();
        $this->meta(fn ($ad) => ['id' => $ad, 'adset_id' => '50005', 'campaign_id' => self::CAMPAIGN]);
        $this->groupWith('By campaign', [self::CAMPAIGN]);
        $g = app(AdGroups::class);
        // Habang may nagtatanong na sa Meta (hawak ang lock): ang iba ay naghihintay hanggang 3 s, walang sariling tawag.
        \Illuminate\Support\Sleep::fake();
        $lock = \Illuminate\Support\Facades\Cache::lock('chatbot-adgroups:lookup:40005', 10);
        $this->assertTrue($lock->get());
        foreach (range(1, 19) as $i) {
            $this->chat('40005', (string) (8000 + $i));
            $this->assertNull($g->forCustomer('1001', (string) (8000 + $i), 'Page P'));
        }
        $this->assertTrue($g->unresolved);
        \Illuminate\Support\Sleep::assertSleptTimes(19 * AdGroups::LOOKUP_TIMEOUT * 10);
        $this->assertSame(0, $this->metaCalls());
        $lock->release();
        foreach (range(1, 20) as $i) $this->assertNotNull($g->forCustomer('1001', (string) (8000 + min($i, 19)), 'Page P'));
        $this->assertSame(1, $this->metaCalls());
    }

    public function test_S_212_2_5_an_ad_unknown_to_meta_gets_page_values_and_is_remembered_for_an_hour(): void
    {
        $this->metaConnection();
        $this->meta([400, ['error' => ['message' => 'Unsupported get request. Object with ID does not exist', 'code' => 100]]]);
        $this->groupWith('By campaign', [self::CAMPAIGN]);
        $this->chat('40007', '7001', true);
        $this->assertSame([self::PAGE_TEXT], $this->sent('7001'));
        $this->chat('40007', '7002', true);
        $this->assertSame(1, $this->metaCalls(), 'tanda ang "wala"');
        $this->travel(61)->minutes();
        $this->chat('40007', '7003', true);
        $this->assertSame(2, $this->metaCalls(), 'lampas isang oras: itinatanong ulit');
        $this->assertSame([self::PAGE_TEXT], $this->sent('7003'));
    }

    public static function metaDown(): array
    {
        return [
            'timeout' => [new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out after 3000 ms')],
            '5xx' => [[503, 'Service Unavailable']],
            'rate limit' => [[400, ['error' => ['message' => 'User request limit reached', 'code' => 17]]]],
            'expired token' => [[400, ['error' => ['message' => 'Error validating access token: Session has expired access_token=' . self::META_TOKEN, 'code' => 190]]]],
        ];
    }

    #[DataProvider('metaDown')]
    public function test_S_212_2_6_and_2_10_meta_down_gives_page_values_a_run_log_note_no_retry_for_5_minutes_and_no_token_anywhere(mixed $answer): void
    {
        $logged = [];
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Log\Events\MessageLogged::class, function ($e) use (&$logged) { $logged[] = $e->message . json_encode($e->context); });
        $this->metaConnection();
        $this->meta($answer);
        $this->groupWith('By campaign', [self::CAMPAIGN]);
        $this->chat('40008', '7001', true);
        $this->assertSame([self::PAGE_TEXT], $this->sent('7001'), 'page values, walang exception');
        $note = DB::table('chatbot_events')->where('psid', 7001)->where('type', 'ad_lookup')->value('detail');
        $this->assertStringContainsString('failed', (string) $note);
        $this->travel(4)->minutes();
        $this->chat('40008', '7002', true);
        $this->assertSame(1, $this->metaCalls(), 'hindi inuulit sa loob ng 5 minuto');
        $this->assertSame([self::PAGE_TEXT], $this->sent('7002'));
        $all = DB::table('chatbot_events')->pluck('detail')->implode(' ') . implode(' ', $logged) . json_encode(DB::table('ad_catalog')->get());
        $this->assertStringNotContainsString(self::META_TOKEN, $all);
    }

    public function test_S_212_2_6_and_8_3_the_meta_call_is_one_try_with_a_3_second_timeout_and_known_ads_never_wait_on_it(): void
    {
        $calls = [];
        $this->app->instance(\App\Services\MetaAds\Graph::class, new class($calls) extends \App\Services\MetaAds\Graph {
            public function __construct(private array &$calls) {}
            public function get(string $pathOrUrl, array $query = [], ?string $token = null, bool $proof = true, int $tries = self::TRIES, int $timeout = 120): array
            {
                $this->calls[] = [$pathOrUrl, $tries, $timeout];
                throw new \App\Services\MetaAds\GraphException('Graph request failed: timed out');
            }
        });
        $this->metaConnection();
        $this->hierarchy();
        $this->groupWith('By campaign', [self::CAMPAIGN]);
        $this->chat('40009', '7001', true);
        $this->assertSame([['40009', 1, AdGroups::LOOKUP_TIMEOUT]], $calls, 'isang subok, 3 segundo');
        $this->assertSame(3, AdGroups::LOOKUP_TIMEOUT);
        // Ang ibang customer (kilalang ad) ay hindi dumadaan sa Meta o sa lock nito.
        $this->chat(self::AD, '7002', true);
        $this->assertSame([self::GROUP_TEXT], $this->sent('7002'));
        $this->assertCount(1, $calls);
    }

    public function test_S_212_2_6_no_meta_connection_gives_page_values_and_a_note(): void
    {
        $this->meta(['id' => '1']);
        $this->groupWith('By campaign', [self::CAMPAIGN]);
        $this->chat('40010', '7001', true);
        $this->assertSame([self::PAGE_TEXT], $this->sent('7001'));
        $this->assertStringContainsString('no Meta Ads connection', (string) DB::table('chatbot_events')->where('type', 'ad_lookup')->value('detail'));
        $this->assertSame(0, $this->metaCalls());
    }

    public function test_S_212_2_7_meta_back_after_the_wait_matches_and_a_sequence_entered_during_the_failure_re_resolves(): void
    {
        $this->metaConnection();
        $down = true;
        $this->meta(function ($ad) use (&$down) { return $down ? [503, 'down'] : ['id' => $ad, 'adset_id' => '50005', 'campaign_id' => self::CAMPAIGN]; });
        $this->groupWith('By campaign', [self::CAMPAIGN]);
        $seq = $this->sequence();
        $this->chat('40011', '7001');
        app(ChatbotSequences::class)->subscribe($seq->id, 1001, 7001, 'other');
        $this->assertNull((new CustomerFields(1001, 7001))->get(AdGroups::SEQ_PIN . $seq->id), 'ang pumalya ay hindi itinatala bilang page values');
        Artisan::call('chatbot:sequence-tick');
        $this->assertSame(['Seq 199'], $this->sent('7001'), 'step 1 habang down: page values');
        $this->assertNull((new CustomerFields(1001, 7001))->get(AdGroups::SEQ_PIN . $seq->id));

        $down = false;
        $this->travel(6)->minutes();
        Artisan::call('chatbot:sequence-tick');
        $this->assertSame(['Seq 199', 'Seq 499'], $this->sent('7001'), 'bumalik ang Meta: ang group');
        $this->assertSame('1', (new CustomerFields(1001, 7001))->get(AdGroups::SEQ_PIN . $seq->id));
        $this->chat('40011', '7002', true);
        $this->assertSame([self::GROUP_TEXT], $this->sent('7002'));
    }

    public function test_S_212_2_8_many_new_ads_in_a_minute_stay_within_the_cap_and_known_ads_are_not_delayed(): void
    {
        \Illuminate\Support\Sleep::fake();
        $this->metaConnection();
        $this->hierarchy();
        $this->meta(fn ($ad) => ['id' => $ad, 'adset_id' => '59999', 'campaign_id' => '69999']);
        $this->groupWith('By campaign', [self::CAMPAIGN]);
        $g = app(AdGroups::class);
        foreach (range(1, 500) as $i) {
            $this->chat((string) (100000 + $i), (string) (9000 + $i));
            $this->assertNull($g->forCustomer('1001', (string) (9000 + $i), 'Page P'));
        }
        $this->assertSame(AdGroups::LOOKUPS_PER_MINUTE, $this->metaCalls());
        $this->chat(self::AD, '7001', true);
        $this->assertSame([self::GROUP_TEXT], $this->sent('7001'), 'ang kilalang ad ay tuloy');
        \Illuminate\Support\Sleep::assertNeverSlept();
    }

    public function test_S_212_3_3_old_chats_are_not_answered_again_only_new_ones_match_the_new_way(): void
    {
        $this->hierarchy();
        $old = $this->chat(self::AD, '7001', true);
        $this->assertSame([self::PAGE_TEXT], $this->sent('7001'));
        $this->groupWith('By campaign', [self::CAMPAIGN]);
        app(ChatbotRunner::class)->handle($old->id);
        $this->assertSame([self::PAGE_TEXT], $this->sent('7001'), 'ang lumang chat ay hindi sinasagot ulit');
        $this->travel(1)->minutes();
        $this->chat(self::AD, '7001', true);
        $this->assertSame([self::PAGE_TEXT, self::GROUP_TEXT], $this->sent('7001'));
    }

    public function test_S_212_3_4_an_ad_matching_nothing_with_empty_page_values_still_says_nothing_to_send(): void
    {
        $this->hierarchy();
        app(ChatbotPage::class)->link('Page P', $this->flow('{{Main Flow 2}}'));
        \App\Models\PageSetting::where('provider', 'chatbot')->where('key', 'main_flow_2')->update(['active' => false]);
        PageSettings::forget('Page P');
        $this->groupWith('By ad set', [self::ADSET]);
        $this->actingAs($this->user());
        $this->chat('40003', '7001', true);
        $this->assertSame([], $this->sent('7001'));
        $path = $this->postJson('/chatbot/test/send', ['page_name' => 'Page P', 'version' => 'published', 'message' => 'Hi'])->assertOk()->json('path');
        $this->assertSame('Nothing to send', collect($path)->firstWhere('type', 'send')['note']);
    }

    public function test_S_212_3_5_the_test_tab_answers_with_a_group_matched_by_campaign_like_the_run(): void
    {
        $this->hierarchy();
        $id = $this->groupWith('By campaign', [self::CAMPAIGN]);
        $this->actingAs($this->user());
        $this->chat(self::AD, '7001', true);
        $this->assertSame(app(AdGroups::class)->forCustomer('1001', '7001', 'Page P'), $id);
        $text = $this->postJson('/chatbot/test/send', ['page_name' => 'Page P', 'version' => 'published', 'message' => 'Hi', 'ad_group' => $id])->assertOk()->json('sends.0.messages.0.text');
        $this->assertSame([$text], $this->sent('7001'));
    }

    public function test_S_212_8_1_matching_a_known_ad_adds_at_most_one_cache_read_and_one_query_whatever_the_number_of_groups(): void
    {
        $this->hierarchy();
        $g = app(AdGroups::class);
        $count = function (string $ad) use ($g) {
            \Illuminate\Support\Facades\Cache::forget(AdGroups::PARENTS . $ad);
            $g->groups('Page P'); // ang settings ng page ay naka-memo na (gaya ng takbo)
            $n = 0;
            DB::listen(function () use (&$n) { $n++; });
            $reads = 0;
            \Illuminate\Support\Facades\Event::listen([\Illuminate\Cache\Events\CacheHit::class, \Illuminate\Cache\Events\CacheMissed::class], function () use (&$reads) { $reads++; });
            $group = $g->groupForAd('Page P', $ad);
            DB::flushQueryLog();

            return [$group, $n, $reads];
        };
        $this->groupWith('By campaign', [self::CAMPAIGN]);
        [$group, $one] = $count(self::AD);
        $this->assertNotNull($group);
        foreach (range(1, 150) as $i) app(AdGroups::class)->save('Page P', null, 'G' . $i, [(string) (300000 + $i)], []);
        [$group, $many, $reads] = $count(self::AD);
        $this->assertNotNull($group);
        $this->assertSame(1, $one, 'isang indexed query (ad_catalog.ad_id)');
        $this->assertSame($one, $many, 'hindi lumalaki sa dami ng group');
        $this->assertSame(1, $reads, 'isang basa ng cache');
    }

    public function test_S_212_8_6_the_lookup_store_is_one_ad_catalog_row_per_ad_with_ids_only(): void
    {
        $this->metaConnection();
        $this->meta(fn ($ad) => ['id' => $ad, 'adset_id' => '50005', 'campaign_id' => self::CAMPAIGN, 'name' => 'Ana Cruz 0917', 'access_token' => self::META_TOKEN]);
        $this->groupWith('By campaign', [self::CAMPAIGN]);
        foreach (['40021', '40022', '40021'] as $i => $ad) $this->chat($ad, (string) (7100 + $i), true);
        $rows = DB::table('ad_catalog')->orderBy('ad_id')->get();
        $this->assertSame(['40021', '40022'], $rows->pluck('ad_id')->all(), 'isang row kada ad');
        foreach ($rows as $row) {
            $filled = array_keys(array_filter((array) $row, fn ($v) => $v !== null));
            $this->assertSame(['id', 'ad_id', 'ad_set_id', 'campaign_id', 'created_at', 'updated_at'], $filled, 'ID lang: walang pangalan ng customer o token');
        }
        $this->assertStringNotContainsString(self::META_TOKEN, json_encode($rows));
        $this->assertStringNotContainsString('Ana Cruz', json_encode($rows));
    }

    public function test_S_212_2_6_and_2_7_an_expired_meta_connection_is_a_failure_and_the_sequence_is_not_pinned(): void
    {
        $this->metaConnection();
        app(\App\Services\MetaAds\Connections::class)->update(1, ['status' => 'expired']);
        $this->meta(['id' => '1']);
        $this->groupWith('By campaign', [self::CAMPAIGN]);
        $seq = $this->sequence();
        $this->chat('40013', '7001');
        app(ChatbotSequences::class)->subscribe($seq->id, 1001, 7001, 'other');
        $this->assertNull((new CustomerFields(1001, 7001))->get(AdGroups::SEQ_PIN . $seq->id), 'expired: hindi itinatala bilang page values');
        $g = app(AdGroups::class);
        $this->assertNull($g->forCustomer('1001', '7001', 'Page P'));
        $this->assertTrue($g->unresolved);
        $this->assertSame(0, $this->metaCalls());
    }

    public function test_S_212_2_7_a_sequence_subscribed_after_a_wait_resume_during_a_failed_lookup_is_not_pinned(): void
    {
        $this->metaConnection();
        $this->meta([503, 'down']);
        $this->groupWith('By campaign', [self::CAMPAIGN]);
        $seq = $this->sequence(1);
        $flow = ChatbotFlow::create(['name' => 'Wait flow', 'graph' => FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start'],
            ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Hi {{PRICE}}']], 'wait' => true, 'skip_after' => ['amount' => 1, 'unit' => 'hours']]],
            ['id' => 'n3', 'type' => 'sequence', 'config' => ['action' => 'subscribe', 'sequence_id' => $seq->id]],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2'], ['from' => 'n2', 'port' => 'skipped', 'to' => 'n3']]])]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);
        app(ChatbotPage::class)->link('Page P', $flow->fresh());
        $this->chat('40014', '7001', true);
        $this->assertSame(['Hi 199'], $this->sent('7001'));
        $wait = DB::table('chatbot_waits')->where('psid', 7001)->value('id');
        $this->assertNotNull($wait);
        $this->travel(61)->minutes();
        app(ChatbotRunner::class)->skip((int) $wait);
        $this->assertNotNull(DB::table('chatbot_sequence_subscriptions')->where('sequence_id', $seq->id)->where('psid', 7001)->first(), 'na-subscribe');
        $this->assertNull((new CustomerFields(1001, 7001))->get(AdGroups::SEQ_PIN . $seq->id), 'ang pumalyang lookup ay hindi itinatala');
        $this->assertSame(2, DB::table('chatbot_events')->where('psid', 7001)->where('type', 'ad_lookup')->count(), 'pati ang takbo ng skip ay may tala');
    }
}
