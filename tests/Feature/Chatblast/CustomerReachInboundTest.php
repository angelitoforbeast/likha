<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakePsid;
use App\Models\ChatbotFlow;
use App\Models\ChatbotSequence;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotSequences;
use App\Services\Chatbot\CustomerReach;
use App\Services\Chatbot\FlowGraph;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ang bagong mensahe ng customer ay nag-aalis ng marka ng hindi maabot, sa Botcake (/api/botcake/chat) at sa gateway
 * (/api/gateway/events: message, postback, quick reply), pati sa page na naka-Off ang bot. Ang pagbukas ng ad ay hindi.
 */
class CustomerReachInboundTest extends ChatblastTestCase
{
    private const SEND = 'https://botcake.io/api/public_api/v1/pages/1001/flows/send_content';
    private const GW = 'https://gw.example.test';
    private const KEY = 'chat-key-for-tests-only-0123456789';
    private const SECRET = 'gw-webhook-secret-test-234reach';
    private const IP = '203.171.22.6';

    private int $eventId = 23400;

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
        config(['services.chatbot.replies_enabled' => true, 'services.botcake.chat_ips' => [self::IP], 'services.botcake.chat_ip_mode' => 'log',
            'services.gateway.url' => self::GW, 'services.gateway.key' => 'gw_test_key_234', 'services.gateway.webhook_secret' => self::SECRET]);
        Http::fake([self::SEND => Http::response(['success' => true]), self::GW . '/*' => Http::response(['id' => 'x', 'status' => 'queued'], 202)]);
        $this->travelTo('2026-11-05 14:03:00');
        $s = app(PageSettings::class);
        $this->page('Page Uno', '1001');
        $s->set('Page Uno', 'botcake', 'chat.key', self::KEY, true, null, true);
        BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Ana Cruz', 'psid' => '7001', 'status' => 'available', 'source' => 'api']);
        $this->page('Page Dos', '1002');
        $s->set('Page Dos', 'chatbot', 'provider', 'gateway');
        app(ChatbotPage::class)->link('Page Uno', $this->flow('Hello back'));
    }

    private function flow(string $text): ChatbotFlow
    {
        $flow = ChatbotFlow::create(['name' => 'Flow ' . $text, 'graph' => FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => $text]]]],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]])]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);

        return $flow->fresh();
    }

    private function mark(string $pageId, string $psid): void
    {
        DB::table('chatbot_customer_values')->insert(['page_id' => (int) $pageId, 'psid' => (int) $psid, 'key' => CustomerReach::KEY,
            'value' => json_encode(['code' => 551, 'at' => '2026-11-05 09:00:00']), 'updated_at' => now()]);
    }

    private function marked(string $pageId, string $psid): bool
    {
        return CustomerReach::skipReason($pageId, $psid) !== null;
    }

    private function botcakeChat(string $message = 'How much?'): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => self::IP])->postJson('/api/botcake/chat',
            ['page_id' => '1001', 'psid' => '7001', 'name' => 'Ana Cruz', 'message' => $message], ['X-Chat-Key' => self::KEY])->assertOk();
    }

    /** @return array<int, string> ang mga text na naipadala sa customer 7001 ng Page Uno */
    private function sent(): array
    {
        return Http::recorded(fn (HttpRequest $r) => $r->url() === self::SEND && ($r->data()['psid'] ?? null) === '7001')
            ->map(fn ($p) => $p[0]->data()['data']['content']['messages'][0]['text'] ?? '')->values()->all();
    }

    private function gatewayEvent(array $e): void
    {
        $id = ++$this->eventId;
        $body = json_encode($e + ['kind' => 'event', 'event_id' => $id, 'page_id' => '1002', 'psid' => '8001', 'type' => 'message', 'mid' => 'm_' . $id,
            'timestamp' => 1762300000000 + $id, 'text' => 'Hi', 'attachments' => [], 'referral' => null, 'postback' => null, 'is_echo' => false, 'app_id' => null]);
        $this->call('POST', '/api/gateway/events', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_GATEWAY_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $body, self::SECRET)], $body)->assertOk();
    }

    public function test_S_234_3_1_a_marked_customer_who_writes_to_the_page_is_unmarked_and_the_bot_answers(): void
    {
        $this->mark('1001', '7001');

        $this->botcakeChat();

        $this->assertFalse($this->marked('1001', '7001'));
        $this->assertSame(['Hello back'], $this->sent());
    }

    public function test_S_234_3_1_the_mark_is_cleared_even_when_the_page_bot_is_off(): void
    {
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'active', '0', false, null, true);
        $this->mark('1001', '7001');

        $this->botcakeChat();

        $this->assertFalse($this->marked('1001', '7001'));
        $this->assertSame([], $this->sent(), 'Off pa rin ang bot');
    }

    public function test_S_234_3_2_after_the_customer_wrote_a_later_sequence_step_is_sent_normally(): void
    {
        $this->mark('1001', '7001');
        $seq = ChatbotSequence::create(['name' => 'Later', 'active' => true, 'exit_on_reply' => false]);
        $seq->steps()->create(['position' => 1, 'delay_amount' => 1, 'delay_unit' => 'hours', 'flow_id' => $this->flow('Seq 1')->id, 'active' => true]);
        $this->botcakeChat();
        app(ChatbotSequences::class)->subscribe($seq->id, 1001, 7001, 'other');

        $this->travelTo('2026-11-05 15:04:00');
        Artisan::call('chatbot:sequence-tick');

        $this->assertSame(['Hello back', 'Seq 1'], $this->sent());
    }

    public static function gatewayTaps(): array
    {
        return [
            'message' => [['text' => 'Hi'], false],
            'postback' => [['type' => 'postback', 'text' => null, 'postback' => ['title' => 'Order', 'payload' => 'ORDER']], false],
            'quick reply' => [['text' => 'Yes', 'quick_reply_payload' => 'YES'], false],
            'the page own send (echo) is not the customer' => [['is_echo' => true, 'app_id' => '123456', 'text' => 'Thanks po'], true],
            'ad open (referral) is not a message' => [['type' => 'referral', 'mid' => null, 'text' => null, 'referral' => ['ad_id' => '6045246247433', 'source' => 'ADS']], true],
        ];
    }

    #[DataProvider('gatewayTaps')]
    public function test_S_234_3_3_a_gateway_postback_or_quick_reply_also_clears_the_mark(array $event, bool $stillMarked): void
    {
        $this->mark('1002', '8001');

        $this->gatewayEvent($event);

        $this->assertSame($stillMarked, $this->marked('1002', '8001'));
    }

    public function test_S_234_3_3_a_gateway_message_to_a_page_on_the_botcake_route_clears_the_mark(): void
    {
        $this->mark('1001', '7001');

        $this->gatewayEvent(['page_id' => '1001', 'psid' => '7001', 'text' => 'Hi']);

        $this->assertFalse($this->marked('1001', '7001'));
    }

    public function test_S_234_3_3_a_gateway_message_clears_the_mark_on_a_page_whose_bot_is_off(): void
    {
        app(PageSettings::class)->set('Page Dos', 'chatbot', 'active', '0', false, null, true);
        $this->mark('1002', '8001');

        $this->gatewayEvent(['text' => 'Hi']);

        $this->assertFalse($this->marked('1002', '8001'));
    }
}
