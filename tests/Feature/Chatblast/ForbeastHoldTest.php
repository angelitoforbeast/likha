<?php

namespace Tests\Feature\Chatblast;

use App\Models\PageSetting;
use App\Services\Chatbot\ChatbotProvider;
use App\Services\Chatbot\ForbeastWatch;
use App\Services\Chatbot\GatewayProvider;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ang walang sagot sa maraming page ng iisang gateway nang sabay (burst) ay problema ng daan: hold muna at susuriin ulit,
 * at lilipat lang sa Botcake kapag tumagal. Fake na HTTP; walang totoong gateway.
 */
class ForbeastHoldTest extends ChatblastTestCase
{
    private const GW = 'https://gw.example.test';
    private const IXB = 'https://ixb.example.test';
    private const PSID = '6012345678901234';
    private const EVENTS_KEY = 'events-key-test-h0ld213';
    /** A read timeout (the request had left): not retried (spec 273), so one send is one request as before. */
    private const NO_ANSWER = 'cURL error 28: Operation timed out after 10000 milliseconds with 0 bytes received';

    /** Ang mga page ID na hindi sinasagot ng gateway (walang koneksyon), kada base URL. */
    private array $down = [self::GW => [], self::IXB => []];
    /** Ang mga page ID na sinasagot ng gateway ng HTTP 422 (may sagot, tinanggihan). */
    private array $refused = [];
    /** Ang mga page ID na ang padala lang ang walang sagot (sumasagot ang health GET). */
    private array $sendsDown = [];
    /** Ang mga page ID na sinasagot ng health GET ng "hindi konektado". */
    private array $notConnected = [];

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
            'database/migrations/2026_11_09_100000_add_via_to_chatbot_replies.php',
            'database/migrations/2026_11_13_100000_widen_via_on_chatbot_replies.php',
        ]]);
        config([
            'services.chatbot.replies_enabled' => true, 'services.chatbot.events_key' => self::EVENTS_KEY,
            'services.gateway.url' => self::GW, 'services.gateway.key' => 'gw_global_key', 'services.gateway.timeout' => 10,
            'services.incepxionbot.url' => self::IXB, 'services.incepxionbot.key' => 'ixb_global_key',
        ]);
        for ($i = 1; $i <= 6; $i++) $this->onGateway('Page ' . $i, (string) (2000 + $i), 'forbeast');
        Http::fake(function (HttpRequest $r) {
            $base = str_starts_with($r->url(), self::IXB) ? self::IXB : self::GW;
            if (!preg_match('#/v1/pages/(\d+)(/messages)?$#', $r->url(), $m)) return Http::response(['ok' => true]);
            if (in_array($m[1], $this->down[$base], true)) return Http::failedConnection(self::NO_ANSWER)($r);
            if (isset($m[2]) && in_array($m[1], $this->sendsDown, true)) return Http::failedConnection(self::NO_ANSWER)($r);
            if (isset($m[2]) && in_array($m[1], $this->refused, true)) return Http::response(['message' => 'Page is in receive mode.'], 422);
            $connected = !in_array($m[1], $this->notConnected, true);

            return isset($m[2]) ? Http::response(['ok' => true]) : Http::response(['id' => $m[1], 'connected' => $connected, 'can_send' => $connected]);
        });
    }

    /** Isang page na nasa gateway $gateway (may sariling token doon at may Botcake token), matagal na. */
    private function onGateway(string $page, string $id, string $gateway): void
    {
        $s = app(PageSettings::class);
        $this->page($page, $id);
        $s->set($page, $gateway, 'token', 'tok_' . $gateway . '_' . $id, true, null, true);
        $s->set($page, $gateway, 'token_status', 'ok · connected', false, null, true);
        if ($gateway !== 'forbeast') $s->set($page, 'chatbot', 'gateway', $gateway, false, null, true);
        $s->set($page, 'chatbot', 'provider', 'gateway');
        PageSetting::where('provider', 'chatbot')->whereIn('key', ['provider', 'gateway'])->update(['created_at' => now()->subHours(3)]);
    }

    private function send(int $n, int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            (new GatewayProvider())->send('Page ' . $n, (string) (2000 + $n), self::PSID, [['type' => 'text', 'text' => 'Hi']], ChatbotProvider::ref());
        }
    }

    private function provider(int $n): string
    {
        return ChatbotProvider::name('Page ' . $n);
    }

    /** @return array<int, array> ang mga auto-switch event (lahat ng page), luma muna, may "page" */
    private function events(?string $result = null): array
    {
        return PageSetting::where('provider', 'chatbot')->where('key', 'auto_switch')->orderBy('id')->get()
            ->map(fn ($s) => ['page' => $s->page_name] + (array) json_decode((string) $s->plainValue(), true))
            ->filter(fn ($e) => $result === null || ($e['result'] ?? null) === $result)->values()->all();
    }

    /** Ang buong forbeast gateway ay walang sagot; pages 1–5 ay may tig-isang walang sagot, ang Page 1 ay tatlo (hold). */
    private function burst(): void
    {
        $this->down[self::GW] = ['2001', '2002', '2003', '2004', '2005', '2006'];
        foreach ([1, 2, 3, 4, 5] as $n) $this->send($n);
        $this->send(1, 2);
    }

    public function test_S_214_1_one_page_alone_switches_after_three_no_answers_as_today(): void
    {
        $this->down[self::GW] = ['2001'];
        foreach ([2, 3, 4, 5, 6] as $n) $this->send($n);
        $this->send(1, 2);
        $this->assertSame('gateway', $this->provider(1));
        $this->send(1);

        $this->assertSame('botcake', $this->provider(1));
        $switched = $this->events(ForbeastWatch::SWITCHED);
        $this->assertCount(1, $switched);
        $this->assertSame(['Page 1', 'no_answer'], [$switched[0]['page'], $switched[0]['condition']]);
        $this->assertSame([], $this->events(ForbeastWatch::HELD));
        foreach ([2, 3, 4, 5, 6] as $n) $this->assertSame('gateway', $this->provider($n));
    }

    public function test_S_214_2_a_burst_on_five_pages_holds_instead_of_switching_with_one_held_event(): void
    {
        $this->burst();

        $this->assertSame('gateway', $this->provider(1));
        $held = $this->events(ForbeastWatch::HELD);
        $this->assertCount(1, $held);
        $this->assertSame(['no_answer', 'forbeast', 5], [$held[0]['condition'], $held[0]['gateway'], $held[0]['page_count']]);
        $this->assertNotEmpty($held[0]['at']);
        $this->assertStringContainsString('5 pages', $held[0]['error']);

        // Ang ibang page na umabot din sa tatlo habang hold: hindi lumilipat, walang bagong held, at padala pa rin sa Forbeast.
        $this->send(2, 2);
        $this->send(1);
        foreach ([1, 2, 3, 4, 5] as $n) $this->assertSame('gateway', $this->provider($n));
        $this->assertCount(1, $this->events(ForbeastWatch::HELD));
        $this->assertSame([], $this->events(ForbeastWatch::SWITCHED));
        Http::assertSentCount(10);
    }

    /** @return array<string, array{0: string}> */
    public static function recoveries(): array
    {
        return ['a sent message' => ['send'], 'a health probe that answers' => ['health']];
    }

    #[DataProvider('recoveries')]
    public function test_S_214_3_an_answer_from_an_affected_page_ends_the_hold_and_resets_the_counters(string $via): void
    {
        $this->burst();
        $this->send(2, 2);
        $this->travel(45)->seconds();
        $this->down[self::GW] = [];
        if ($via === 'send') $this->send(3); else app(ForbeastWatch::class)->health('Page 3');

        $recovered = $this->events(ForbeastWatch::RECOVERED);
        $this->assertCount(1, $recovered);
        $this->assertSame(['no_answer', 'forbeast', $via, 45], [$recovered[0]['condition'], $recovered[0]['gateway'], $recovered[0]['via'], $recovered[0]['held_seconds']]);
        $this->assertStringContainsString('45 s', $recovered[0]['error']);
        $this->assertSame([], $this->events(ForbeastWatch::SWITCHED));

        // Ang mga bilang ay nagsimula ulit: dalawang bagong walang sagot ay hindi pa lipat, at ang oras ng hold ay hindi na naglilipat.
        $this->down[self::GW] = ['2001', '2002'];
        $this->send(1, 2);
        $this->send(2, 2);
        $this->travel(3)->minutes();
        app(ForbeastWatch::class)->settleHolds();
        foreach ([1, 2, 3, 4, 5] as $n) $this->assertSame('gateway', $this->provider($n));
        $this->assertCount(1, $this->events(ForbeastWatch::RECOVERED));
    }

    public function test_S_214_3_a_probe_that_answers_while_sends_still_fail_gives_one_hold_then_pages_switch_as_today(): void
    {
        // Sumasagot ang GET pero hindi ang padala: ang health ang tumapos sa hold, at ang mga padalang pumapalya pa rin ay
        // lumilipat na gaya ng dati, walang pangalawang hold.
        $this->burst();
        $this->down[self::GW] = [];
        $this->sendsDown = ['2001', '2002', '2003', '2004', '2005'];
        $this->artisan('chatbot:forbeast-watch')->assertSuccessful();
        $this->assertCount(1, $this->events(ForbeastWatch::RECOVERED));

        // Ang naipadala ng page na wala sa burst ay hindi patunay para sa mga pumapalyang page.
        $this->send(6);
        foreach ([1, 2, 3, 4, 5] as $n) $this->send($n);
        $this->send(1, 2);
        $this->assertSame('botcake', $this->provider(1));
        $this->assertCount(1, $this->events(ForbeastWatch::HELD));

        // Ang naipadala ulit ay patunay: ang susunod na burst ay hold ulit.
        $this->sendsDown = [];
        $this->send(2);
        $this->sendsDown = ['2002', '2003', '2004', '2005', '2006'];
        $this->travel(61)->seconds();
        foreach ([2, 3, 4, 5, 6] as $n) $this->send($n);
        $this->send(2, 2);
        $this->assertSame('gateway', $this->provider(2));
        $this->assertCount(2, $this->events(ForbeastWatch::HELD));
    }

    public function test_S_214_3_a_probe_saying_the_page_is_not_connected_does_not_end_the_hold(): void
    {
        $this->burst();
        $this->down[self::GW] = [];
        $this->notConnected = ['2003'];
        app(ForbeastWatch::class)->health('Page 3');

        $this->assertSame([], $this->events(ForbeastWatch::RECOVERED));
        $this->down[self::GW] = ['2002'];
        $this->send(2, 2);
        $this->assertSame('gateway', $this->provider(2));
    }

    public function test_S_214_3_an_answer_from_a_page_outside_the_burst_does_not_end_the_hold(): void
    {
        $this->burst();
        $this->assertCount(1, $this->events(ForbeastWatch::HELD));

        // Ang page na walang palya sa burst ay hindi patunay na bumalik ang daan ng mga apektadong page.
        $this->onGateway('Page 7', '2007', 'forbeast');
        (new GatewayProvider())->send('Page 7', '2007', self::PSID, [['type' => 'text', 'text' => 'Hi']], ChatbotProvider::ref());

        $this->assertSame([], $this->events(ForbeastWatch::RECOVERED));
        $this->send(2, 2);
        $this->assertSame('gateway', $this->provider(2));
    }

    public function test_S_214_4_when_the_hold_ends_without_an_answer_every_page_that_reached_the_threshold_switches(): void
    {
        $this->burst();
        $this->send(2, 2);
        $this->travel(60)->seconds();
        $this->artisan('chatbot:forbeast-watch')->assertSuccessful();
        foreach ([1, 2, 3] as $n) $this->assertSame('gateway', $this->provider($n));

        $this->travel(61)->seconds();
        $this->artisan('chatbot:forbeast-watch')->assertSuccessful();

        $this->assertSame(['botcake', 'botcake', 'gateway'], [$this->provider(1), $this->provider(2), $this->provider(3)]);
        $switched = $this->events(ForbeastWatch::SWITCHED);
        $this->assertSame([['Page 1', 'no_answer', 'forbeast'], ['Page 2', 'no_answer', 'forbeast']],
            array_map(fn ($e) => [$e['page'], $e['condition'], $e['gateway']], $switched));
        $this->assertSame([], $this->events(ForbeastWatch::RECOVERED));
        // Ang parehong balik gaya ng dati: ang lipat na ito ay kusang ibinabalik.
        $row = PageSetting::where('page_name', 'Page 1')->where('key', 'auto_switch')->orderByDesc('id')->first();
        $this->assertTrue(ForbeastWatch::returnable($row));

        // Tuloy pa rin ang palya pagkatapos ng hold: ang page na umabot sa tatlo ngayon ay lumilipat agad, walang bagong hold.
        $this->send(3, 2);
        $this->assertSame('botcake', $this->provider(3));
        $this->assertCount(1, $this->events(ForbeastWatch::HELD));
    }

    public function test_S_214_4_the_first_no_answer_after_the_window_ends_the_hold_before_the_command_does(): void
    {
        $this->burst();
        $this->travel(121)->seconds();
        $this->send(2, 2);

        // Lahat ng umabot sa bilang (Page 1 sa hold, Page 2 ngayon) ay lumipat; ang Page 3 ay hindi umabot.
        $this->assertSame(['botcake', 'botcake', 'gateway'], [$this->provider(1), $this->provider(2), $this->provider(3)]);
        app(ForbeastWatch::class)->settleHolds();
        $this->assertCount(2, $this->events(ForbeastWatch::SWITCHED));

        // Ang sagot pagkatapos ng hold na nauwi sa lipat: walang "recovered" (may nailipat na).
        $this->down[self::GW] = [];
        $this->send(3);
        $this->assertSame([], $this->events(ForbeastWatch::RECOVERED));
    }

    public function test_S_214_5_refused_sends_with_an_answer_keep_the_failed_sends_rule_and_never_make_a_burst(): void
    {
        // 422 sa apat na page: hindi burst, kaya ang Page 1 na walang sagot nang tatlo ay lumilipat gaya ng dati.
        $this->refused = ['2002', '2003', '2004', '2005'];
        foreach ([2, 3, 4, 5] as $n) $this->send($n);
        $this->down[self::GW] = ['2001'];
        $this->send(1, 3);
        $this->assertSame('botcake', $this->provider(1));
        $this->assertSame([], $this->events(ForbeastWatch::HELD));

        // Habang may hold sa gateway: ang 422 nang lima ay lumilipat pa rin (failed_sends).
        $this->refused = [];
        $this->onGateway('Page 7', '2007', 'forbeast');
        $this->down[self::GW] = ['2002', '2003', '2004', '2005', '2006', '2007'];
        foreach ([2, 3, 4, 5, 6] as $n) $this->send($n);
        $this->send(2, 2);
        $this->assertCount(1, $this->events(ForbeastWatch::HELD));
        $this->down[self::GW] = ['2002', '2003', '2004', '2005', '2006'];
        $this->refused = ['2007'];
        for ($i = 0; $i < 5; $i++) (new GatewayProvider())->send('Page 7', '2007', self::PSID, [['type' => 'text', 'text' => 'Hi']], ChatbotProvider::ref());

        $this->assertSame('botcake', ChatbotProvider::name('Page 7'));
        $last = $this->events(ForbeastWatch::SWITCHED);
        $this->assertSame(['Page 7', 'failed_sends'], [end($last)['page'], end($last)['condition']]);
    }

    public function test_S_214_6_holds_are_per_gateway(): void
    {
        $this->onGateway('Page 7', '2007', 'incepxionbot');
        $this->burst();
        $this->down[self::IXB] = ['2007'];
        for ($i = 0; $i < 3; $i++) (new GatewayProvider())->send('Page 7', '2007', self::PSID, [['type' => 'text', 'text' => 'Hi']], ChatbotProvider::ref());

        $this->assertSame('botcake', ChatbotProvider::name('Page 7'));
        $this->assertSame('gateway', $this->provider(1));
        $switched = $this->events(ForbeastWatch::SWITCHED);
        $this->assertSame([['Page 7', 'incepxionbot']], array_map(fn ($e) => [$e['page'], $e['gateway']], $switched));
        $this->assertSame(['forbeast'], array_column($this->events(ForbeastWatch::HELD), 'gateway'));
    }

    public function test_S_214_7_a_cleared_cache_during_a_hold_resumes_it_and_never_skips_the_window(): void
    {
        $this->burst();
        $this->travel(30)->seconds();
        Cache::flush();

        // Lahat ng bilang ay nawala: tatlong bagong walang sagot sa dalawang page ay hold pa rin (ang held row ang batayan).
        $this->send(1, 3);
        $this->send(2, 3);
        foreach ([1, 2] as $n) $this->assertSame('gateway', $this->provider($n));
        $this->assertCount(1, $this->events(ForbeastWatch::HELD));

        // Ang hold key lang ang nawala (may bilang pa): ang susunod na walang sagot ay hold pa rin.
        Cache::forget('forbeast-watch:hold:forbeast');
        $this->send(1);
        $this->send(2);
        foreach ([1, 2] as $n) $this->assertSame('gateway', $this->provider($n));

        // Ang oras ay mula sa unang hold: sa 2 minuto mula roon, hindi mula sa flush.
        $this->travel(91)->seconds();
        app(ForbeastWatch::class)->settleHolds();
        $this->assertSame(['botcake', 'botcake'], [$this->provider(1), $this->provider(2)]);
        $this->assertCount(1, $this->events(ForbeastWatch::HELD));
    }

    public function test_S_214_8_the_window_and_burst_are_config_values_and_zero_pages_turns_the_hold_off(): void
    {
        $this->assertSame([2, 5, 60], [config('services.gateway.auto_switch.hold_minutes'), config('services.gateway.auto_switch.burst_pages'),
            config('services.gateway.auto_switch.burst_seconds')]);

        // Ang burst_seconds: ang walang sagot na mas luma sa window ay hindi kasama sa burst.
        $this->down[self::GW] = ['2001', '2002', '2003', '2004', '2005'];
        foreach ([2, 3, 4, 5] as $n) $this->send($n);
        $this->travel(61)->seconds();
        $this->send(1, 3);
        $this->assertSame('botcake', $this->provider(1));
        $this->assertSame([], $this->events(ForbeastWatch::HELD));

        // burst_pages 0: walang hold, gaya ng dati.
        config(['services.gateway.auto_switch.burst_pages' => 0]);
        foreach ([2, 3, 4, 5] as $n) $this->send($n, 2);
        $this->assertSame(['botcake', 'botcake', 'botcake', 'botcake'], [$this->provider(2), $this->provider(3), $this->provider(4), $this->provider(5)]);
        $this->assertSame([], $this->events(ForbeastWatch::HELD));

        // Ibang laki at haba: 2 page, hold nang 1 minuto.
        config(['services.gateway.auto_switch.burst_pages' => 2, 'services.gateway.auto_switch.hold_minutes' => 1]);
        $this->down[self::GW] = ['2006'];
        $this->onGateway('Page 7', '2007', 'forbeast');
        $this->down[self::GW][] = '2007';
        (new GatewayProvider())->send('Page 7', '2007', self::PSID, [['type' => 'text', 'text' => 'Hi']], ChatbotProvider::ref());
        $this->send(6, 3);
        $this->assertSame('gateway', $this->provider(6));
        $this->travel(61)->seconds();
        app(ForbeastWatch::class)->settleHolds();
        $this->assertSame('botcake', $this->provider(6));
    }

    public function test_S_214_9_the_feed_shows_the_held_and_recovered_events(): void
    {
        $this->burst();
        $this->travel(20)->seconds();
        $this->down[self::GW] = [];
        $this->send(4);

        $events = $this->getJson('/api/chatbot/provider-events?since=0', ['X-CHATBOT-EVENTS-KEY' => self::EVENTS_KEY])->assertOk()->json('events');
        $this->assertSame([['held', 'no_answer', 'forbeast', 5, null], ['recovered', 'no_answer', 'forbeast', 5, 20]],
            array_map(fn ($e) => [$e['result'], $e['condition'], $e['gateway'] ?? 'missing', $e['page_count'] ?? 'missing', $e['held_seconds'] ?? null], $events));
        $this->assertStringContainsString('did not answer', $events[0]['reason']);
        $this->assertStringContainsString('answered again', $events[1]['reason']);
    }

    /** Isang takbo ng chatbot:forbeast-watch (health ng bawat page, saka ang hold), $seconds mula sa nauna. */
    private function watch(int $seconds = 0): void
    {
        if ($seconds > 0) $this->travel($seconds)->seconds();
        $this->artisan('chatbot:forbeast-watch')->assertSuccessful();
    }

    public function test_S_214_10_health_failures_during_a_hold_do_not_switch_and_the_hold_end_switches_both_kinds_together(): void
    {
        // Ang hold ay mula sa padala; ang health ng lahat ng page (pati Page 6, wala sa burst) ay pumapalya nang tatlo at higit pa.
        $this->burst();
        $this->watch(30);
        $this->watch(30);
        $this->watch(30);
        foreach ([1, 2, 3, 4, 5, 6] as $n) $this->assertSame('gateway', $this->provider($n));

        $this->watch(31);
        foreach ([1, 2, 3, 4, 5, 6] as $n) $this->assertSame('botcake', $this->provider($n));
        $switched = $this->events(ForbeastWatch::SWITCHED);
        $this->assertSame(['Page 1' => 'no_answer', 'Page 2' => 'health', 'Page 3' => 'health', 'Page 4' => 'health', 'Page 5' => 'health', 'Page 6' => 'health'],
            collect($switched)->mapWithKeys(fn ($e) => [$e['page'] => $e['condition']])->sortKeys()->all());
        $this->assertTrue(collect($switched)->every(fn ($e) => ($e['held_seconds'] ?? 0) >= 120));
        $row = PageSetting::where('page_name', 'Page 4')->where('key', 'auto_switch')->orderByDesc('id')->first();
        $this->assertTrue(ForbeastWatch::returnable($row));
        $this->assertCount(1, $this->events(ForbeastWatch::HELD));
    }

    public function test_S_214_10_a_recovery_resets_the_health_counters_too(): void
    {
        $this->burst();
        $this->watch(30);
        $this->watch(30);
        $this->down[self::GW] = [];
        $this->send(3);
        $this->assertCount(1, $this->events(ForbeastWatch::RECOVERED));

        // Bago ang bawi ay dalawa na ang palya ng health ng Page 3; pagkatapos, dalawa pa lang ulit: hindi pa lipat.
        $this->down[self::GW] = ['2003'];
        $this->watch(30);
        $this->watch(30);
        $this->assertSame('gateway', $this->provider(3));
    }

    public function test_S_214_11_a_burst_of_failed_probes_starts_a_hold_and_one_page_alone_switches_as_today(): void
    {
        // Isang page lang ang pumapalya ang health: lipat sa ikatlo, gaya ng dati.
        $this->down[self::GW] = ['2006'];
        $this->watch();
        $this->watch(30);
        $this->assertSame('gateway', $this->provider(6));
        $this->watch(30);
        $this->assertSame('botcake', $this->provider(6));
        $this->assertSame([], $this->events(ForbeastWatch::HELD));

        // Lahat ng page ay pumapalya ang health (walang padala): hold sa halip na lipat.
        $this->travel(5)->minutes();
        $this->down[self::GW] = ['2001', '2002', '2003', '2004', '2005'];
        $this->watch();
        $this->watch(30);
        $this->watch(30);
        foreach ([1, 2, 3, 4, 5] as $n) $this->assertSame('gateway', $this->provider($n));
        $held = $this->events(ForbeastWatch::HELD);
        $this->assertCount(1, $held);
        $this->assertSame(['forbeast', 5, 'health'], [$held[0]['gateway'], $held[0]['page_count'], $held[0]['trigger']]);

        // Hindi bumalik: sa dulo ng hold, lahat ng umabot ay lumilipat nang sabay.
        $this->watch(60);
        $this->watch(61);
        foreach ([1, 2, 3, 4, 5] as $n) $this->assertSame('botcake', $this->provider($n));
    }
}
