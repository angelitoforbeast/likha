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

        // Walang flow ang page pero may flow ang group: ang bagong chat ay pinapatakbo pa rin (ChatbotInbound).
        app(ChatbotPage::class)->link('Page P', null);
        $this->assertTrue(app(ChatbotPage::class)->runs('Page P'));
        $this->chat('111', '7004', true);
        $this->assertSame(['Group flow 499'], $this->sent('7004'));

        // Ang page na naka-Off ay walang takbo, kahit may sariling flow ang group.
        app(PageSettings::class)->set('Page P', 'chatbot', 'active', '0', false, null, true);
        $this->chat('111', '7003', true);
        $this->assertSame([], $this->sent('7003'));
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
}
