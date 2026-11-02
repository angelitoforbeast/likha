<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\ResumeChatbotAi;
use App\Models\AppSetting;
use App\Services\Chatbot\ChatbotAiChain;
use App\Services\Chatbot\ChatbotAiPool;
use App\Services\Chatbot\ChatbotAiQueue;
use App\Services\Chatbot\ChatbotRunner;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

/**
 * Spec 210, S-210.4: bawat AI call ng chatbot ay may key, provider, model, tokens at gastos (integer micro-USD mula sa price
 * list), at ang row na hindi pa naipapadala ang usage ay hindi binubura.
 */
class AiKeyCostTest extends AiKeyPoolTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-15 10:00:00');
        $this->live('Page Uno');
        // Ang usage ay pending lang para sa page na dumadaan sa gateway (spec 216).
        $this->onGateway('Page Uno');
    }

    public function test_S_210_4_1_a_successful_call_records_provider_model_key_tokens_and_cost_in_micro_usd(): void
    {
        $this->addKey('openai', 'pool-1', self::KEY_A);
        $this->says('hello', 'Page Uno');
        $row = $this->lastRow();
        // gpt-4o-mini: 0.15 at 0.60 USD kada 1M → 100 × 0.15 + 20 × 0.60 = 27 micro-USD.
        $this->assertSame(['openai', 'gpt-4o-mini', 'pool-1', '1111', 100, 20, 27, null],
            [$row->provider, $row->model_used, $row->key_label, $row->key_last4, (int) $row->prompt_tokens, (int) $row->completion_tokens, (int) $row->cost_micros, $row->cost_flag]);
        $this->assertSame(['pending', 'forbeast'], [$row->usage_status, $row->usage_gateway]);
    }

    public function test_S_210_4_2_after_failover_both_attempts_are_listed_and_only_the_answering_keys_tokens_are_costed(): void
    {
        $this->addKey('openai', 'pool-1', self::KEY_A);
        $this->addKey('openai', 'pool-2', self::KEY_B);
        $this->byKey[self::KEY_A] = [Http::response(['error' => ['message' => 'no credit'], 'usage' => ['prompt_tokens' => 999, 'completion_tokens' => 999]], 402)];
        $this->byKey[self::KEY_B] = [$this->okFor(self::OPENAI, 'second key', ['in' => 300, 'out' => 50])];
        $this->says('hello', 'Page Uno');
        $row = $this->lastRow();
        $this->assertSame([['pool-1', false, 'OpenAI refused (HTTP 402).'], ['pool-2', true, null]],
            array_map(fn ($a) => [$a['key'], $a['ok'], $a['why']], json_decode($row->ai_attempts, true)));
        // 300 × 0.15 + 50 × 0.60 = 75
        $this->assertSame(['pool-2', 300, 50, 75], [$row->key_label, (int) $row->prompt_tokens, (int) $row->completion_tokens, (int) $row->cost_micros]);
    }

    public function test_S_210_4_4_no_usage_block_or_an_unpriced_model_gives_a_null_flagged_cost_never_0(): void
    {
        $this->addKey('openai', 'pool-1', self::KEY_A);
        $this->byKey[self::KEY_A] = [$this->okFor(self::OPENAI, 'no usage', null)];
        $this->says('hello', 'Page Uno', '7001');
        $row = $this->lastRow();
        $this->assertSame([null, 'no_usage', 'pending'], [$row->cost_micros, $row->cost_flag, $row->usage_status]);

        $this->addKey('anthropic', 'claude-1', self::KEY_B);
        $this->useProvider('Page Uno', 'anthropic', 'none');
        AppSetting::set('chatbot.ai.anthropic_model', 'claude-opus-9-preview');
        ChatbotAiChain::forget();
        $this->says('hello', 'Page Uno', '7002');
        $row = $this->lastRow();
        $this->assertSame(['anthropic', 'claude-opus-9-preview', null, 'no_price'], [$row->provider, $row->model_used, $row->cost_micros, $row->cost_flag]);
    }

    public function test_S_210_4_5_a_tiny_call_keeps_micro_precision_and_is_never_rounded_to_0(): void
    {
        $this->addKey('openai', 'pool-1', self::KEY_A);
        $this->byKey[self::KEY_A] = [$this->okFor(self::OPENAI, 'ok', ['in' => 1, 'out' => 1])];
        $this->says('hi', 'Page Uno');
        // 1 × 0.15 + 1 × 0.60 = 0.75 micro-USD → 1, hindi 0.
        $this->assertSame(1, (int) $this->lastRow()->cost_micros);
        $this->assertSame([0, null], \App\Services\Chatbot\ChatbotAiCost::of('gpt-4o-mini', 0, 0));
        $this->assertSame([27, null], \App\Services\Chatbot\ChatbotAiCost::of('gpt-4o-mini-2024-07-18', 100, 20));
    }

    public function test_S_210_4_6_a_queue_retry_of_the_same_call_gives_one_cost_row_not_two(): void
    {
        config(['services.chatbot.ai_async' => true, 'services.chatbot.ai_concurrency' => 10]);
        $calls = [];
        $pool = new ChatbotAiPool(function (RequestInterface $req) use (&$calls) {
            $p = new Promise();
            $calls[] = $p;

            return $p;
        });
        $this->assertTrue($pool->start());
        $this->addKey('openai', 'pool-1', self::KEY_A);
        $this->says('hello', 'Page Uno');
        $pool->tick();
        $calls[0]->resolve(new Response(200, ['Content-Type' => 'application/json'], json_encode(['choices' => [['message' => ['content' => 'pool']]], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20]])));
        $pool->tick(false);
        $row = DB::table(ChatbotAiQueue::TABLE)->first();
        // Ulit ng resume job at ulit ng finish (hal. ibang pool na huli): walang bagong row o gastos.
        (new ResumeChatbotAi(ChatbotAiQueue::ticket($row)))->handle(app(ChatbotRunner::class));
        $this->assertFalse(ChatbotAiQueue::finish($row, 'other-pool', ['ok' => true, 'text' => 'again', 'error' => null], 200));
        $this->assertSame(1, DB::table(ChatbotAiQueue::TABLE)->count());
        $this->assertSame([27, 'pending'], [(int) DB::table(ChatbotAiQueue::TABLE)->value('cost_micros'), DB::table(ChatbotAiQueue::TABLE)->value('usage_status')]);
        $this->assertSame(['Reply: pool'], $this->sent());
    }

    public function test_S_210_4_7_the_prune_never_deletes_a_row_whose_usage_has_not_reached_forbeast(): void
    {
        $base = ['page_id' => 1001, 'psid' => 7001, 'flow_id' => 1, 'node_id' => 'n2', 'page_name' => 'Page Uno', 'model' => 'gpt-4o-mini', 'max_tokens' => 10,
            'deadline_at' => now(), 'provider' => 'openai', 'path' => 'sync'];
        $old = now()->subDays(91);
        DB::table(ChatbotAiQueue::TABLE)->insert([
            $base + ['request_id' => 'old-pending', 'status' => 'sync', 'usage_status' => 'pending', 'created_at' => $old, 'updated_at' => $old],
            $base + ['request_id' => 'old-sent', 'status' => 'sync', 'usage_status' => 'sent', 'created_at' => $old, 'updated_at' => $old],
            $base + ['request_id' => 'old-answered-pending', 'status' => 'answered', 'usage_status' => 'pending', 'created_at' => now()->subDays(3), 'updated_at' => now()],
            $base + ['request_id' => 'old-answered-sent', 'status' => 'answered', 'usage_status' => 'sent', 'created_at' => now()->subDays(3), 'updated_at' => now()],
        ]);
        ChatbotAiQueue::sweep();
        $this->assertEqualsCanonicalizing(['old-pending', 'old-answered-pending'], DB::table(ChatbotAiQueue::TABLE)->pluck('request_id')->all());
    }
}
