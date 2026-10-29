<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\HandleGatewayEvent;
use App\Models\BotcakePsid;
use App\Models\ChatbotFlow;
use App\Models\ChatbotSequence;
use App\Services\BotcakeChatGuard;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotSequences;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowGraph;
use App\Services\Chatbot\GatewayTyping;
use App\Services\PageSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Spec 191: ang "typing…" habang nag-iisip ang bot. Ang mensahe ng customer sa page na nasa Forbeast (bot On) na
 * sasagutin ay may isang tawag sa POST /v1/pages/{page_id}/typing bago ang unang hakbang ng takbo; isa lang kada
 * customer kada 15 s; walang tawag sa Botcake, bot Off, ignored na attachment, Test at sequence; ang palya ay hindi
 * humaharang sa sagot. Fake na HTTP; walang tawag sa totoong Forbeast.
 */
class GatewayTypingTest extends ChatblastTestCase
{
    private const GW = 'https://gw.example.test/api';
    private const KEY = 'gw_test_key_191';
    private const CHAT_KEY = 'page-key-uno-test-191-a1s2d3f4g5h6';
    private const PSID = '6012345678901234';

    /** Ang sagot ng typing endpoint: ok, 500 o timeout. */
    private string $typing = 'ok';

    /** @var array<int, array> ang options (timeout) ng bawat typing request */
    private array $typingOptions = [];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_22_100000_widen_source_on_botcake_psids.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_24_100000_add_inbox_indexes.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_25_100000_create_chatbot_waits_table.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
            'database/migrations/2026_10_28_100000_create_chatbot_ai_requests_table.php',
            'database/migrations/2026_10_29_100000_create_botcake_send_requests_table.php',
        ]]);
        config(['services.chatbot.replies_enabled' => true, 'services.gateway.url' => self::GW, 'services.gateway.key' => self::KEY,
            'services.gateway.webhook_secret' => 'gw-secret', 'services.botcake.chat_ips' => ['203.171.22.6'], 'services.botcake.chat_ip_mode' => 'log']);
        $this->travelTo('2026-10-29 10:00:00');
        $this->page('Page Uno', '1001');
        $this->page('Page Dos', '1002');
        $s = app(PageSettings::class);
        $s->set('Page Uno', 'chatbot', 'provider', 'gateway');
        $s->set('Page Dos', 'botcake', 'chat.key', self::CHAT_KEY, true, null, true);
        BotcakePsid::create(['page' => 'Page Dos', 'fb_name' => 'Ana Cruz', 'psid' => self::PSID, 'status' => 'available', 'source' => 'api']);
        $flow = $this->flow('Hi po');
        app(ChatbotPage::class)->link('Page Uno', $flow);
        app(ChatbotPage::class)->link('Page Dos', $flow);

        Http::preventStrayRequests();
        Http::fake([
            self::GW . '/v1/pages/*/typing' => function (HttpRequest $r, array $options) {
                $this->typingOptions[] = $options;
                if ($this->typing === 'timeout') throw new ConnectionException('cURL error 28: Operation timed out');

                return $this->typing === '500' ? Http::response(['error' => 'boom'], 500) : Http::response(['ok' => true], 202);
            },
            self::GW . '/v1/pages/*/messages' => Http::response(['public_id' => 'snd_1', 'status' => 'queued'], 202),
            self::GW . '/*' => Http::response(['error' => 'not found'], 404),
            'https://botcake.io/*' => Http::response(['success' => true]),
        ]);
    }

    /** Isang published na flow na nagpapadala ng $text. */
    private function flow(string $text): ChatbotFlow
    {
        $flow = ChatbotFlow::create(['name' => 'Flow ' . $text, 'graph' => FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => $text]]]],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]])]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);

        return $flow->fresh();
    }

    /** Ang mensahe ng customer mula sa Forbeast, gaya ng controller (sync na queue: tumatakbo agad ang flow). */
    private function forbeast(?string $text = 'Magkano po?', array $attachments = [], string $page = '1001', string $psid = self::PSID): void
    {
        (new HandleGatewayEvent(HandleGatewayEvent::fields(['kind' => 'event', 'event_id' => random_int(1, 999999), 'page_id' => $page, 'psid' => $psid,
            'type' => 'message', 'mid' => 'm_' . uniqid('', true), 'text' => $text, 'attachments' => $attachments])))->handle(app(PageSettings::class), app(BotcakeChatGuard::class));
    }

    /** @return array<int, HttpRequest> ang mga typing request, ayon sa ayos */
    private function typingCalls(): array
    {
        return Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '/typing'))->map(fn ($p) => $p[0])->values()->all();
    }

    /** @return array<int, string> ang mga URL ng gateway (typing at messages), ayon sa ayos */
    private function gatewayOrder(): array
    {
        return Http::recorded(fn (HttpRequest $r) => str_starts_with($r->url(), self::GW . '/v1/pages/'))
            ->map(fn ($p) => substr($p[0]->url(), strlen(self::GW)))->values()->all();
    }

    private function sentReplies(): int
    {
        return DB::table('chatbot_replies')->where('status', 'sent')->count();
    }

    private function counted(string $what): int
    {
        return (int) Cache::get('gateway-typing:' . now('Asia/Manila')->toDateString() . ':' . $what, 0);
    }

    public function test_S_191_1_a_customer_message_on_a_gateway_page_calls_typing_once_before_the_first_step(): void
    {
        $this->forbeast();

        $calls = $this->typingCalls();
        $this->assertCount(1, $calls);
        $this->assertSame('POST', $calls[0]->method());
        $this->assertSame(self::GW . '/v1/pages/1001/typing', $calls[0]->url());
        $this->assertSame(['psid' => self::PSID], $calls[0]->data());
        $this->assertTrue($calls[0]->hasHeader('Authorization', 'Bearer ' . self::KEY), 'same auth as the send API');
        // Bago ang padala ng unang hakbang (send) ng takbo.
        $this->assertSame(['/v1/pages/1001/typing', '/v1/pages/1001/messages'], $this->gatewayOrder());
        $this->assertSame(1, $this->sentReplies());
        // Maikling timeout: ≤ 1.5 s ang connect at ang kabuuan.
        $this->assertLessThanOrEqual(1.5, $this->typingOptions[0]['timeout']);
        $this->assertLessThanOrEqual(1.5, $this->typingOptions[0]['connect_timeout']);
        $this->assertSame([1, 0], [$this->counted('calls'), $this->counted('failed')]);
    }

    public function test_S_191_2_two_messages_from_the_same_customer_within_15_seconds_make_one_call(): void
    {
        $this->forbeast('Hello');
        $this->travel(10)->seconds();
        $this->forbeast('Magkano po?');
        $this->forbeast('Ibang customer', [], '1001', '6012345678909999'); // ibang customer: sariling tawag

        $this->assertSame([self::PSID, '6012345678909999'], array_map(fn ($r) => $r->data()['psid'], $this->typingCalls()));
        $this->assertSame(3, $this->sentReplies(), 'every message still answered');

        // Lampas 15 s mula sa tawag: may bagong tawag.
        $this->travel(6)->seconds();
        $this->forbeast('Pa-order po');
        $this->assertCount(3, $this->typingCalls());
    }

    public function test_S_191_3_no_call_for_botcake_bot_off_ignored_attachment_paused_test_run_or_sequence(): void
    {
        // Botcake page: ang chat ng Botcake ay tumatakbo at sumasagot sa Botcake, walang typing.
        $this->withServerVariables(['REMOTE_ADDR' => '203.171.22.6'])->postJson('/api/botcake/chat',
            ['page_id' => '1002', 'psid' => self::PSID, 'name' => 'Ana Cruz', 'message' => 'Hi'], ['X-Chat-Key' => self::CHAT_KEY])->assertOk();
        $this->assertSame(1, $this->sentReplies(), 'the Botcake page answered');
        // Ang Forbeast event ng page na nasa Botcake: walang takbo.
        $this->forbeast('Hi', [], '1002');

        // Ignored na attachment (audio lang).
        $this->forbeast(null, [['type' => 'audio', 'url' => 'https://cdn.fbsbx.com/voice.mp4']]);

        // Ang customer na naka-pause (tao ang sumasagot).
        (new CustomerFields(1001, (int) self::PSID))->set(CustomerFields::BOT_PAUSED, '1');
        $this->forbeast('Tao po?');
        (new CustomerFields(1001, (int) self::PSID))->clear(CustomerFields::BOT_PAUSED);

        // Bot Off.
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'active', '0', false, null, true);
        $this->forbeast('Hello?');
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'active', '1', false, null, true);

        // Test run (/chatbot/test) sa page na nasa Forbeast.
        $this->actingAs($this->user());
        $this->postJson('/chatbot/test/send', ['page_name' => 'Page Uno', 'message' => 'Hi', 'version' => 'published'])->assertOk();

        // Sequence step sa page na nasa Forbeast: may padala, walang typing.
        $seq = ChatbotSequence::create(['name' => 'Follow up', 'active' => true, 'exit_on_reply' => false]);
        $seq->steps()->create(['position' => 1, 'delay_amount' => 1, 'delay_unit' => 'hours', 'flow_id' => $this->flow('Seq 1')->id, 'active' => true]);
        app(ChatbotSequences::class)->subscribe($seq->id, 1001, (int) self::PSID, 'other');
        $this->travel(61)->minutes();
        Artisan::call('chatbot:sequence-tick');
        $this->assertSame(['/v1/pages/1001/messages'], $this->gatewayOrder(), 'the sequence step was sent through Forbeast');

        $this->assertSame([], $this->typingCalls());
        $this->assertSame(0, $this->counted('calls'));
    }

    public function test_S_191_4_a_typing_timeout_or_500_does_not_stop_the_reply(): void
    {
        $this->typing = 'timeout';
        $this->forbeast('Hello');
        // Ang timeout ay walang response (hindi naitatala ng fake): ang tawag ay nasa typingOptions, ang padala ay tuloy.
        $this->assertCount(1, $this->typingOptions);
        $this->assertSame(['/v1/pages/1001/messages'], $this->gatewayOrder());
        $this->assertSame(1, $this->sentReplies());

        $this->typing = '500';
        $this->forbeast('Hello', [], '1001', '6012345678909999');
        $this->assertCount(2, $this->typingOptions);
        $this->assertSame(['/v1/pages/1001/messages', '/v1/pages/1001/typing', '/v1/pages/1001/messages'], $this->gatewayOrder());
        $this->assertSame(2, $this->sentReplies(), 'the reply is sent as before');
        $this->assertSame(0, DB::table('chatbot_events')->where('type', 'run')->where('detail', 'like', '%failed%')->count());
        $this->assertSame([2, 2], [$this->counted('calls'), $this->counted('failed')]);
    }

    public function test_S_191_5_the_switch_off_means_no_call(): void
    {
        config(['services.gateway.typing' => false]);
        $this->forbeast();

        $this->assertSame([], $this->typingCalls());
        $this->assertSame(['/v1/pages/1001/messages'], $this->gatewayOrder());
        $this->assertSame(1, $this->sentReplies());
    }

    public function test_S_191_5_the_switch_is_on_by_default(): void
    {
        $this->assertTrue(config('services.gateway.typing'));
        $this->assertTrue(class_exists(GatewayTyping::class));
    }
}
