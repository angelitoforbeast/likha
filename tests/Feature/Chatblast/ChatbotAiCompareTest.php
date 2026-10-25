<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\ChatbotAiQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Spec 150, ang paghahambing ng mga AI ng isang page (GET /chatbot/ai/compare): bawat provider ay may requests, answered,
 * failed, fallbacks, average at p95 na bilis, at kabuuang tokens; Manila ang hangganan ng araw; walang text ng customer.
 */
class ChatbotAiCompareTest extends ChatbotAiProviderTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-20 12:00:00');
        $this->actingAs($this->user('CEO', 'ana@example.test'));
    }

    /** Isang tapos na request: ang mga subok ay [provider, ms, ok, why?]. */
    private function request(string $at, array $attempts, ?int $in = null, ?int $out = null, string $page = self::TECH): void
    {
        $ok = (bool) end($attempts)[2];
        DB::table(ChatbotAiQueue::TABLE)->insert([
            'request_id' => (string) Str::uuid(), 'page_id' => 1002, 'psid' => 7001, 'chat_id' => null, 'flow_id' => 1, 'node_id' => 'n2', 'page_name' => $page,
            'model' => 'gpt-4o-mini', 'messages' => '[{"role":"user","content":"LIHIM"}]', 'max_tokens' => 250, 'context' => '{}', 'status' => 'resumed',
            'answer' => $ok ? 'LIHIM NA SAGOT' : null, 'deadline_at' => $at, 'created_at' => $at, 'updated_at' => $at, 'path' => 'async',
            'provider' => $ok ? end($attempts)[0] : null, 'fell_back' => $ok && count($attempts) > 1, 'prompt_tokens' => $in, 'completion_tokens' => $out,
            'latency_ms' => array_sum(array_column($attempts, 1)),
            'ai_attempts' => json_encode(array_map(fn ($a) => ['provider' => $a[0], 'model' => 'm', 'ms' => $a[1], 'ok' => $a[2], 'why' => $a[3] ?? null], $attempts)),
        ]);
    }

    private function compare(string $query): array
    {
        return $this->getJson('/chatbot/ai/compare?page=' . urlencode(self::TECH) . '&' . $query)->assertOk()->json();
    }

    private function byProvider(array $res): array
    {
        return collect($res['providers'])->keyBy('provider')->all();
    }

    public function test_S_150_D3_per_provider_counts_latency_and_tokens_for_one_page_and_range(): void
    {
        // Local: 4 subok (2 sagot, 2 palya na lumipat sa OpenAI); OpenAI: 3 subok (lahat sagot).
        $this->request('2026-11-20 09:00:00', [['local', 1000, true]], 100, 20);
        $this->request('2026-11-20 09:01:00', [['local', 3000, true]], 50, null);
        $this->request('2026-11-20 09:02:00', [['local', 8000, false, 'Local did not answer in time.'], ['openai', 900, true]], 40, 10);
        $this->request('2026-11-20 09:03:00', [['local', 20, false, 'Local did not answer.'], ['openai', 1100, true]], 60, 30);
        $this->request('2026-11-20 09:04:00', [['openai', 700, true]], 10, 5);
        $this->request('2026-11-20 09:05:00', [['openai', 500, true]], 1, 1, 'Page Uno'); // ibang page: hindi kasama

        $p = $this->byProvider($this->compare('from=2026-11-20&to=2026-11-20'));

        $this->assertSame(['requests' => 4, 'answered' => 2, 'failed' => 2, 'fallbacks' => 2, 'avg_ms' => 3005, 'p95_ms' => 8000, 'prompt_tokens' => 150, 'completion_tokens' => 20],
            array_intersect_key($p['local'], array_flip(['requests', 'answered', 'failed', 'fallbacks', 'avg_ms', 'p95_ms', 'prompt_tokens', 'completion_tokens'])));
        $this->assertSame(['requests' => 3, 'answered' => 3, 'failed' => 0, 'fallbacks' => 0, 'avg_ms' => 900, 'p95_ms' => 1100, 'prompt_tokens' => 110, 'completion_tokens' => 45],
            array_intersect_key($p['openai'], array_flip(['requests', 'answered', 'failed', 'fallbacks', 'avg_ms', 'p95_ms', 'prompt_tokens', 'completion_tokens'])));
        $this->assertSame(['Local', 'OpenAI'], [$p['local']['label'], $p['openai']['label']]);
    }

    public function test_S_150_D6_the_comparison_holds_no_customer_text(): void
    {
        $this->request('2026-11-20 09:00:00', [['local', 1000, true]], 100, 20);

        $body = $this->getJson('/chatbot/ai/compare?page=' . urlencode(self::TECH) . '&from=2026-11-20&to=2026-11-20')->getContent();

        $this->assertStringNotContainsString('LIHIM', $body);
    }

    public function test_S_150_D7_missing_usage_is_null_not_zero_and_averages_skip_it(): void
    {
        $this->request('2026-11-20 09:00:00', [['local', 1000, true]]);
        $this->request('2026-11-20 09:01:00', [['local', 1000, true]], 30, 10);
        $this->request('2026-11-20 09:02:00', [['local', 1000, true]], 50, 20);
        $this->request('2026-11-20 09:03:00', [['openai', 500, true]]);

        $p = $this->byProvider($this->compare('from=2026-11-20&to=2026-11-20'));

        $this->assertSame([80, 30, 40, 15], [$p['local']['prompt_tokens'], $p['local']['completion_tokens'], $p['local']['avg_prompt_tokens'], $p['local']['avg_completion_tokens']]);
        $this->assertSame([null, null, null, null], [$p['openai']['prompt_tokens'], $p['openai']['completion_tokens'], $p['openai']['avg_prompt_tokens'], $p['openai']['avg_completion_tokens']]);
    }

    public function test_S_150_D8_a_30_day_range_covers_the_whole_range(): void
    {
        $this->request('2026-10-22 00:00:01', [['openai', 100, true]]); // unang segundo ng unang araw
        $this->request('2026-11-05 12:00:00', [['openai', 100, true]]);
        $this->request('2026-11-20 11:59:00', [['openai', 100, true]]);
        $this->request('2026-10-21 23:59:59', [['openai', 100, true]]); // labas

        $res = $this->compare('from=2026-10-22&to=2026-11-20');

        $this->assertSame(3, $res['requests']);
        $this->assertSame(3, $this->byProvider($res)['openai']['requests']);
    }

    public function test_S_150_D9_an_empty_range_or_page_says_no_requests(): void
    {
        $this->request('2026-11-20 09:00:00', [['openai', 100, true]], 1, 1, 'Page Uno');

        $res = $this->compare('from=2026-11-01&to=2026-11-19');

        $this->assertSame([0, [], 'No requests'], [$res['requests'], $res['providers'], $res['message']]);
        $this->getJson('/chatbot/ai/compare?page=Not+A+Page')->assertStatus(422);
    }

    public function test_S_150_D10_days_are_manila_days(): void
    {
        $this->request('2026-11-19 23:59:59', [['openai', 100, true]]); // Manila, Nob 19
        $this->request('2026-11-20 00:00:00', [['openai', 100, true]]); // Manila, Nob 20
        $this->request('2026-11-20 23:59:59', [['openai', 100, true]]);

        $this->assertSame(2, $this->compare('from=2026-11-20&to=2026-11-20')['requests']);
        $this->assertSame(1, $this->compare('from=2026-11-19&to=2026-11-19')['requests']);
    }

    public function test_S_150_D14_the_comparison_screen_is_on_the_pages_screen_and_fits_a_phone(): void
    {
        $html = $this->get('/chatbot/pages')->assertOk()->getContent();

        $this->assertStringContainsString('data-ai-compare-url="' . route('chatbot.ai.compare') . '"', $html);
        // Ang table ay nasa sariling kahon na naii-scroll (hindi ang buong page) sa makitid na screen.
        $this->assertMatchesRegularExpression('/id="ai-compare"[^>]*>.*overflow-x-auto/s', $html);
    }
}
