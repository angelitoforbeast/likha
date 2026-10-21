<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\MarkChatbotReplied;
use App\Jobs\ResumeChatbotAi;
use App\Jobs\ResumeChatbotSend;
use App\Jobs\ResumeChatbotWait;
use App\Jobs\RunChatbotFlow;
use App\Jobs\RunChatbotSequenceStep;
use App\Models\ChatbotFlow;
use App\Models\ChatbotSequence;
use App\Services\Chatbot\ChatbotSequences;
use App\Services\Chatbot\FlowGraph;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ang unang sagot sa bagong customer ay hindi naghihintay sa likod ng mga sequence step (sariling queue na chatbot-high),
 * at ang mabagal na queue ay hindi napupuno ng kopya ng parehong step (isang job lang kada subscription habang naghihintay).
 */
class ChatbotPriorityTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_22_100000_widen_source_on_botcake_psids.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_25_100000_create_chatbot_waits_table.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
        ]]);
        config(['services.chatbot.replies_enabled' => true]);
        $this->page('Page Uno', '1001');
        Http::fake(['*' => Http::response(['success' => true])]);
        $this->travelTo('2026-10-20 10:00:00');
    }

    /** Isang sequence na may isang step na due na agad, at ang subscription ng customer. */
    private function due(string $psid = '7001'): int
    {
        $flow = ChatbotFlow::firstOrCreate(['name' => 'Flow'], ['graph' => FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Seq 1']]]],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]])]);
        $seq = ChatbotSequence::firstOrCreate(['name' => 'Preorder'], ['active' => true, 'exit_on_reply' => true]);
        if (!$seq->steps()->exists()) $seq->steps()->create(['position' => 1, 'delay_amount' => 0, 'delay_unit' => 'minutes', 'flow_id' => $flow->id, 'active' => true]);
        app(ChatbotSequences::class)->subscribe($seq->id, 1001, (int) $psid, 'other');

        return (int) DB::table('chatbot_sequence_subscriptions')->where('psid', (int) $psid)->value('id');
    }

    public static function connections(): array
    {
        return ['redis (Horizon)' => ['redis'], 'database (the queue:work fallback)' => ['database']];
    }

    #[DataProvider('connections')]
    public function test_S_108_1_the_reply_path_goes_to_chatbot_high_and_the_rest_stays_on_chatbot(string $connection): void
    {
        Queue::fake();
        config(['queue.chatbot_connection' => $connection]);
        RunChatbotFlow::dispatch(1);
        ResumeChatbotAi::dispatch(['request_id' => 'a']);
        ResumeChatbotSend::dispatch(['request_id' => 's']);
        RunChatbotSequenceStep::dispatch(1);
        ResumeChatbotWait::dispatch(1);
        MarkChatbotReplied::dispatch(1);

        foreach ([RunChatbotFlow::class => 'chatbot-high', ResumeChatbotAi::class => 'chatbot-high', ResumeChatbotSend::class => 'chatbot-high',
            RunChatbotSequenceStep::class => 'chatbot', ResumeChatbotWait::class => 'chatbot', MarkChatbotReplied::class => 'chatbot'] as $job => $queue) {
            $this->assertSame([$connection, $queue], [Queue::pushed($job)->first()->connection, Queue::pushed($job)->first()->queue], $job);
        }
    }

    public function test_S_108_2_horizon_works_chatbot_high_first_with_its_own_workers_inside_the_same_cap(): void
    {
        $chatbot = config('horizon.defaults.chatbot');
        $this->assertSame(['chatbot-high', 'chatbot'], $chatbot['queue']);
        // balance auto: isang pool kada queue, kaya ang chatbot-high ay laging may sariling worker (minProcesses kada queue)
        // at hindi naghihintay sa likod ng chatbot; ang maxProcesses ay para sa buong supervisor, gaya ng dati.
        $this->assertSame('auto', $chatbot['balance']);
        $this->assertGreaterThanOrEqual(2, $chatbot['minProcesses']);
        $this->assertSame(16, $chatbot['maxProcesses']);
    }

    public function test_S_108_3_a_second_dispatch_of_the_same_subscriptions_step_while_one_is_queued_is_dropped(): void
    {
        Queue::fake();
        RunChatbotSequenceStep::dispatch(5, '2026-10-20 10:00:00');
        RunChatbotSequenceStep::dispatch(5, '2026-10-20 10:11:00');
        RunChatbotSequenceStep::dispatch(6, '2026-10-20 10:00:00');

        $this->assertSame([5, 6], Queue::pushed(RunChatbotSequenceStep::class)->map(fn ($j) => $j->subscriptionId)->all());
        $this->assertSame('2026-10-20 10:00:00', Queue::pushed(RunChatbotSequenceStep::class)->first()->claim, 'the queued job keeps its claim');
        $this->assertGreaterThanOrEqual(3 * 3600, (new RunChatbotSequenceStep(5))->uniqueFor, 'longer than the worst backlog');
    }

    public static function waiting(): array
    {
        return ['claimed 11 minutes ago' => [11], 'unclaimed (e.g. re-subscribed while its old job waits)' => [null]];
    }

    #[DataProvider('waiting')]
    public function test_S_108_4_the_tick_leaves_a_subscription_alone_while_its_job_still_waits(?int $minutesAgo): void
    {
        Queue::fake();
        $id = $this->due();
        Artisan::call('chatbot:sequence-tick');
        Queue::assertPushed(RunChatbotSequenceStep::class, 1);

        $this->travel(11)->minutes();
        $stamp = $minutesAgo === null ? null : now()->subMinutes($minutesAgo)->format('Y-m-d H:i:s');
        DB::table('chatbot_sequence_subscriptions')->where('id', $id)->update(['dispatched_at' => $stamp]);
        for ($i = 0; $i < 15; $i++) {
            Artisan::call('chatbot:sequence-tick');
            $this->travel(1)->minutes();
        }

        Queue::assertPushed(RunChatbotSequenceStep::class, 1);
        $this->assertSame($stamp, DB::table('chatbot_sequence_subscriptions')->where('id', $id)->value('dispatched_at'), 'no new claim');
    }

    public function test_S_108_5_a_job_that_died_after_it_started_is_queued_again_after_10_minutes(): void
    {
        $id = $this->due();
        // Ang worker ay namatay sa gitna ng step: nakuha na ng Laravel ang lock bago ang handle, ang stamp ay naiwan.
        $this->app->bind(ChatbotSequences::class, fn () => new class extends ChatbotSequences {
            public function __construct() {}
            public function runStep(int $subscriptionId, ?string $claim = null): string { throw new \RuntimeException('worker died'); }
        });
        $real = $this->app->build(ChatbotSequences::class);
        config(['queue.chatbot_connection' => 'sync']);
        try {
            $real->tick();
            $this->fail('the sync job should have died');
        } catch (\RuntimeException $e) {
            $this->assertSame('worker died', $e->getMessage());
        }
        $this->assertNotNull(DB::table('chatbot_sequence_subscriptions')->where('id', $id)->value('dispatched_at'));

        Queue::fake();
        $this->travel(9)->minutes();
        $real->tick();
        Queue::assertNothingPushed();
        $this->travel(2)->minutes();
        $real->tick();
        Queue::assertPushed(RunChatbotSequenceStep::class, fn ($j) => $j->subscriptionId === $id);
        $this->assertFalse(Cache::lock(UniqueLock::getKey(new RunChatbotSequenceStep($id)), 1)->get(), 'the new job holds the lock');
    }

    public function test_S_108_6_a_full_batch_of_waiting_jobs_does_not_hide_the_due_rows_behind_it(): void
    {
        Queue::fake();
        $first = $this->due();
        $seq = DB::table('chatbot_sequence_subscriptions')->where('id', $first)->value('sequence_id');
        // Isang buong batch na naghihintay pa sa queue (hawak ang lock, claim na lampas 10 minuto), at sa likod nila ay
        // isang due na walang job.
        $rows = [];
        for ($k = 1; $k < ChatbotSequences::BATCH; $k++) {
            $rows[] = ['sequence_id' => $seq, 'page_id' => 1001, 'psid' => 8000 + $k, 'status' => 'active', 'current_step' => 1, 'next_send_at' => now()->subHours(2),
                'dispatched_at' => null, 'enrolled_via' => 'other', 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('chatbot_sequence_subscriptions')->insert($rows);
        DB::table('chatbot_sequence_subscriptions')->update(['next_send_at' => now()->subHours(2), 'dispatched_at' => now()->subMinutes(11)]);
        foreach (DB::table('chatbot_sequence_subscriptions')->pluck('id') as $id) {
            $this->assertTrue((new UniqueLock(app('cache.store')))->acquire(new RunChatbotSequenceStep((int) $id)));
        }
        $behind = $this->due('7999');
        DB::table('chatbot_sequence_subscriptions')->where('id', $behind)->update(['next_send_at' => now()->subHour()]);

        $start = microtime(true);
        $this->assertSame(1, app(ChatbotSequences::class)->tick());
        Queue::assertPushed(RunChatbotSequenceStep::class, fn ($j) => $j->subscriptionId === $behind);
        $this->assertLessThan(ChatbotSequences::SECONDS / 2, microtime(true) - $start, 'one pass over the waiting rows, not a 50 s spin');
    }
}
