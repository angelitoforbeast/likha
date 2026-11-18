<?php

namespace Tests\Feature\Chatblast;

use App\Models\AppSetting;
use App\Models\BotcakeChat;
use App\Services\Chatbot\BotcakeSendQueue;
use App\Services\Chatbot\SequenceSendPool;
use App\Services\PageSettings;
use Carbon\Carbon;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;

/**
 * Spec 301, ang load test ng chatbot:sequence-pool: libu-libong due na ticket sa ~120 page, fake na Botcake na sumasagot
 * pagkalipas ng 5 s, at ang pool sa virtual na oras. Ang bawat ikot ng pool ay may halaga: ang totoong oras ng ikot (PHP at
 * sqlite) + ang bilang ng query × ang oras ng isang query sa production (QUERY_MS, tinantya mula sa base na 10.7 kada
 * segundo), + ang pahinga ng loop (queue.pools.busy_ms). Ang bawat proseso ay may sariling oras, kaya ang ilang pool ay
 * tumatakbo nang sabay gaya ng ilang proseso.
 */
class SequencePoolThroughputTest extends SequenceHandoffTestCase
{
    private const LATENCY = 5.0; // ang fake na Botcake: sagot pagkalipas ng 5 s
    public const QUERY_MS = 9.0;  // tinantya: sa 9 ms, ang base (isang pool, 250 slot) ay ~10.7 kada segundo, gaya ng production (spec 301)

    /** @var array<int, array{t: float, shard: int, psid: string, page: string, p: Promise, done?: bool}> */
    private array $loadCalls = [];
    /** @var array<string, array{n: int, ms: float}> */
    private array $byClass = [];
    private int $queries = 0;
    private float $queryMs = self::QUERY_MS;
    private bool $paused = false;
    private bool $untilDone = false;
    private bool $listening = false;

    /**
     * $pages page (sariling token bawat isa), $total na due na ticket (isang customer bawat isa; ang ilang page ay mas
     * marami), ang subscription na may hawak ng ticket at ang chat sa loob ng 24 oras. $twice: ilang customer ang may
     * dalawang ticket (dalawang sequence, iisang customer: ang ikalawa ay naghihintay sa una, ang ayos kada customer).
     * @return array<int, string> ang mga PSID na may dalawang ticket
     */
    protected function load(int $pages, int $total, int $twice = 0): array
    {
        $flow = $this->stepFlow(['Hi']);
        $seq = $this->sequence('Load', [$flow->id, $flow->id]);
        $seq2 = $this->sequence('Load two', [$flow->id]);
        $weights = [];
        for ($i = 0; $i < $pages; $i++) $weights[$i] = $i < 6 ? 4 : 1; // ang ilang abalang page
        $sum = array_sum($weights);
        $names = [];
        for ($i = 0; $i < $pages; $i++) {
            $names[$i] = ['Load ' . $i, (string) (40000 + $i * 7)];
            $this->page($names[$i][0], $names[$i][1]);
            app(PageSettings::class)->set($names[$i][0], 'botcake', 'token', 'tok-load-' . $i);
        }
        $chats = $subs = $tickets = [];
        $psid = 900000;
        $twins = [];
        $stamp = now()->format('Y-m-d H:i:s');
        $n = 0;
        for ($i = 0; $i < $pages && $n < $total; $i++) {
            $count = $i === $pages - 1 ? $total - $n : max(1, (int) round($total * $weights[$i] / $sum));
            for ($k = 0; $k < $count && $n < $total; $k++, $n++) {
                $psid++;
                [$page, $pageId] = $names[$i];
                $chats[] = ['page_id' => $pageId, 'page_name' => $page, 'psid' => (string) $psid, 'name' => 'Ana', 'message' => 'Hi', 'payload' => '[]',
                    'received_at' => now()->subMinutes(5)];
                $steps = count($twins) < $twice && $k === 0 ? 2 : 1;
                if ($steps === 2) $twins[] = (string) $psid;
                for ($s = 1; $s <= $steps; $s++) {
                    $rid = (string) Str::uuid();
                    $in = $s === 1 ? $seq : $seq2;
                    $subs[] = ['sequence_id' => $in->id, 'page_id' => (int) $pageId, 'psid' => $psid, 'status' => 'active', 'current_step' => 1, 'next_send_at' => now(),
                        'dispatched_at' => $stamp, 'send_request_id' => $rid, 'enrolled_via' => 'other', 'created_at' => now(), 'updated_at' => now()];
                    $tickets[] = ['request_id' => $rid, 'kind' => BotcakeSendQueue::SEQUENCE, 'priority' => BotcakeSendQueue::SEQUENCE_PRIORITY,
                        'customer' => BotcakeSendQueue::sequenceCustomer($pageId, $psid), 'page_id' => (int) $pageId, 'page_name' => $page, 'psid' => (string) $psid,
                        'chat_id' => null, 'flow_id' => $flow->id, 'node_id' => 'n10', 'run_id' => 'load' . $s,
                        'payload' => json_encode(['messages' => [['type' => 'text', 'text' => 'Step ' . $s]]]),
                        'context' => json_encode(['sequence' => ['subscription_id' => null, 'current_step' => 1, 'step' => 1, 'due' => $stamp, 'name' => $in->name,
                            'sequence_id' => $in->id]]),
                        'status' => 'pending', 'deadline_at' => now()->addHours(25), 'created_at' => now(), 'updated_at' => now()];
                }
            }
        }
        foreach (array_chunk($chats, 300) as $c) DB::table('botcake_chats')->insert($c);
        foreach (array_chunk($subs, 300) as $c) DB::table('chatbot_sequence_subscriptions')->insert($c);
        // Ang subscription ng bawat ticket (ang id ay alam na lang pagkatapos ng insert).
        $subIds = DB::table('chatbot_sequence_subscriptions')->pluck('id', 'send_request_id');
        foreach ($tickets as &$t) {
            $ctx = json_decode($t['context'], true);
            $ctx['sequence']['subscription_id'] = (int) $subIds[$t['request_id']];
            $t['context'] = json_encode($ctx);
        }
        unset($t);
        foreach (array_chunk($tickets, 300) as $c) DB::table(BotcakeSendQueue::TABLE)->insert($c);
        BotcakeChat::forgetPageMap();

        return $twins;
    }

    /** Ang pool ng shard $shard sa $of (ang fake na handler ay itinatala ang tawag at ang shard). */
    protected function shardPool(int $shard, int $of): SequenceSendPool
    {
        $handler = function (RequestInterface $req) use ($shard) {
            $p = new Promise();
            $body = json_decode((string) $req->getBody(), true) ?? [];
            $this->loadCalls[] = ['t' => $this->clock->now(), 'shard' => $shard, 'psid' => (string) ($body['psid'] ?? ''), 'page' => explode('/', $req->getUri()->getPath())[5] ?? '',
                'text' => (string) ($body['data']['content']['messages'][0]['text'] ?? ''), 'p' => $p];

            return $p;
        };

        return new SequenceSendPool($handler, $this->clock, $shard, $of);
    }

    private function classify(string $sql): string
    {
        $s = strtolower($sql);
        $t = BotcakeSendQueue::TABLE;
        return match (true) {
            str_contains($s, 'not exists') && str_contains($s, $t) => 'claim: candidates',
            str_starts_with($s, 'update "' . $t . '" set "status" = ?, "claimed_by"') => 'claim: update',
            str_starts_with($s, 'update "' . $t . '"') && str_contains($s, '"claimed_by" = ? where "id" in') => 'claim: update',
            str_starts_with($s, 'select * from "' . $t . '" where "id" = ?') || str_starts_with($s, 'select * from "' . $t . '" where "claimed_by"') => 'claim: read row',
            str_contains($s, 'from "' . $t . '" where "customer" = ?') => 'check: reply open',
            str_contains($s, 'from "chatbot_sequence_subscriptions"') && str_starts_with($s, 'select') => 'check: subscription',
            str_contains($s, 'from "chatbot_customer_values"') => 'check: customer values',
            str_contains($s, 'from "botcake_chats"') => 'check: last chat',
            str_starts_with($s, 'update "' . $t . '" set "called_at"') => 'called (before the call)',
            str_starts_with($s, 'update "' . $t . '" set "status" = ?, "result"') => 'finish: ticket',
            str_starts_with($s, 'insert into "chatbot_replies"') => 'finish: reply row',
            str_contains($s, 'page_settings') => 'page settings',
            default => 'other: ' . substr(preg_replace('/\s+/', ' ', $s), 0, 70),
        };
    }

    /**
     * Ang mga pool sa virtual na oras nang $seconds: bawat ikot ay sa pool na may pinakamaagang oras.
     * @param array<int, SequenceSendPool> $pools
     * @return array{rate: float, calls: int, slots: float, slotsMax: int, ticks: int, tickMs: float, perTicket: float, classes: array}
     */
    protected function simulate(array $pools, float $seconds, float $warmup = 10.0): array
    {
        if (!$this->listening) DB::listen(function (QueryExecuted $e) {
            if ($this->paused) return;
            $this->queries++;
            $c = $this->classify($e->sql);
            $this->byClass[$c]['n'] = ($this->byClass[$c]['n'] ?? 0) + 1;
            $this->byClass[$c]['ms'] = ($this->byClass[$c]['ms'] ?? 0) + $e->time;
        });
        $this->listening = true;
        $checks = 0;
        $base = Carbon::now();
        $t0 = $this->clock->time;
        $vt = array_fill(0, count($pools), $t0);
        $sweepAt = array_fill(0, count($pools), $t0);
        $slotSum = $slotN = $slotMax = $ticks = 0;
        $tickCost = 0.0;
        $busyMs = max(0, (int) config('queue.pools.busy_ms', 5));
        $qStart = null;
        $callsAtWarmup = null;
        while (true) {
            $k = array_keys($vt, min($vt))[0];
            $now = $vt[$k];
            if ($now - $t0 >= $seconds) break;
            if ($this->untilDone && ++$checks % 20 === 0 && $this->finished()) break;
            if ($callsAtWarmup === null && $now - $t0 >= $warmup) {
                $callsAtWarmup = count($this->loadCalls);
                $qStart = $this->queries;
                $this->byClass = [];
            }
            $this->clock->time = $now;
            Carbon::setTestNow($base->copy()->addMilliseconds((int) round(($now - $t0) * 1000)));
            foreach ($this->loadCalls as &$c) {
                if ($c['shard'] === $pools[$k]->shard && !isset($c['done']) && $now >= $c['t'] + self::LATENCY) {
                    $c['done'] = true;
                    $c['p']->resolve(new Response(200, ['Content-Type' => 'application/json'], '{"success":true}'));
                }
            }
            unset($c);
            // Ang resume (ibang proseso, ang worker): ang tapos ay resumed, para hindi ito ibalik ng sweep.
            $this->paused = true;
            DB::table(BotcakeSendQueue::TABLE)->whereIn('status', ['done', 'failed'])->update(['status' => 'resumed', 'resumed_at' => now()]);
            $this->paused = false;
            $q = $this->queries;
            $start = hrtime(true);
            $pools[$k]->beat();
            $started = $pools[$k]->tick();
            if ($now - $sweepAt[$k] >= 5) {
                $sweepAt[$k] = $now;
                (fn () => $this->sweep())->call($pools[$k]);
            }
            $cost = (hrtime(true) - $start) / 1e9 + ($this->queries - $q) * $this->queryMs / 1000 + ($started || $pools[$k]->inflight() ? $busyMs : 200) / 1000;
            $vt[$k] += $cost;
            if ($callsAtWarmup !== null) {
                $slotSum += $pools[$k]->inflight();
                $slotN++;
                $slotMax = max($slotMax, $pools[$k]->inflight());
                $ticks++;
                $tickCost += $cost;
            }
        }
        $calls = count($this->loadCalls) - (int) $callsAtWarmup;
        $window = $seconds - $warmup;
        $classes = $this->byClass;
        foreach ($classes as &$c) $c['per_ticket'] = $calls ? round($c['n'] / $calls, 2) : 0;
        unset($c);
        arsort($classes);

        return ['rate' => $calls / $window, 'calls' => $calls, 'slots' => $slotN ? $slotSum / $slotN : 0, 'slotsMax' => $slotMax, 'ticks' => $ticks,
            'tickMs' => $ticks ? $tickCost / $ticks * 1000 : 0, 'perTicket' => $calls ? ($this->queries - (int) $qStart) / $calls : 0, 'classes' => $classes];
    }

    /** Wala nang naghihintay o hawak na ticket. */
    private function finished(): bool
    {
        $this->paused = true;
        try {
            return !DB::table(BotcakeSendQueue::TABLE)->whereIn('status', ['pending', 'sending'])->exists();
        } finally {
            $this->paused = false;
        }
    }

    /** Ang mga ticket mula sa claim hanggang sa tawag at hanggang sa sagot (segundo, ayon sa mga timestamp ng ticket). */
    protected function phases(): array
    {
        $rows = DB::table(BotcakeSendQueue::TABLE)->whereNotNull('called_at')->get(['claimed_at', 'called_at', 'done_at']);
        $d = fn ($a, $b) => Carbon::parse($b)->getTimestamp() - Carbon::parse($a)->getTimestamp();
        $cc = $rows->map(fn ($r) => $d($r->claimed_at, $r->called_at));
        $cd = $rows->filter(fn ($r) => $r->done_at)->map(fn ($r) => $d($r->called_at, $r->done_at));

        return ['claimed_to_called' => round((float) $cc->avg(), 2), 'called_to_done' => round((float) $cd->avg(), 2), 'n' => $rows->count()];
    }

    protected function report(string $label, array $r): void
    {
        if (!getenv('SEQ_PROFILE')) return;
        $lines = [sprintf("\n[%s] %.1f tickets/s (%d calls in the window), slots avg %.0f (max %d), %d ticks, %.0f ms per tick, %.1f queries per ticket",
            $label, $r['rate'], $r['calls'], $r['slots'], $r['slotsMax'], $r['ticks'], $r['tickMs'], $r['perTicket'])];
        foreach ($r['classes'] as $name => $c) $lines[] = sprintf('    %-34s %6d queries  %5.2f per ticket  sqlite %.0f ms', $name, $c['n'], $c['per_ticket'], $c['ms']);
        $p = $this->phases();
        $lines[] = sprintf('    claimed → called avg %.2f s, called → done avg %.2f s (%d tickets)', $p['claimed_to_called'], $p['called_to_done'], $p['n']);
        fwrite(STDERR, implode("\n", $lines) . "\n");
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.botcake.rate_per_page' => 10, 'services.botcake.lane_seconds' => 3, 'services.botcake.sequence_concurrency' => 250]);
        $this->pool->stop();
        $this->replyPool->beat();
        if (is_numeric(getenv('SEQ_QUERY_MS'))) $this->queryMs = (float) getenv('SEQ_QUERY_MS');
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_04_10_000003_create_app_settings_table.php']]);
        SequenceSendPool::forgetPools();
    }

    /** Ang bilang ng proseso sa app_settings row (gaya ng pagse-set sa server), at agad na nababasa. */
    protected function usePools(int|string|null $n): void
    {
        if ($n === null) AppSetting::where('key', SequenceSendPool::POOLS_SETTING)->delete();
        else AppSetting::set(SequenceSendPool::POOLS_SETTING, $n);
        SequenceSendPool::forgetPools();
    }

    public function test_SQ_2_the_pool_count_comes_from_the_app_setting_then_the_config_then_1(): void
    {
        config(['services.botcake.sequence_pools' => 3]);
        $this->usePools(null);
        $this->assertSame(3, SequenceSendPool::pools(), 'no row: the config (BOTCAKE_SEQUENCE_POOLS)');
        $this->usePools('');
        $this->assertSame(3, SequenceSendPool::pools(), 'an empty row is no row');

        $this->usePools(4);
        $this->assertSame(4, SequenceSendPool::pools(), 'the row first');
        $this->assertSame(4, (new SequenceSendPool())->pools, 'what the command and its --shard check use');

        foreach (['0', '9', '-2', 'abc', '2.5', '100'] as $bad) {
            $this->usePools($bad);
            $this->assertSame(1, SequenceSendPool::pools(), "row '$bad' is outside 1..8: one process");
        }
        $this->usePools(8);
        $this->assertSame(8, SequenceSendPool::pools());
        $this->usePools(null);
        config(['services.botcake.sequence_pools' => 12]);
        SequenceSendPool::forgetPools();
        $this->assertSame(1, SequenceSendPool::pools(), 'a config outside 1..8: one process');
        config(['services.botcake.sequence_pools' => 1]);
    }

    public function test_SQ_2_a_changed_row_reaches_every_reader_within_a_minute_and_a_running_process_notices(): void
    {
        $this->usePools(2);
        [$a, $b] = [$this->shardPool(0, SequenceSendPool::pools()), $this->shardPool(1, SequenceSendPool::pools())];
        $this->assertTrue($a->start());
        $this->assertFalse(SequenceSendPool::capable(), 'two shares configured, one running');
        $this->assertTrue($b->start());
        $this->assertTrue(SequenceSendPool::capable());
        $this->assertFalse($a->poolsChanged());

        // The row changes on the server (no config:cache): the same cached value for everyone until it expires.
        AppSetting::set(SequenceSendPool::POOLS_SETTING, 3);
        $this->assertSame(2, SequenceSendPool::pools(), 'cached for up to a minute');
        $this->travel(SequenceSendPool::POOLS_CACHE_SECONDS + 1)->seconds();
        $a->beat();
        $b->beat();
        $this->assertSame(3, SequenceSendPool::pools(), 'within a minute');
        $this->assertFalse(SequenceSendPool::capable(), 'the hand-off waits for three shares');
        // The command checks poolsChanged() in its loop at most every POOLS_CHECK_SECONDS; a process of the old count sees it.
        $this->assertTrue($this->shardPool(0, 2)->poolsChanged(), 'a running process of the old count sees the new one (and stops cleanly)');

        // A process started with the old count refuses beside live processes of the new count, and the other way round.
        $c = $this->shardPool(2, 3);
        $this->assertFalse($c->start(), 'the new share waits while the old count still runs');
        $a->stop();
        $b->stop();
        foreach ([0, 1, 2] as $k) $this->assertTrue($this->shardPool($k, SequenceSendPool::pools())->start(), 'share ' . $k . ' of 3');
        $this->assertTrue(SequenceSendPool::capable());
        $this->assertFalse($this->shardPool(1, 2)->start(), 'a process with the old count refuses beside the new one');
        $this->assertFalse($this->shardPool(0, 1)->start(), 'the old single pool too');
        $this->assertFalse((new SequenceSendPool(null, $this->clock, 3))->start(), 'a share outside the count');
    }

    /** Ang mga pool ng $n na proseso (shard 0..n-1), sinimulan. @return array<int, SequenceSendPool> */
    protected function startShards(int $n): array
    {
        $this->usePools($n);
        $pools = [];
        for ($k = 0; $k < $n; $k++) {
            $pools[$k] = $this->shardPool($k, $n);
            $this->assertTrue($pools[$k]->start(), 'shard ' . $k . ' starts');
        }

        return $pools;
    }

    /** Hanggang wala nang naghihintay o hawak na ticket (ang lahat ng tawag ay sinagot). */
    protected function drain(array $pools, float $max = 600): void
    {
        $this->untilDone = true;
        $this->simulate($pools, $max, 0.0);
        $this->untilDone = false;
        $this->assertSame(0, DB::table(BotcakeSendQueue::TABLE)->whereIn('status', ['pending', 'sending'])->count(), 'every ticket finished');
    }

    /** Bawat ticket ay isang beses natawag; bawat customer ay ayon sa ayos; bawat page ay sa rate nito at sa iisang proseso lang. */
    protected function assertOnceInOrderAtRate(array $twins): void
    {
        $byText = collect($this->loadCalls)->groupBy(fn ($c) => $c['psid'] . ':' . $c['text']);
        $this->assertSame([], $byText->filter(fn ($g) => $g->count() > 1)->keys()->all(), 'no step called twice');
        $tickets = DB::table(BotcakeSendQueue::TABLE)->count();
        $this->assertCount($tickets, $byText, 'every step called once');
        $this->assertSame($tickets, DB::table(BotcakeSendQueue::TABLE)->whereIn('status', ['done', 'resumed'])->whereNull('error')->count(), 'every step sent');
        foreach ($twins as $psid) {
            $one = $byText[$psid . ':Step 1']->first();
            $two = $byText[$psid . ':Step 2']->first();
            $this->assertGreaterThanOrEqual($one['t'] + self::LATENCY - 1e-6, $two['t'], 'customer ' . $psid . ': step 2 only after step 1 was answered');
        }
        $interval = 1 / (float) config('services.botcake.rate_per_page');
        foreach (collect($this->loadCalls)->groupBy('page') as $page => $calls) {
            $this->assertCount(1, $calls->pluck('shard')->unique(), 'page ' . $page . ' is sent by one process only');
            $ts = $calls->pluck('t')->sort()->values();
            for ($i = 1; $i < $ts->count(); $i++) $this->assertGreaterThanOrEqual($interval - 1e-6, $ts[$i] - $ts[$i - 1], 'page ' . $page . ' stays at its rate');
        }
    }

    /** Ang mga table ng load, walang laman (para sa ikalawang takbo sa iisang test). */
    protected function reset(): void
    {
        foreach (['chatbot_sequence_subscriptions', 'botcake_chats', 'chatbot_replies', 'chatbot_events', BotcakeSendQueue::TABLE, 'chatbot_sequences', 'chatbot_sequence_steps', 'chatbot_flows'] as $t) {
            DB::table($t)->delete();
        }
        \Illuminate\Support\Facades\Cache::flush();
        $this->replyPool->beat();
        $this->loadCalls = [];
        $this->byClass = [];
    }

    public function test_SQ_1_profile_one_pool_as_today(): void
    {
        $this->load(120, 4000);
        $pool = $this->shardPool(0, 1);
        $this->assertTrue($pool->start());
        $r = $this->simulate([$pool], 60);
        $this->report('one pool, 250 slots', $r);
        // Ang base: ~10.7 kada segundo sa production; ang loop ay laging may ginagawa (puno ang mga slot, pero mabagal ang ikot).
        $this->assertEqualsWithDelta(10.7, $r['rate'], 2.0);
        $this->assertGreaterThan(200, $r['slots']);
        $this->assertGreaterThan(9, $r['perTicket'], 'about ten round trips per ticket, each one blocking the loop');
    }

    public function test_SQ_2_H_four_processes_send_at_least_2_5x_the_base_rate_with_slots_near_full_and_every_step_once_in_order(): void
    {
        $this->load(120, 4000);
        $base = $this->shardPool(0, 1);
        $this->assertTrue($base->start());
        $one = $this->simulate([$base], 40);
        $this->report('one pool (base)', $one);
        $base->stop();
        $this->reset();

        $twins = $this->load(120, 6000, 60);
        $pools = $this->startShards(4);
        $four = $this->simulate($pools, 40);
        $this->report('four pools', $four);

        $this->assertGreaterThanOrEqual(2.5 * $one['rate'], $four['rate'], sprintf('%.1f vs %.1f tickets/s', $four['rate'], $one['rate']));
        $this->assertGreaterThanOrEqual(27, $four['rate']);
        $this->assertGreaterThanOrEqual(0.8 * 250, $four['slots'], 'slots near full in every process');
        $this->drain($pools);
        $this->assertOnceInOrderAtRate($twins);
    }

    public function test_SQ_2_E_two_processes_never_claim_the_same_ticket_page_or_customer(): void
    {
        $twins = $this->load(40, 800, 40);
        [$a, $b] = $this->startShards(2);
        $this->simulate([$a, $b], 20, 0.0);

        $owners = DB::table(BotcakeSendQueue::TABLE)->whereNotNull('claimed_by')->get(['page_id', 'customer', 'claimed_by']);
        $this->assertGreaterThan(100, $owners->count());
        $this->assertEqualsCanonicalizing([$a->owner, $b->owner], $owners->pluck('claimed_by')->unique()->values()->all(), 'both processes worked');
        foreach (['page_id', 'customer'] as $col) {
            $this->assertSame([], $owners->groupBy($col)->filter(fn ($g) => $g->pluck('claimed_by')->unique()->count() > 1)->keys()->all(), "no $col held by two processes");
        }
        // Each process owns exactly the pages of its share (page ID mod 2), in the claim itself.
        foreach ($owners as $o) $this->assertSame($o->claimed_by === $a->owner ? 0 : 1, (int) $o->page_id % 2);
        $this->drain([$a, $b]);
        $this->assertOnceInOrderAtRate($twins);

        // The claim alone, from both shares at once: disjoint, and together every claimable ticket.
        $this->reset();
        $this->load(30, 300);
        $x = collect(BotcakeSendQueue::claim('pool-x', 1000, null, null, [], [], false, true, false, [2, 0]))->pluck('id');
        $y = collect(BotcakeSendQueue::claim('pool-y', 1000, null, null, [], [], false, true, false, [2, 1]))->pluck('id');
        $this->assertSame([], $x->intersect($y)->all());
        $this->assertSame(300, $x->count() + $y->count());
    }

    public function test_SQ_2_N_a_crash_or_stop_mid_run_sends_nothing_twice_and_does_not_touch_the_other_process(): void
    {
        $twins = $this->load(40, 1200, 40);
        [$a, $b] = $this->startShards(2);
        $this->simulate([$a, $b], 8, 0.0);
        $held = DB::table(BotcakeSendQueue::TABLE)->where('status', 'sending')->where('claimed_by', $b->owner)->count();
        $this->assertGreaterThan(0, $held, 'process 1 holds tickets when it dies');
        $calledA = DB::table(BotcakeSendQueue::TABLE)->where('status', 'sending')->where('claimed_by', $a->owner)->whereNotNull('called_at')->pluck('id');
        $this->assertNotEmpty($calledA);

        // Process 1 dies (no answers, no beat); process 0 keeps going alone for 70 s, and the minute tick runs meanwhile.
        $deadCalls = collect($this->loadCalls)->where('shard', 1)->count();
        $this->simulate([$a], 70, 0.0);
        app(\App\Services\Chatbot\ChatbotSequences::class)->settleInFlight();
        $this->assertSame(0, DB::table(BotcakeSendQueue::TABLE)->whereIn('id', $calledA)->where(fn ($q) => $q->where('error', 'like', '%did not answer%')
            ->orWhere('error', 'like', '%not running%'))->count(), 'the living process keeps its calls');

        // A new process 1 (supervisor): the dead one's called tickets are failed without a resend, the rest go once.
        $b2 = $this->shardPool(1, 2);
        $this->assertTrue($b2->start());
        foreach ($this->loadCalls as &$c) if ($c['shard'] === 1) $c['done'] = true; // the dead process never hears back
        unset($c);
        $this->drain([$a, $b2]);
        $byText = collect($this->loadCalls)->groupBy(fn ($c) => $c['psid'] . ':' . $c['text']);
        $this->assertSame([], $byText->filter(fn ($g) => $g->count() > 1)->keys()->all(), 'no step called twice');
        $this->assertSame(0, DB::table(BotcakeSendQueue::TABLE)->whereIn('status', ['pending', 'sending'])->count());
        foreach ($twins as $psid) {
            $one = $byText[$psid . ':Step 1'] ?? null;
            $two = $byText[$psid . ':Step 2'] ?? null;
            if ($one && $two) $this->assertGreaterThan($one->first()['t'], $two->first()['t'], 'customer ' . $psid . ' in order');
        }
    }

    public function test_SQ_2_a_process_refuses_to_start_beside_a_live_pool_of_another_count_or_outside_the_count(): void
    {
        $this->usePools(1);
        $old = $this->shardPool(0, 1);
        $this->assertTrue($old->start());
        $this->assertSame(SequenceSendPool::BEAT . ':' . \App\Services\Chatbot\ChatbotSequences::site(), SequenceSendPool::beatKey(), 'one process: the same beat as today');

        $this->usePools(3);
        $this->assertFalse($this->shardPool(1, 3)->start(), 'the old single pool is still live');
        $this->assertFalse(SequenceSendPool::capable(), 'three configured, one running: steps are not handed off');
        $old->stop(); // clean stop (beat "stopped"): no longer in the way
        $pools = [];
        foreach ([0, 1, 2] as $k) {
            $pools[$k] = $this->shardPool($k, 3);
            $this->assertTrue($pools[$k]->start(), 'shard ' . $k);
        }
        $this->assertFalse($this->shardPool(3, 3)->start(), 'outside the count');
        $this->assertFalse($this->shardPool(1, 3)->start(), 'the same share twice');
        $this->assertTrue(SequenceSendPool::capable());
        $this->assertFalse($this->shardPool(0, 2)->start(), 'a different count while these are live');
        $pools[2]->stop();
        $this->assertFalse(SequenceSendPool::capable(), 'one share stopped: steps go inline as with a dead pool');
    }

    public function test_SQ_2_while_the_count_changes_the_tick_never_takes_back_what_the_old_single_pool_still_holds(): void
    {
        $this->load(10, 40);
        $this->usePools(1);
        $old = $this->shardPool(0, 1);
        $this->assertTrue($old->start());
        $this->simulate([$old], 2, 0.0);
        $held = DB::table(BotcakeSendQueue::TABLE)->where('status', 'sending')->where('claimed_by', $old->owner)->get(['id', 'called_at']);
        $this->assertNotEmpty($held->whereNotNull('called_at'));

        // The setting is now 2 (deploy) while the old single pool still runs: share 1 has no process yet.
        $this->usePools(2);
        $this->assertFalse(SequenceSendPool::capable(), 'steps are not handed off until every share runs');
        $this->assertFalse($this->shardPool(1, 2)->start(), 'share 1 waits for the old pool to stop');
        $this->travel(70)->seconds();
        $old->beat();
        app(\App\Services\Chatbot\ChatbotSequences::class)->settleInFlight();
        $this->assertSame($held->count(), DB::table(BotcakeSendQueue::TABLE)->whereIn('id', $held->pluck('id'))->where('status', 'sending')->where('claimed_by', $old->owner)->count(),
            'the old pool keeps every ticket it holds, called or not');
    }

    public function test_SQ_2_the_minute_tick_leaves_a_live_process_calls_alone_and_cleans_up_a_dead_share_only(): void
    {
        $this->load(10, 40);
        [$a, $b] = $this->startShards(2);
        $this->simulate([$a, $b], 2, 0.0);
        $byShard = fn (int $k) => DB::table(BotcakeSendQueue::TABLE)->where('status', 'sending')->whereRaw('page_id % 2 = ' . $k)->whereNotNull('called_at')->pluck('id');
        [$calledA, $calledB] = [$byShard(0), $byShard(1)];
        $this->assertNotEmpty($calledA);
        $this->assertNotEmpty($calledB);

        // Both live, calls older than CALL_SECONDS: nothing is failed (each process answers for its own).
        $this->travel(\App\Services\Chatbot\ChatbotSequences::CALL_SECONDS + 10)->seconds();
        $a->beat();
        $b->beat();
        app(\App\Services\Chatbot\ChatbotSequences::class)->settleInFlight();
        $this->assertSame(0, DB::table(BotcakeSendQueue::TABLE)->whereIn('id', $calledA->merge($calledB))->where('status', '!=', 'sending')->count());

        // Process 1 dead: only its calls are failed (no resend); process 0 keeps its own.
        $this->travel(70)->seconds();
        $a->beat();
        app(\App\Services\Chatbot\ChatbotSequences::class)->settleInFlight();
        $this->assertSame($calledB->count(), DB::table(BotcakeSendQueue::TABLE)->whereIn('id', $calledB)->where('status', '!=', 'sending')->count());
        $this->assertSame(0, DB::table(BotcakeSendQueue::TABLE)->whereIn('id', $calledA)->where('status', '!=', 'sending')->count());
    }
}
