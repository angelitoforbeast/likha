<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\MarkChatbotReplied;
use App\Jobs\RunChatbotFlow;
use App\Models\BotcakeChat;
use App\Models\BotcakePsid;
use App\Models\ChatbotFlow;
use App\Models\ChatbotReply;
use App\Services\Chatbot\AdGroups;
use App\Services\Chatbot\ChatbotInbound;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotRunner;
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
 * Ang pagbukas ng ad (referral ng Forbeast Gateway) ay itinatala lang: ang unang mensahe (o postback) ng customer ang
 * nagsisimula ng Main Flow, kasama ang ad ng referral. Sa Ad-based na page, ang unang mensahe ng bagong customer na wala
 * pang ad ay naghihintay nang hanggang 2 s sa referral. Fake na HTTP; walang tawag sa totoong gateway.
 */
class AdOpenWaitTest extends ChatblastTestCase
{
    private const GW = 'https://gw.example.test';
    private const GW_SEND = self::GW . '/v1/pages/1001/messages';
    private const SECRET = 'gw-webhook-secret-test-9o8i7u';
    private const PSID = '6012345678901234';
    private const AD_X = '6045246247433';
    private const AD_Y = '6045246247999';

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
            'database/migrations/2026_10_30_098000_add_raw_to_chatbot_replies.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
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

    /** Ang event ng gateway (pinirmahan), bawat isa may sariling event_id at oras ng Meta. */
    private function event(array $e): void
    {
        $id = ++$this->eventId;
        $body = json_encode($e + ['kind' => 'event', 'event_id' => $id, 'page_id' => '1001', 'psid' => self::PSID, 'type' => 'message',
            'mid' => 'm_' . $id, 'timestamp' => 1761800000000 + $id, 'text' => 'Hm', 'attachments' => [], 'referral' => null,
            'postback' => null, 'is_echo' => false, 'app_id' => null, 'attempt' => 1]);
        $this->call('POST', '/api/gateway/events', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_GATEWAY_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $body, self::SECRET)], $body)->assertOk();
    }

    private function referral(string $ad = self::AD_X, array $extra = []): void
    {
        $this->event($extra + ['type' => 'referral', 'mid' => null, 'text' => null, 'referral' => ['ad_id' => $ad, 'ref' => null, 'source' => 'ADS']]);
    }

    private function says(string $text, array $extra = []): void
    {
        $this->event($extra + ['text' => $text]);
    }

    /** @return array<int, RunChatbotFlow> ang mga job na naipila mula sa huling tawag */
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

    public function test_S_204_1_an_ad_open_alone_is_recorded_with_its_ad_and_starts_nothing(): void
    {
        $this->referral();

        $chat = BotcakeChat::sole();
        $this->assertSame(['source' => 'gateway', 'ad_id' => self::AD_X, ChatbotInbound::AD_OPEN => true, '_via' => 'forbeast'], $chat->payload);
        $this->assertNull($chat->message);
        $this->assertSame(1, $this->counted('referral'));
        Queue::assertNotPushed(RunChatbotFlow::class);
        // Ang Results ay bumabasa nito gaya ng dati (ang oras na backfill ay ganoon din).
        Queue::assertPushed(MarkChatbotReplied::class, 1);
        $this->assertSame([], $this->sent());
        $this->assertNull((new CustomerFields(1001, (int) self::PSID))->get(CustomerFields::LAST_CHAT));
    }

    public function test_S_204_2_the_first_message_after_the_ad_open_starts_the_main_flow_from_its_first_step_with_the_ad(): void
    {
        $this->referral();
        $this->travel(5)->seconds();
        $this->says('Hm');
        $jobs = $this->newJobs();
        $this->assertCount(1, $jobs);
        $message = BotcakeChat::where('message', 'Hm')->sole();
        $this->assertSame([$message->id, null], [$jobs[0]->chatId, $jobs[0]->delay]);

        app(ChatbotRunner::class)->handle($jobs[0]->chatId);
        $this->assertSame(['Hello po! PROMO 199'], $this->sent());
        $this->assertSame([self::AD_X, $message->id], [ChatbotReply::sole()->ad_id, ChatbotReply::sole()->chat_id]);
    }

    public function test_S_204_3_a_referral_after_the_message_or_a_repeated_referral_does_not_start_or_double_the_flow(): void
    {
        $this->says('Hm');
        $this->work();
        $this->referral();
        $this->referral(extra: ['event_id' => $this->eventId, 'attempt' => 2]); // ang ulit ng parehong event
        $this->assertSame([], $this->newJobs());
        $this->assertSame(1, $this->counted('duplicate'));

        $this->travel(10)->seconds();
        $this->says('Magkano po?');
        $this->work();
        $this->work();
        $this->assertSame(['Hello po! PROMO 199', 'Got: Magkano po?'], $this->sent());
        $last = BotcakeChat::orderByDesc('id')->first();
        $this->assertSame((string) $last->id, (new CustomerFields(1001, (int) self::PSID))->get(CustomerFields::LAST_CHAT));
    }

    public function test_S_204_4_a_referral_inside_a_running_flow_changes_nothing_in_the_run(): void
    {
        $this->says('Hi');
        $this->work();
        $fields = new CustomerFields(1001, (int) self::PSID);
        $before = [$fields->get(CustomerFields::WAIT), $fields->get(CustomerFields::LAST_CHAT), DB::table('chatbot_waits')->count()];

        $this->travel(1)->minutes();
        $this->referral(self::AD_Y);
        $this->assertSame([], $this->newJobs());
        $fields = new CustomerFields(1001, (int) self::PSID);
        $this->assertSame($before, [$fields->get(CustomerFields::WAIT), $fields->get(CustomerFields::LAST_CHAT), DB::table('chatbot_waits')->count()]);

        // Ang ad open ay hindi sagot: ang paghihintay ay natatapos bilang "walang sagot".
        $this->travel(61)->minutes();
        Artisan::call('chatbot:resume-skipped');
        $this->assertSame(['Hello po! PROMO 199', 'Still there?'], $this->sent());
    }

    public function test_S_204_5_a_postback_starts_the_flow_as_today_and_the_botcake_webhook_path_is_unchanged(): void
    {
        $this->adBased();
        $this->event(['type' => 'postback', 'mid' => null, 'text' => null, 'postback' => ['title' => 'Order now', 'payload' => 'ORDER']]);
        $jobs = $this->newJobs();
        // Ang postback ng bagong customer na walang ad sa Ad-based na page ay naghihintay din sa referral (S-204.9).
        $this->assertSame(1, count($jobs));
        app(ChatbotRunner::class)->handle($jobs[0]->chatId);
        $this->assertSame(['Hello po! PROMO 199'], $this->sent());

        // Botcake webhook (page na nasa Botcake): agad, walang paghihintay, kahit Ad-based na may group.
        $this->page('Page Dos', '1002');
        app(PageSettings::class)->set('Page Dos', 'botcake', 'chat.key', 'page-key-dos-test-a1s2d3f4g5h6j7k8', true, null, true);
        app(ChatbotPage::class)->link('Page Dos', ChatbotFlow::sole());
        app(AdGroups::class)->setMode('Page Dos', true);
        app(AdGroups::class)->save('Page Dos', null, 'Item X', [self::AD_X], ['price' => '499']);
        config(['services.botcake.chat_ips' => ['203.171.22.6'], 'services.botcake.chat_ip_mode' => 'log']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.171.22.6'])->postJson('/api/botcake/chat',
            ['page_id' => '1002', 'psid' => '7009', 'name' => 'Ana Cruz', 'message' => 'Hello po'], ['X-Chat-Key' => 'page-key-dos-test-a1s2d3f4g5h6j7k8'])->assertOk();
        $jobs = $this->newJobs();
        $this->assertCount(1, $jobs);
        $this->assertNull($jobs[0]->delay);
    }

    public static function adCases(): array
    {
        return [
            'referral X then message: ad X' => [true, [['ref', self::AD_X], ['say', null]], 'Hello po! PROMO 499'],
            'X then Y then message: the newer ad wins' => [true, [['ref', self::AD_X], ['ref', self::AD_Y], ['say', null]], 'Hello po! PROMO 799'],
            'a message that carries its own referral: that ad' => [true, [['ref', self::AD_X], ['say', self::AD_Y]], 'Hello po! PROMO 799'],
            'no referral, Botcake\'s ad of the customer: the fallback' => [true, [['psid_ad', self::AD_X], ['say', null]], 'Hello po! PROMO 499'],
            'no referral and no known ad: page values' => [true, [['say', null]], 'Hello po! PROMO 199'],
            'page-based page: page values' => [false, [['ref', self::AD_X], ['say', null]], 'Hello po! PROMO 199'],
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

    public function test_S_204_9_on_an_ad_based_page_a_new_customers_first_message_waits_up_to_2_s_for_the_referral(): void
    {
        $this->adBased();
        // Unang dating ang mensahe: naghihintay (2 s).
        $this->says('Hm');
        $held = $this->newJobs();
        $this->assertCount(1, $held);
        $this->assertSame(2, (int) now()->diffInSeconds($held[0]->delay));

        // Dumating ang referral sa loob ng paghihintay: tumatakbo na agad, ang ad ang sagot, isang beses lang.
        $this->travel(1)->seconds();
        $this->referral(self::AD_Y);
        $released = $this->newJobs();
        $this->assertCount(1, $released);
        $this->assertSame([$held[0]->chatId, null], [$released[0]->chatId, $released[0]->delay]);
        app(ChatbotRunner::class)->handle($released[0]->chatId);
        app(ChatbotRunner::class)->handle($held[0]->chatId); // ang naantalang job: wala nang ginagawa
        $this->assertSame(['Hello po! PROMO 799'], $this->sent());
        $this->assertSame(1, ChatbotReply::count());

        // Walang referral: ang fallback pagkatapos ng paghihintay.
        $this->says('Hello', ['psid' => '6012345678900001']);
        $alone = $this->newJobs();
        $this->assertSame(2, (int) now()->diffInSeconds($alone[0]->delay));
        $this->travel(2)->seconds();
        app(ChatbotRunner::class)->handle($alone[0]->chatId);
        $this->assertSame('Hello po! PROMO 199', $this->sent()[1]);
    }

    public static function noHold(): array
    {
        return [
            'page-based page' => ['page'],
            'Ad-based page with no groups' => ['no_groups'],
            'returning customer' => ['returning'],
            'the message carries its own ad' => ['own_ad'],
        ];
    }

    #[DataProvider('noHold')]
    public function test_S_204_9_no_hold_where_the_ad_is_known_or_not_needed(string $case): void
    {
        if ($case !== 'page') app(AdGroups::class)->setMode('Page Uno', true);
        if (in_array($case, ['returning', 'own_ad'], true)) $this->adBased();
        if ($case === 'returning') {
            BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => self::PSID, 'message' => 'Hi kahapon', 'payload' => [], 'received_at' => now()->subDay()]);
        }
        $this->says('Hm', $case === 'own_ad' ? ['referral' => ['ad_id' => self::AD_X, 'source' => 'ADS']] : []);

        $jobs = $this->newJobs();
        $this->assertCount(1, $jobs);
        $this->assertNull($jobs[0]->delay);
    }
}
