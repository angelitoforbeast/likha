<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\HandleGatewayEvent;
use App\Jobs\RunChatbotFlow;
use App\Models\BotcakeChat;
use App\Models\BotcakePsid;
use App\Models\ChatbotFlow;
use App\Models\PageSetting;
use App\Services\BotcakeChatGuard;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotProvider;
use App\Services\Chatbot\FlowGraph;
use App\Services\Chatbot\ForbeastWatch;
use App\Services\Chatbot\GatewayProvider;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Ang mas maingat na kusang lipat: lumilipat lang kapag may ebidensya na ang Forbeast ang may sira (3 customer, 300 s,
 * hindi mabagal ang Likha, hindi sinasabi ng Forbeast na mabilis ito), sinasagot ang naiwang customer, at kusang
 * ibinabalik sa Forbeast ang page na inilipat ng bantay pagkatapos ng 30 minutong maayos na health. Fake na HTTP.
 */
class ForbeastSmartSwitchTest extends ChatblastTestCase
{
    private const GW = 'https://gw.example.test';
    private const GW_SEND = self::GW . '/v1/pages/1001/messages';
    private const GW_PAGE = self::GW . '/v1/pages/1001';
    private const KEY_UNO = 'page-key-uno-test-a1s2d3f4g5h6j7k8l9';
    /** Ang inbound_lag_seconds sa sagot ng health (null = wala ang field). */
    private ?int $lag = null;
    private const PSIDS = ['6012345678901234' => 'Available po?', '6012345678905678' => 'Magkano po?', '6012345678909012' => 'Hm po?'];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/0001_01_01_000002_create_jobs_table.php',
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_22_100000_widen_source_on_botcake_psids.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_inbox_indexes.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_29_100000_create_botcake_send_requests_table.php',
        ]]);
        config([
            'services.chatbot.replies_enabled' => true, 'services.gateway.url' => self::GW, 'services.gateway.key' => 'gw_test_key_1q2w3e4r',
            'services.gateway.timeout' => 10, 'services.botcake.chat_ips' => ['203.171.22.6'], 'services.botcake.chat_ip_mode' => 'log',
        ]);
        $s = app(PageSettings::class);
        $this->page('Page Uno', '1001');
        $s->set('Page Uno', 'botcake', 'chat.key', self::KEY_UNO, true, null, true);
        $s->set('Page Uno', 'forbeast', 'token', 'fbp_page_uno_token', true, null, true);
        $s->set('Page Uno', 'forbeast', 'token_status', 'ok · Forbeast connected', false, null, true);
        $s->set('Page Uno', 'chatbot', 'provider', 'gateway');
        PageSetting::where('provider', 'chatbot')->where('key', 'provider')->update(['created_at' => now()->subHours(3)]);
        Http::fake([self::GW_PAGE => fn () => Http::response(['connected' => true, 'can_send' => true] + ($this->lag === null ? [] : ['inbound_lag_seconds' => $this->lag]))]);
        foreach (array_keys(self::PSIDS) as $i => $psid) {
            BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Customer ' . $i, 'psid' => (string) $psid, 'status' => 'available', 'source' => 'api']);
        }
    }

    private function provider(): string
    {
        return ChatbotProvider::name('Page Uno');
    }

    /** @return array<int, array> ang mga auto-switch event ng page, luma muna */
    private function events(): array
    {
        return PageSetting::where('provider', 'chatbot')->where('key', 'auto_switch')->orderBy('id')->get()
            ->map(fn ($s) => json_decode((string) $s->plainValue(), true))->all();
    }

    /** Ang health ng Forbeast ng page mula ngayon: konektado, may inbound_lag_seconds kung hindi null. */
    private function health(?int $lag): void
    {
        $this->lag = $lag;
    }

    /** Ang kopya ng Botcake ng unang $n customer, walang kopya sa Forbeast. */
    private function missing(int $n): void
    {
        foreach (array_slice(self::PSIDS, 0, $n, true) as $psid => $m) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.171.22.6'])->postJson('/api/botcake/chat',
                ['page_id' => '1001', 'psid' => (string) $psid, 'name' => 'Customer', 'message' => $m], ['X-Chat-Key' => self::KEY_UNO])->assertOk();
        }
    }

    private function watch(): void
    {
        $this->artisan('chatbot:forbeast-watch')->assertSuccessful();
    }

    private function greeting(): void
    {
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start'],
            ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'You said: {{last_input}}']]]],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]])]);
        app(ChatbotPage::class)->link('Page Uno', $flow);
    }

    /** @return array<int, string> ang mensahe ng bawat takbong naipasa sa queue */
    private function ranMessages(): array
    {
        $ran = [];
        Queue::assertPushed(RunChatbotFlow::class, function (RunChatbotFlow $job) use (&$ran) {
            $ran[] = BotcakeChat::find($job->chatId)?->message;
            return true;
        });

        return $ran;
    }

    public function test_S_175_1_three_customers_missing_for_300_s_switch_when_forbeast_says_nothing_about_lag(): void
    {
        Queue::fake();
        $this->health(null);
        $this->missing(3);
        $this->travel(301)->seconds();

        $this->watch();

        $this->assertSame('botcake', $this->provider());
        $e = $this->events()[0];
        $this->assertSame(['switched', 'inbound', 'Botcake chats from 3 customers with no Forbeast copy after 300 seconds'], [$e['result'], $e['condition'], $e['error']]);
    }

    public static function notEnough(): array
    {
        // Ang sinasagot: ang customer na wala pang kopya pagkalipas ng 300 s (hindi ang nasa 200 s pa lang).
        return ['two customers after 300 s' => [2, 301, ['Available po?', 'Magkano po?']], 'three customers after 200 s' => [3, 200, []]];
    }

    /** @dataProvider notEnough */
    public function test_S_175_2_fewer_customers_or_a_shorter_wait_never_switch_and_customers_past_the_wait_are_answered(int $customers, int $seconds, array $answered): void
    {
        Queue::fake();
        $this->greeting();
        $this->health(null);
        $this->missing($customers);
        $this->travel($seconds)->seconds();

        $this->watch();

        $this->assertSame('gateway', $this->provider());
        $this->assertSame([], $this->events());
        $answered === [] ? Queue::assertNotPushed(RunChatbotFlow::class) : $this->assertSame($answered, $this->ranMessages());
    }

    public static function likhaBehind(): array
    {
        return ['a gateway event stored 120 s after it came' => ['processed'], 'a gateway job waiting 120 s in the database queue' => ['waiting']];
    }

    /** @dataProvider likhaBehind */
    public function test_S_175_3_when_likha_is_behind_the_page_is_held_with_one_event_and_nothing_is_answered(string $how): void
    {
        Queue::fake();
        $this->health(null);
        $this->missing(3);
        $this->travel(181)->seconds();
        $event = HandleGatewayEvent::fields(['kind' => 'event', 'event_id' => 77, 'type' => 'message', 'page_id' => '1001', 'psid' => '6012345678900000', 'mid' => 'm_echo_1', 'is_echo' => true]);
        if ($how === 'waiting') {
            config(['queue.chatbot_connection' => 'database']);
            DB::table('jobs')->insert(['queue' => RunChatbotFlow::HIGH_QUEUE, 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
                'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp()]);
        }
        $this->travel(120)->seconds();
        if ($how === 'processed') (new HandleGatewayEvent($event))->handle(app(PageSettings::class), app(BotcakeChatGuard::class));

        $this->watch();
        $this->watch();

        $this->assertSame('gateway', $this->provider());
        $events = $this->events();
        $this->assertCount(1, $events, 'one held event, not one a minute');
        $this->assertSame(['held', 'inbound', 'held: Likha ingest lag 120 s', 120], [$events[0]['result'], $events[0]['condition'], $events[0]['error'], $events[0]['likha_lag']]);
        Queue::assertNotPushed(RunChatbotFlow::class); // ang kopya ng Forbeast ay nasa queue pa ng Likha: siya ang sasagot
    }

    public function test_S_175_4_when_forbeast_says_it_is_fast_the_page_is_held_and_the_missing_customers_are_answered(): void
    {
        Queue::fake();
        $this->greeting();
        $this->health(3);
        $this->missing(3);
        $this->travel(301)->seconds();

        $this->watch();

        $this->assertSame('gateway', $this->provider());
        $e = $this->events()[0];
        $this->assertSame(['held', 'inbound', 3, 3], [$e['result'], $e['condition'], $e['forbeast_lag'], $e['customers']]);
        $this->assertSame('held: Forbeast healthy (lag 3 s), 3 customers missing — likely not delivered by Meta', $e['error']);
        $this->assertSame(array_values(self::PSIDS), $this->ranMessages());
        $this->assertSame(0, BotcakeChat::get()->filter(fn ($c) => ForbeastWatch::standby($c))->count());
    }

    public function test_S_175_5_a_slow_forbeast_switches_with_its_lag_as_the_reason(): void
    {
        Queue::fake();
        $this->actingAs($this->user());
        $this->health(400);
        $this->missing(3);
        $this->travel(301)->seconds();

        $this->watch();

        $this->assertSame('botcake', $this->provider());
        $this->assertStringStartsWith('Forbeast slow: 400 s', $this->events()[0]['error']);
        $this->assertSame(400, $this->events()[0]['forbeast_lag']);
        $row = collect($this->getJson('/chatbot/data')->assertOk()->json('rows'))->firstWhere('page', 'Page Uno');
        $this->assertSame('Auto-switched', $row['auto_switched']['label']);
        $this->assertStringContainsString('Forbeast slow: 400 s', $row['auto_switched']['reason']);
    }

    /** Ang page na inilipat ng bantay (inbound), saka $minutes na minutong maayos na health, isang check kada minuto. */
    private function switchedThenHealthy(int $minutes, string $condition = 'inbound'): void
    {
        $this->health(2);
        app(ForbeastWatch::class)->switch('Page Uno', $condition, 'test');
        $this->assertSame('botcake', $this->provider());
        $this->healthyFor($minutes);
    }

    public function test_S_175_6a_a_page_the_watcher_moved_goes_back_after_30_healthy_minutes_and_not_at_29(): void
    {
        Queue::fake();
        $this->actingAs($this->user());
        $this->switchedThenHealthy(29);
        $this->assertSame('botcake', $this->provider(), '29 minutes is not enough');

        $this->travel(1)->minutes();
        $this->watch();

        $this->assertSame('gateway', $this->provider());
        $e = $this->events()[1];
        $this->assertSame(['switched_back', 'botcake', 'gateway', 'back on Forbeast after 30 min healthy', 2],
            [$e['result'], $e['from'], $e['to'], $e['error'], $e['forbeast_lag']]);
        $row = collect($this->getJson('/chatbot/data')->assertOk()->json('rows'))->firstWhere('page', 'Page Uno');
        $this->assertSame(['Back on Forbeast', 'back on Forbeast after 30 min healthy'], [$row['auto_switched']['label'], $row['auto_switched']['reason']]);
        $changes = collect($this->getJson('/chatbot/changes')->assertOk()->json('entries'));
        $this->assertSame('Switched back to Forbeast · back on Forbeast after 30 min healthy', $changes->firstWhere('key', 'auto_switch')['new']);
    }

    public function test_S_175_6e_a_send_lag_alarm_written_after_the_switch_does_not_keep_the_page_on_botcake(): void
    {
        Queue::fake();
        $this->health(2);
        app(ForbeastWatch::class)->switch('Page Uno', 'failed_sends', 'HTTP 500');
        app(ForbeastWatch::class)->event('Page Uno', \App\Services\Chatbot\ForbeastSendHealth::ALARM, null, 'send_lag', 'lag');

        $this->healthyFor(30);

        $this->assertSame('gateway', $this->provider());
    }

    public function test_S_175_6b_a_page_a_person_set_after_the_switch_is_never_moved_back(): void
    {
        Queue::fake();
        $this->health(2);
        app(ForbeastWatch::class)->switch('Page Uno', 'inbound', 'test');
        // Ang tao ay naglipat sa Forbeast at ibinalik sa Botcake (ang parehong value ay walang bagong row, kaya walang bakas).
        $person = $this->user()->id;
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'provider', 'gateway', false, $person);
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'provider', 'botcake', false, $person);

        $this->healthyFor(31);

        $this->assertSame('botcake', $this->provider());
        $this->assertCount(1, $this->events());
    }

    /** Isang check ngayon, saka isa kada minuto hanggang $minutes. */
    private function healthyFor(int $minutes): void
    {
        for ($i = 0; $i <= $minutes; $i++) {
            if ($i > 0) $this->travel(1)->minutes();
            $this->watch();
        }
    }

    public function test_S_175_6c_a_second_switch_back_within_6_hours_waits(): void
    {
        Queue::fake();
        config(['services.gateway.auto_switch.back_gap_minutes' => 360]); // ang dating default; ang 60 minuto ay S-183.5b
        $this->switchedThenHealthy(30);
        $this->assertSame('gateway', $this->provider());

        $this->travel(1)->hours();
        app(ForbeastWatch::class)->switch('Page Uno', 'inbound', 'again');
        $this->healthyFor(31);
        $this->assertSame('botcake', $this->provider(), 'within 6 hours of the last switch back');

        // Ang puwang ng 5 oras ay hindi "sunod-sunod": 30 minuto ulit, at lampas na sa 6 na oras mula sa unang balik.
        $this->travel(5)->hours();
        $this->healthyFor(30);
        $this->assertSame('gateway', $this->provider(), 'after 6 hours, a new healthy streak moves it back');
    }

    public function test_S_175_6d_a_slow_or_failing_check_restarts_the_30_minutes(): void
    {
        Queue::fake();
        $this->switchedThenHealthy(20);
        $this->health(400);
        $this->travel(1)->minutes();
        $this->watch();
        $this->health(2);
        $this->healthyFor(29);
        $this->assertSame('botcake', $this->provider());

        $this->travel(1)->minutes();
        $this->watch();
        $this->assertSame('gateway', $this->provider());
    }

    /** Ang standby ng 3 customer na natanggap sa $at, walang kopya sa Forbeast. */
    private function standbyAt(\Illuminate\Support\Carbon $at): void
    {
        foreach (self::PSIDS as $psid => $m) {
            BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => (string) $psid, 'message' => $m, 'message_id' => 'm_bc_' . $psid,
                'payload' => ['_standby' => true], 'received_at' => $at]);
        }
    }

    public static function aroundTheMove(): array
    {
        // Ang segundo mula sa lipat sa Forbeast (T) ng mga kopya ng Botcake, at kung lilipat sa T+500.
        return ['received at T-60 s (Forbeast copy dropped while on Botcake)' => [-60, 'gateway', 180], 'received at T+200 s' => [200, 'botcake', 180],
            'settle_seconds 30: received at T+60 s' => [60, 'botcake', 30]];
    }

    /** @dataProvider aroundTheMove */
    public function test_S_175_9_only_botcake_chats_after_the_move_plus_the_settle_time_count(int $offset, string $provider, int $settle): void
    {
        Queue::fake();
        config(['services.gateway.auto_switch.settle_seconds' => $settle]);
        $this->health(null);
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'provider', 'botcake');
        $this->travel(60)->seconds();
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'provider', 'gateway', false, $this->user()->id);
        $moved = now();
        $this->standbyAt($moved->copy()->addSeconds($offset));

        $this->travel(500)->seconds();
        $this->watch();

        $this->assertSame($provider, $this->provider());
    }

    public function test_S_175_9b_the_automatic_switch_back_starts_the_same_settle_window(): void
    {
        Queue::fake();
        $this->switchedThenHealthy(30);
        $this->assertSame('gateway', $this->provider());
        $this->standbyAt(now()->addSeconds(60));

        $this->travel(400)->seconds();
        $this->watch();

        $this->assertSame('gateway', $this->provider());
        $this->assertSame(['switched', 'switched_back'], array_column($this->events(), 'result'), 'no "moved" after the watcher\'s own move');
    }

    public function test_S_175_10b_a_system_move_is_recorded_as_system_and_a_move_older_than_an_hour_is_not_recorded(): void
    {
        Queue::fake();
        $this->actingAs($this->user());
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'provider', 'botcake');
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'provider', 'gateway');
        PageSetting::where('provider', 'chatbot')->where('key', 'provider')->where('active', true)->update(['created_at' => now()->subMinutes(61)]);
        $this->watch();
        $this->assertSame([], $this->events(), 'a move older than an hour');

        app(PageSettings::class)->set('Page Uno', 'chatbot', 'provider', 'botcake');
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'provider', 'gateway');
        $this->watch();

        $this->assertStringStartsWith('moved to Forbeast by system at ', $this->events()[0]['error']);
        $changes = collect($this->getJson('/chatbot/changes')->assertOk()->json('entries'));
        $this->assertSame('system', $changes->where('key', 'provider')->first()['by'], 'not auto-switch');
    }

    public function test_S_175_10_a_move_to_forbeast_the_watcher_did_not_make_is_recorded_once_with_who_and_when(): void
    {
        Queue::fake();
        $this->actingAs($this->user());
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'provider', 'botcake');
        $this->postJson('/chatbot/provider', ['page_name' => 'Page Uno', 'provider' => 'gateway'])->assertOk();
        $at = now('Asia/Manila')->format('Y-m-d H:i');

        $this->watch();
        $this->watch();

        $events = $this->events();
        $this->assertCount(1, $events);
        $this->assertSame(['moved', 'botcake', 'gateway', 'moved to Forbeast by CEO User at ' . $at],
            [$events[0]['result'], $events[0]['from'], $events[0]['to'], $events[0]['error']]);
        $changes = collect($this->getJson('/chatbot/changes')->assertOk()->json('entries'));
        $this->assertSame('CEO User', $changes->where('key', 'provider')->first()['by'], 'the person, not auto-switch');
        $this->assertStringStartsWith('Moved to Forbeast · moved to Forbeast by', $changes->firstWhere('key', 'auto_switch')['new']);
    }

    public function test_S_175_7_a_connection_code_still_switches_at_once_and_never_moves_back(): void
    {
        Queue::fake();
        $this->health(1);
        Http::fake([self::GW_SEND => Http::response(['error' => ['code' => 190, 'message' => 'Meta said #190']], 400)]);

        (new GatewayProvider())->send('Page Uno', '1001', '6012345678901234', [['type' => 'text', 'value' => 'Hi', 'text' => 'Hi']], ChatbotProvider::ref());
        $this->assertSame('botcake', $this->provider());
        $this->assertSame('connection_error', $this->events()[0]['condition']);

        $this->healthyFor(31);
        $this->assertSame('botcake', $this->provider());
        $this->assertCount(1, $this->events());
    }

    public function test_S_183_5_a_page_moved_for_health_goes_back_after_30_healthy_minutes_unless_a_person_changed_it(): void
    {
        Queue::fake();
        $this->switchedThenHealthy(30, 'health');
        $this->assertSame('gateway', $this->provider());
        $this->assertSame(['switched', 'switched_back'], array_column($this->events(), 'result'));

        // Ang tao ay naglipat pagkatapos ng lipat: hindi na ibinabalik.
        $this->travel(2)->hours();
        app(ForbeastWatch::class)->switch('Page Uno', 'health', 'Forbeast did not answer');
        $person = $this->user()->id;
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'provider', 'gateway', false, $person);
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'provider', 'botcake', false, $person);
        $this->healthyFor(31);
        $this->assertSame('botcake', $this->provider());
    }

    public function test_S_183_5b_one_switch_back_per_60_minutes(): void
    {
        Queue::fake();
        $this->switchedThenHealthy(30);
        $this->assertSame('gateway', $this->provider());

        // 10 minuto pagkatapos ng balik, lipat ulit: ang 30 minutong maayos ay umaabot lang sa 41 minuto mula sa balik.
        $this->travel(10)->minutes();
        app(ForbeastWatch::class)->switch('Page Uno', 'inbound', 'again');
        $this->healthyFor(31);
        $this->assertSame('botcake', $this->provider(), 'within 60 minutes of the last switch back');

        $this->healthyFor(20);
        $this->assertSame('gateway', $this->provider(), 'after 60 minutes');
    }

    public function test_S_183_6_a_customer_answered_by_any_app_since_the_message_is_not_evidence_for_a_switch(): void
    {
        Queue::fake();
        $this->health(null);
        $this->missing(3);
        // Isang customer ay sinagot ng tao (echo ng Page Inbox) kahit walang kopya sa Forbeast.
        $this->travel(30)->seconds();
        \App\Services\Chatbot\ChatEchoes::store(HandleGatewayEvent::fields(['kind' => 'event', 'type' => 'message', 'page_id' => '1001', 'psid' => array_key_first(self::PSIDS),
            'mid' => 'm_person_1', 'text' => 'Ako na po', 'is_echo' => true, 'app_id' => '263902037430900']));
        $this->travel(271)->seconds();

        $this->watch();

        $this->assertSame('gateway', $this->provider(), 'only 2 customers have no copy and no echo');
        $this->assertNotContains('switched', array_column($this->events(), 'result'));
    }
}
