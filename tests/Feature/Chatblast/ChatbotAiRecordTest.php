<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\ResumeChatbotAi;
use App\Services\Chatbot\ChatbotAiPool;
use App\Services\Chatbot\ChatbotAiQueue;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

/**
 * Spec 150, ang record kada request sa chatbot_ai_requests: sino ang sumagot, gaano katagal, tokens, ang bawat subok na may
 * dahilan, ang naghihintay at Test-tab na tawag (path sync), at ang tagal ng pagtatabi (text ng customer 2 araw, record 90).
 */
class ChatbotAiRecordTest extends ChatbotAiProviderTestCase
{
    /** @var array<int, array{req: RequestInterface, p: Promise}> */
    private array $poolCalls = [];

    private function pool(): ChatbotAiPool
    {
        config(['services.chatbot.ai_async' => true]);
        $pool = new ChatbotAiPool(function (RequestInterface $req, array $options) {
            $p = new Promise();
            $this->poolCalls[] = ['req' => $req, 'p' => $p];

            return $p;
        });
        $this->assertTrue($pool->start());

        return $pool;
    }

    private static function answer(string $text, ?array $usage = null, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($status === 200 ? ['choices' => [['message' => ['content' => $text]]]] + ($usage ? ['usage' => $usage] : []) : ['error' => 'x']));
    }

    private function row(string $psid = '7001'): ?object
    {
        return DB::table(ChatbotAiQueue::TABLE)->where('psid', (int) $psid)->orderByDesc('id')->first();
    }

    public function test_S_150_D1_an_openai_answer_records_provider_model_latency_and_tokens(): void
    {
        $pool = $this->pool();
        $this->live('Page Uno');
        $this->says('hello', 'Page Uno');
        $pool->tick();
        $this->poolCalls[0]['p']->resolve(self::answer('hi po', ['prompt_tokens' => 12, 'completion_tokens' => 7, 'total_tokens' => 19]));
        $pool->tick(false);

        $r = $this->row();
        $this->assertSame(['openai', 'gpt-4o-mini', 12, 7, false, 'async', null], [$r->provider, $r->model_used, (int) $r->prompt_tokens, (int) $r->completion_tokens, (bool) $r->fell_back, $r->path, $r->fallback_reason]);
        $this->assertGreaterThanOrEqual(0, (int) $r->latency_ms);
        $this->assertNotNull($r->latency_ms);
    }

    public function test_S_150_D2_local_failed_then_openai_records_the_fallback_with_the_local_attempt(): void
    {
        $pool = $this->pool();
        $this->useLocal();
        $this->live();
        $this->says('hello');
        $pool->tick();
        $this->travel(2)->seconds();
        $this->poolCalls[0]['p']->resolve(self::answer('x', null, 500));
        $pool->tick(false);
        $this->poolCalls[1]['p']->resolve(self::answer('openai answer', ['prompt_tokens' => 5, 'completion_tokens' => 3]));
        $pool->tick(false);

        $r = $this->row();
        $this->assertSame(['openai', true, 'fell back from Local: Local refused (HTTP 500).'], [$r->provider, (bool) $r->fell_back, $r->fallback_reason]);
        $attempts = json_decode($r->ai_attempts, true);
        $this->assertSame(['provider' => 'local', 'model' => 'qwen3:14b', 'ok' => false, 'why' => 'Local refused (HTTP 500).'], array_diff_key($attempts[0], ['ms' => 1]));
        $this->assertEqualsWithDelta(2000, $attempts[0]['ms'], 1000);
        $this->assertSame(['openai', true], [$attempts[1]['provider'], $attempts[1]['ok']]);
        $this->assertSame(['Reply: openai answer'], $this->sent());
    }

    public function test_S_150_D5_when_all_fail_every_attempt_has_its_reason_and_no_tokens(): void
    {
        $this->useLocal();
        $this->live();
        $this->localAnswers = [self::refused()];
        $this->openaiAnswers = [Http::response(['error' => 'x', 'usage' => ['prompt_tokens' => 9]], 500)];

        $this->says('hello');

        $r = $this->row();
        $this->assertSame([null, null, null, 'OpenAI refused (HTTP 500).'], [$r->provider, $r->prompt_tokens, $r->completion_tokens, $r->error]);
        $this->assertSame([['local', 'Local did not answer.'], ['openai', 'OpenAI refused (HTTP 500).']],
            array_map(fn ($a) => [$a['provider'], $a['why']], json_decode($r->ai_attempts, true)));
    }

    public function test_S_150_D6_the_record_holds_no_customer_text_or_answer(): void
    {
        $this->useLocal();
        $this->live();
        $this->localAnswers = [Http::response([], 500)];
        $this->openaiAnswers = [self::ai('LIHIM NA SAGOT')];

        $this->says('LIHIM NA TANONG');

        $r = (array) $this->row();
        $this->assertSame([null, null, null], [$r['messages'], $r['answer'], $r['context']]);
        $record = json_encode(array_intersect_key($r, array_flip(['provider', 'model_used', 'fell_back', 'fallback_reason', 'ai_attempts', 'latency_ms', 'prompt_tokens', 'completion_tokens', 'path', 'error'])));
        $this->assertStringNotContainsString('LIHIM', $record);
        $this->assertSame(['Reply: LIHIM NA SAGOT'], $this->sent());
    }

    public function test_S_150_D7_no_usage_leaves_tokens_null_not_zero(): void
    {
        $this->live('Page Uno');
        $this->openaiAnswers = [self::ai('walang usage')];

        $this->says('hello', 'Page Uno');

        $r = $this->row();
        $this->assertSame(['openai', null, null], [$r->provider, $r->prompt_tokens, $r->completion_tokens]);
    }

    public function test_S_150_D8_customer_text_goes_after_2_days_and_the_record_after_90(): void
    {
        $pool = $this->pool();
        $this->live('Page Uno');
        foreach (['7001' => 3, '7002' => 89, '7003' => 91] as $psid => $days) {
            $this->says('tanong ' . $psid, 'Page Uno', (string) $psid);
            $pool->tick();
            end($this->poolCalls)['p']->resolve(self::answer('sagot ' . $psid, ['prompt_tokens' => 1, 'completion_tokens' => 1]));
            $pool->tick(false);
            DB::table(ChatbotAiQueue::TABLE)->where('psid', (int) $psid)->update(['created_at' => now()->subDays($days)]);
        }
        $this->says('bago pa', 'Page Uno', '7004');
        $pool->tick();
        end($this->poolCalls)['p']->resolve(self::answer('bago'));
        $pool->tick(false);

        // Ang naiwang answered (hindi naituloy) na lampas 2 araw ay binubura pa rin gaya ng dati (may text, luma na ang usapan).
        DB::table(ChatbotAiQueue::TABLE)->where('psid', 7004)->update(['status' => 'answered']);
        $this->says('naiwan', 'Page Uno', '7005');
        $pool->tick();
        end($this->poolCalls)['p']->resolve(self::answer('naiwan'));
        $pool->tick(false);
        DB::table(ChatbotAiQueue::TABLE)->where('psid', 7005)->update(['status' => 'answered', 'created_at' => now()->subDays(3)]);
        DB::table(ChatbotAiQueue::TABLE)->where('psid', 7004)->update(['status' => 'resumed']);

        ChatbotAiQueue::sweep();

        $this->assertNull($this->row('7005'));
        $this->assertNull($this->row('7003'));
        $r = $this->row('7001');
        $this->assertSame([null, null, null, 'openai', 1], [$r->messages, $r->answer, $r->context, $r->provider, (int) $r->prompt_tokens]);
        $this->assertSame(['openai', 1], [$this->row('7002')->provider, (int) $this->row('7002')->prompt_tokens], 'the record stays until 90 days');
        $this->assertNotNull($this->row('7004')->messages, 'within 2 days the text stays');
    }

    public function test_S_150_D11_sync_and_test_tab_calls_are_recorded_as_sync(): void
    {
        $this->live('Page Uno');
        $this->says('hello', 'Page Uno');
        $this->assertSame(['sync', 'sync', 'openai'], [$this->row()->path, $this->row()->status, $this->row()->provider]);

        $this->actingAs($this->user());
        $this->postJson('/chatbot/test/send', ['page_name' => 'Page Uno', 'message' => 'test tab', 'version' => 'published'])->assertOk();
        $rows = DB::table(ChatbotAiQueue::TABLE)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['sync', 'sync'], $rows->pluck('path')->all());

        // Ang Bot Health ay nagbibilang lang ng tawag ng pool, gaya ng dati (ang sync na record ay walang hintay na masusukat).
        $calls = (fn () => $this->aiCalls(now()->subDay()->toDateTimeString(), now()->addDay()->toDateTimeString(), null))->call(app(\App\Services\Chatbot\BotHealth::class));
        $this->assertSame(0, array_sum(array_column($calls, 'calls')));
    }

    public function test_S_150_D12_before_the_migration_the_ai_step_works_as_today(): void
    {
        $migration = require base_path('database/migrations/2026_11_10_100000_add_provider_record_to_chatbot_ai_requests.php');
        $migration->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn(ChatbotAiQueue::TABLE, 'provider'));

        $this->useLocal();
        $this->live();
        $this->localAnswers = [Http::response([], 500)];
        $this->says('sync');
        $this->assertSame(['Reply: openai answer'], $this->sent());
        $this->assertSame(0, DB::table(ChatbotAiQueue::TABLE)->count(), 'no record without the columns');

        $pool = $this->pool();
        $this->says('async', self::TECH, '7002');
        $pool->tick();
        $this->poolCalls[0]['p']->resolve(self::answer('pool answer'));
        $pool->tick(false);
        $this->assertSame(['Reply: pool answer'], $this->sent('7002'));
        $this->travel(3)->days();
        ChatbotAiQueue::sweep();
        $this->assertSame(0, DB::table(ChatbotAiQueue::TABLE)->count(), 'pruned after 2 days as today');

        $migration->up();
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn(ChatbotAiQueue::TABLE, 'provider'));
    }

    public function test_S_150_D13_a_redispatch_never_doubles_the_record_or_its_tokens(): void
    {
        $pool = $this->pool();
        $this->live('Page Uno');
        $this->says('hello', 'Page Uno');
        $pool->tick();
        $this->poolCalls[0]['p']->resolve(self::answer('hi', ['prompt_tokens' => 4, 'completion_tokens' => 2]));
        $pool->tick(false);
        $row = $this->row();

        ResumeChatbotAi::dispatch(ChatbotAiQueue::ticket($row));
        $this->travel(31)->seconds();
        Artisan::call('chatbot:resume-skipped');

        $this->assertSame(1, DB::table(ChatbotAiQueue::TABLE)->count());
        $this->assertSame([4, 2], [(int) $this->row()->prompt_tokens, (int) $this->row()->completion_tokens]);
        $this->assertSame(['Reply: hi'], $this->sent());
    }
}
