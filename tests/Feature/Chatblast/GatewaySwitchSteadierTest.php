<?php

namespace Tests\Feature\Chatblast;

use App\Models\PageSetting;
use App\Services\Chatbot\BotcakeOutbox;
use App\Services\Chatbot\ChatbotProvider;
use App\Services\Chatbot\CpuSteal;
use App\Services\Chatbot\ForbeastPageSetup;
use App\Services\Chatbot\ForbeastWatch;
use App\Services\Chatbot\GatewayProvider;
use App\Services\Chatbot\ProblemsPanel;
use App\Services\PageSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Spec 273: the gateway switch ignores one stuck connection (same-burst failures count once, strikes must span minutes,
 * connect-phase failures are tried once more) and our own server's network blips (Botcake failing too, or the probe
 * failing, holds the page), and takes one live look at the gateway before a no_answer switch. Plus the CPU steal line.
 * Fake HTTP and a faked clock only.
 */
class GatewaySwitchSteadierTest extends ChatblastTestCase
{
    private const GW = 'https://gw.example.test';

    private const GW_SEND = self::GW.'/v1/pages/1001/messages';

    private const GW_PAGE = self::GW.'/v1/pages/1001';

    private const PSID = '6012345678901234';

    private const READ_TIMEOUT = 'cURL error 28: Operation timed out after 11800 milliseconds with 0 bytes received';

    private const CONNECT_FAIL = 'cURL error 7: Failed to connect to gw.example.test port 443 after 3 ms: Couldn\'t connect to server';

    private const PROBE = 'https://probe.example.test/';

    /** What the gateway does to a send: 'ok', 'timeout' (read timeout), 'connect' (connect-phase failure). */
    private array $sends = [];

    /** What the health GET answers: 'ok', 'down'. */
    private string $health = 'down';

    private bool $probeDown = false;

    /** Requests per URL, counted in the fake (a thrown connection error is not in Http::recorded). */
    private array $hits = [];

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
            'services.gateway.url' => self::GW, 'services.gateway.key' => 'gw_test_key', 'services.gateway.timeout' => 10,
            // The spec defaults, set here so the test does not depend on the environment.
            'services.gateway.auto_switch.no_answer' => 3, 'services.gateway.auto_switch.burst_window_seconds' => 15,
            'services.gateway.auto_switch.no_answer_min_seconds' => 180, 'services.gateway.auto_switch.connect_retry_ms' => 2000,
            'services.gateway.auto_switch.server_side_min' => 5, 'services.gateway.auto_switch.server_side_pct' => 20,
            'services.gateway.auto_switch.server_probe_url' => self::PROBE, 'services.gateway.auto_switch.steal_alert_percent' => 5,
            'services.gateway.auto_switch.recheck_max_holds' => 1,
        ]);
        $this->page('Page Uno', '1001');
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'provider', 'gateway');
        PageSetting::where('provider', 'chatbot')->where('key', 'provider')->update(['created_at' => now()->subHours(3)]);
        Sleep::fake();
        Http::fake(function (HttpRequest $r) {
            $this->hits[$r->url()] = ($this->hits[$r->url()] ?? 0) + 1;
            if ($r->url() === self::PROBE) {
                if ($this->probeDown) {
                    throw new ConnectionException('cURL error 6: Could not resolve host: probe.example.test');
                }

                return Http::response('', 200);
            }
            if ($r->url() === self::GW_PAGE) {
                return $this->health === 'ok' ? Http::response(['id' => '1001', 'connected' => true, 'can_send' => true]) : Http::response('', 503);
            }
            if ($r->url() === self::GW_SEND) {
                $what = array_shift($this->sends) ?? 'timeout';
                if ($what === 'timeout') {
                    throw new ConnectionException(self::READ_TIMEOUT);
                }
                if ($what === 'connect') {
                    throw new ConnectionException(self::CONNECT_FAIL);
                }
                if ($what === '400') {
                    return Http::response(['message' => 'Bad request'], 400);
                }

                return Http::response(['ok' => true]);
            }

            return null;
        });
    }

    private function send(): array
    {
        return (new GatewayProvider)->send('Page Uno', '1001', self::PSID, [['type' => 'text', 'text' => 'Hi']], ChatbotProvider::ref());
    }

    private function provider(): string
    {
        return ChatbotProvider::name('Page Uno');
    }

    /** @return array<int, array> the auto-switch events of the page, oldest first */
    private function events(): array
    {
        return PageSetting::where('provider', 'chatbot')->where('key', 'auto_switch')->orderBy('id')->get()
            ->map(fn ($s) => json_decode((string) $s->plainValue(), true))->all();
    }

    private function sendCount(): int
    {
        return $this->hits[self::GW_SEND] ?? 0;
    }

    /** Three strikes of no answer 0, 90 and 180 s apart (one send each). */
    private function threeStrikes(): void
    {
        $this->send();
        $this->travel(90)->seconds();
        $this->send();
        $this->travel(90)->seconds();
        $this->send();
    }

    private function withPageToken(): void
    {
        app(PageSettings::class)->set('Page Uno', ForbeastPageSetup::PROVIDER, 'token', 'fbp_page_uno_token', true, null, true);
        app(PageSettings::class)->set('Page Uno', ForbeastPageSetup::PROVIDER, 'token_status', 'ok · connected', false, null, true);
    }

    public function test_s1_three_timeouts_in_the_same_second_count_once_and_do_not_switch(): void
    {
        $this->freezeTime();
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame('failed', $this->send()['status']);
        }

        $this->assertSame('gateway', $this->provider());
        $this->assertSame([], $this->events());
        // Even five failures of the same burst stay one strike, also for the failed-sends count.
        $this->send();
        $this->send();
        $this->assertSame('gateway', $this->provider());
    }

    public function test_s1_three_strikes_inside_three_minutes_do_not_switch_but_a_strike_after_three_minutes_does(): void
    {
        $this->send();
        $this->travel(20)->seconds();
        $this->send();
        $this->travel(20)->seconds();
        $this->send();
        $this->assertSame('gateway', $this->provider(), 'three strikes within 40 s: not yet minutes of failures');

        $this->travel(150)->seconds();
        $this->send();
        $this->assertSame('botcake', $this->provider());
        $this->assertSame(['switched', 'no_answer'], [$this->events()[0]['result'], $this->events()[0]['condition']]);
    }

    public function test_s1_three_strikes_over_three_minutes_switch(): void
    {
        $this->threeStrikes();

        $this->assertSame('botcake', $this->provider());
        $this->assertCount(1, $this->events());
        $this->assertSame('no_answer', $this->events()[0]['condition']);
    }

    public function test_s1_a_success_resets_the_strikes(): void
    {
        $this->sends = ['timeout', 'ok', 'timeout', 'timeout'];
        $this->send();
        $this->travel(90)->seconds();
        $this->assertSame('sent', $this->send()['status']);
        $this->travel(90)->seconds();
        $this->send();
        $this->travel(90)->seconds();
        $this->send();

        $this->assertSame('gateway', $this->provider(), 'only two strikes since the success');
    }

    public function test_s2_a_connect_phase_failure_is_tried_once_more_and_a_success_is_no_strike(): void
    {
        $this->sends = ['connect', 'ok'];

        $o = $this->send();

        $this->assertSame('sent', $o['status']);
        $this->assertSame(2, $this->sendCount());
        Sleep::assertSleptTimes(1);
        Sleep::assertSlept(fn ($d) => (int) $d->totalMilliseconds === 2000);
    }

    public function test_s2_a_connect_phase_failure_twice_counts_as_one_failure(): void
    {
        $this->sends = ['connect', 'connect'];

        $o = $this->send();

        $this->assertSame('failed', $o['status']);
        $this->assertSame(2, $this->sendCount(), 'one retry only');
        $this->assertStringContainsString('cURL error 7', $o['error']);
    }

    public function test_s2_a_read_timeout_is_not_retried(): void
    {
        $this->sends = ['timeout', 'ok'];

        $this->assertSame('failed', $this->send()['status']);
        $this->assertSame(1, $this->sendCount());
        Sleep::assertNeverSlept();
    }

    public function test_s2_a_4xx_is_never_retried(): void
    {
        $this->sends = ['400', 'ok'];

        $this->send();

        $this->assertSame(1, $this->sendCount());
    }

    public function test_s2_only_failures_before_the_request_left_count_as_connect_phase(): void
    {
        $d = fn (int $n, string $e) => ['curl_errno' => $n, 'curl_error' => $e];
        $this->assertTrue(GatewayProvider::connectPhase($d(6, 'Could not resolve host: gw.example.test')));
        $this->assertTrue(GatewayProvider::connectPhase($d(7, 'Failed to connect to gw.example.test port 443')));
        $this->assertTrue(GatewayProvider::connectPhase($d(28, 'Connection timed out after 5001 milliseconds')));
        $this->assertTrue(GatewayProvider::connectPhase($d(28, 'Resolving timed out after 5000 milliseconds')));
        $this->assertFalse(GatewayProvider::connectPhase($d(28, 'Operation timed out after 11800 milliseconds with 0 bytes received')));
        $this->assertFalse(GatewayProvider::connectPhase($d(56, 'Recv failure: Connection reset by peer')));
        $this->assertFalse(GatewayProvider::connectPhase(['curl_errno' => null, 'curl_error' => null]));
    }

    public function test_s3_botcake_failing_too_holds_the_page_on_the_gateway(): void
    {
        $this->send();
        $this->travel(90)->seconds();
        $this->send();
        $this->travel(90)->seconds();
        // Our own server cannot reach Botcake either: 5 Botcake sends without an HTTP answer in the last 2 minutes.
        for ($i = 0; $i < 5; $i++) {
            BotcakeOutbox::outcome(['http' => 0, 'body' => null]);
        }
        $this->send();

        $this->assertSame('gateway', $this->provider());
        $e = $this->events();
        $this->assertCount(1, $e);
        $this->assertSame(['held', 'no_answer', 'server_side'], [$e[0]['result'], $e[0]['condition'], $e[0]['held']]);
        $this->assertStringContainsString('server-side network problem', $e[0]['error']);
        $this->assertStringContainsString('Botcake: 5 of 5 sends got no answer', $e[0]['error']);
        $this->assertArrayNotHasKey(self::PROBE, $this->hits, 'the Botcake count alone decides; no probe');

        // Re-evaluated on the next failure: once Botcake answers again (the window passed), the page switches.
        $this->travel(3)->minutes();
        $this->send();
        $this->assertSame('botcake', $this->provider());
    }

    public function test_s3_a_fifth_of_botcake_sends_without_an_answer_is_a_server_side_problem(): void
    {
        $watch = app(ForbeastWatch::class);
        for ($i = 0; $i < 4; $i++) {
            BotcakeOutbox::outcome(['http' => 200, 'body' => ['success' => true]]);
        }
        BotcakeOutbox::outcome(['http' => 0, 'body' => null]);
        $this->assertSame('Botcake: 1 of 5 sends got no answer in 2 min', $watch->serverSide());

        $this->travel(3)->minutes();
        for ($i = 0; $i < 9; $i++) {
            BotcakeOutbox::outcome(['http' => 200, 'body' => ['success' => true]]);
        }
        BotcakeOutbox::outcome(['http' => 0, 'body' => null]);
        $this->assertNull($watch->serverSide(), '1 of 10 is below 20% and the probe answers');
    }

    public function test_s3_a_failing_probe_holds_the_page(): void
    {
        $this->probeDown = true;

        $this->threeStrikes();

        $this->assertSame('gateway', $this->provider());
        $e = $this->events();
        $this->assertSame(['held', 'server_side'], [$e[0]['result'], $e[0]['held']]);
        $this->assertStringContainsString('probe.example.test did not answer either', $e[0]['error']);
        $this->assertSame(1, $this->hits[self::PROBE] ?? 0);
    }

    public function test_s4_the_gateway_answering_on_the_recheck_holds_and_resets_the_strikes(): void
    {
        $this->withPageToken();
        $this->health = 'ok';

        $this->threeStrikes();

        $this->assertSame('gateway', $this->provider());
        $e = $this->events();
        $this->assertCount(1, $e);
        $this->assertSame(['held', 'no_answer', 'recheck'], [$e[0]['result'], $e[0]['condition'], $e[0]['held']]);
        $this->assertStringContainsString('answered on re-check', $e[0]['error']);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::GW_PAGE && $r->hasHeader('Authorization', 'Bearer fbp_page_uno_token'));

        // The strikes start over: two more strikes are not enough.
        $this->travel(90)->seconds();
        $this->send();
        $this->travel(90)->seconds();
        $this->send();
        $this->assertSame('gateway', $this->provider());
        $this->assertCount(1, $this->events());

        // Sends still stall while the page GET answers: the live look held once (recheck_max_holds 1), so the next run of
        // strikes switches; a stalled send path is still left.
        $this->travel(90)->seconds();
        $this->send();
        $this->assertSame('botcake', $this->provider());
        $this->assertSame(['switched', 'no_answer'], [$this->events()[1]['result'], $this->events()[1]['condition']]);
    }

    public function test_s4_a_send_that_gets_through_gives_the_live_look_back(): void
    {
        $this->withPageToken();
        $this->health = 'ok';
        $this->threeStrikes();
        $this->assertSame('held', $this->events()[0]['result']);

        $this->sends = ['ok'];
        $this->travel(30)->seconds();
        $this->assertSame('sent', $this->send()['status']);
        $this->travel(30)->seconds();
        $this->threeStrikes();

        $this->assertSame('gateway', $this->provider());
        $this->assertSame(['held', 'held'], array_column($this->events(), 'result'));
    }

    public function test_s4_the_gateway_failing_the_recheck_switches(): void
    {
        $this->withPageToken();
        $this->health = 'down';

        $this->threeStrikes();

        $this->assertSame('botcake', $this->provider());
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::GW_PAGE);
    }

    public function test_s5_the_health_check_switch_also_passes_the_server_side_check(): void
    {
        $this->withPageToken();
        $this->health = 'down';
        $this->artisan('chatbot:forbeast-watch')->assertSuccessful();
        $this->travel(1)->minutes();
        $this->artisan('chatbot:forbeast-watch')->assertSuccessful();
        $this->travel(1)->minutes();
        for ($i = 0; $i < 5; $i++) {
            BotcakeOutbox::outcome(['http' => 0, 'body' => null]);
        }
        $this->artisan('chatbot:forbeast-watch')->assertSuccessful();

        $this->assertSame('gateway', $this->provider());
        $this->assertSame(['held', 'health', 'server_side'], [$this->events()[0]['result'], $this->events()[0]['condition'], $this->events()[0]['held']]);

        $this->travel(3)->minutes();
        $this->artisan('chatbot:forbeast-watch')->assertSuccessful();
        $this->assertSame('botcake', $this->provider());
        $this->assertSame(['switched', 'health'], [$this->events()[1]['result'], $this->events()[1]['condition']]);
    }

    public function test_s5_a_page_without_a_botcake_token_still_gets_cannot_switch(): void
    {
        PageSetting::where('provider', 'botcake')->where('key', 'token')->delete();

        $this->threeStrikes();

        $this->assertSame('gateway', $this->provider());
        $this->assertSame('cannot_switch', $this->events()[0]['result']);
    }

    public function test_s5_switch_back_after_thirty_healthy_minutes_is_unchanged(): void
    {
        $this->withPageToken();
        $this->threeStrikes();
        $this->assertSame('botcake', $this->provider());

        $this->health = 'ok';
        for ($m = 0; $m <= 34; $m += 2) {
            $this->artisan('chatbot:forbeast-watch')->assertSuccessful();
            if ($this->provider() === 'gateway') {
                break;
            }
            $this->travel(2)->minutes();
        }

        $this->assertSame('gateway', $this->provider());
        $this->assertSame(30, $m, 'back after 30 healthy minutes, not before');
        $events = $this->events();
        $this->assertSame('switched_back', end($events)['result'] ?? null);
    }

    public function test_s5_a_burst_on_five_pages_still_holds_and_no_page_switches(): void
    {
        $this->freezeTime();
        for ($i = 2; $i <= 5; $i++) {
            $this->page('Page '.$i, (string) (1000 + $i));
            app(PageSettings::class)->set('Page '.$i, 'chatbot', 'provider', 'gateway');
        }
        Http::fake([self::GW.'/*' => Http::failedConnection('cURL error 28: Operation timed out after 10000 milliseconds with 0 bytes received')]);
        // Strikes 0, 90, 150 and 180 s apart on every page (an outage fails the pages all along, so all five are in the
        // burst window when the first one reaches its count at 180 s).
        foreach ([0, 90, 60, 30] as $wait) {
            $this->travel($wait)->seconds();
            foreach (['Page Uno' => '1001', 'Page 2' => '1002', 'Page 3' => '1003', 'Page 4' => '1004', 'Page 5' => '1005'] as $page => $id) {
                (new GatewayProvider)->send($page, $id, self::PSID, [['type' => 'text', 'text' => 'Hi']], ChatbotProvider::ref());
            }
        }

        foreach (['Page Uno', 'Page 2', 'Page 3', 'Page 4', 'Page 5'] as $page) {
            $this->assertSame('gateway', ChatbotProvider::name($page));
        }
        $held = array_values(array_filter($this->events(), fn ($e) => ($e['result'] ?? null) === 'held'));
        $this->assertCount(1, $held);
        $this->assertArrayNotHasKey('held', $held[0], 'the burst hold, not a spec 273 hold');
        $this->assertSame(5, $held[0]['page_count']);
    }

    public function test_s6_the_steal_command_stores_the_sample_and_the_panel_shows_the_line_above_the_threshold(): void
    {
        $this->app->instance(CpuSteal::class, $this->stealReader([[1000, 100], [2000, 180]])); // 80 of 1000 jiffies = 8%
        $this->artisan('server:cpu-steal')->expectsOutputToContain('CPU steal 8%')->assertSuccessful();

        $this->assertSame(8.0, CpuSteal::hourAverage());
        $this->assertCount(1, Cache::get(CpuSteal::CACHE));
        Sleep::assertSlept(fn ($d) => (int) $d->totalSeconds === 5);
        $line = $this->stealLine();
        $this->assertNotNull($line);
        $this->assertSame('yellow', $line['level']);
        $this->assertSame("Server CPU steal 8.0% in the last hour (the hosting provider's machine is overloaded; replies and sends may slow down)", $line['text']);
        $this->assertSame(0, app(ProblemsPanel::class)->panel()['urgent'], 'a yellow line is not urgent');

        // A low sample brings the hour's average to 5%: not above the threshold, no line.
        $this->travel(5)->minutes();
        $this->app->instance(CpuSteal::class, $this->stealReader([[3000, 300], [4000, 320]])); // 2%
        $this->artisan('server:cpu-steal')->assertSuccessful();
        $this->assertSame(5.0, CpuSteal::hourAverage());
        $this->assertNull($this->stealLine());

        // Samples older than an hour no longer count.
        $this->travel(61)->minutes();
        $this->assertNull(CpuSteal::hourAverage());
    }

    public function test_s6_an_unreadable_proc_stat_stores_nothing_and_shows_no_line(): void
    {
        $this->app->instance(CpuSteal::class, $this->stealReader([null, null]));

        $this->artisan('server:cpu-steal')->expectsOutputToContain('cannot be read')->assertSuccessful();

        $this->assertNull(Cache::get(CpuSteal::CACHE));
        $this->assertNull($this->stealLine());
    }

    /** A CpuSteal whose /proc/stat reads are the given [total, steal] pairs (null = unreadable). */
    private function stealReader(array $reads): CpuSteal
    {
        return new class($reads) extends CpuSteal
        {
            public function __construct(private array $reads) {}

            public function read(): ?array
            {
                return array_shift($this->reads);
            }
        };
    }

    private function stealLine(): ?array
    {
        foreach (app(ProblemsPanel::class)->panel()['pages'] ?? [] as $p) {
            foreach ($p['lines'] as $l) {
                if (str_starts_with($l['text'], 'Server CPU steal')) {
                    return $l;
                }
            }
        }

        return null;
    }
}
