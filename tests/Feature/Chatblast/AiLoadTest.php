<?php

namespace Tests\Feature\Chatblast;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Spec 210, S-210.6: bago ang AI step, tinatanong ang Forbeast ng page kung may load pa ang account (GET
 * /v1/site/ai-load, naka-cache nang 60 s kada page, kada 100 page). "stopped" = walang tawag sa provider, ang failed port, at
 * ang note ng Forbeast sa row ng page; anumang iba, o anumang error, ay tumatakbo ang AI.
 */
class AiLoadTest extends AiKeyPoolTestCase
{
    private const STOPPED_NOTE = 'AI stopped: no load. Your pages still reply, but AI steps are skipped until you top up.';
    private const LOW_NOTE = 'Low AI load: ₱78.78 left. Contact Forbeast to top up before the AI stops.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-15 10:00:00');
        $this->addKey('openai', 'pool-1', self::KEY_A);
        $this->live('Page Uno');
        // Ang load ay tinatanong lang para sa page na dumadaan sa gateway (spec 216).
        $this->onGateway('Page Uno');
    }

    /** Ang sagot ng Forbeast: ang page ay $state (ang iba ay ok). */
    private function load(string $state, ?string $note = null, string $page = '1001')
    {
        return function (HttpRequest $r) use ($state, $note, $page) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return Http::response(['pages' => array_map(fn ($id) => $id === $page ? ['page_id' => $id, 'state' => $state, 'balance_php' => $state === 'stopped' ? '-0.01' : '78.78', 'note' => $note]
                : ['page_id' => $id, 'state' => 'ok', 'balance_php' => '500.00', 'note' => null], explode(',', (string) ($q['pages'] ?? '')))]);
        };
    }

    /** @return array<int, HttpRequest> */
    private function loadCalls(): array
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/v1/site/ai-load'))->map(fn ($p) => $p[0])->values()->all();
    }

    public function test_S_210_6_1_a_stopped_page_makes_no_provider_call_and_takes_the_failed_path_with_no_load(): void
    {
        $this->forbeast = [$this->load('stopped', self::STOPPED_NOTE)];
        $this->says('hello', 'Page Uno');
        $this->assertSame([], $this->providerCalls);
        $this->assertSame(['Sorry'], $this->sent());
        $this->assertSame(['AI step n2 → failed: No AI load: Forbeast stopped the AI for this page.'], $this->events('ai'));
        $row = $this->lastRow();
        $this->assertSame(['No AI load: Forbeast stopped the AI for this page.', null, null], [$row->error, $row->usage_status, $row->provider]);
        $call = $this->loadCalls()[0];
        $this->assertStringStartsWith(self::FORBEAST . '/v1/site/ai-load?pages=', $call->url());
        $this->assertSame('Bearer fbs_test_site_key_not_real', $call->header('Authorization')[0]);
    }

    public function test_S_210_6_2_ok_low_or_unbilled_runs_the_ai(): void
    {
        foreach (['ok', 'low', 'unbilled'] as $i => $state) {
            \Illuminate\Support\Facades\Cache::flush();
            $this->forbeast = [$this->load($state, $state === 'low' ? self::LOW_NOTE : null)];
            $this->says('q' . $i, 'Page Uno', (string) (7001 + $i));
            $this->assertSame(['Reply: answer by 1111'], $this->sent((string) (7001 + $i)), $state);
        }
        $this->assertCount(3, $this->providerCalls);
    }

    public function test_S_210_6_3_a_low_or_stopped_page_shows_the_forbeast_note_on_its_row(): void
    {
        $this->actingAs($this->user('CEO', 'ana@example.test'));
        $this->forbeast = [$this->load('low', self::LOW_NOTE)];
        Artisan::call('chatbot:ai-load');
        $rows = collect($this->getJson('/chatbot/data')->assertOk()->json('rows'))->keyBy('page');
        $this->assertSame(['low', self::LOW_NOTE], [$rows['Page Uno']['ai_load']['state'], $rows['Page Uno']['ai_load']['note']]);
        $this->assertNull($rows[self::TECH]['ai_load']);

        $this->travel(2)->minutes();
        $this->forbeast = [$this->load('stopped', self::STOPPED_NOTE)];
        Artisan::call('chatbot:ai-load');
        $rows = collect($this->getJson('/chatbot/data')->assertOk()->json('rows'))->keyBy('page');
        $this->assertSame(['stopped', self::STOPPED_NOTE], [$rows['Page Uno']['ai_load']['state'], $rows['Page Uno']['ai_load']['note']]);
        // Ang page ay may sariling lugar sa row at sa AI dialog para sa note.
        $this->get('/chatbot/pages')->assertOk()->assertSee('ai-load-note', false);
    }

    public function test_S_210_6_4_the_state_is_cached_60_seconds_so_a_top_up_resumes_the_ai_within_a_minute(): void
    {
        $this->forbeast = [$this->load('stopped', self::STOPPED_NOTE), $this->load('ok')];
        $this->says('one', 'Page Uno', '7001');
        $this->travel(30)->seconds();
        $this->says('two', 'Page Uno', '7002');
        $this->assertCount(1, $this->loadCalls());
        $this->assertSame(['Sorry'], $this->sent('7002'));
        $this->travel(31)->seconds();
        $this->says('three', 'Page Uno', '7003');
        $this->assertCount(2, $this->loadCalls());
        $this->assertSame(['Reply: answer by 1111'], $this->sent('7003'));
    }

    public function test_S_210_6_5_an_unreachable_forbeast_page_not_found_or_no_gateway_runs_the_ai(): void
    {
        $this->forbeast = [Http::failedConnection('cURL error 7: Failed to connect')];
        $this->says('one', 'Page Uno', '7001');
        $this->assertSame(['Reply: answer by 1111'], $this->sent('7001'));
        // Ang error ay naka-cache sandali: hindi tinatanong ang Forbeast sa bawat mensahe habang down ito.
        $this->says('two', 'Page Uno', '7002');
        $this->assertCount(1, $this->loadCalls());

        $this->travel(2)->minutes();
        $this->forbeast = [$this->load('page_not_found')];
        $this->says('three', 'Page Uno', '7003');
        $this->assertSame(['Reply: answer by 1111'], $this->sent('7003'));

        $this->travel(2)->minutes();
        config(['services.gateway.url' => '', 'services.incepxionbot.url' => '']);
        $this->says('four', 'Page Uno', '7004');
        // Walang URL ang gateway: hindi rin maipadala ang sagot, pero tumakbo ang AI (walang tanong ng load).
        $this->assertSame(['openai', null], [$this->lastRow()->provider, $this->lastRow()->error]);
        $this->assertCount(2, $this->loadCalls());
    }

    public function test_S_210_6_6_many_pages_are_fetched_in_batches_of_up_to_100_not_one_request_per_message(): void
    {
        $rows = [];
        for ($i = 1; $i <= 150; $i++) {
            $rows[] = ['page_name' => 'Bulk Page ' . $i, 'provider' => 'botcake', 'key' => 'page_id', 'value' => (string) (500000 + $i), 'is_secret' => false, 'active' => true, 'created_at' => now(), 'updated_at' => now()];
            $rows[] = ['page_name' => 'Bulk Page ' . $i, 'provider' => 'chatbot', 'key' => 'provider', 'value' => 'gateway', 'is_secret' => false, 'active' => true, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('page_settings')->insert($rows);
        $this->onGateway(self::TECH);
        Artisan::call('chatbot:ai-load');
        $calls = $this->loadCalls();
        $this->assertCount(2, $calls);
        $counts = array_map(function ($c) {
            parse_str((string) parse_url($c->url(), PHP_URL_QUERY), $q);
            return count(explode(',', $q['pages']));
        }, $calls);
        sort($counts);
        $this->assertSame([52, 100], $counts); // 150 + Page Uno + Tech Flask Ph
        // Naka-cache na: ang mga mensahe ay walang bagong tanong.
        for ($i = 0; $i < 5; $i++) $this->says('q' . $i, 'Page Uno', (string) (7600 + $i));
        $this->assertCount(2, $this->loadCalls());
    }
}
