<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\HandleGatewayEvent;
use App\Jobs\MarkChatbotReplied;
use App\Jobs\RunChatbotFlow;
use App\Models\BotcakeChat;
use App\Models\BotcakePsid;
use App\Models\ChatbotFlow;
use App\Models\ChatbotReply;
use App\Models\ChatbotSequence;
use App\Services\BotcakeChatGuard;
use App\Services\Chatbot\AdGroups;
use App\Services\Chatbot\ChatbotInbound;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotRunner;
use App\Services\Chatbot\ChatbotSequences;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowGraph;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ang pagbukas ng ad (referral ng Forbeast Gateway) ay itinatala lang, bago ang 200: ang unang mensahe (o postback) ng
 * customer ang nagsisimula ng Main Flow, kasama ang ad ng referral. Ang tanong na hindi naihatid (send_status failed) ay
 * itinatanong ulit sa susunod na mensahe. Fake na HTTP; walang tawag sa totoong gateway.
 */
class AdOpenWaitTest extends ChatblastTestCase
{
    private const GW = 'https://gw.example.test';
    private const GW_SEND = self::GW . '/v1/pages/1001/messages';
    private const SECRET = 'gw-webhook-secret-test-9o8i7u';
    private const PSID = '6012345678901234';
    private const AD_X = '6045246247433';
    private const AD_Y = '6045246247999';
    private const OPENING = 'Hello po! PROMO 199';

    private int $eventId = 18000;
    private int $ran = 0;

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
            'database/migrations/2026_10_30_098000_add_raw_to_chatbot_replies.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
            'database/migrations/2026_11_06_100000_add_chat_index_to_chatbot_replies.php',
            'database/migrations/2026_11_09_100000_add_via_to_chatbot_replies.php',
            'database/migrations/2026_11_11_100000_add_real_send_to_chatbot_replies.php',
        ]]);
        config([
            'services.chatbot.replies_enabled' => true, 'services.chatbot.ai_async' => false,
            'services.gateway.url' => self::GW, 'services.gateway.key' => 'gw_test_key_1q2w3e4r',
            'services.gateway.webhook_secret' => self::SECRET, 'services.gateway.timeout' => 10,
        ]);
        Http::fake([self::GW_SEND => Http::response(['id' => '01k84x', 'status' => 'queued'], 202), 'https://botcake.io/*' => Http::response(['success' => true])]);
        Queue::fake([RunChatbotFlow::class, MarkChatbotReplied::class]);
        $this->travelTo('2026-10-30 14:57:00');
        $this->page('Page Uno', '1001');
        $s = app(PageSettings::class);
        $s->set('Page Uno', 'chatbot', 'provider', 'gateway');
        $s->set('Page Uno', 'chatbot', 'price', '199');
        // Ang opening (unang hakbang) ay naghihintay ng sagot; ang sagot ay ang kasunod, ang walang sagot sa 1 oras ay "Still there?".
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start'],
            ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Hello po! PROMO {{PRICE}}']], 'wait' => true, 'save_to' => 'answer',
                'skip_after' => ['amount' => 1, 'unit' => 'hours']]],
            ['id' => 'n3', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Got: {{answer}}']]]],
            ['id' => 'n4', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Still there?']]]],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2'], ['from' => 'n2', 'port' => 'reply', 'to' => 'n3'], ['from' => 'n2', 'port' => 'skipped', 'to' => 'n4']]])]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);
        app(ChatbotPage::class)->link('Page Uno', $flow->fresh());
    }

    /** Ang event ng gateway, bawat isa may sariling event_id, mid at oras ng Meta. */
    private function shape(array $e): array
    {
        $id = ++$this->eventId;

        return $e + ['kind' => 'event', 'event_id' => $id, 'page_id' => '1001', 'psid' => self::PSID, 'type' => 'message',
            'mid' => 'm_' . $id, 'timestamp' => 1761800000000 + $id, 'text' => 'Hm', 'attachments' => [], 'referral' => null,
            'postback' => null, 'is_echo' => false, 'app_id' => null, 'attempt' => 1];
    }

    /** Ipinapadala sa /api/gateway/events, pinirmahan. */
    private function event(array $e): void
    {
        $body = json_encode($this->shape($e));
        $this->call('POST', '/api/gateway/events', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_GATEWAY_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $body, self::SECRET)], $body)->assertOk();
    }

    private static function ref(string $ad = self::AD_X): array
    {
        return ['type' => 'referral', 'mid' => null, 'text' => null, 'referral' => ['ad_id' => $ad, 'ref' => null, 'source' => 'ADS']];
    }

    private function referral(string $ad = self::AD_X, array $extra = []): void
    {
        $this->event($extra + self::ref($ad));
    }

    private function says(string $text, array $extra = []): void
    {
        $this->event($extra + ['text' => $text]);
    }

    /** @return array<int, RunChatbotFlow> ang mga job ng flow na naipila mula sa huling tawag */
    private function newJobs(): array
    {
        $jobs = Queue::pushed(RunChatbotFlow::class)->values()->all();
        $new = array_slice($jobs, $this->ran);
        $this->ran = count($jobs);

        return $new;
    }

    /** Pinatatakbo ang mga bagong job ng flow, gaya ng worker. */
    private function work(): void
    {
        foreach ($this->newJobs() as $job) app(ChatbotRunner::class)->handle($job->chatId);
    }

    /** @return array<int, string> ang mga text na naipadala sa customer, ayon sa ayos */
    private function sent(): array
    {
        return Http::recorded(fn (HttpRequest $r) => $r->url() === self::GW_SEND)->map(fn ($p) => $p[0]->data()['messages'][0]['text'] ?? '')->values()->all();
    }

    private function counted(string $what): int
    {
        return (int) Cache::get('gateway-events:' . now('Asia/Manila')->toDateString() . ':' . $what, 0);
    }

    /** Ad-based ang page, may dalawang group: X = ₱499, Y = ₱799. */
    private function adBased(): void
    {
        $g = app(AdGroups::class);
        $g->setMode('Page Uno', true);
        $g->save('Page Uno', null, 'Item X', [self::AD_X], ['price' => '499']);
        $g->save('Page Uno', null, 'Item Y', [self::AD_Y], ['price' => '799']);
    }

    public function test_S_204_1_an_ad_open_alone_is_recorded_with_its_ad_and_is_not_a_reply_or_a_run(): void
    {
        $seq = ChatbotSequence::create(['name' => 'Follow', 'active' => true, 'exit_on_reply' => true]);
        DB::table('chatbot_sequence_subscriptions')->insert(['sequence_id' => $seq->id, 'page_id' => 1001, 'psid' => (int) self::PSID, 'status' => 'active',
            'next_send_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);

        $this->referral();

        $chat = BotcakeChat::sole();
        $this->assertSame(['source' => 'gateway', 'ad_id' => self::AD_X, ChatbotInbound::AD_OPEN => true, '_via' => 'forbeast'], $chat->payload);
        $this->assertNull($chat->message);
        $this->assertSame(1, $this->counted('referral'));
        Queue::assertNotPushed(RunChatbotFlow::class);
        Queue::assertNotPushed(MarkChatbotReplied::class);
        $this->assertSame('active', DB::table('chatbot_sequence_subscriptions')->where('sequence_id', $seq->id)->value('status'));
        $this->assertSame([], $this->sent());
        $this->assertNull((new CustomerFields(1001, (int) self::PSID))->get(CustomerFields::LAST_CHAT));
    }

    public function test_S_204_2_the_first_message_after_the_ad_open_starts_the_main_flow_from_its_first_step_with_the_ad(): void
    {
        $this->referral();
        $this->travel(5)->seconds();
        $this->says('Hm');
        $jobs = $this->newJobs();
        $message = BotcakeChat::where('message', 'Hm')->sole();
        $this->assertSame([$message->id], array_map(fn ($j) => $j->chatId, $jobs));
        Queue::assertPushed(MarkChatbotReplied::class, 1); // ang mensahe ay sagot gaya ng dati

        app(ChatbotRunner::class)->handle($jobs[0]->chatId);
        $this->assertSame([self::OPENING], $this->sent());
        $this->assertSame([self::AD_X, $message->id], [ChatbotReply::sole()->ad_id, ChatbotReply::sole()->chat_id]);
    }

    public function test_S_204_3_a_referral_after_the_message_or_a_repeated_referral_does_not_start_or_double_the_flow(): void
    {
        $this->says('Hm');
        $this->work();
        $this->referral();
        $this->referral(extra: ['event_id' => $this->eventId, 'attempt' => 2]); // ang ulit ng parehong event
        $this->assertSame([], $this->newJobs());
        $this->assertSame([1, 1], [BotcakeChat::whereNull('message')->count(), $this->counted('duplicate')]);

        $this->travel(10)->seconds();
        $this->says('Magkano po?');
        $this->work();
        $this->assertSame([self::OPENING, 'Got: Magkano po?'], $this->sent());
        $last = BotcakeChat::orderByDesc('id')->first();
        $this->assertSame((string) $last->id, (new CustomerFields(1001, (int) self::PSID))->get(CustomerFields::LAST_CHAT));
    }

    public function test_S_204_4_a_referral_inside_a_running_flow_changes_nothing_in_the_run(): void
    {
        $this->says('Hi');
        $this->work();
        $state = fn () => [(new CustomerFields(1001, (int) self::PSID))->get(CustomerFields::WAIT), (new CustomerFields(1001, (int) self::PSID))->get(CustomerFields::LAST_CHAT),
            DB::table('chatbot_waits')->count()];
        $before = $state();

        $this->travel(1)->minutes();
        $this->referral(self::AD_Y);
        $this->assertSame([], $this->newJobs());
        $this->assertSame($before, $state());

        // Ang ad open ay hindi sagot: ang paghihintay ay natatapos bilang "walang sagot".
        $this->travel(61)->minutes();
        Artisan::call('chatbot:resume-skipped');
        $this->assertSame([self::OPENING, 'Still there?'], $this->sent());
    }

    public function test_S_204_5_a_postback_starts_the_flow_as_today_and_the_botcake_webhook_path_is_unchanged(): void
    {
        $this->event(['type' => 'postback', 'mid' => null, 'text' => null, 'postback' => ['title' => 'Order now', 'payload' => 'ORDER']]);
        $this->work();
        $this->assertSame([self::OPENING], $this->sent());

        // Botcake webhook (page na nasa Botcake): ang chat ay tumatakbo at sagot gaya ng dati.
        $this->page('Page Dos', '1002');
        app(PageSettings::class)->set('Page Dos', 'botcake', 'chat.key', 'page-key-dos-test-a1s2d3f4g5h6j7k8', true, null, true);
        app(ChatbotPage::class)->link('Page Dos', ChatbotFlow::sole());
        config(['services.botcake.chat_ips' => ['203.171.22.6'], 'services.botcake.chat_ip_mode' => 'log']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.171.22.6'])->postJson('/api/botcake/chat',
            ['page_id' => '1002', 'psid' => '7009', 'name' => 'Ana Cruz', 'message' => 'Hello po'], ['X-Chat-Key' => 'page-key-dos-test-a1s2d3f4g5h6j7k8'])->assertOk();
        $this->assertCount(1, $this->newJobs());
        Queue::assertPushed(MarkChatbotReplied::class, 2);
    }

    public static function adCases(): array
    {
        return [
            'referral X then message: ad X' => [true, [['ref', self::AD_X], ['say', null]], 'Hello po! PROMO 499'],
            'X then Y then message: the newer ad wins' => [true, [['ref', self::AD_X], ['ref', self::AD_Y], ['say', null]], 'Hello po! PROMO 799'],
            'a message that carries its own referral: that ad' => [true, [['ref', self::AD_X], ['say', self::AD_Y]], 'Hello po! PROMO 799'],
            'no referral, Botcake\'s ad of the customer: the fallback' => [true, [['psid_ad', self::AD_X], ['say', null]], 'Hello po! PROMO 499'],
            'no referral and no known ad: page values' => [true, [['say', null]], self::OPENING],
            'page-based page: page values' => [false, [['ref', self::AD_X], ['say', null]], self::OPENING],
        ];
    }

    #[DataProvider('adCases')]
    public function test_S_204_8_the_first_message_gets_the_reply_of_the_customers_latest_ad(bool $adBased, array $steps, string $reply): void
    {
        $this->adBased();
        if (!$adBased) app(AdGroups::class)->setMode('Page Uno', false);
        foreach ($steps as [$what, $ad]) {
            match ($what) {
                'ref' => $this->referral($ad),
                'say' => $this->says('Hm', $ad === null ? [] : ['referral' => ['ad_id' => $ad, 'source' => 'ADS']]),
                'psid_ad' => BotcakePsid::create(['page' => 'Page Uno', 'psid' => self::PSID, 'fb_name' => 'Ana Cruz', 'ad_id' => $ad, 'status' => 'matched']),
            };
            $this->travel(1)->seconds();
        }
        $this->work();

        $this->assertSame([$reply], $this->sent());
    }

    public function test_S_204_9_the_referral_is_stored_before_the_200_so_the_queued_message_finds_its_ad(): void
    {
        $this->adBased();
        // Ang mga job ng event ay nasa queue (hindi pa tumatakbo), gaya ng sabay-sabay na mga worker.
        Queue::fake([RunChatbotFlow::class, MarkChatbotReplied::class, HandleGatewayEvent::class]);

        $this->referral(self::AD_Y);
        $this->assertSame([self::AD_Y], BotcakeChat::pluck('payload')->map(fn ($p) => $p['ad_id'])->all(), 'naimbak bago ang 200');
        Queue::assertNotPushed(HandleGatewayEvent::class);

        $this->travel(1)->seconds();
        $this->says('Hm');
        Queue::assertPushed(HandleGatewayEvent::class, 1);
        $this->assertSame(1, BotcakeChat::count(), 'ang mensahe ay nasa queue pa');

        Queue::pushed(HandleGatewayEvent::class)->first()->handle(app(PageSettings::class), app(BotcakeChatGuard::class));
        $this->work();
        $this->assertSame(['Hello po! PROMO 799'], $this->sent());
        $this->assertSame(1, ChatbotReply::count());
    }

    public static function orders(): array
    {
        return ['referral stored first' => [['ref', 'say']], 'message stored first' => [['say', 'ref']]];
    }

    #[DataProvider('orders')]
    public function test_S_204_9_the_event_jobs_in_either_order_give_the_ads_reply_and_one_start(array $order): void
    {
        $this->adBased();
        $jobs = ['ref' => new HandleGatewayEvent(HandleGatewayEvent::fields($this->shape(self::ref(self::AD_Y)))),
            'say' => new HandleGatewayEvent(HandleGatewayEvent::fields($this->shape(['text' => 'Hm'])))];
        foreach ($order as $k) $jobs[$k]->handle(app(PageSettings::class), app(BotcakeChatGuard::class));
        $this->work();
        $this->work();

        $this->assertSame(['Hello po! PROMO 799'], $this->sent());
    }

    public static function sendStatuses(): array
    {
        return [
            'failed (551): asked again' => [['status' => 'failed', 'sent_count' => 0, 'error' => 'This person isn\'t available right now.', 'error_code' => 551, 'error_subcode' => 1545041], [self::OPENING, self::OPENING]],
            'partial with nothing sent: asked again' => [['status' => 'partial', 'sent_count' => 0], [self::OPENING, self::OPENING]],
            'partial with some sent: the answer' => [['status' => 'partial', 'sent_count' => 1], [self::OPENING, 'Got: Magkano po?']],
            'sent: the answer' => [['status' => 'sent', 'sent_count' => 1], [self::OPENING, 'Got: Magkano po?']],
        ];
    }

    #[DataProvider('sendStatuses')]
    public function test_S_204_11_a_question_that_comes_back_failed_is_asked_again_on_the_next_message(array $send, array $sent): void
    {
        $this->says('Hm');
        $this->work();
        $reply = ChatbotReply::sole();
        $this->event(['type' => 'send_status', 'mid' => null, 'text' => null, 'send' => $send + ['client_ref' => $reply->client_ref, 'accepted_at' => '2026-10-30T06:57:00.000Z',
            'sent_at' => '2026-10-30T06:57:01.000Z', 'message_count' => 2, 'error' => null, 'error_code' => null, 'error_subcode' => null]]);

        $this->travel(5)->seconds();
        $this->says('Magkano po?');
        $this->work();
        $this->assertSame($sent, $this->sent());
    }
}
