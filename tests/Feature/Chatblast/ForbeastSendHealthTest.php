<?php

namespace Tests\Feature\Chatblast;

use App\Models\PageSetting;
use App\Services\Chatbot\ChatbotProvider;
use App\Services\Chatbot\ForbeastSendHealth;
use App\Services\PageSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/**
 * Spec 155: ang send health ng Forbeast (GET /v1/health/sends) sa Bot Health, kada minuto mula sa forbeast-watch, at ang
 * alarm sa send lag (event sa chatbot.auto_switch, walang lipat). Fake na HTTP lang.
 */
class ForbeastSendHealthTest extends ChatblastTestCase
{
    private const GW = 'https://gw.example.test';
    private const GW_KEY = 'gw_test_key_155_q1w2e3';
    private const SENDS = self::GW . '/v1/health/sends';

    /** Ang sagot ng Forbeast: sunod-sunod (bawat takbo ng watch ay isang item). */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_28_100000_create_chatbot_ai_requests_table.php',
            'database/migrations/2026_10_30_098000_add_raw_to_chatbot_replies.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
            'database/migrations/2026_11_06_100000_add_chat_index_to_chatbot_replies.php',
        ]]);
        config(['services.gateway.url' => self::GW, 'services.gateway.key' => self::GW_KEY]);
        $this->travelTo('2026-10-26 20:00:00');
        $this->page('Page Uno', '1001');
        $this->page('Page Dos', '1002');
        $this->page('Page Tres', '1003');
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'provider', 'gateway');
        app(PageSettings::class)->set('Page Dos', 'chatbot', 'provider', 'gateway');
        Http::preventStrayRequests();
        Http::fake([self::SENDS => function () {
            $a = array_shift($this->answers) ?? $this->body();
            if ($a === 'down') throw new ConnectionException('timed out');

            return is_int($a) ? Http::response(['error' => 'x'], $a) : Http::response($a, 200);
        }, self::GW . '/v1/pages/*' => Http::response(['connected' => true, 'can_send' => true], 200)]);
    }

    /** Ang sagot ayon sa kontrata; $pages: page_id => lag. */
    private function body(int $lag = 2123, float $failed = 1.4, array $pages = ['1001' => 1800], string $workers = 'running', int $queued = 1001): array
    {
        $rows = [];
        foreach ($pages as $id => $l) $rows[] = ['page_id' => (string) $id, 'name' => 'FB ' . $id, 'queued_sends' => 120, 'send_lag_seconds' => $l, 'sent_per_min_5m' => 60, 'failed_pct_60m' => 0.5];

        return ['at' => '2026-10-26T12:00:00Z',
            'overall' => ['queued_sends' => $queued, 'send_lag_seconds' => $lag, 'sent_last_5m' => 5120, 'sent_per_min_5m' => 1024, 'failed_last_60m' => 157,
                'failed_pct_60m' => $failed, 'forward_lag_seconds' => 0, 'workers' => ['sends' => 32, 'status' => $workers]],
            'pages' => $rows, 'thresholds' => ['send_lag_amber' => 30, 'send_lag_red' => 120, 'failed_amber' => 5, 'failed_red' => 15]];
    }

    /** Isang takbo ng watch kada minuto. */
    private function watch(mixed ...$answers): void
    {
        foreach ($answers as $a) {
            $this->answers = [$a];
            $this->assertSame(0, Artisan::call('chatbot:forbeast-watch'));
            $this->travel(1)->minutes();
        }
    }

    /** @return array<int, array> ang mga send_lag event, luma muna (kasama ang pangalan ng page ng row) */
    private function lagEvents(): array
    {
        return PageSetting::where('provider', 'chatbot')->where('key', 'auto_switch')->orderBy('id')->get()
            ->map(fn ($s) => json_decode((string) $s->plainValue(), true) + ['_page' => $s->page_name])
            ->filter(fn ($e) => ($e['condition'] ?? null) === 'send_lag')->values()->all();
    }

    private function panel(): ?array
    {
        return app(ForbeastSendHealth::class)->panel();
    }

    public function test_S_155_1_fetch_keeps_24h_of_samples_404_is_not_available_and_a_failure_goes_stale_without_stopping_the_watch(): void
    {
        $this->watch($this->body(10, 1.4, []), $this->body(20, 1.4, []), $this->body(30, 1.4, []));
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::SENDS && $r->hasHeader('Authorization', 'Bearer ' . self::GW_KEY));
        $p = $this->panel();
        $this->assertSame('ok', $p['status']);
        $this->assertSame([10, 20, 30], array_column($p['series'], 'lag'));

        // Pagkalipas ng 24 oras: ang lumang samples ay wala na.
        $this->travel(24)->hours();
        $this->watch($this->body(40, 1.4, []));
        $this->assertSame([40], array_column($this->panel()['series'], 'lag'));

        // Hindi pa naka-deploy (404): "not available yet", walang alarm kahit ilang beses.
        $this->watch(404, 404, 404, 404);
        $this->assertSame('not_available', $this->panel()['status']);
        $this->assertSame([], $this->lagEvents());

        // Timeout at 500: ang huling data ay ipinapakita pa hanggang 5 minuto, saka stale; tuloy ang page health check.
        $this->watch($this->body(50, 1.4, []));
        app(PageSettings::class)->set('Page Uno', 'forbeast', 'token', 'fbp_page_uno_token_155', true, null, true);
        $this->watch('down', 500);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::GW . '/v1/pages/1001');
        $this->assertSame('ok', $this->panel()['status']);
        $this->watch('down', 500, 'down', 500);
        $this->assertSame('stale', $this->panel()['status']);
        $this->assertSame([], $this->lagEvents());
    }

    public function test_S_155_2_panel_numbers_and_lights_follow_the_endpoint_thresholds(): void
    {
        // [send lag, failed %, workers status] => [ilaw ng lag, ilaw ng failed, ilaw ng workers]
        $cases = [
            [10, 1.4, 'running', 'green', 'green', 'green'],
            [60, 6.5, 'running', 'amber', 'amber', 'green'],
            [2123, 20.5, 'stopped', 'red', 'red', 'red'],
        ];
        foreach ($cases as [$lag, $failed, $workers, $lagLight, $failedLight, $workersLight]) {
            $this->watch($this->body($lag, $failed, ['1001' => 5], $workers));
            $o = $this->panel()['overall'];
            $this->assertSame([$lag, $lagLight], [$o['send_lag_seconds']['value'], $o['send_lag_seconds']['light']], "lag $lag");
            $this->assertSame([$failed, $failedLight], [$o['failed_pct_60m']['value'], $o['failed_pct_60m']['light']], "failed $failed");
            $this->assertSame([32, $workersLight], [$o['workers']['value'], $o['workers']['light']], "workers $workers");
            $this->assertSame(1001, $o['queued_sends']['value']);
            $this->assertSame(1024, $o['sent_per_min_5m']['value']);
            $this->assertSame([0, 'green'], [$o['forward_lag_seconds']['value'], $o['forward_lag_seconds']['light']]);
        }

        // Sa page: ang mga numero, ang ilaw, at ang note sa first reply (tinanggap lang ng Forbeast).
        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        $html = $this->get('/chatbot/health')->assertOk()->getContent();
        $this->assertStringContainsString('data-panel="forbeast"', $html);
        $this->assertMatchesRegularExpression('/data-fb="send_lag_seconds"[^>]*data-light="red"/', $html);
        $this->assertStringContainsString('2,123 s', $html);
        $this->assertStringContainsString('32 (stopped)', $html);
        $this->assertStringContainsString('accepted by Forbeast; real send lag: 2123s', $html);
        $this->assertStringContainsString('<svg', $html);
    }

    public function test_S_155_3_per_page_table_matches_by_facebook_page_id_worst_first(): void
    {
        // 1003 ay nasa Botcake, 9999 ay wala sa Likha: hindi kasama.
        $this->watch($this->body(200, 1.4, ['1001' => 300, '1002' => 5000, '1003' => 9000, '9999' => 9999]));
        $rows = $this->panel()['pages'];
        $this->assertSame(['Page Dos', 'Page Uno'], array_column($rows, 'page'));
        $this->assertSame([5000, 300], array_column($rows, 'send_lag_seconds'));
        $this->assertSame(['red', 'red'], array_column($rows, 'light'));
        $this->assertSame(120, $rows[0]['queued_sends']);
    }

    public function test_S_155_4_three_lagging_samples_raise_one_alarm_per_30_minutes_then_one_recovered(): void
    {
        $lag = $this->body(2123, 1.4, ['1001' => 1800, '1002' => 10]);
        $this->watch($lag, $lag);
        $this->assertSame([], $this->lagEvents());
        $this->watch($lag);
        $e = $this->lagEvents();
        $this->assertCount(1, $e);
        $this->assertSame(['alarm', 'gateway', null, 'send_lag', 'Forbeast send lag 2123s, 1001 queued', ['Page Uno']],
            [$e[0]['result'], $e[0]['from'], $e[0]['to'], $e[0]['condition'], $e[0]['error'], $e[0]['pages']]);

        // Habang tuloy: wala nang iba sa loob ng 30 minuto, isa ulit pagkatapos.
        for ($i = 0; $i < 27; $i++) $this->watch($lag);
        $this->assertCount(1, $this->lagEvents());
        $this->watch($lag, $lag, $lag);
        $this->assertCount(2, $this->lagEvents());

        // Bumaba sa ilalim ng 30 s nang 3 beses: isang "recovered".
        $ok = $this->body(5, 1.4, ['1001' => 5, '1002' => 5]);
        $this->watch($ok, $ok);
        $this->assertCount(2, $this->lagEvents());
        $this->watch($ok, $ok, $ok);
        $e = $this->lagEvents();
        $this->assertCount(3, $e);
        $this->assertSame(['recovered', 'send_lag'], [$e[2]['result'], $e[2]['condition']]);

        // Ang feed ng owner ay may pages ng event.
        config(['services.chatbot.events_key' => 'events-key-155']);
        $feed = $this->getJson('/api/chatbot/provider-events', ['X-CHATBOT-EVENTS-KEY' => 'events-key-155'])->assertOk()->json('events');
        $this->assertSame(['alarm', 'alarm', 'recovered'], array_column($feed, 'result'));
        $this->assertSame(['Page Uno'], $feed[0]['pages']);
    }

    public function test_S_155_5_a_spike_of_one_or_two_samples_raises_no_alarm(): void
    {
        $high = $this->body(500, 1.4, ['1001' => 500]);
        $low = $this->body(100, 1.4, ['1001' => 100]);
        $this->watch($high, $low, $high, $high, $low, $high, $low);
        $this->assertSame([], $this->lagEvents());
    }

    public function test_S_155_6_send_lag_never_switches_a_page_to_botcake(): void
    {
        $lag = $this->body(2123, 1.4, ['1001' => 1800, '1002' => 1800]);
        $this->watch($lag, $lag, $lag, $lag);
        $this->assertCount(1, $this->lagEvents());
        $this->assertSame([ChatbotProvider::GATEWAY, ChatbotProvider::GATEWAY], [ChatbotProvider::name('Page Uno'), ChatbotProvider::name('Page Dos')]);
        $this->assertSame(0, PageSetting::where('key', 'auto_switch')->get()->filter(fn ($s) => str_contains((string) $s->plainValue(), '"switched"'))->count());
        // Ang alarm lang ng buong Forbeast (walang page na lampas): lahat ng page sa Forbeast ang apektado.
        $this->travel(31)->minutes();
        $this->watch($this->body(2123, 1.4, ['1001' => 5]));
        $this->assertSame(['Page Uno', 'Page Dos'], $this->lagEvents()[1]['pages']);
    }

    public function test_S_155_7_red_banner_on_bot_health_and_chatbot_pages_while_the_alarm_is_active(): void
    {
        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        $lag = $this->body(2123);
        $this->watch($lag, $lag);
        $this->assertStringNotContainsString('data-alarm="send_lag"', $this->get('/chatbot/health')->getContent());
        $this->watch($lag);
        foreach (['/chatbot/health', '/chatbot/pages'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('data-alarm="send_lag"', $html, $url);
            $this->assertStringContainsString('Forbeast send lag 2123s, 1001 queued', $html, $url);
        }
        $ok = $this->body(5, 1.4, ['1001' => 5]);
        $this->watch($ok, $ok, $ok);
        foreach (['/chatbot/health', '/chatbot/pages'] as $url) $this->assertStringNotContainsString('data-alarm="send_lag"', $this->get($url)->getContent(), $url);
    }

    public function test_S_155_8_bot_health_renders_without_the_panel_when_no_page_is_on_forbeast(): void
    {
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'provider', 'botcake');
        app(PageSettings::class)->set('Page Dos', 'chatbot', 'provider', 'botcake');
        $this->watch($this->body(2123));
        $this->assertNull($this->panel());
        $this->actingAs($this->user('CEO', 'ceo@example.test'));
        $html = $this->get('/chatbot/health')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-panel="forbeast"', $html);
        $this->assertStringNotContainsString('real send lag', $html);
    }

    public function test_S_155_9_roles_are_unchanged(): void
    {
        $this->watch($this->body(2123));
        $this->actingAs($this->user('Data Encoder', 'enc@example.test'))->get('/chatbot/health')->assertForbidden();
        $this->actingAs($this->user('CEO', 'ceo2@example.test'))->get('/chatbot/health')->assertOk()->assertSee('data-panel="forbeast"', false);
    }
}
