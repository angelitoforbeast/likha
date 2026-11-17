<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Services\Chatbot\ChatbotAiPool;
use App\Services\Chatbot\ChatbotAiQueue;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotRunner;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowGraph;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;

/**
 * Spec 290: a restart of chatbot:ai-pool never leaves chats locked. The first pass of a new pool claims first and sweeps
 * right after, so the rows that went past their deadline while no pool ran are failed and resumed at once, and a sweep
 * that hits its time limit only logs a warning: the pool keeps claiming and the next sweep fails them.
 */
class ChatbotAiRestartTest extends ChatblastTestCase
{
    private const SEND = 'https://botcake.io/api/public_api/v1/pages/1001/flows/send_content';

    /** @var array<int, array{req: RequestInterface, p: Promise}> the "OpenAI" requests not answered yet */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_25_100000_create_chatbot_waits_table.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
            'database/migrations/2026_10_28_100000_create_chatbot_ai_requests_table.php',
        ]]);
        config(['services.chatbot.replies_enabled' => true, 'services.chatbot.ai_async' => true, 'services.chatbot.ai_concurrency' => 50,
            'services.openai.key' => 'sk-test-not-real', 'services.openai.timeout' => 60]);
        $this->page('Page Uno', '1001');
        Http::fake([self::SEND => Http::response(['success' => true])]);
        $this->live();
    }

    /** n2 AI → success: n3 "Reply: {{answer}}", failed: n4 "Sorry". */
    private function live(): void
    {
        $graph = FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start'],
            ['id' => 'n2', 'type' => 'ai', 'config' => ['user' => '{{last_input}}', 'save_to' => 'answer']],
            ['id' => 'n3', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Reply: {{answer}}']]]],
            ['id' => 'n4', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Sorry']]]],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2'], ['from' => 'n2', 'port' => 'success', 'to' => 'n3'], ['from' => 'n2', 'port' => 'failed', 'to' => 'n4']]]);
        $flow = ChatbotFlow::create(['name' => 'AI flow', 'graph' => $graph]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);
        app(ChatbotPage::class)->link('Page Uno', $flow->fresh());
    }

    private function pool(): ChatbotAiPool
    {
        return new ChatbotAiPool(function (RequestInterface $req, array $options) {
            $p = new Promise();
            $this->calls[] = ['req' => $req, 'p' => $p];

            return $p;
        });
    }

    private function says(string $message, string $psid): void
    {
        $chat = BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => $psid, 'name' => 'Ana Cruz', 'message' => $message, 'payload' => [], 'received_at' => now()]);
        app(ChatbotRunner::class)->handle($chat->id);
    }

    /** @return array<int, string> the texts sent to Botcake for the PSID, in order */
    private function sent(string $psid): array
    {
        return Http::recorded(fn (HttpRequest $r) => $r->url() === self::SEND && ($r->data()['psid'] ?? null) === $psid)
            ->map(fn ($p) => $p[0]->data()['data']['content']['messages'][0]['text'] ?? '')->values()->all();
    }

    /**
     * One pass of the pool's own loop (TicketPool::run): claim, heartbeat, sweep. On the next check of the stop flag every
     * open request is answered, so the clean stop has nothing left to wait for.
     */
    private function onePass(ChatbotAiPool $pool): void
    {
        $passes = 0;
        $pool->run(60, function () use (&$passes) {
            if (++$passes === 1) return false;
            foreach ($this->calls as $c) {
                if ($c['p']->getState() !== 'pending') continue;
                $messages = json_decode((string) $c['req']->getBody(), true)['messages'];
                $c['p']->resolve(new Response(200, ['Content-Type' => 'application/json'], json_encode(['choices' => [['message' => ['content' => 'answer to ' . end($messages)['content']]]]])));
            }

            return true;
        });
    }

    /** Five customers handed to the old pool, which then died; a new pool starts 61 s later (past the 60 s deadline). */
    private function restartAfterFiveLate(): ChatbotAiPool
    {
        $old = $this->pool();
        $this->assertTrue($old->start());
        foreach (['7001', '7002', '7003', '7004', '7005'] as $psid) $this->says('question ' . $psid, $psid);
        $this->assertSame(array_fill(0, 5, 'pending'), DB::table(ChatbotAiQueue::TABLE)->orderBy('id')->pluck('status')->all());

        $this->travel(61)->seconds();
        $new = $this->pool();
        $this->assertTrue($new->start(), 'the old heartbeat is stale');
        // A customer who writes right after the start: not late, so it is claimed normally.
        $this->says('question 7006', '7006');

        return $new;
    }

    private function locked(string $psid): bool
    {
        return ChatbotAiQueue::pending(new CustomerFields(1001, (int) $psid));
    }

    public function test_SW3_rows_past_their_deadline_at_start_are_failed_and_resumed_on_the_first_pass(): void
    {
        $pool = $this->restartAfterFiveLate();
        foreach (['7001', '7002', '7003', '7004', '7005'] as $psid) $this->assertTrue($this->locked($psid), 'locked before the first pass');

        $this->onePass($pool);

        foreach (['7001', '7002', '7003', '7004', '7005'] as $psid) {
            $this->assertSame(['Sorry'], $this->sent($psid), "$psid took the failed port");
            $this->assertFalse($this->locked($psid), "$psid is no longer locked");
            $this->assertSame('resumed', DB::table(ChatbotAiQueue::TABLE)->where('psid', (int) $psid)->value('status'));
        }
        $this->assertCount(1, $this->calls, 'only the row that was not late went to the AI');
        $this->assertSame(['Reply: answer to question 7006'], $this->sent('7006'), 'claimed in the same pass and answered normally');
    }

    public function test_SW3_a_row_not_yet_past_its_deadline_is_left_alone_by_the_sweep_and_claimed(): void
    {
        $pool = $this->pool();
        $this->assertTrue($pool->start());
        $this->says('question 7001', '7001');
        $this->travel(59)->seconds(); // one second before its deadline
        ChatbotAiQueue::sweep();
        $this->assertSame('pending', DB::table(ChatbotAiQueue::TABLE)->value('status'), 'the sweep leaves it');

        $this->onePass($pool);

        $this->assertCount(1, $this->calls);
        $this->assertSame(['Reply: answer to question 7001'], $this->sent('7001'));
        $this->assertFalse($this->locked('7001'));
    }

    public function test_SW2_a_sweep_that_hits_the_time_limit_logs_one_warning_and_the_pool_keeps_claiming(): void
    {
        $pool = $this->restartAfterFiveLate();
        // The deadline query fails as MySQL fails a SELECT past its MAX_EXECUTION_TIME.
        $slow = true;
        DB::connection()->beforeExecuting(function (string $sql, array $bindings, $connection) use (&$slow) {
            if (!$slow || !str_starts_with($sql, 'select') || !str_contains($sql, '"chatbot_ai_requests"') || !str_contains($sql, '"deadline_at" <= ?')) return;
            $pdo = new \PDOException('SQLSTATE[HY000]: General error: 3024 Query execution was interrupted, maximum statement execution time exceeded');
            $pdo->errorInfo = ['HY000', 3024, 'Query execution was interrupted, maximum statement execution time exceeded'];
            throw new QueryException($connection->getName(), $sql, $bindings, $pdo);
        });
        Log::spy();

        $this->onePass($pool); // no exception: the pool does not crash

        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($msg) => $msg === 'chatbot AI sweep stopped at its 10000 ms limit; the next sweep tries again');
        $this->assertSame(['Reply: answer to question 7006'], $this->sent('7006'), 'the pool still claimed and answered the new row');
        foreach (['7001', '7002', '7003', '7004', '7005'] as $psid) $this->assertTrue($this->locked($psid), 'not failed yet: that sweep stopped');

        // The next sweep, with the database fast again, fails and resumes them.
        $slow = false;
        $this->onePass($pool);
        foreach (['7001', '7002', '7003', '7004', '7005'] as $psid) {
            $this->assertSame(['Sorry'], $this->sent($psid));
            $this->assertFalse($this->locked($psid));
        }
    }
}
