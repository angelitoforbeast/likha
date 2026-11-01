<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\ResumeChatbotSequenceSend;
use App\Jobs\RunChatbotSequenceStep;
use App\Services\BotcakeClient;
use App\Services\Chatbot\BotcakeSendPool;
use App\Services\Chatbot\BotcakeSendQueue;
use App\Services\PageSettings;
use Carbon\Carbon;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;

/**
 * Spec 215, ang bench: 4 na worker sa virtual na oras (bawat job ay ang sinukat na tagal nito; ang padalang naghihintay ay
 * + 2.9 s), ang fake na API na sumasagot pagkalipas ng 2.9 s, ang pool kada 10 ms. Ang pantay na halo (600 step, 40 page)
 * at ang hindi pantay (isang minuto ng 17k kada oras, ang pinakamalaking page ay 6.5 %), sa 2/s at 10/s kada page. Ang
 * kontrol ay ang parehong fixture na naka-off ang hand-off (ang dating daan).
 */
class SequenceBenchTest extends SequenceHandoffTestCase
{
    private const LATENCY = 2.9;
    private const WORKERS = 4;
    private const DT = 0.01;

    /** @var array<int, array{t: float, page: string, done: bool, p: Promise}> */
    private array $benchCalls = [];

    /** @param array<int, int> $mix steps per page */
    private function fixture(array $mix): void
    {
        $flow = $this->stepFlow(['Hi']);
        $seq = $this->sequence('Bench', [$flow->id]);
        $chats = [];
        $subs = [];
        $psid = 500000;
        foreach ($mix as $i => $n) {
            $page = 'Bench ' . $i;
            $id = (string) (3000 + $i);
            $this->page($page, $id);
            app(PageSettings::class)->set($page, 'botcake', 'token', 'tok-bench-' . $i);
            for ($k = 0; $k < $n; $k++) {
                $psid++;
                $chats[] = ['page_id' => $id, 'page_name' => $page, 'psid' => (string) $psid, 'name' => 'Ana', 'message' => 'Hi', 'payload' => '[]',
                    'received_at' => now()->subMinutes(5)];
                $subs[] = ['sequence_id' => $seq->id, 'page_id' => (int) $id, 'psid' => $psid, 'status' => 'active', 'current_step' => 1, 'next_send_at' => now(),
                    'dispatched_at' => null, 'enrolled_via' => 'other', 'created_at' => now(), 'updated_at' => now()];
            }
        }
        foreach (array_chunk($chats, 300) as $c) DB::table('botcake_chats')->insert($c);
        foreach (array_chunk($subs, 300) as $c) DB::table('chatbot_sequence_subscriptions')->insert($c);
        \App\Models\BotcakeChat::forgetPageMap();
    }

    /**
     * Run the fixture in virtual time until every step is done or $limit seconds passed.
     * @return array{done: int, finished: ?float, peak: int, gaps: array<string, float>, reply: ?float}
     */
    private function simulate(int $total, float $limit, ?float $replyAt = null): array
    {
        $base = Carbon::parse('2026-11-02 10:00:00');
        $this->pool->stop();
        $this->replyPool->stop();
        $this->pool = new \App\Services\Chatbot\SequenceSendPool(function (RequestInterface $req) {
            $p = new Promise();
            $this->benchCalls[] = ['t' => $this->clock->now(), 'page' => explode('/', $req->getUri()->getPath())[5] ?? '', 'p' => $p];

            return $p;
        }, $this->clock);
        $this->assertTrue($this->pool->start());
        $replyCalls = [];
        $this->replyPool = new BotcakeSendPool(function (RequestInterface $req) use (&$replyCalls) {
            $replyCalls[] = $this->clock->now();

            return new \GuzzleHttp\Promise\FulfilledPromise(new Response(200, [], '{"success":true}'));
        }, $this->clock);
        // An inline send waits on the API (2.9 s) and on the page's slot: the job's virtual cost, not the clock's.
        $cost = 0.0;
        Http::fake(['botcake.io/*' => function () use (&$cost) {
            $cost += self::LATENCY;
            return Http::response(['success' => true]);
        }]);
        $this->clock->onSleep = function (float $s) use (&$cost) {
            $cost += $s;
            return true;
        };
        $t0 = $this->clock->time;
        $busy = array_fill(0, self::WORKERS, 0.0);
        $fifo = [];
        $seen = [RunChatbotSequenceStep::class => 0, ResumeChatbotSequenceSend::class => 0];
        $peak = 0;
        $finished = null;
        $reply = null;
        $replyTicket = null;
        $lastBeat = -1;
        $this->tick();
        for ($t = 0.0; $t <= $limit; $t += self::DT) {
            $this->clock->time = $t0 + $t;
            Carbon::setTestNow($base->copy()->addMilliseconds((int) round($t * 1000)));
            if ((int) $t !== $lastBeat) {
                $lastBeat = (int) $t;
                $this->pool->beat();
                $this->replyPool->beat();
            }
            if ($replyAt !== null && $replyTicket === null && $t >= $replyAt) {
                $replyTicket = DB::table(BotcakeSendQueue::TABLE)->insertGetId(['request_id' => 'reply-bench', 'kind' => 'chatbot', 'priority' => 0,
                    'customer' => BotcakeSendQueue::chatbotCustomer(3000, 1), 'page_id' => 3000, 'page_name' => 'Bench 0', 'psid' => '1', 'node_id' => 'n2', 'flow_id' => 1,
                    'payload' => json_encode(['messages' => [['type' => 'text', 'text' => 'first reply']]]), 'status' => 'pending', 'deadline_at' => now()->addMinutes(10),
                    'created_at' => now(), 'updated_at' => now()]);
            }
            foreach ($seen as $class => $n) {
                $jobs = Queue::pushed($class)->values();
                for ($i = $n; $i < $jobs->count(); $i++) $fifo[] = $jobs[$i];
                $seen[$class] = $jobs->count();
            }
            foreach ($busy as $w => $until) {
                if ($until > $t || !$fifo) continue;
                $job = array_shift($fifo);
                if ($job instanceof ShouldBeUniqueUntilProcessing) (new UniqueLock(app('cache.store')))->release($job);
                $cost = 0.0;
                $start = microtime(true);
                app()->call([$job, 'handle']);
                $busy[$w] = $t + (microtime(true) - $start) + $cost;
            }
            foreach ($this->benchCalls as &$c) {
                if (!isset($c['done']) && $t0 + $t >= $c['t'] + self::LATENCY) {
                    $c['done'] = true;
                    $c['p']->resolve(new Response(200, ['Content-Type' => 'application/json'], '{"success":true}'));
                }
            }
            unset($c);
            $this->pool->tick();
            $this->replyPool->tick();
            if ($replyTicket !== null && $reply === null && $replyCalls) $reply = end($replyCalls) - ($t0 + $replyAt);
            $peak = max($peak, count(array_filter($this->benchCalls, fn ($c) => !isset($c['done']))));
            $done = DB::table('chatbot_sequence_subscriptions')->where('status', 'completed')->count();
            if ($done === $total && $finished === null) $finished = $t;
            if ($finished !== null && ($replyAt === null || $reply !== null)) break;
        }
        $this->clock->onSleep = null;
        $gaps = [];
        foreach (collect($this->benchCalls)->groupBy('page') as $page => $calls) {
            $ts = $calls->pluck('t')->sort()->values();
            for ($i = 1; $i < $ts->count(); $i++) $gaps[$page] = min($gaps[$page] ?? INF, $ts[$i] - $ts[$i - 1]);
        }

        return ['done' => DB::table('chatbot_sequence_subscriptions')->where('status', 'completed')->count(), 'finished' => $finished, 'peak' => $peak, 'gaps' => $gaps,
            'reply' => $reply, 'rows' => DB::table('chatbot_replies')->where('status', 'sent')->count()];
    }

    /** One minute of 17k an hour (about 285 steps): the top page 6.5 %, then a long tail over 60 pages. */
    private static function skewed(): array
    {
        $total = 285;
        $mix = [0 => (int) round($total * 0.065)];
        $weights = [];
        for ($k = 2; $k <= 60; $k++) $weights[$k - 1] = 61 - $k; // a long tail, each page below the top page
        $rest = $total - $mix[0];
        $sum = array_sum($weights);
        foreach ($weights as $i => $w) $mix[$i] = max(1, (int) floor($rest * $w / $sum));
        for ($i = 1; array_sum($mix) < $total; $i = $i % 59 + 1) $mix[$i]++;

        return $mix;
    }

    public static function mixes(): array
    {
        $even = array_fill(0, 40, 15);

        return ['even at 2/s' => [$even, 2.0], 'even at 10/s' => [$even, 10.0], 'skewed at 2/s' => [self::skewed(), 2.0], 'skewed at 10/s' => [self::skewed(), 10.0]];
    }

    #[DataProvider('mixes')]
    public function test_bench_the_due_steps_are_sent_within_60_virtual_seconds_at_the_page_rate(array $mix, float $rate): void
    {
        config(['services.botcake.rate_per_page' => $rate]);
        $total = array_sum($mix);
        $this->fixture($mix);
        $r = $this->simulate($total, 120);

        $this->assertSame($total, $r['done'], 'every step done');
        $this->assertSame($total, $r['rows'], 'one sent row per step');
        $this->assertCount($total, $this->benchCalls, 'one call per step');
        $this->assertLessThanOrEqual(60, $r['finished'], 'within 60 virtual seconds');
        $this->assertGreaterThanOrEqual(40, $r['peak'], 'peak in flight');
        foreach ($r['gaps'] as $page => $gap) $this->assertGreaterThanOrEqual(1 / $rate - 1e-6, $gap, 'page ' . $page . ' stays at its rate');
        fwrite(STDERR, sprintf("\n[bench] %d steps on %d pages at %.0f/s: finished %.2f s, peak in flight %d, top page %d steps\n", $total, count($mix), $rate, $r['finished'], $r['peak'], max($mix)));
    }

    #[DataProvider('mixes')]
    public function test_bench_control_the_same_fixture_through_the_old_inline_path_is_slower_than_the_bound(array $mix, float $rate): void
    {
        config(['services.botcake.rate_per_page' => $rate, 'services.chatbot.sequence_handoff' => false]);
        $total = array_sum($mix);
        $this->fixture($mix);
        $r = $this->simulate($total, 60);

        $this->assertNull($r['finished'], 'not done in 60 virtual seconds');
        $this->assertLessThan($total, $r['done']);
        $this->assertSame([], $this->benchCalls, 'the old path never used the pool');
        fwrite(STDERR, sprintf("\n[control] %d steps at %.0f/s: %d done in 60 s\n", $total, $rate, $r['done']));
    }

    public function test_S_215_1_four_workers_300_steps_on_20_pages_peak_at_least_40_all_within_60_virtual_seconds(): void
    {
        $this->fixture(array_fill(0, 20, 15));
        $r = $this->simulate(300, 120);
        $this->assertSame(300, $r['done']);
        $this->assertGreaterThanOrEqual(40, $r['peak']);
        $this->assertLessThanOrEqual(60, $r['finished']);
    }

    public function test_S_215e_7_a_first_reply_on_a_busy_page_is_not_slower_than_with_the_old_code(): void
    {
        $mix = array_fill(0, 40, 50); // 2,000 steps
        $this->fixture($mix);
        $new = $this->simulate(2000, 8, 5.0)['reply'];

        $this->refreshDatabase();
        config(['services.chatbot.sequence_handoff' => false]);
        $this->fixture($mix);
        $old = $this->simulate(2000, 8, 5.0)['reply'];

        $this->assertNotNull($new);
        $this->assertNotNull($old);
        // The sequences now use the page's rate, which the old path hardly reached on this fixture: a reply waits at most the one
        // slot already taken on its page (the reply pool reserves the next one), never behind the sequence queue.
        $this->assertLessThanOrEqual(max($old, BotcakeClient::interval()) + self::DT + 1e-6, $new, 'no later than the old code, or one page slot');
        $this->assertLessThanOrEqual(2 * BotcakeClient::interval() + 0.05, $new, 'within two of the page\'s slots');
        fwrite(STDERR, sprintf("\n[first reply] 2,000 steps in flight: %.2f s (old code %.2f s)\n", $new, $old));
    }

    /** Empty the tables between the two runs of one test (same schema). */
    private function refreshDatabase(): void
    {
        foreach (['chatbot_sequence_subscriptions', 'botcake_chats', 'chatbot_replies', 'chatbot_events', BotcakeSendQueue::TABLE, 'chatbot_sequences', 'chatbot_sequence_steps', 'chatbot_flows'] as $t) {
            DB::table($t)->delete();
        }
        \Illuminate\Support\Facades\Cache::flush();
        Queue::fake();
        $this->benchCalls = [];
        Carbon::setTestNow('2026-11-02 10:00:00');
    }
}
