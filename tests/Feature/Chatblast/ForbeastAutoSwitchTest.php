<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\MarkChatbotReplied;
use App\Jobs\RunChatbotFlow;
use App\Models\BotcakeChat;
use App\Models\BotcakePsid;
use App\Models\ChatbotFlow;
use App\Models\ChatbotReply;
use App\Models\PageSetting;
use App\Services\Chatbot\BotcakeOutbox;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotProvider;
use App\Services\Chatbot\ForbeastPageSetup;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowGraph;
use App\Services\Chatbot\FlowRun;
use App\Services\Chatbot\ForbeastWatch;
use App\Services\Chatbot\GatewayProvider;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Ang page na nasa Forbeast ay kusang lumilipat sa Botcake kapag pumapalya ang Forbeast (5 kondisyon), may talaan at
 * event feed; ang mga chat ng Botcake habang nasa Forbeast ay standby at sinasagot pagkalipat. Fake na HTTP.
 */
class ForbeastAutoSwitchTest extends ChatblastTestCase
{
    private const GW = 'https://gw.example.test';
    private const GW_SEND = self::GW . '/v1/pages/1001/messages';
    private const GW_PAGE = self::GW . '/v1/pages/1001';
    private const GW_KEY = 'gw_test_key_1q2w3e4r';
    private const PSID = '6012345678901234';
    private const KEY_UNO = 'page-key-uno-test-a1s2d3f4g5h6j7k8l9';
    private const EVENTS_KEY = 'events-key-test-z9x8c7v6';

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
            'services.chatbot.replies_enabled' => true, 'services.chatbot.events_key' => self::EVENTS_KEY,
            'services.gateway.url' => self::GW, 'services.gateway.key' => self::GW_KEY, 'services.gateway.timeout' => 10,
            'services.botcake.chat_ips' => ['203.171.22.6'], 'services.botcake.chat_ip_mode' => 'log',
        ]);
        $this->page('Page Uno', '1001');
        app(PageSettings::class)->set('Page Uno', 'botcake', 'chat.key', self::KEY_UNO, true, null, true);
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'provider', 'gateway');
        BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Ana Cruz', 'psid' => self::PSID, 'status' => 'available', 'source' => 'api']);
        BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Ben Reyes', 'psid' => self::PSID_B, 'status' => 'available', 'source' => 'api']);
    }

    private function provider(): string
    {
        return ChatbotProvider::name('Page Uno');
    }

    private function send(): array
    {
        return (new GatewayProvider())->send('Page Uno', '1001', self::PSID, [['type' => 'text', 'text' => 'Hi']], ChatbotProvider::ref());
    }

    /** @return array<int, array> ang mga auto-switch event ng page, luma muna */
    private function events(): array
    {
        return PageSetting::where('provider', 'chatbot')->where('key', 'auto_switch')->orderBy('id')->get()
            ->map(fn ($s) => json_decode((string) $s->plainValue(), true))->all();
    }

    private function metaError(int $code, int $http = 400): array
    {
        return ['error' => ['code' => $code, 'message' => 'Meta said #' . $code]];
    }

    private function botcakePush(string $message, ?string $mid = null, string $psid = self::PSID)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '203.171.22.6'])->postJson('/api/botcake/chat',
            ['page_id' => '1001', 'psid' => $psid, 'name' => 'Ana Cruz', 'message' => $message] + ($mid ? ['message_id' => $mid] : []),
            ['X-Chat-Key' => self::KEY_UNO]);
    }

    private const PSID_B = '6012345678905678';

    /** Ang kopya ng Forbeast ng isang mensahe ng customer $psid (walang mid na kapareho ng Botcake). */
    private function gatewayChat(string $message, string $psid = self::PSID, ?string $reply = null): BotcakeChat
    {
        $chat = BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => $psid, 'message' => $message,
            'message_id' => 'm_' . uniqid(), 'payload' => ['source' => 'gateway'], 'received_at' => now()]);
        if ($reply !== null) BotcakeOutbox::record(1001, (int) $psid, $chat->id, null, 'n2', 'send', [], $reply);

        return $chat;
    }

    /** @return array<int, string> ang mga log line (mensahe + context) habang tumatakbo ang $fn */
    private function logged(callable $fn): array
    {
        $lines = [];
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Log\Events\MessageLogged::class, function ($m) use (&$lines) {
            $lines[] = $m->message . ' ' . json_encode($m->context);
        });
        $fn();

        return $lines;
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

    public function test_S_117_1_five_failed_sends_in_a_row_switch_the_page_once(): void
    {
        Http::fake([self::GW_SEND => Http::response(['message' => 'Page is in receive mode.'], 422)]);

        for ($i = 0; $i < 4; $i++) $this->send();
        $this->assertSame('gateway', $this->provider());
        $this->send();
        $this->assertSame('botcake', $this->provider());
        $this->send();

        $events = $this->events();
        $this->assertCount(1, $events);
        $this->assertSame(['switched', 'gateway', 'botcake', 'failed_sends'], [$events[0]['result'], $events[0]['from'], $events[0]['to'], $events[0]['condition']]);
        $this->assertSame('HTTP 422', $events[0]['error'], 'walang text ng sagot ng Forbeast sa event');
    }

    public function test_S_117_1b_a_sent_message_resets_the_failed_count(): void
    {
        Http::fake([self::GW_SEND => Http::sequence()
            ->push(['message' => 'x'], 422)->push(['message' => 'x'], 422)->push(['message' => 'x'], 422)->push(['message' => 'x'], 422)
            ->push(['status' => 'queued'], 202)
            ->push(['message' => 'x'], 422)->push(['message' => 'x'], 422)->push(['message' => 'x'], 422)->push(['message' => 'x'], 422)]);

        for ($i = 0; $i < 9; $i++) $this->send();

        $this->assertSame('gateway', $this->provider());
        $this->assertSame([], $this->events());
    }

    public function test_S_117_2_a_meta_connection_error_switches_at_once(): void
    {
        Http::fake([self::GW_SEND => Http::response($this->metaError(190), 400)]);

        $this->send();

        $this->assertSame('botcake', $this->provider());
        $this->assertSame('connection_error', $this->events()[0]['condition']);
        $this->assertStringContainsString('#190', $this->events()[0]['error']);
    }

    public function test_S_117_3_three_sends_without_an_answer_switch_the_page(): void
    {
        Http::fake([self::GW_SEND => Http::sequence()->push('', 503)->push('', 502)->push('', 500)]);

        $this->send();
        $this->send();
        $this->assertSame('gateway', $this->provider());
        $this->send();

        $this->assertSame('botcake', $this->provider());
        $this->assertSame('no_answer', $this->events()[0]['condition']);
    }

    public function test_S_117_4_botcake_chats_without_forbeast_chats_switch_the_page(): void
    {
        Queue::fake();
        Http::fake([self::GW_PAGE => Http::response(['connected' => true, 'can_send' => true])]);

        // Dalawang customer (ang isang customer lang ay hindi sapat).
        foreach (['Una' => self::PSID, 'Pangalawa' => self::PSID, 'Pangatlo' => self::PSID_B] as $m => $psid) $this->botcakePush($m, null, $psid)->assertOk();
        // Kararating lang: ang kopya ng Forbeast ay maaaring nasa queue pa, kaya walang lipat.
        $this->artisan('chatbot:forbeast-watch')->assertSuccessful();
        $this->assertSame('gateway', $this->provider());

        $this->travel(2)->minutes();
        $this->artisan('chatbot:forbeast-watch')->assertSuccessful();
        $this->assertSame('botcake', $this->provider());
        $this->assertSame('inbound', $this->events()[0]['condition']);
    }

    public function test_S_117_4b_a_forbeast_chat_in_the_window_keeps_the_page_on_forbeast(): void
    {
        Queue::fake();
        Http::fake([self::GW_PAGE => Http::response(['connected' => true, 'can_send' => true])]);
        $messages = ['Una', 'Pangalawa', 'Pangatlo', 'Pang-apat'];
        foreach ($messages as $m) $this->botcakePush($m)->assertOk();
        $this->travel(2)->minutes();
        // Every Botcake chat has its Forbeast copy: the page stays.
        foreach ($messages as $i => $m) BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => self::PSID, 'message' => $m,
            'message_id' => 'm_fb' . $i, 'payload' => ['source' => 'gateway'], 'received_at' => now()->subMinute()]);

        $this->artisan('chatbot:forbeast-watch')->assertSuccessful();
        $this->assertSame('gateway', $this->provider());

        // One customer's Botcake chat with no Forbeast copy is not enough; a second customer's is.
        $this->botcakePush('Panglima')->assertOk();
        $this->travel(2)->minutes();
        $this->artisan('chatbot:forbeast-watch')->assertSuccessful();
        $this->assertSame('gateway', $this->provider());
        $this->botcakePush('Hello po', null, self::PSID_B)->assertOk();
        $this->travel(2)->minutes();
        $this->artisan('chatbot:forbeast-watch')->assertSuccessful();
        $this->assertSame('botcake', $this->provider());
    }

    /** @return array<string, array{0: string, 1: string}> ang kopya ng Botcake at ang sa Forbeast */
    public static function twins(): array
    {
        return [
            'audio' => ['[audio] https://cdn.fbsbx.com/v/t59.3654-21/voice_clip.mp4?_nc_cat=1&oh=00_abc', '[Audio]'],
            'image' => ['https://scontent.xx.fbcdn.net/v/t1.15752-9/abc_n.jpg?stp=dst-jpg', '[Photo]'],
            'sticker' => ['[sticker] https://scontent.xx.fbcdn.net/v/t39.1997-6/sticker_n.png', '[Photo]'],
            'like (a Botcake sticker marker too)' => ['[sticker] https://scontent.xx.fbcdn.net/v/like.png', '👍'],
            'like' => ['https://scontent.xx.fbcdn.net/v/t39.1997-6/39178562_1505197616293642_5411344281094848512_n.png', '👍'],
            'video' => ['[video] https://video.xx.fbcdn.net/v/clip.mp4', '[Video]'],
            'text, other spacing and case' => ["  Magkano   PO\nito? ", 'magkano po ito?'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('twins')]
    public function test_S_149_20_a_botcake_copy_pairs_with_its_forbeast_twin_by_kind_and_time(string $botcake, string $forbeast): void
    {
        Queue::fake();
        Http::fake([self::GW_PAGE => Http::response(['connected' => true, 'can_send' => true])]);
        foreach ([self::PSID, self::PSID_B] as $psid) {
            $this->gatewayChat($forbeast, $psid);
            $this->botcakePush($botcake, null, $psid)->assertOk();
        }

        $this->travel(3)->minutes();
        $this->artisan('chatbot:forbeast-watch')->assertSuccessful();

        $this->assertSame('gateway', $this->provider(), 'two customers, but every copy has its twin');
    }

    public function test_S_149_21_the_inbound_switch_needs_unmatched_copies_from_two_customers_after_the_grace(): void
    {
        Queue::fake();
        Http::fake([self::GW_PAGE => Http::response(['connected' => true, 'can_send' => true])]);
        // Isang customer, kahit dalawang mensahe, at isang Forbeast chat na isa lang ang kapares: walang lipat.
        $this->gatewayChat('Available po?'); // text: ang attachment lang ay hindi kailanman ebidensya
        foreach (['Available po?', 'Magkano po?'] as $m) $this->botcakePush($m)->assertOk();
        $this->travel(3)->minutes();
        $this->artisan('chatbot:forbeast-watch')->assertSuccessful();
        $this->assertSame('gateway', $this->provider(), 'one customer is not enough');

        // Ang pangalawang customer na walang kopya sa Forbeast, pagkalipas ng grace: lipat.
        $this->botcakePush('Hello po, available?', null, self::PSID_B)->assertOk();
        $this->artisan('chatbot:forbeast-watch')->assertSuccessful();
        $this->assertSame('gateway', $this->provider(), 'still inside the 120 s grace');
        $this->travel(3)->minutes();
        $lines = $this->logged(fn () => $this->artisan('chatbot:forbeast-watch')->assertSuccessful());

        $this->assertSame('botcake', $this->provider());
        $this->assertSame(['inbound', 'Botcake chats from 2 customers with no Forbeast copy after 120 seconds'], [$this->events()[0]['condition'], $this->events()[0]['error']]);
        $line = collect($lines)->first(fn ($l) => str_contains($l, 'forbeast watch: switched'));
        $this->assertNotNull($line, 'a log line with the evidence');
        $this->assertStringContainsString('"customers":2', $line);
        $this->assertStringContainsString('"chat_ids":[', $line);
        $this->assertStringNotContainsString('Hello po', implode("\n", $lines));
    }

    public function test_S_149_22_after_a_switch_a_copy_whose_twin_was_answered_is_skipped_and_one_without_a_twin_runs(): void
    {
        Queue::fake();
        $this->greeting();
        $this->botcakePush('[audio] https://cdn.fbsbx.com/voice.mp4')->assertOk();
        $this->gatewayChat('[Audio]', self::PSID, 'sent');
        $this->travel(1)->minutes();
        $this->botcakePush('Hello po, available?', null, self::PSID_B)->assertOk();

        Http::fake([self::GW_SEND => Http::response($this->metaError(190), 400)]);
        $lines = $this->logged(fn () => $this->send());
        $this->assertSame('botcake', $this->provider());

        $ran = [];
        Queue::assertPushed(RunChatbotFlow::class, function (RunChatbotFlow $job) use (&$ran) {
            $ran[] = BotcakeChat::find($job->chatId)?->message;
            return true;
        });
        $this->assertSame(['Hello po, available?'], $ran);
        $skip = collect($lines)->first(fn ($l) => str_contains($l, 'forbeast watch: standby skipped'));
        $this->assertNotNull($skip);
        $this->assertStringContainsString('"reason":"forbeast_twin"', $skip);
        $this->assertStringNotContainsString('voice.mp4', implode("\n", $lines));
    }

    public function test_S_149_32_attachment_only_copies_never_count_for_the_inbound_switch(): void
    {
        Queue::fake();
        Http::fake([self::GW_PAGE => Http::response(['connected' => true, 'can_send' => true])]);
        $this->botcakePush('[audio] https://cdn.fbsbx.com/a.mp4')->assertOk();
        $this->botcakePush('https://scontent.xx.fbcdn.net/v/t1.15752-9/p_n.jpg', null, self::PSID_B)->assertOk();

        $this->travel(3)->minutes();
        $this->artisan('chatbot:forbeast-watch')->assertSuccessful();

        $this->assertSame('gateway', $this->provider(), 'two customers, but only photos and voice notes');
    }

    public function test_S_149_23_a_forbeast_reply_removes_only_its_own_standby_copy_even_after_several_sends(): void
    {
        Queue::fake();
        // Dalawang voice note; ang Forbeast ay ang una lang ang natanggap, at ang flow nito ay may dalawang send.
        $this->botcakePush('[audio] https://cdn.fbsbx.com/one.mp4')->assertOk();
        $f1 = $this->gatewayChat('[Audio]');
        $this->travel(30)->seconds();
        $this->botcakePush('[audio] https://cdn.fbsbx.com/two.mp4')->assertOk();

        app(ForbeastWatch::class)->answered($f1->id);
        app(ForbeastWatch::class)->answered($f1->id);

        $left = BotcakeChat::get()->filter(fn ($c) => ForbeastWatch::standby($c))->pluck('message')->values()->all();
        $this->assertSame(['[audio] https://cdn.fbsbx.com/two.mp4'], $left, 'the second voice note stays for a switch');
    }

    public function test_S_149_22_a_copy_with_no_twin_is_skipped_when_the_customer_was_answered_since_it_came(): void
    {
        Queue::fake();
        $this->greeting();
        $this->botcakePush('Hello po', null, self::PSID_B)->assertOk();
        BotcakeOutbox::record(1001, (int) self::PSID_B, null, null, 'n2', 'manual', [], 'sent'); // hal. sagot mula sa Inbox
        \Illuminate\Support\Facades\DB::table('chatbot_replies')->update(['kind' => 'send', 'sent_at' => now()->subSeconds(3)]);

        Http::fake([self::GW_SEND => Http::response($this->metaError(190), 400)]);
        $lines = $this->logged(fn () => $this->send());

        Queue::assertNotPushed(RunChatbotFlow::class);
        $this->assertNotNull(collect($lines)->first(fn ($l) => str_contains($l, 'standby skipped') && str_contains($l, '"reason":"already_answered"')));
    }

    public function test_S_117_5_the_health_check_switches_after_three_failures_or_at_once_when_not_connected(): void
    {
        // Walang sariling token ang page: walang health check (ang system key ay 404 sa /v1/pages/{id}).
        Http::fake([self::GW_PAGE => Http::response('', 500)]);
        for ($i = 0; $i < 4; $i++) $this->artisan('chatbot:forbeast-watch')->assertSuccessful();
        $this->assertSame('gateway', $this->provider());
        Http::assertNotSent(fn (HttpRequest $r) => $r->url() === self::GW_PAGE);

        app(PageSettings::class)->set('Page Uno', ForbeastPageSetup::PROVIDER, 'token', 'fbp_page_uno_token', true, null, true);
        Http::fake([self::GW_PAGE => Http::sequence()->push('', 500)->push('', 500)->push('', 500)]);
        $this->artisan('chatbot:forbeast-watch')->assertSuccessful();
        $this->artisan('chatbot:forbeast-watch')->assertSuccessful();
        $this->assertSame('gateway', $this->provider());
        $this->artisan('chatbot:forbeast-watch')->assertSuccessful();
        $this->assertSame('botcake', $this->provider());
        $this->assertSame('health', $this->events()[0]['condition']);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::GW_PAGE && $r->method() === 'GET' && $r->hasHeader('Authorization', 'Bearer fbp_page_uno_token'));

        // Ibang page: sumagot pero hindi makapagpadala.
        $this->page('Page Dos', '2002');
        app(PageSettings::class)->set('Page Dos', 'chatbot', 'provider', 'gateway');
        app(PageSettings::class)->set('Page Dos', ForbeastPageSetup::PROVIDER, 'token', 'fbp_page_dos_token', true, null, true);
        Http::fake([self::GW . '/v1/pages/2002' => Http::response(['id' => '2002', 'connected' => true, 'can_send' => false])]);
        $this->artisan('chatbot:forbeast-watch')->assertSuccessful();
        $this->assertSame('botcake', ChatbotProvider::name('Page Dos'));
        $this->assertCount(2, $this->events());
    }

    public function test_S_117_6_customer_side_errors_never_count(): void
    {
        Http::fake([self::GW_SEND => Http::sequence()
            ->push($this->metaError(551), 400)->push($this->metaError(10), 400)->push($this->metaError(551), 400)
            ->push($this->metaError(551), 400)->push($this->metaError(10), 400)->push($this->metaError(551), 400)
            ->push(['message' => 'x'], 422)->push(['message' => 'x'], 422)->push(['message' => 'x'], 422)->push(['message' => 'x'], 422)]);

        for ($i = 0; $i < 10; $i++) $this->send();

        $this->assertSame('gateway', $this->provider());
        $this->assertSame([], $this->events());
    }

    public function test_S_117_7_a_page_without_botcake_is_not_switched_and_gets_one_cannot_switch_event(): void
    {
        $token = PageSetting::where('provider', 'botcake')->where('key', 'token')->where('active', true)->sole();
        app(PageSettings::class)->deactivate($token);
        Http::fake([self::GW_SEND => Http::response($this->metaError(190), 400)]);

        $this->send();
        $this->send();

        $this->assertSame('gateway', $this->provider());
        $events = $this->events();
        $this->assertCount(1, $events);
        $this->assertSame(['cannot_switch', 'gateway', null, 'connection_error'], [$events[0]['result'], $events[0]['from'], $events[0]['to'], $events[0]['condition']]);
    }

    public function test_S_117_8_nothing_switches_back_by_itself_and_a_manual_switch_clears_the_note(): void
    {
        $this->actingAs($this->user());
        Http::fake([self::GW_SEND => Http::response($this->metaError(190), 400), self::GW_PAGE => Http::response(['connected' => true, 'can_send' => true])]);
        $this->send();
        $this->assertSame('botcake', $this->provider());

        for ($i = 0; $i < 3; $i++) $this->artisan('chatbot:forbeast-watch')->assertSuccessful();
        $this->assertSame('botcake', $this->provider());

        $row = collect($this->getJson('/chatbot/data')->assertOk()->json('rows'))->firstWhere('page', 'Page Uno');
        $this->assertSame('botcake', $row['provider']);
        $this->assertStringContainsString('Meta connection error', $row['auto_switched']['reason']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $row['auto_switched']['at']);
        $changes = collect($this->getJson('/chatbot/changes')->assertOk()->json('entries'));
        $this->assertSame('auto-switch', $changes->firstWhere('key', 'provider')['by']);
        $this->assertStringStartsWith('Switched to Botcake · Meta connection error', $changes->firstWhere('key', 'auto_switch')['new']);
        $this->assertStringContainsString("'Auto-switched '", $this->get('/chatbot/pages')->assertOk()->getContent());

        app(PageSettings::class)->set('Page Uno', 'forbeast', 'token', 'fbp_test_token_PageUno', true, null, true); app(PageSettings::class)->set('Page Uno', 'forbeast', 'token_status', 'ok · Forbeast connected', false, null, true);
        $this->postJson('/chatbot/provider', ['page_name' => 'Page Uno', 'provider' => 'gateway'])->assertOk();
        $row = collect($this->getJson('/chatbot/data')->assertOk()->json('rows'))->firstWhere('page', 'Page Uno');
        $this->assertSame(['gateway', null], [$row['provider'], $row['auto_switched']]);
    }

    /** Ang kopya ng Forbeast ng isang mensahe, at (kung $reply) ang sagot dito na naipadala o pumalya. */
    private function forbeastCopy(string $message, string $mid, ?string $reply = null): BotcakeChat
    {
        $chat = BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => self::PSID, 'message' => $message,
            'message_id' => $mid, 'payload' => ['source' => 'gateway'], 'received_at' => now()]);
        if ($reply !== null) BotcakeOutbox::record(1001, (int) self::PSID, $chat->id, null, 'n2', 'send', [], $reply);

        return $chat;
    }

    public function test_S_117_9_standby_chats_are_stored_without_running_and_run_once_after_the_switch(): void
    {
        Queue::fake();
        $this->greeting();

        $this->botcakePush('Luma na', 'm_old')->assertOk();
        BotcakeChat::where('message_id', 'm_old')->update(['received_at' => now()->subMinutes(20)]);
        $this->botcakePush('Una po', 'm_1', self::PSID_B)->assertOk(); // ibang customer: walang sagot sa kanya pagkatapos
        $this->botcakePush('Sinagot ng Forbeast', 'm_2')->assertOk();
        $this->forbeastCopy('Sinagot ng Forbeast', 'm_2', 'sent');
        $this->botcakePush('Pumalya ang sagot', 'm_3')->assertOk();
        $this->forbeastCopy('Pumalya ang sagot', 'm_3', 'failed');

        $this->assertSame(4, BotcakeChat::get()->filter(fn ($c) => ForbeastWatch::standby($c))->count());
        Queue::assertNotPushed(RunChatbotFlow::class);
        Queue::assertNotPushed(MarkChatbotReplied::class);

        Http::fake([self::GW_SEND => Http::response($this->metaError(190), 400)]);
        $this->send();
        $this->assertSame('botcake', $this->provider());
        app(ForbeastWatch::class)->switch('Page Uno', 'health', 'again'); // ang pangalawang subok: walang ulit

        $ran = [];
        Queue::assertPushed(RunChatbotFlow::class, function (RunChatbotFlow $job) use (&$ran) {
            $ran[] = BotcakeChat::find($job->chatId)?->message;
            return true;
        });
        // Ang may kopya sa Forbeast ay hindi tumatakbo, kahit pumalya ang sagot doon (walang dobleng sagot).
        $this->assertSame(['Una po'], $ran);
        $this->assertSame(['Luma na', 'Sinagot ng Forbeast', 'Pumalya ang sagot'], BotcakeChat::get()->filter(fn ($c) => ForbeastWatch::standby($c))->pluck('message')->values()->all());
    }

    public function test_S_117_9b_a_forbeast_event_for_a_standby_mid_runs_once_and_the_standby_copy_goes_after_the_reply_is_sent(): void
    {
        Queue::fake();
        $this->greeting();
        $this->botcakePush('Available pa po?', 'm_AbC1')->assertOk();

        (new \App\Jobs\HandleGatewayEvent(\App\Jobs\HandleGatewayEvent::fields(['kind' => 'event', 'event_id' => 1, 'page_id' => '1001',
            'psid' => self::PSID, 'type' => 'message', 'mid' => 'm_AbC1', 'text' => 'Available pa po?'])))->handle(app(PageSettings::class), app(\App\Services\BotcakeChatGuard::class));

        Queue::assertPushed(RunChatbotFlow::class, 1);
        $this->assertSame(2, BotcakeChat::count(), 'ang standby ay naiiwan hanggang may naipadalang sagot');

        Http::fake([self::GW_SEND => Http::response(['status' => 'queued'], 202)]);
        app(\App\Services\Chatbot\ChatbotRunner::class)->handle(BotcakeChat::where('payload->source', 'gateway')->value('id'));

        $this->assertSame('gateway', BotcakeChat::sole()->payload['source']);
    }

    public function test_S_117_10_the_events_feed_needs_its_key_and_returns_events_since_an_id(): void
    {
        $this->getJson('/api/chatbot/provider-events')->assertStatus(401);
        $this->getJson('/api/chatbot/provider-events', ['X-CHATBOT-EVENTS-KEY' => 'wrong'])->assertStatus(401);

        Http::fake([self::GW_SEND => Http::response($this->metaError(190), 400)]);
        $this->send();
        $first = PageSetting::where('key', 'auto_switch')->value('id');

        $res = $this->getJson('/api/chatbot/provider-events?since=0', ['X-CHATBOT-EVENTS-KEY' => self::EVENTS_KEY])->assertOk();
        $e = $res->json('events.0');
        $this->assertSame([$first, 'Page Uno', 'switched', 'gateway', 'botcake', 'connection_error'], [$e['id'], $e['page'], $e['result'], $e['from'], $e['to'], $e['condition']]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+08:00$/', $e['at']);
        $this->assertStringNotContainsString(self::GW_KEY, $res->getContent());
        $this->getJson('/api/chatbot/provider-events?since=' . $first, ['X-CHATBOT-EVENTS-KEY' => self::EVENTS_KEY])->assertOk()->assertExactJson(['events' => []]);

        config(['services.chatbot.events_key' => '']);
        $this->getJson('/api/chatbot/provider-events', ['X-CHATBOT-EVENTS-KEY' => ''])->assertStatus(401);
    }

    public function test_S_117_11_sequence_sends_go_with_update_and_replies_with_response(): void
    {
        Http::fake([self::GW_SEND => Http::response(['status' => 'queued'], 202)]);
        $chat = BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => self::PSID, 'message' => 'Hi', 'payload' => ['source' => 'gateway'], 'received_at' => now()]);
        $fields = new CustomerFields(1001, (int) self::PSID);
        $reply = new FlowRun(1, [], 'Page Uno', '1001', [], $fields, $chat->id, 'Hi', 'Ana', '');
        $step = new FlowRun(1, [], 'Page Uno', '1001', [], $fields, null, '', 'Ana', '');
        $step->startedBy = 'sequence X step 2';

        app(BotcakeOutbox::class)->send($reply, 'n2', [['type' => 'text', 'text' => 'Reply']]);
        app(BotcakeOutbox::class)->send($step, 'n2', [['type' => 'text', 'text' => 'Step']]);

        $types = [];
        Http::assertSent(function (HttpRequest $r) use (&$types) {
            $types[$r->data()['messages'][0]['text']] = $r->data()['messaging_type'];
            return true;
        });
        $this->assertSame(['Reply' => 'RESPONSE', 'Step' => 'UPDATE'], $types);
        $this->assertStringNotContainsString('tag', json_encode(Http::recorded()->map(fn ($p) => array_keys($p[0]->data()))->all()));
    }

    public function test_S_120_3_only_a_provider_row_with_a_matching_event_is_labelled_auto_switch(): void
    {
        $this->actingAs($this->user());
        $this->travel(1)->days(); // ang provider row ng setUp ay luma na, walang event
        Http::fake([self::GW_SEND => Http::response($this->metaError(190), 400)]);
        $this->send();
        $this->assertSame('botcake', $this->provider());

        $by = collect($this->getJson('/chatbot/changes')->assertOk()->json('entries'))->where('key', 'provider')->pluck('by', 'new')->all();
        $this->assertSame(['botcake' => 'auto-switch', 'gateway' => 'system'], $by);
    }
}
