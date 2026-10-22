<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\MarkChatbotReplied;
use App\Jobs\RunChatbotFlow;
use App\Models\BotcakeChat;
use App\Models\BotcakePsid;
use App\Models\ChatbotFlow;
use App\Models\ChatbotReply;
use App\Models\PageSetting;
use App\Services\BotcakeChatGuard;
use App\Services\Chatbot\BotcakeSendPool;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotRunner;
use App\Services\Chatbot\FlowGraph;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * Ang padala at pagtanggap ng chatbot sa Botcake o sa Forbeast Gateway, pinipili kada page (chatbot.provider). Fake na
 * HTTP; walang tawag sa totoong Botcake o gateway.
 */
class ChatbotProviderTest extends ChatblastTestCase
{
    private const GW = 'https://gw.example.test';
    private const GW_SEND = self::GW . '/v1/pages/1001/messages';
    private const GW_KEY = 'gw_test_key_1q2w3e4r';
    private const SECRET = 'gw-webhook-secret-test-9o8i7u';
    private const BOTCAKE_SEND = 'https://botcake.io/api/public_api/v1/pages/1001/flows/send_content';
    private const PSID = '6012345678901234';
    private const KEY_UNO = 'page-key-uno-test-a1s2d3f4g5h6j7k8l9';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_22_100000_widen_source_on_botcake_psids.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_inbox_indexes.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_29_100000_create_botcake_send_requests_table.php',
        ]]);
        config([
            'services.chatbot.replies_enabled' => true,
            'services.gateway.url' => self::GW, 'services.gateway.key' => self::GW_KEY,
            'services.gateway.webhook_secret' => self::SECRET, 'services.gateway.timeout' => 10,
            'services.botcake.chat_ips' => ['203.171.22.6'], 'services.botcake.chat_ip_mode' => 'log',
        ]);
        $this->page('Page Uno', '1001');
        app(PageSettings::class)->set('Page Uno', 'botcake', 'chat.key', self::KEY_UNO, true, null, true);
    }

    private function useGateway(string $page = 'Page Uno'): void
    {
        app(PageSettings::class)->set($page, 'chatbot', 'provider', 'gateway');
    }

    private function greeting(): ChatbotFlow
    {
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start'],
            ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'You said: {{last_input}}']]]],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]])]);
        app(ChatbotPage::class)->link('Page Uno', $flow);

        return $flow;
    }

    private function chat(string $message): BotcakeChat
    {
        return BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => self::PSID, 'name' => 'Ana Cruz',
            'message' => $message, 'payload' => [], 'received_at' => now()]);
    }

    /** Ang event ng gateway, pinirmahan ng $secret (null = walang pirma). */
    private function event(array $e = [], ?string $secret = self::SECRET, ?string $signature = null)
    {
        $body = json_encode($e + ['kind' => 'event', 'event_id' => 18342, 'page_id' => '1001', 'psid' => self::PSID, 'type' => 'message',
            'mid' => 'm_AbC1', 'timestamp' => 1761037200123, 'text' => 'Available pa po?', 'attachments' => [], 'referral' => null,
            'postback' => null, 'is_echo' => false, 'app_id' => null, 'attempt' => 1]);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($signature !== null) $headers['HTTP_X_GATEWAY_SIGNATURE'] = $signature;
        elseif ($secret !== null) $headers['HTTP_X_GATEWAY_SIGNATURE'] = 'sha256=' . hash_hmac('sha256', $body, $secret);

        return $this->call('POST', '/api/gateway/events', [], [], [], $headers, $body);
    }

    private function botcakePush(string $message = 'Hello po', array $extra = [])
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '203.171.22.6'])->postJson('/api/botcake/chat',
            $extra + ['page_id' => '1001', 'psid' => self::PSID, 'name' => 'Ana Cruz', 'message' => $message], ['X-Chat-Key' => self::KEY_UNO]);
    }

    private function counted(string $what): int
    {
        return (int) Cache::get('gateway-events:' . now('Asia/Manila')->toDateString() . ':' . $what, 0);
    }

    public function test_S_111_1_a_gateway_pages_flow_sends_through_the_gateway_and_a_botcake_page_as_before(): void
    {
        Http::fake([self::GW_SEND => Http::response(['id' => '01k84x', 'status' => 'queued'], 202), self::BOTCAKE_SEND => Http::response(['success' => true])]);
        $flow = $this->greeting();

        app(ChatbotRunner::class)->handle($this->chat('Hello')->id);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::BOTCAKE_SEND);
        $this->useGateway();
        $chat = $this->chat('Hi again');
        app(ChatbotRunner::class)->handle($chat->id);

        Http::assertSentCount(2);
        Http::assertSent(function (HttpRequest $r) {
            $d = $r->data();
            return $r->url() === self::GW_SEND && $r->method() === 'POST' && $r->hasHeader('Authorization', 'Bearer ' . self::GW_KEY)
                && $d['psid'] === self::PSID && $d['messages'] === [['type' => 'text', 'text' => 'You said: Hi again']]
                && $d['messaging_type'] === 'RESPONSE' && preg_match('/^likha-[0-9A-Za-z]{26}$/', $d['client_ref']) === 1;
        });
        $reply = ChatbotReply::orderByDesc('id')->first();
        $this->assertSame([$chat->id, $flow->id, 'n2', 'send', 'sent', null, 202], [$reply->chat_id, $reply->flow_id, $reply->node_id, $reply->kind, $reply->status, $reply->error, $reply->http]);
        $this->assertSame(['sent', 'sent'], ChatbotReply::orderBy('id')->pluck('status')->all());
    }

    public function test_S_111_2_a_gateway_refusal_or_missing_setup_fails_the_send_with_a_clear_reason(): void
    {
        $this->greeting();
        $this->useGateway();
        Http::fake([self::GW_SEND => Http::response(['error' => ['code' => 'page_not_sendable', 'message' => 'The page is in receive mode.']], 422)]);
        app(ChatbotRunner::class)->handle($this->chat('One')->id);
        $this->assertSame(['failed', 'Gateway said "The page is in receive mode." (HTTP 422)', 422], [ChatbotReply::sole()->status, ChatbotReply::sole()->error, ChatbotReply::sole()->http]);

        config(['services.gateway.url' => '']);
        Http::fake();
        app(ChatbotRunner::class)->handle($this->chat('Two')->id);
        Http::assertNothingSent();
        $last = ChatbotReply::orderByDesc('id')->first();
        $this->assertSame('failed', $last->status);
        $this->assertStringContainsString('not set up', $last->error);
    }

    public function test_S_111_3_a_gateway_page_skips_the_botcake_send_pool(): void
    {
        config(['services.botcake.async_sends' => true]);
        Cache::put(BotcakeSendPool::BEAT, ['owner' => 'pool-test', 'at' => now()->getTimestamp()], 60);
        Http::fake([self::GW_SEND => Http::response(['id' => '01k84x', 'status' => 'queued'], 202)]);
        $this->greeting();
        $this->useGateway();

        app(ChatbotRunner::class)->handle($this->chat('Hello')->id);

        $this->assertSame(0, DB::table('botcake_send_requests')->count());
        Http::assertSentCount(1);
        $this->assertSame('sent', ChatbotReply::sole()->status);
    }

    public function test_S_111_4_a_gateway_message_on_a_gateway_page_stores_the_chat_and_runs_the_flow_once(): void
    {
        Queue::fake([RunChatbotFlow::class, MarkChatbotReplied::class]);
        $this->greeting();
        $this->useGateway();
        BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Ana Cruz', 'psid' => self::PSID, 'status' => 'available', 'source' => 'api']);

        $this->event()->assertOk()->assertExactJson(['ok' => true]);
        $this->event(['attempt' => 2])->assertOk();          // ang ulit ng gateway: parehong mid
        BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => self::PSID, 'message' => 'via Botcake', 'message_id' => 'm_Botcake9',
            'payload' => [], 'received_at' => now()]);
        $this->event(['mid' => 'm_Botcake9', 'event_id' => 18343])->assertOk(); // naitala na ng Botcake

        $chat = BotcakeChat::where('message_id', 'm_AbC1')->sole();
        $this->assertSame(['1001', 'Page Uno', self::PSID, 'Ana Cruz', 'Available pa po?', 'gateway'],
            [$chat->page_id, $chat->page_name, $chat->psid, $chat->name, $chat->message, $chat->payload['source'] ?? null]);
        $this->assertSame(2, BotcakeChat::count());
        Queue::assertPushed(RunChatbotFlow::class, 1);
        Queue::assertPushed(RunChatbotFlow::class, fn ($j) => $j->chatId === $chat->id);
        Queue::assertPushed(MarkChatbotReplied::class, 1);
    }

    /** Ang ibang anyo ng event: [ang event, ang message, ang payload na inaasahan]. */
    public static function shapes(): array
    {
        return [
            'referral without mid, from an ad' => [['type' => 'referral', 'mid' => null, 'text' => null, 'referral' => ['ad_id' => '6045246247433', 'ref' => null, 'source' => 'ADS']],
                null, ['source' => 'gateway', 'ad_id' => '6045246247433'], 'gw-event-18342'],
            'postback' => [['type' => 'postback', 'mid' => null, 'text' => null, 'postback' => ['title' => 'Order now', 'payload' => 'ORDER']],
                'Order now', ['source' => 'gateway', 'postback' => 'ORDER'], 'gw-event-18342'],
            'photo' => [['text' => null, 'attachments' => [['type' => 'image', 'url' => 'https://scontent.xx.fbcdn.net/v/t1/p.jpg']]],
                '[Photo]', ['source' => 'gateway', 'image_url' => 'https://scontent.xx.fbcdn.net/v/t1/p.jpg'], 'm_AbC1'],
            'like sticker' => [['text' => null, 'attachments' => [['type' => 'image', 'url' => 'https://scontent.xx.fbcdn.net/v/t39.1997-6/851587_369239346556147_162929011_n.png']]],
                '👍', ['source' => 'gateway'], 'm_AbC1'],
            'video, ad id not digits' => [['text' => null, 'attachments' => [['type' => 'video', 'url' => 'https://v.example.test/v.mp4']], 'referral' => ['ad_id' => 'x<1>']],
                '[Video]', ['source' => 'gateway'], 'm_AbC1'],
        ];
    }

    /** @dataProvider shapes */
    public function test_S_111_4b_referral_postback_and_attachments_are_stored_like_a_botcake_chat_once(array $event, ?string $message, array $payload, string $ref): void
    {
        Queue::fake([RunChatbotFlow::class, MarkChatbotReplied::class]);
        $this->greeting();
        $this->useGateway();

        $this->event($event)->assertOk();
        $this->event($event + ['attempt' => 2])->assertOk(); // ang ulit: parehong mid o event_id

        $chat = BotcakeChat::sole();
        $this->assertSame([$message, $payload, $ref], [$chat->message, $chat->payload, $chat->message_id]);
        Queue::assertPushed(RunChatbotFlow::class, 1);
    }

    public function test_S_111_4c_bot_off_no_ids_bad_json_or_a_big_body_store_nothing(): void
    {
        Queue::fake([RunChatbotFlow::class, MarkChatbotReplied::class]);
        $this->greeting();
        $this->useGateway();

        $this->event(['mid' => null, 'event_id' => null])->assertOk();
        $this->event(['mid' => null, 'event_id' => null, 'text' => 'second'])->assertOk();
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'active', '0', false, null, true);
        $this->event()->assertOk();
        $sign = fn (string $b) => ['CONTENT_TYPE' => 'application/json', 'HTTP_X_GATEWAY_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $b, self::SECRET)];
        $this->call('POST', '/api/gateway/events', [], [], [], $sign('not json'), 'not json')->assertStatus(400);
        $big = json_encode(['text' => str_repeat('a', 70000)]);
        $this->call('POST', '/api/gateway/events', [], [], [], $sign($big), $big)->assertStatus(413);

        $this->assertSame(0, BotcakeChat::count());
        Queue::assertNothingPushed();
        $this->assertSame([2, 1], [$this->counted('no_id'), $this->counted('bot_off')]);
    }

    public function test_S_111_5_a_gateway_event_for_a_botcake_page_or_an_echo_only_counts(): void
    {
        Queue::fake([RunChatbotFlow::class, MarkChatbotReplied::class]);
        $this->greeting();

        $this->event()->assertOk();
        $this->useGateway();
        $this->event(['type' => 'echo', 'is_echo' => true, 'mid' => 'm_echo'])->assertOk();
        $this->event(['type' => 'read', 'mid' => null])->assertOk();

        $this->assertSame(0, BotcakeChat::count());
        Queue::assertNothingPushed();
        $this->assertSame([1, 1, 1], [$this->counted('botcake_page'), $this->counted('echo'), $this->counted('read')]);
    }

    public function test_S_111_6_a_bad_or_missing_signature_is_401_and_no_secret_fails_closed(): void
    {
        Queue::fake();
        $this->useGateway();

        $this->event([], 'wrong-secret')->assertStatus(401);
        $this->event([], null)->assertStatus(401);
        $this->event([], null, 'sha256=')->assertStatus(401);
        config(['services.gateway.webhook_secret' => '']);
        $this->event([], '')->assertStatus(503);

        Queue::assertNothingPushed();
        $this->assertSame(0, BotcakeChat::count());
    }

    public function test_S_111_7_a_botcake_push_for_a_gateway_page_is_accepted_and_kept_on_standby_without_running(): void
    {
        Queue::fake();
        Http::fake();
        BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Ana Cruz', 'psid' => self::PSID, 'status' => 'available', 'source' => 'api']);
        $this->greeting();
        $this->useGateway();

        $this->botcakePush()->assertOk()->assertExactJson(['ok' => true]);

        $this->assertTrue(\App\Services\Chatbot\ForbeastWatch::standby(BotcakeChat::sole()));
        Queue::assertNothingPushed();
        $this->assertSame(['gateway_page' => 1], array_filter(app(BotcakeChatGuard::class)->counts()));
    }

    public function test_S_111_8_the_provider_select_saves_with_history_shows_in_changes_and_needs_the_gateway_set_up(): void
    {
        Queue::fake();
        Http::fake();
        $ceo = $this->user();
        $this->actingAs($ceo);
        // Forbeast needs the page's own working token (spec 113, no global-key shortcut).
        app(PageSettings::class)->set('Page Uno', 'forbeast', 'token', 'fbp_test_token_PageUno', true, null, true); app(PageSettings::class)->set('Page Uno', 'forbeast', 'token_status', 'ok · Forbeast connected', false, null, true);

        $row = fn () => collect($this->getJson('/chatbot/data')->assertOk()->json('rows'))->firstWhere('page', 'Page Uno');
        $this->assertSame('botcake', $row()['provider']);
        $this->assertTrue($this->getJson('/chatbot/data')->json('gateway_ready'));

        $this->postJson('/chatbot/provider', ['page_name' => 'page uno', 'provider' => 'gateway'])->assertOk()->assertJson(['provider' => 'gateway']);
        $this->postJson('/chatbot/provider', ['page_name' => 'Page Uno', 'provider' => 'botcake'])->assertOk();
        $this->postJson('/chatbot/provider', ['page_name' => 'Page Uno', 'provider' => 'pancake'])->assertStatus(422);
        $this->postJson('/chatbot/provider', ['page_name' => 'No Such Page', 'provider' => 'gateway'])->assertStatus(422);

        $this->assertSame('botcake', $row()['provider']);
        $this->assertSame(['gateway', 'botcake'], PageSetting::where('provider', 'chatbot')->where('key', 'provider')->orderBy('id')->pluck('value')->all());
        $this->assertSame(1, PageSetting::where('provider', 'chatbot')->where('key', 'provider')->where('active', true)->count());
        $changes = collect($this->getJson('/chatbot/changes')->assertOk()->json('entries'))->where('key', 'provider')->values();
        $this->assertSame([['Provider', 'gateway', 'botcake'], ['Provider', null, 'gateway']], $changes->map(fn ($e) => [$e['field'], $e['old'], $e['new']])->all());
        $this->assertNotContains('provider', array_column($this->getJson('/chatbot/data')->json('columns'), 'key'));

        config(['services.gateway.url' => '']);
        $this->assertFalse($this->getJson('/chatbot/data')->json('gateway_ready'));
        $this->postJson('/chatbot/provider', ['page_name' => 'Page Uno', 'provider' => 'gateway'])->assertStatus(422);
        $this->assertSame('botcake', $row()['provider']);
        $this->get('/chatbot/pages')->assertOk()->assertSee('data-provider-url', false);
    }

    public function test_S_111_10_a_bulk_switch_saves_every_page_with_its_own_history_and_changes_entry_or_nothing(): void
    {
        Queue::fake();
        $this->page('Page Dos', '2002');
        $this->page('Page Tres', '3003');
        $this->actingAs($this->user());
        app(PageSettings::class)->set('Page Uno', 'forbeast', 'token', 'fbp_test_token_PageUno', true, null, true); app(PageSettings::class)->set('Page Uno', 'forbeast', 'token_status', 'ok · Forbeast connected', false, null, true);
        app(PageSettings::class)->set('Page Dos', 'forbeast', 'token', 'fbp_test_token_PageDos', true, null, true); app(PageSettings::class)->set('Page Dos', 'forbeast', 'token_status', 'ok · Forbeast connected', false, null, true);

        $this->postJson('/chatbot/provider', ['page_names' => ['Page Uno', 'page dos'], 'provider' => 'gateway'])
            ->assertOk()->assertJson(['provider' => 'gateway', 'pages' => ['Page Uno', 'Page Dos']]);
        $providers = fn () => collect($this->getJson('/chatbot/data')->json('rows'))->pluck('provider', 'page')->all();
        $this->assertSame(['Page Dos' => 'gateway', 'Page Tres' => 'botcake', 'Page Uno' => 'gateway'], $providers());

        // Isang page na wala sa listahan: wala ni isa ang nabago. Lampas sa 200: 422.
        $this->postJson('/chatbot/provider', ['page_names' => ['Page Uno', 'Page Tres', 'No Such Page'], 'provider' => 'botcake'])->assertStatus(422);
        $this->postJson('/chatbot/provider', ['page_names' => array_map(fn ($i) => "P$i", range(1, 201)), 'provider' => 'botcake'])->assertStatus(422);
        $this->assertSame(['Page Dos' => 'gateway', 'Page Tres' => 'botcake', 'Page Uno' => 'gateway'], $providers());

        $this->postJson('/chatbot/provider', ['page_names' => ['Page Uno', 'Page Dos'], 'provider' => 'botcake'])->assertOk();
        $this->assertSame(['botcake', 'botcake', 'botcake'], array_values($providers()));
        $entries = collect($this->getJson('/chatbot/changes')->json('entries'))->where('key', 'provider');
        $this->assertSame(['Page Dos' => 2, 'Page Uno' => 2], $entries->countBy('page')->sortKeys()->all()); // isang entry kada page kada pagbabago

        config(['services.gateway.url' => '']);
        $this->postJson('/chatbot/provider', ['page_names' => ['Page Uno', 'Page Dos'], 'provider' => 'gateway'])->assertStatus(422);
    }

    public function test_S_111_11_the_page_shows_bulk_controls_and_labels_the_option_forbeast(): void
    {
        $this->actingAs($this->user());

        $html = $this->get('/chatbot/pages')->assertOk()->getContent();

        foreach (['sel-all', 'id="bulk-provider"', 'id="bulk-apply"'] as $needle) $this->assertStringContainsString($needle, $html);
        $this->assertStringContainsString("text: 'Forbeast'", $html);
        $this->assertStringNotContainsString('Forbeast Gateway', $html);
    }

    /** @dataProvider prefixes */
    public function test_S_111_12_a_gateway_url_with_a_path_prefix_gets_v1_appended_once(string $configured): void
    {
        config(['services.gateway.url' => $configured]);
        $send = 'https://app.forbeast.app/api/v1/pages/1001/messages';
        Http::fake([$send => Http::response(['id' => '01k84x', 'status' => 'queued'], 202)]);
        $this->useGateway();
        $this->actingAs($this->user());
        $this->chat('Hi');

        $this->postJson('/chatbot/inbox/send', ['page_id' => '1001', 'psid' => self::PSID, 'text' => 'Hello'])->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === $send);
    }

    public static function prefixes(): array
    {
        return ['prefix' => ['https://app.forbeast.app/api'], 'prefix with a trailing slash' => ['https://app.forbeast.app/api/']];
    }

    public function test_S_111_9_an_inbox_manual_send_on_a_gateway_page_goes_through_the_gateway(): void
    {
        Http::fake([self::GW_SEND => Http::response(['id' => '01k84x', 'status' => 'queued'], 202)]);
        $this->useGateway();
        $this->chat('Hi');
        $this->actingAs($this->user());

        $res = $this->postJson('/chatbot/inbox/send', ['page_id' => '1001', 'psid' => self::PSID, 'text' => 'Hello po!'])->assertOk()->json();

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::GW_SEND && $r['psid'] === self::PSID && $r['messages'] === [['type' => 'text', 'text' => 'Hello po!']]
            && str_starts_with($r['client_ref'], 'likha-'));
        $reply = ChatbotReply::sole();
        $this->assertSame(['manual', 'sent'], [$reply->kind, $reply->status]);
        $this->assertSame('sent', $res['item']['status']);
        $this->assertStringNotContainsString(self::GW_KEY, json_encode($res));
    }
}
