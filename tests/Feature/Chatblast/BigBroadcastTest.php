<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeMessage;
use App\Models\ChatblastCampaign;
use App\Services\Chatblast;
use App\Services\Chatbot\BotcakeSendPool;
use App\Services\Chatbot\BotcakeSendQueue;
use App\Services\ChatblastSender;
use App\Services\PageSettings;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Psr\Http\Message\RequestInterface;

/**
 * Ang malaking chatblast ay lumalabas nang buo sa isang takbo: ang naghihintay sa pila ay hindi binabagsak at
 * hindi ibinabalik sa queue dahil lang naghintay, ang sulat at basa na pumalya bago ang send_flow ay inuulit nang may
 * pagitan, at ang send_flow ay hindi kailanman inuulit. Fake na Botcake (handler na hawak ng test) at orasan.
 */
class BigBroadcastTest extends ChatblastTestCase
{
    /** @var array<int, array{req: RequestInterface, p: Promise, t: float}> */
    private array $calls = [];
    private BotcakeSendPool $pool;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_10_29_100000_create_botcake_send_requests_table.php']]);
        config(['services.botcake.async_sends' => true, 'services.botcake.rate_per_page' => 10]);
        Queue::fake(); // ang job na ibinalik sa queue ay hindi tumatakbo: walang naipapadala nang hindi dumaan sa pool
        $this->pool = $this->pool();
        $this->assertTrue($this->pool->start());
    }

    private function pool(): BotcakeSendPool
    {
        return new BotcakeSendPool(function (RequestInterface $req) {
            $p = new Promise();
            $this->calls[] = ['req' => $req, 'p' => $p, 't' => $this->clock->now()];

            return $p;
        }, $this->clock);
    }

    /** Isang page na may sariling token (kaya sariling lane). */
    private function setUpPage(string $page, string $id): void
    {
        $this->page($page, $id);
        app(PageSettings::class)->set($page, 'botcake', 'token', 'tok-' . $id);
    }

    private function blast(string $page, string $psid, string $waybill): BotcakeMessage
    {
        $m = BotcakeMessage::create(['page_name' => $page, 'waybill' => $waybill, 'kind' => 'in_transit', 'psid' => $psid,
            'text' => 'Hello ' . $waybill, 'status' => 'queued', 'is_test' => true, 'queued_at' => now()]);
        app(ChatblastSender::class)->process($m->id);

        return $m;
    }

    /** @return array{page: string, psid: string, step: string, text: ?string} */
    private static function req(array $call): array
    {
        $path = $call['req']->getUri()->getPath();
        $parts = explode('/', (string) preg_replace('#^.*?(?=/pages/)#', '', $path));
        $body = json_decode((string) $call['req']->getBody(), true) ?? [];
        if (str_ends_with($path, '/send_flow')) return ['page' => $parts[2], 'psid' => (string) $body['psid'], 'step' => 'flow', 'text' => null];

        return ['page' => $parts[2], 'psid' => $parts[4], 'step' => $call['req']->getMethod() === 'GET' ? 'read' : 'write', 'text' => $body['data'][0]['value'] ?? null];
    }

    private function respond(array $call, int $status, array $body = ['success' => true]): void
    {
        $call['p']->resolve(new Response($status, ['Content-Type' => 'application/json'], json_encode($body)));
    }

    /** Ang sagot ng Botcake na walang problema: ang GET ay ang huling isinulat sa customer. */
    private function ok(array $call, array &$written): void
    {
        $r = self::req($call);
        $key = $r['page'] . '/' . $r['psid'];
        if ($r['step'] === 'write') $written[$key] = $r['text'];
        $this->respond($call, 200, $r['step'] === 'read' ? ['success' => true, 'data' => [['id' => 7701, 'value' => $written[$key] ?? '']]] : ['success' => true]);
    }

    private function second(int $ms = 1000): void
    {
        $this->clock->time += $ms / 1000;
        $this->travel($ms)->milliseconds();
        $this->pool->beat();
    }

    private function open(): int
    {
        return DB::table(BotcakeSendQueue::TABLE)->whereIn('status', ['pending', 'sending'])->count();
    }

    public function test_S_167_1_5000_messages_on_50_pages_all_go_out_without_one_failed_for_waiting(): void
    {
        $pages = [];
        for ($p = 1; $p <= 50; $p++) {
            $pages[$page = sprintf('Page %02d', $p)] = (string) (2000 + $p);
            $this->setUpPage($page, $pages[$page]);
        }
        // 100 bawat page: 95 na customer, at ang unang 5 ay may pangalawang mensahe (ibang waybill), kaya isa-isa at sa ayos.
        foreach ($pages as $page => $id) {
            for ($j = 1; $j <= 100; $j++) $this->blast($page, (string) ($id * 1000 + ($j > 95 ? $j - 95 : $j)), 'JT' . $id . sprintf('%03d', $j));
        }
        $this->assertSame(5000, DB::table(BotcakeSendQueue::TABLE)->where('status', 'pending')->count(), 'all 5,000 handed to the pool at once');

        // Ang Botcake ay sumasagot pagkalipas ng 2 s; isang ikot kada 0.1 s, at ang sweep kada 5 s (gaya ng pool).
        $written = [];
        $answered = 0;
        $start = $this->clock->now();
        for ($tick = 0; $tick < 20000 && ($tick % 50 || $this->open() > 0); $tick++) {
            $this->pool->tick();
            while ($answered < count($this->calls) && $this->calls[$answered]['t'] <= $this->clock->now() - 2) $this->ok($this->calls[$answered++], $written);
            $this->pool->tick(false);
            if ($tick % 50 === 0) BotcakeSendQueue::sweep();
            $this->second(100);
        }
        $this->assertGreaterThan(240, $this->clock->now() - $start, 'the queue waited longer than the old 4-minute deadline');

        $this->assertSame(['sent' => 5000], BotcakeMessage::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all());
        $this->assertSame(0, DB::table(BotcakeSendQueue::TABLE)->where('error', 'like', 'Not sent in time%')->count(), 'no "Not sent in time"');
        $this->assertSame(0, DB::table(BotcakeSendQueue::TABLE)->where('status', 'failed')->count(), 'no ticket failed for waiting');
        $this->assertSame(5000, DB::table(BotcakeSendQueue::TABLE)->count(), 'one ticket per message: none queued again');
        Queue::assertNothingPushed();

        // Bawat customer: isa-isa (sulat, basa, flow, saka ang susunod) at sa ayos ng mensahe; isang send_flow bawat mensahe.
        $byCustomer = [];
        $flows = 0;
        $last = [];
        foreach ($this->calls as $c) {
            $r = self::req($c);
            $byCustomer[$r['page'] . '/' . $r['psid']][] = $r['step'] . ($r['text'] !== null ? ':' . $r['text'] : '');
            if ($r['step'] === 'flow') $flows++;
            if (isset($last[$r['page']])) $this->assertGreaterThanOrEqual(0.1 - 1e-6, $c['t'] - $last[$r['page']], 'at most 10 per second on one page');
            $last[$r['page']] = $c['t'];
        }
        $this->assertSame(5000, $flows, 'one send_flow per message');
        foreach ($pages as $id) {
            $this->assertSame(['write:Hello JT' . $id . '001', 'read', 'flow', 'write:Hello JT' . $id . '096', 'read', 'flow'], $byCustomer[$id . '/' . ($id * 1000 + 1)]);
            $this->assertSame(['write:Hello JT' . $id . '050', 'read', 'flow'], $byCustomer[$id . '/' . ($id * 1000 + 50)]);
        }

        $this->assertSame(['queued' => 5000, 'sent' => 5000, 'retried' => [], 'failed' => []], $this->pool->counts());
    }

    public function test_S_167_1_a_waybill_another_sequence_messaged_while_it_waited_in_line_is_skipped_when_the_pool_starts_it(): void
    {
        $this->setUpPage('Page Uno', '1001');
        ChatblastCampaign::create(['kind' => 'c-two', 'name' => 'Iba', 'statuses' => ['In Transit'], 'pages' => null, 'window_days' => 10,
            'days' => [['Iba {waybill}']], 'requires_on_delivery' => false, 'mode' => 'manual', 'active' => true]);
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-1', 'psid-a', 'In Transit');
        app(Chatblast::class)->build(null, 1);
        $rows = BotcakeMessage::where('waybill', 'WB-1')->orderBy('id')->get();
        $this->assertCount(2, $rows, 'two sequences, one waybill');
        BotcakeMessage::whereKey($rows->pluck('id'))->update(['status' => 'queued']);
        foreach ($rows as $m) app(ChatblastSender::class)->process($m->id);
        $this->assertSame(2, DB::table(BotcakeSendQueue::TABLE)->where('status', 'pending')->count(), 'both pass the queue-time check and wait in line');

        $written = [];
        for ($s = 0; $s < 60 && $this->open() > 0; $s++) {
            $this->pool->tick();
            foreach ($this->calls as $c) if ($c['p']->getState() === 'pending') $this->ok($c, $written);
            $this->pool->tick(false);
            $this->second();
        }

        [$first, $second] = [$rows[0]->fresh(), $rows[1]->fresh()];
        $this->assertSame('sent', $first->status);
        $this->assertSame(['skipped', Chatblast::CROSS, null], [$second->status, $second->reason, $second->dedupe_key]);
        $this->assertSame(['write', 'read', 'flow'], array_map(fn ($c) => self::req($c)['step'], $this->calls), 'no Botcake call for the skipped one');
        $this->assertSame(['done', 'done'], DB::table(BotcakeSendQueue::TABLE)->orderBy('id')->pluck('status')->all());
        $lock = Cache::lock(ChatblastSender::customerLockKey('Page Uno', 'psid-a'), 10);
        $this->assertTrue($lock->get(), 'the customer lock is free');
        $lock->release();
    }

    public static function beforeSendFlow(): array
    {
        $e500 = [500, ['message' => 'Internal error']];

        return [
            'a write 500, then ok: sent' => [['write' => [$e500, 'echo']], 'sent', null, [15], ['write' => 1]],
            'a write timeout, then ok: sent' => [['write' => [[0, null], 'echo']], 'sent', null, [15], ['write' => 1]],
            'three write 500s: failed as today' => [['write' => [$e500, $e500, $e500]], 'failed', 'The custom field was not written (HTTP 500)', [15, 60], ['write' => 2]],
            'a read 500, then ok: sent' => [['read' => [$e500, 'echo']], 'sent', null, [15], ['read' => 1]],
            'three read 500s: field_mismatch as today' => [['read' => [$e500, $e500, $e500]], 'field_mismatch', 'The custom field could not be read (HTTP 500); not sent', [15, 60], ['read' => 2]],
        ];
    }

    /** @dataProvider beforeSendFlow */
    public function test_S_167_2_a_step_before_send_flow_that_botcake_fails_is_tried_again_after_15_s_then_60_s(array $script, string $status, ?string $reason, array $gaps, array $retried): void
    {
        $this->setUpPage('Page Uno', '1001');
        $m = $this->blast('Page Uno', '7001', 'JT7001');
        $written = [];
        for ($s = 0; $s < 400 && $this->open() > 0; $s++) {
            $this->pool->tick();
            foreach ($this->calls as $c) {
                if ($c['p']->getState() !== 'pending') continue;
                $step = self::req($c)['step'];
                $next = isset($script[$step]) && $script[$step] ? array_shift($script[$step]) : 'echo';
                if ($next === 'echo') $this->ok($c, $written);
                elseif ($next[0] === 0) $c['p']->reject(new \RuntimeException('timed out'));
                else $this->respond($c, ...$next);
            }
            $this->pool->tick(false);
            $this->second();
        }

        $m->refresh();
        $this->assertSame([$status, $reason], [$m->status, $m->reason]);
        $flows = collect($this->calls)->filter(fn ($c) => self::req($c)['step'] === 'flow')->count();
        $this->assertSame($status === 'sent' ? 1 : 0, $flows, 'send_flow at most once');
        $step = array_key_first($retried);
        $times = collect($this->calls)->filter(fn ($c) => self::req($c)['step'] === $step)->pluck('t')->values()->all();
        $this->assertCount(count($gaps) + 1, $times, 'three tries at most');
        foreach ($gaps as $i => $gap) $this->assertEqualsWithDelta($gap, $times[$i + 1] - $times[$i], 1.0, 'the wait before try ' . ($i + 2));
        $this->assertSame($retried, $this->pool->counts()['retried']);
        $this->assertSame($status === 'sent' ? [] : [$status . ': ' . strtok((string) $reason, ';') => 1], $this->pool->counts()['failed']);
    }

    public function test_S_167_3_an_error_after_send_flow_is_never_tried_again(): void
    {
        $this->setUpPage('Page Uno', '1001');
        $m = $this->blast('Page Uno', '7001', 'JT7001');
        $written = [];
        for ($s = 0; $s < 300; $s++) {
            $this->pool->tick();
            foreach ($this->calls as $c) {
                if ($c['p']->getState() !== 'pending') continue;
                self::req($c)['step'] === 'flow' ? $this->respond($c, 500, ['message' => 'down']) : $this->ok($c, $written);
            }
            $this->pool->tick(false);
            $this->second();
        }

        $this->assertSame(1, collect($this->calls)->filter(fn ($c) => self::req($c)['step'] === 'flow')->count(), 'one send_flow call');
        $this->assertSame(['unknown', 'Not sure it was sent (send_flow was called) (HTTP 500); check Botcake before sending again'], [$m->fresh()->status, $m->fresh()->reason]);
        $this->assertSame([], $this->pool->counts()['retried']);
    }

    public function test_S_167_5_a_dead_pools_stuck_messages_are_taken_back_and_sent_once_by_the_next_pool(): void
    {
        $this->setUpPage('Page Uno', '1001');
        $a = $this->blast('Page Uno', '7001', 'JT7001');
        $b = $this->blast('Page Uno', '7002', 'JT7002');
        $later = $this->blast('Page Uno', '7001', 'JT7003'); // the same customer as $a: waits behind it
        $written = [];
        // The pool takes $a and $b; their writes are answered, then it dies before send_flow.
        $this->pool->tick();
        foreach ($this->calls as $c) $this->ok($c, $written);
        $this->pool->tick(false);
        $key = ChatblastSender::customerLockKey('Page Uno', '7001');
        $this->assertFalse(Cache::lock($key, 10)->get(), 'the pool holds the customer lock while it sends');

        // No heartbeat for over a minute: the sweep takes the stuck rows back (the existing recover path).
        $this->clock->time += 61;
        $this->travel(61)->seconds();
        BotcakeSendQueue::sweep();
        $this->assertSame(['pending', 'pending', 'pending'], DB::table(BotcakeSendQueue::TABLE)->orderBy('id')->pluck('status')->all());
        $this->assertSame(['sending', 'sending', 'sending'], [$a->fresh()->status, $b->fresh()->status, $later->fresh()->status]);
        $lock = Cache::lock($key, 10);
        $this->assertTrue($lock->get(), 'a taken-back message does not hold the customer lock while it waits');
        $lock->release();

        // The next pool sends each once, $later after $a.
        $before = count($this->calls);
        $this->pool = $this->pool();
        $this->assertTrue($this->pool->start());
        for ($s = 0; $s < 60 && $this->open() > 0; $s++) {
            $this->pool->tick();
            foreach ($this->calls as $c) if ($c['p']->getState() === 'pending') $this->ok($c, $written);
            $this->pool->tick(false);
            $this->second();
        }
        $this->assertSame(['sent', 'sent', 'sent'], [$a->fresh()->status, $b->fresh()->status, $later->fresh()->status]);
        $flows = collect(array_slice($this->calls, $before))->filter(fn ($c) => self::req($c)['step'] === 'flow')->map(fn ($c) => self::req($c)['psid'])->values()->all();
        $this->assertEqualsCanonicalizing(['7001', '7002', '7001'], $flows, 'one send_flow per message');
        $this->assertGreaterThan($b->fresh()->sent_at, $later->fresh()->sent_at, 'the same customer\'s next message waits for the gap');
    }
}
