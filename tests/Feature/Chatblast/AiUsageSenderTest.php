<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\SendChatbotAiUsage;
use App\Services\Chatbot\ChatbotAiQueue;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Spec 210, S-210.5: ang usage ng bawat sagot ay umaabot nang isang beses sa Forbeast na gamit ng page (POST
 * /v1/site/ai-usage, hanggang 100 kada batch, ang site key ng gateway), at ang bawat row ay minamarkahan ayon sa sagot.
 */
class AiUsageSenderTest extends AiKeyPoolTestCase
{
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-15 10:00:00');
    }

    /** Isang row na may gastos (gaya ng naitala ng AI step), pending sa $gateway. */
    private function costed(int $count = 1, string $gateway = 'forbeast', array $extra = []): void
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $this->n++;
            $rows[] = array_replace(['request_id' => sprintf('req-%05d', $this->n), 'page_id' => 100000000000001, 'psid' => 7001, 'flow_id' => 1, 'node_id' => 'n2', 'page_name' => 'Page Uno',
                'model' => 'gpt-4o-mini', 'max_tokens' => 250, 'status' => 'sync', 'deadline_at' => now(), 'answered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                'provider' => 'openai', 'model_used' => 'gpt-4o-mini', 'path' => 'sync', 'prompt_tokens' => 100, 'completion_tokens' => 20, 'key_id' => 'aaaa1111',
                'key_label' => 'pool-1', 'key_last4' => '1111', 'cost_micros' => 27, 'usage_status' => 'pending', 'usage_gateway' => $gateway], $extra);
        }
        foreach (array_chunk($rows, 500) as $chunk) DB::table(ChatbotAiQueue::TABLE)->insert($chunk);
    }

    private function send(): void
    {
        (new SendChatbotAiUsage())->handle();
    }

    /** @return array<int, HttpRequest> */
    private function usageCalls(?string $base = null): array
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/v1/site/ai-usage') && ($base === null || str_starts_with($r->url(), $base)))->map(fn ($p) => $p[0])->values()->all();
    }

    private function statuses(): array
    {
        return DB::table(ChatbotAiQueue::TABLE)->orderBy('id')->pluck('usage_status')->countBy()->all();
    }

    public function test_S_210_5_1_costed_rows_are_posted_in_batches_of_at_most_100_with_the_site_key_and_marked(): void
    {
        $this->costed(150);
        $this->send();
        $calls = $this->usageCalls();
        $this->assertCount(2, $calls);
        $this->assertSame(self::FORBEAST . '/v1/site/ai-usage', $calls[0]->url());
        $this->assertSame('Bearer fbs_test_site_key_not_real', $calls[0]->header('Authorization')[0]);
        $this->assertSame([100, 50], [count($calls[0]->data()['records']), count($calls[1]->data()['records'])]);
        $this->assertSame(['client_ref' => 'req-00001', 'page_id' => '100000000000001', 'at' => '2026-11-15T10:00:00+08:00', 'provider' => 'openai', 'model' => 'gpt-4o-mini',
            'key_label' => 'pool-1', 'tokens_in' => 100, 'tokens_out' => 20, 'cost_micros' => 27], $calls[0]->data()['records'][0]);
        $this->assertSame(['sent' => 150], $this->statuses());
        $row = DB::table(ChatbotAiQueue::TABLE)->first();
        $this->assertSame(['stored', '2026-11-15 10:00:00'], [$row->usage_result, (string) $row->usage_sent_at]);
        $this->send();
        $this->assertCount(2, $this->usageCalls()); // wala nang pending: walang bagong padala
    }

    public function test_S_210_5_2_incepxionbot_pages_go_to_the_incepxionbot_gateway(): void
    {
        $this->costed(2, 'incepxionbot');
        $this->costed(1, 'forbeast');
        $this->send();
        $inc = $this->usageCalls(self::INCEPXION);
        $this->assertCount(1, $inc);
        $this->assertSame('Bearer fbs_test_incepxion_key_not_real', $inc[0]->header('Authorization')[0]);
        $this->assertSame(['req-00001', 'req-00002'], array_column($inc[0]->data()['records'], 'client_ref'));
        $this->assertSame(['req-00003'], array_column($this->usageCalls(self::FORBEAST)[0]->data()['records'], 'client_ref'));
        // Ang gateway ng row ay mula sa chatbot.gateway ng page sa oras ng tawag.
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'gateway', 'incepxionbot', false, null, true);
        $this->assertSame('incepxionbot', \App\Services\Chatbot\ChatbotAiUsage::gatewayOf('Page Uno'));
    }

    public function test_S_210_5_3_a_duplicate_answer_marks_the_row_sent(): void
    {
        $this->costed(2);
        $this->forbeast = [Http::response(['results' => [['client_ref' => 'req-00001', 'result' => 'duplicate', 'duplicate' => true], ['client_ref' => 'req-00002', 'result' => 'stored', 'charged_php' => '0.0031', 'needs_price' => false]]])];
        $this->send();
        $this->assertSame(['sent' => 2], $this->statuses());
        $this->assertSame(['duplicate', 'stored'], DB::table(ChatbotAiQueue::TABLE)->orderBy('id')->pluck('usage_result')->all());
    }

    public function test_S_210_5_4_page_not_found_and_unbilled_are_marked_not_retried_and_counted_on_the_key_screen(): void
    {
        $this->costed(4);
        $this->forbeast = [Http::response(['results' => [
            ['client_ref' => 'req-00001', 'result' => 'page_not_found'], ['client_ref' => 'req-00002', 'result' => 'unbilled'],
            ['client_ref' => 'req-00003', 'result' => 'invalid', 'status' => 422, 'message' => 'tokens_in must be an integer'],
            ['client_ref' => 'req-00004', 'result' => 'stored', 'charged_php' => null, 'needs_price' => true],
        ]])];
        $this->send();
        $this->assertSame(['page_not_found', 'unbilled', 'invalid', 'needs_price'], DB::table(ChatbotAiQueue::TABLE)->orderBy('id')->pluck('usage_status')->all());
        $this->travel(1)->hours();
        $this->send();
        $this->assertCount(1, $this->usageCalls());
        $this->actingAs($this->user('CEO', 'ana@example.test'));
        $usage = collect($this->getJson('/chatbot/ai-keys/data')->assertOk()->json('usage'))->firstWhere('gateway', 'forbeast');
        $counts = array_intersect_key($usage['counts'], array_flip(['page_not_found', 'unbilled', 'invalid', 'needs_price']));
        ksort($counts);
        $this->assertSame(['invalid' => 1, 'needs_price' => 1, 'page_not_found' => 1, 'unbilled' => 1], $counts);
    }

    public function test_S_210_5_5_when_forbeast_is_down_rows_stay_pending_and_are_retried_with_backoff_in_order(): void
    {
        $this->costed(3);
        foreach ([Http::response(['error' => 'server'], 503), Http::failedConnection('cURL error 28: Operation timed out'), Http::response(['error' => 'too_many_requests', 'message' => 'Wait'], 429),
            Http::response('Not Found', 404)] as $i => $fail) { // ang 404 (wala pa ang route): ulit, hindi invalid
            $this->forbeast = [$fail];
            $this->send();
            $this->assertSame(['pending' => 3], $this->statuses(), 'after failure ' . $i);
            $before = count($this->usageCalls());
            $this->send(); // naka-backoff: walang tawag agad
            $this->assertCount($before, $this->usageCalls());
            $this->travel(31)->minutes();
        }
        $this->send();
        $this->assertSame(['sent' => 3], $this->statuses());
        $all = $this->usageCalls();
        $this->assertSame(['req-00001', 'req-00002', 'req-00003'], array_column(end($all)->data()['records'], 'client_ref'));
    }

    public function test_S_210_5_6_a_401_pauses_sending_shows_on_the_key_screen_and_drops_nothing(): void
    {
        $this->costed(2);
        $this->forbeast = [Http::response(['error' => 'unauthorized', 'message' => 'Missing, wrong or inactive key.'], 401)];
        $this->send();
        $this->assertSame(['pending' => 2], $this->statuses());
        $this->actingAs($this->user('CEO', 'ana@example.test'));
        $usage = collect($this->getJson('/chatbot/ai-keys/data')->json('usage'))->firstWhere('gateway', 'forbeast');
        $this->assertSame('Forbeast refused the site key (HTTP 401).', $usage['paused']['error']);
        $this->travel(10)->minutes();
        $this->send();
        $this->assertCount(1, $this->usageCalls());
        $this->travel(6)->minutes();
        $this->send(); // ang probe: tama na ang key
        $this->assertSame(['sent' => 2], $this->statuses());
        $this->assertNull(collect($this->getJson('/chatbot/ai-keys/data')->json('usage'))->firstWhere('gateway', 'forbeast')['paused']);
    }

    public function test_S_210_5_7_no_gateway_is_never_sent_and_a_null_cost_is_sent_as_null(): void
    {
        $this->costed(1, 'forbeast', ['cost_micros' => null, 'cost_flag' => 'no_price', 'model_used' => 'claude-opus-9']);
        $this->costed(1, 'forbeast', ['usage_status' => 'no_gateway', 'usage_gateway' => null]);
        $this->send();
        $records = $this->usageCalls()[0]->data()['records'];
        $this->assertSame(['req-00001'], array_column($records, 'client_ref'));
        $this->assertArrayHasKey('cost_micros', $records[0]);
        $this->assertNull($records[0]['cost_micros']);
        $this->assertSame('no_gateway', DB::table(ChatbotAiQueue::TABLE)->where('request_id', 'req-00002')->value('usage_status'));

        // Ang gateway na wala nang site key: walang tawag, nananatiling pending.
        $this->costed(1);
        config(['services.forbeast_site.key' => '']);
        $this->send();
        $this->assertCount(1, $this->usageCalls());
        $this->assertSame('pending', DB::table(ChatbotAiQueue::TABLE)->where('request_id', 'req-00003')->value('usage_status'));
    }

    public function test_S_210_5_8_a_10000_row_backlog_drains_in_batches_within_the_rate_limit(): void
    {
        $this->costed(10000);
        $runs = 0;
        while (DB::table(ChatbotAiQueue::TABLE)->where('usage_status', 'pending')->exists() && $runs < 10) {
            $before = count($this->usageCalls());
            $this->send();
            $this->assertLessThanOrEqual(20, count($this->usageCalls()) - $before);
            $runs++;
            $this->travel(1)->minutes();
        }
        $this->assertSame(5, $runs);
        $this->assertSame(['sent' => 10000], $this->statuses());
        foreach ($this->usageCalls() as $c) $this->assertLessThanOrEqual(100, count($c->data()['records']));
        $this->assertCount(100, $this->usageCalls());
    }
}
