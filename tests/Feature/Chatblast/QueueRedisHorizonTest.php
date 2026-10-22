<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\ResumeChatbotAi;
use App\Jobs\ResumeChatbotWait;
use App\Jobs\RunChatbotFlow;
use App\Jobs\RunChatbotSequenceStep;
use App\Jobs\SendChatblastMessage;
use App\Models\BotcakeChat;
use App\Models\BotcakeMessage;
use App\Models\ChatbotFlow;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotRunner;
use App\Services\Chatbot\FlowGraph;
use App\Services\ChatblastSender;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Redis at Horizon para sa chatbot at chatblast (spec 088): ang Delivering ay sa chatblast-high, ang connection ng bawat
 * queue ay mula sa env (pabalik sa database nang walang deploy), ang /horizon ay CEO lang, at ang lock ng customer ay
 * gumagana sa cache store ng test (at sa Redis kapag may Redis sa makinang ito).
 */
class QueueRedisHorizonTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
        ]]);
    }

    private function message(string $kind): BotcakeMessage
    {
        return BotcakeMessage::create(['page_name' => 'Page Uno', 'waybill' => 'JT-' . $kind, 'kind' => $kind, 'psid' => '7001', 'text' => 'Hi', 'status' => 'ready', 'is_test' => false]);
    }

    public function test_S_088_1_a_delivering_message_goes_to_chatblast_high_and_an_in_transit_one_to_chatblast(): void
    {
        Queue::fake();
        $delivering = $this->message('delivering');
        $transit = $this->message('in_transit');

        $this->assertSame(2, app(ChatblastSender::class)->queue([$transit->id, $delivering->id], 1));

        Queue::assertPushedOn('chatblast-high', SendChatblastMessage::class, fn ($job) => $job->messageId === $delivering->id);
        Queue::assertPushedOn('chatblast', SendChatblastMessage::class, fn ($job) => $job->messageId === $transit->id);
        Queue::assertNotPushed(SendChatblastMessage::class, fn ($job) => $job->messageId === $transit->id && $job->queue !== 'chatblast');

        // Still on the database driver (before the cutover, or after a rollback): everything stays on "chatblast", the only
        // queue the old worker group listens to.
        config(['queue.chatblast_connection' => 'database']);
        SendChatblastMessage::dispatch($delivering->id);
        $this->assertSame(['database', 'chatblast'], [Queue::pushed(SendChatblastMessage::class)->last()->connection, Queue::pushed(SendChatblastMessage::class)->last()->queue]);

        // Horizon's chatblast supervisor works both queues, and every timeout stays under the Redis retry_after (no job runs twice).
        $this->assertSame(['chatblast-high', 'chatblast'], config('horizon.defaults.chatblast.queue'), 'a pool per queue: Delivering has its own workers');
        $this->assertGreaterThanOrEqual(2, config('horizon.defaults.chatblast.minProcesses'), 'per queue in balance auto');
        $this->assertSame(['chatbot-high', 'chatbot'], config('horizon.defaults.chatbot.queue'));
        $retry = config('queue.connections.redis.retry_after');
        foreach ([config('horizon.defaults.chatbot.timeout'), config('horizon.defaults.chatblast.timeout'), (new SendChatblastMessage($transit->id))->timeout, (new RunChatbotFlow(1))->timeout] as $timeout) {
            $this->assertLessThan($retry, $timeout);
        }
    }

    public function test_S_088_2_each_queue_takes_its_connection_from_env_and_falls_back_to_the_default(): void
    {
        $env = function (string $name, ?string $value) {
            foreach ([&$_ENV, &$_SERVER] as &$bag) {
                if ($value === null) unset($bag[$name]); else $bag[$name] = $value;
            }
            putenv($value === null ? $name : "{$name}={$value}");
        };
        foreach (['CHATBOT_QUEUE_CONNECTION' => 'chatbot_connection', 'CHATBLAST_QUEUE_CONNECTION' => 'chatblast_connection'] as $name => $key) {
            $env($name, 'redis');
            $this->assertSame('redis', (require base_path('config/queue.php'))[$key], $name);
            $env($name, null);
            $env('QUEUE_CONNECTION', 'database');
            $this->assertSame('database', (require base_path('config/queue.php'))[$key], "{$name} unset: the default connection");
            $env('QUEUE_CONNECTION', 'sync'); // phpunit.xml
        }

        Queue::fake();
        config(['queue.chatbot_connection' => 'redis', 'queue.chatblast_connection' => 'redis-chatblast']);
        RunChatbotFlow::dispatch(1);
        ResumeChatbotAi::dispatch(['request_id' => 'x']);
        ResumeChatbotWait::dispatch(1);
        RunChatbotSequenceStep::dispatch(1);
        SendChatblastMessage::dispatch($this->message('in_transit')->id);
        foreach ([RunChatbotFlow::class => 'chatbot-high', ResumeChatbotAi::class => 'chatbot-high', ResumeChatbotWait::class => 'chatbot', RunChatbotSequenceStep::class => 'chatbot'] as $job => $queue) {
            $this->assertSame(['redis', $queue], [Queue::pushed($job)->first()->connection, Queue::pushed($job)->first()->queue], $job);
        }
        $this->assertSame(['redis-chatblast', 'chatblast'], [Queue::pushed(SendChatblastMessage::class)->first()->connection, Queue::pushed(SendChatblastMessage::class)->first()->queue]);
    }

    public function test_S_088_3_horizon_is_open_to_the_ceo_only(): void
    {
        $this->get('/horizon')->assertRedirect(); // not signed in: the login page

        $this->actingAs($this->user('Marketing - OIC', 'oic@example.test'));
        $this->get('/horizon')->assertForbidden();
        $this->get('/horizon/api/stats')->assertForbidden();
        $this->app['env'] = 'local'; // Horizon's own default opens the dashboard to everyone on "local"
        $this->get('/horizon')->assertForbidden();
        $this->app['env'] = 'testing';

        $this->actingAs($this->user());
        $this->get('/horizon')->assertOk();
    }

    public static function stores(): array
    {
        return ['the test store (array)' => ['array'], 'redis, when a Redis server runs on this machine' => ['redis']];
    }

    /** @dataProvider stores */
    public function test_S_088_4_the_chatbot_and_chatblast_customer_locks_still_serialise(string $store): void
    {
        if ($store === 'redis') {
            config(['database.redis.client' => 'predis', 'database.redis.default.timeout' => 0.5, 'database.redis.cache.timeout' => 0.5]);
            try {
                Cache::store('redis')->get('ping');
            } catch (\Throwable $e) {
                $this->markTestSkipped('No Redis server on this machine.');
            }
        }
        config(['cache.default' => $store]);
        Cache::store($store)->forget('ping');

        // Chatbot: while another run holds the customer, a chat for that customer is "locked" (its job comes back later).
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::clean(['nodes' => [['id' => 'n1', 'type' => 'start']], 'edges' => []])]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);
        app(ChatbotPage::class)->link('Page Uno', $flow->fresh());
        $chat = BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => '7001', 'name' => 'Ana', 'message' => 'hi', 'payload' => [], 'received_at' => now()]);
        $held = Cache::lock('chatbot-run:1001:7001', 300);
        $this->assertTrue($held->get());
        $this->assertSame('locked', app(ChatbotRunner::class)->handle($chat->id));
        $held->release();
        $this->assertSame('done', app(ChatbotRunner::class)->handle($chat->id));

        // Chatblast: one message at a time per customer.
        $key = ChatblastSender::customerLockKey('Page Uno', '7001');
        $first = Cache::lock($key, 300);
        $this->assertTrue($first->get());
        $this->assertFalse(Cache::lock($key, 300)->get(), 'a second job for the same customer waits');
        $this->assertTrue(Cache::lock(ChatblastSender::customerLockKey('Page Uno', '7002'), 300)->get(), 'another customer does not wait');
        $first->release();
        $this->assertTrue(Cache::lock($key, 300)->get());
        Cache::lock($key)->forceRelease();
        Cache::lock(ChatblastSender::customerLockKey('Page Uno', '7002'))->forceRelease();
    }
}
