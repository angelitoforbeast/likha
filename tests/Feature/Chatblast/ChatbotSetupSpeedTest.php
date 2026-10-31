<?php

namespace Tests\Feature\Chatblast;

use App\Services\BotcakeClient;
use App\Services\PageSettings;
use App\Support\BotcakeClock;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Botcake\FakeBotcakeClock;

/**
 * Ang bilis ng setup ng isang page sa Connections (Test, Save and test, Fix chat block, On/Off): bawat tawag sa fake na
 * Botcake ay 500 ms (sagot na dumarating 500 ms matapos ipadala, kaya ang sabay na tawag ay nagsasapawan at ang
 * sunod-sunod ay nagdaragdag), at ang orasan ng client ay ang totoong oras kasama ang bawat "hintay" (hindi talaga
 * naghihintay). Ang inaasahang JSON at mga naitala ay mula sa takbo ng lumang code sa parehong fakes (snapshots/207).
 */
class ChatbotSetupSpeedTest extends ChatblastTestCase
{
    private const BC = 'https://botcake.io/api/public_api/v1/pages/1001';
    private const LATENCY = 0.5;
    private const KEY = 'kept-chat-key-123';
    private const NEW_TOKEN = 'tok-botcake-new-test-9z8y7x';

    /** @var array<int, array{0: string, 1: string, 2: float}> [method, path, oras na ipinadala] */
    private array $log = [];

    /** Mga sagot na kakaiba: "METHOD path" => listahan ng [status, body, headers] (isa kada tawag; ang huli ay inuulit). */
    private array $odd = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-31 10:00:00', 'Asia/Manila'));
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_10_20_100000_create_botcake_chats_table.php']]);
        config(['app.url' => 'https://app.example.test/']);
        $this->page('Page Uno', '1001');
        app(PageSettings::class)->set('Page Uno', 'botcake', 'chat.key', self::KEY, true, null, true);
        $this->actingAs($this->user());

        // Ang now() ay sumusunod sa totoong oras (ang 500 ms ng tawag ay kita sa rate), at ang sleep ay idinadagdag lang.
        $this->clock = new class extends FakeBotcakeClock {
            public float $wall0;
            public function now(): float
            {
                return $this->time + (microtime(true) - $this->wall0);
            }
        };
        $this->clock->wall0 = microtime(true);
        $this->app->instance(BotcakeClock::class, $this->clock);

        Http::fake(function (HttpRequest $r) {
            $path = substr(parse_url($r->url(), PHP_URL_PATH), strlen(parse_url(self::BC, PHP_URL_PATH)));
            $this->log[] = [$r->method(), $path, $this->clock->now()];
            $odd = $this->odd[$r->method() . ' ' . $path] ?? null;
            if ($odd) {
                [$status, $body, $headers] = count($odd) > 1 ? array_shift($this->odd[$r->method() . ' ' . $path]) : $odd[0];
                return $this->slow($status, $body, $headers);
            }

            return $this->slow(200, match ($r->method() . ' ' . $path) {
                'GET /custom_fields' => ['success' => true, 'data' => [['id' => 7701, 'name' => 'message']]],
                'GET /default_reply/content' => ['name' => 'Default reply', 'status' => 'published', 'blocks' => [['key' => 'chat_to_site', 'cards' => [[
                    'plugin_id' => 'json_api', 'config' => ['method' => 'post', 'url' => 'https://app.example.test/api/botcake/chat'],
                    'headers' => [['key' => 'X-Chat-Key', 'value' => self::KEY], ['key' => '', 'value' => '']]]]]]],
                'GET /default_reply' => ['success' => true, 'settings' => ['is_published' => true, 'unlimit_default_reply' => true], 'page_id' => '1001'],
                'GET /keywords' => ['data' => [['id' => 1, 'is_activated' => true, 'flow_id' => 424398467], ['id' => 2, 'is_activated' => false, 'flow_id' => 1415244560]], 'success' => true],
                'GET /flows' => ['data' => ['flows' => [['id' => 424398467, 'name' => 'ON DELIVERY SCRIPT '], ['id' => 1415244560, 'name' => 'Old Promo']]], 'success' => true],
                default => ['success' => true], // ang mga POST (isulat ang block, i-publish o i-unpublish)
            });
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Isang sagot na dumarating LATENCY segundo matapos ipadala: ang paghihintay ay hanggang sa oras na iyon lang. Ang
     * fake ng Laravel ay tumatawag agad ng wait() para sa on_stats habang ipinapadala pa; ang unang wait() na iyon ay
     * ibinabalik ang sagot nang walang hintay, para hindi maging sunod-sunod ang sabay na mga tawag (Http::pool).
     */
    private function slow(int $status, array $body, array $headers = []): PromiseInterface
    {
        $at = microtime(true) + self::LATENCY;
        $response = new PsrResponse($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body));
        $inner = new Promise(function () use (&$inner, $at, $response) {
            $left = $at - microtime(true);
            if ($left > 0) usleep((int) ceil($left * 1_000_000));
            $inner->resolve($response);
        });

        return new class($inner, $response) implements PromiseInterface {
            private bool $stats = true;
            public function __construct(private Promise $inner, private PsrResponse $response) {}
            public function then(?callable $onFulfilled = null, ?callable $onRejected = null): PromiseInterface { return $this->inner->then($onFulfilled, $onRejected); }
            public function otherwise(callable $onRejected): PromiseInterface { return $this->inner->otherwise($onRejected); }
            public function getState(): string { return $this->inner->getState(); }
            public function resolve($value): void { $this->inner->resolve($value); }
            public function reject($reason): void { $this->inner->reject($reason); }
            public function cancel(): void { $this->inner->cancel(); }
            public function wait(bool $unwrap = true)
            {
                if ($this->stats) { $this->stats = false; return $this->response; }

                return $this->inner->wait($unwrap);
            }
        };
    }

    /** Ang aksyon at ilang segundo ito (totoong oras at ang mga hintay ng client). @return array{0: \Illuminate\Testing\TestResponse, 1: float} */
    private function timed(callable $action): array
    {
        $start = $this->clock->now();
        $res = $action();

        return [$res, $this->clock->now() - $start];
    }

    private function test(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/chatbot/connections/botcake-test', ['page_name' => 'Page Uno']);
    }

    private function save(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/chatbot/connections/botcake', ['page_name' => 'Page Uno', 'page_id' => '1001', 'token' => self::NEW_TOKEN]);
    }

    private function fix(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/chatbot/connections/chat-block', ['page_name' => 'Page Uno']);
    }

    /** Ang mga naitala ng Connections (Botcake status at ang On/Off), gaya ng nakikita ng Changes. */
    private function stored(): array
    {
        $s = app(PageSettings::class);

        return ['token_status' => $s->get('Page Uno', 'botcake', 'token_status'), 'chat_block' => $s->get('Page Uno', 'botcake', 'chat_block'),
            'keywords' => $s->get('Page Uno', 'botcake', 'keywords'), 'active' => $s->get('Page Uno', 'chatbot', 'active'), 'chat_key' => $s->get('Page Uno', 'botcake', 'chat.key')];
    }

    /** Ang JSON at ang mga naitala ay katumbas ng takbo ng lumang code (snapshots/207/<name>.json). */
    private function assertSameAsBefore(string $name, array $json): void
    {
        $file = __DIR__ . '/snapshots/207/' . $name . '.json';
        $now = ['json' => $json, 'stored' => $this->stored()];
        if (getenv('WRITE_207_SNAPSHOTS') === '1') {
            @mkdir(dirname($file), 0777, true);
            file_put_contents($file, json_encode($now, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        }
        $this->assertFileExists($file);
        $this->assertSame(json_decode((string) file_get_contents($file), true), json_decode(json_encode($now), true), $name);
    }

    /** @return array<int, string> "METHOD path" ng bawat tawag, sa pagkakasunod na ipinadala */
    private function calls(): array
    {
        return array_map(fn ($c) => $c[0] . ' ' . $c[1], $this->log);
    }

    private function times(string $call): int
    {
        return count(array_filter($this->calls(), fn ($c) => $c === $call));
    }

    private function note(string $what, float $seconds): void
    {
        if (getenv('SHOW_207_TIMINGS') === '1') fwrite(STDERR, sprintf("\n[207] %s: %.2f s\n", $what, $seconds));
    }

    public function test_S_207_1_test_finishes_in_one_round_trip_and_stores_the_same_statuses(): void
    {
        [$res, $took] = $this->timed(fn () => $this->test());
        $this->note('Test', $took);

        $res->assertOk();
        $this->assertSameAsBefore('test', $res->json());
        $this->assertLessThan(1.2, $took, 'Test took ' . round($took, 2) . ' s');
    }

    public function test_S_207_2_save_and_fix_write_in_order_read_the_default_reply_once_and_answer_as_before(): void
    {
        [$res, $took] = $this->timed(fn () => $this->save());
        $this->note('Save and test', $took);
        $res->assertOk();
        $this->assertSameAsBefore('save', $res->json());
        $this->assertSame(1, $this->times('GET /default_reply/content'), 'Save reads the Default Reply once');
        $this->assertSame(0, $this->times('GET /default_reply'));
        $writes = array_values(array_filter($this->calls(), fn ($c) => str_starts_with($c, 'POST')));
        $this->assertSame(['POST /default_reply/content', 'POST /default_reply'], $writes, 'content before publish');
        $saveTook = $took;

        $this->log = [];
        [$res, $took] = $this->timed(fn () => $this->fix());
        $this->note('Fix chat block', $took);
        $res->assertOk();
        $this->assertSameAsBefore('fix', $res->json());
        $this->assertSame(['GET /default_reply/content', 'POST /default_reply/content', 'POST /default_reply'], $this->calls(), 'one read, then content before publish');
        // Ang dalawang sulat ay hindi sabay: ang publish ay ipinapadala pagkatapos dumating ang sagot ng content.
        $this->assertGreaterThanOrEqual(self::LATENCY - 0.01, $this->log[2][2] - $this->log[1][2]);
        $this->assertLessThan(1.7, $saveTook, 'Save and test took ' . round($saveTook, 2) . ' s');
    }

    /** @return array<string, array{0: string}> */
    public static function setupActions(): array
    {
        return ['Test' => ['test'], 'Save and test' => ['save'], 'Fix chat block' => ['fix'], 'On' => ['on'], 'Off' => ['off']];
    }

    #[DataProvider('setupActions')]
    public function test_S_207_3_a_busy_send_lane_does_not_slow_setup_and_setup_takes_none_of_its_slots(string $action): void
    {
        // Ang mga padala ng page ay may puwesto na hanggang 4 segundo mula ngayon (ang pool at ang chatblast).
        $lane = BotcakeClient::rateCacheKey(sha1(self::TOKEN));
        $busy = $this->clock->now() + 4.0;
        Cache::put($lane, $busy, 60);

        [$res, $took] = $this->timed(fn () => match ($action) {
            'test' => $this->test(), 'save' => $this->postJson('/chatbot/connections/botcake', ['page_name' => 'Page Uno', 'page_id' => '1001', 'token' => self::TOKEN]), 'fix' => $this->fix(),
            'on' => $this->postJson('/chatbot/active', ['page_name' => 'Page Uno', 'on' => true]), 'off' => $this->postJson('/chatbot/active', ['page_name' => 'Page Uno', 'on' => false]),
        });
        $this->note($action . ' with a busy send lane', $took);

        $res->assertOk();
        $this->assertLessThan(0.01, array_sum($this->clock->sleeps), $action . ' waited ' . round(array_sum($this->clock->sleeps), 2) . ' s for the sends');
        $this->assertSame($busy, Cache::get($lane), 'the sends keep their slots');
    }

    /** @return array<string, array{0: string, 1: array, 2: string}> [action, kakaibang sagot, snapshot] */
    public static function failures(): array
    {
        return [
            'Test: 429 on the keywords read' => ['test', ['GET /keywords' => [[429, ['success' => false, 'message' => 'Too many requests'], []]]], 'test-keywords-429'],
            'Test: 429 on the Default Reply read' => ['test', ['GET /default_reply/content' => [[429, ['success' => false], ['Retry-After' => '1']]]], 'test-content-429'],
            'Test: 500 on the token read' => ['test', ['GET /custom_fields' => [[500, ['success' => false], []]]], 'test-token-500'],
            'Save: 429 on the flows read' => ['save', ['GET /flows' => [[429, ['success' => false], []]]], 'save-flows-429'],
            'Save: 429 on the token read' => ['save', ['GET /custom_fields' => [[429, ['success' => false], []]]], 'save-token-429'],
            'Save: 429 on the Default Reply read' => ['save', ['GET /default_reply/content' => [[429, ['success' => false], []]]], 'save-content-429'],
            'Fix: 429 on the publish, then ok' => ['fix', ['POST /default_reply' => [[429, ['success' => false], []], [200, ['success' => true], []]]], 'fix-publish-429'],
        ];
    }

    #[DataProvider('failures')]
    public function test_S_207_4_a_429_or_failed_read_is_handled_as_before_and_the_other_reads_are_stored(string $action, array $odd, string $snapshot): void
    {
        $this->odd = $odd;
        $res = match ($action) { 'test' => $this->test(), 'save' => $this->save(), 'fix' => $this->fix() };

        $this->assertSameAsBefore($snapshot, ['http' => $res->status()] + $res->json());
        // Ilang beses tinawag ang may problema (ang read ng Test ay isang subok lang; ang sulat ay inuulit sa 429).
        $call = array_key_first($odd);
        $this->assertSame(['fix-publish-429' => 2, 'test-token-500' => 1][$snapshot] ?? 1, $this->times($call), $call . ' calls');
        if ($snapshot === 'fix-publish-429') $this->assertContains(2.0, $this->clock->sleeps, 'the 429 on a write waits 2 s, then retries');
    }
}
