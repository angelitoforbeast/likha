<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\Inbox;
use App\Services\Chatbot\InboxFilters;
use App\Services\PageSettings;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PDOException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * 302: ang listahan ng /chatbot/inbox ay mabilis kahit sa pinakamalaking page (binabasa mula sa pinakabago at humihinto
 * kapag sapat na, pareho ang sagot ng dating GROUP BY), hindi kayang hawakan ang worker nang isang minuto (InboxTooSlow
 * at ang "too slow" na sagot), at ang page ay hindi nagpapatong ng kuha (Node run ng inboxListLoop).
 */
class ChatbotInboxListFastTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_22_100000_widen_source_on_botcake_psids.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_inbox_indexes.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
        ]]);
        $this->page('Page Uno', '1001');
        $this->page('Page Dos', '2002');
        $this->travelTo('2026-10-19 12:00:00');
        SlowInbox::$calls = 0;
    }

    /**
     * Isang page na may 650 customer sa palabas at 560 sa papasok (lampas sa GROUP_LIMIT), may sheet row, pumalya,
     * standby na kopya, mga row sa iisang segundo (sa hangganan ng chunk), mas luma sa window, at ibang page.
     */
    protected function fixture(): void
    {
        $base = Carbon::parse('2026-10-19 11:00:00');
        $out = $in = [];
        // Ang huling galaw ng bawat customer ay sa sariling segundo (walang tabla sa hangganan ng 500).
        for ($i = 0; $i < 650; $i++) {
            $psid = 800000 + $i;
            $last = $base->copy()->subSeconds($i * 7);
            foreach (range(0, 2) as $k) {
                $out[] = ['page_id' => 1001, 'psid' => $psid, 'node_id' => 'n2', 'kind' => $k === 1 && $i % 5 === 0 ? 'sheet' : 'send',
                    'content' => json_encode([['type' => 'text', 'text' => "msg {$i}-{$k}"]]), 'status' => $k === 0 && $i % 4 === 0 ? 'failed' : 'sent',
                    'error' => null, 'sent_at' => ($k === 0 ? $last : $last->copy()->subHours(2 + $k * 30 + $i % 90))->format('Y-m-d H:i:s')];
            }
            // Tatlong padala sa iisang segundo para sa ilan: ang cursor ($at, id) ay tumatawid sa loob ng segundo.
            if ($i % 50 === 0) foreach (range(1, 3) as $k) $out[] = ['page_id' => 1001, 'psid' => $psid, 'node_id' => 'n3', 'kind' => 'send',
                'content' => json_encode([['type' => 'text', 'text' => "same second {$k}"]]), 'status' => 'sent', 'error' => null, 'sent_at' => $last->copy()->subMinutes(3)->format('Y-m-d H:i:s')];
            if ($i % 9 === 0) $out[] = ['page_id' => 1001, 'psid' => $psid, 'node_id' => 'n2', 'kind' => 'manual', 'content' => json_encode([['type' => 'text', 'text' => 'human']]),
                'status' => 'sent', 'error' => null, 'sent_at' => $last->copy()->subMinutes(1)->format('Y-m-d H:i:s')];
        }
        for ($i = 0; $i < 560; $i++) {
            $psid = 800000 + $i * 2;                               // kalahati ay may palabas din
            $last = $base->copy()->subSeconds($i * 11 + 3);
            foreach (range(0, 1) as $k) $in[] = ['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => (string) $psid, 'name' => ($i % 3 ? 'Ana ' : 'Ben ') . $i,
                'message' => "hello {$i}-{$k}", 'payload' => json_encode($i % 17 === 0 && $k === 0 ? ['_standby' => true] : []),
                'received_at' => ($k === 0 ? $last : $last->copy()->subDays(1 + $i % 20))->format('Y-m-d H:i:s')];
        }
        // Ibang page at mas luma pa sa 30 araw: hindi kasama.
        $out[] = ['page_id' => 2002, 'psid' => 800001, 'node_id' => 'n2', 'kind' => 'send', 'content' => '[]', 'status' => 'sent', 'error' => null, 'sent_at' => '2026-10-19 11:59:00'];
        $out[] = ['page_id' => 1001, 'psid' => 899999, 'node_id' => 'n2', 'kind' => 'send', 'content' => '[]', 'status' => 'sent', 'error' => null, 'sent_at' => '2026-09-01 11:59:00'];
        foreach (array_chunk($out, 400) as $chunk) DB::table('chatbot_replies')->insert($chunk);
        foreach (array_chunk($in, 400) as $chunk) DB::table('botcake_chats')->insert($chunk);

        $events = $fields = [];
        for ($i = 0; $i < 650; $i += 3) $events[] = ['page_id' => 1001, 'psid' => 800000 + $i, 'type' => 'order', 'detail' => '{}', 'created_at' => $base->copy()->subDays($i % 12)->format('Y-m-d H:i:s')];
        for ($i = 0; $i < 650; $i++) {
            $fields[] = ['page_id' => 1001, 'psid' => 800000 + $i, 'key' => 'chat status', 'value' => ['LOOP 1', 'loop 2', 'Loop3', 'done'][$i % 4], 'updated_at' => now()];
            if ($i % 5 === 0) $fields[] = ['page_id' => 1001, 'psid' => 800000 + $i, 'key' => 'gpt phone number', 'value' => '0917', 'updated_at' => now()];
            if ($i % 13 === 0) $fields[] = ['page_id' => 1001, 'psid' => 800000 + $i, 'key' => CustomerFields::BOT_PAUSED, 'value' => '1', 'updated_at' => now()];
        }
        foreach (array_chunk($events, 400) as $chunk) DB::table('chatbot_events')->insert($chunk);
        foreach (array_chunk($fields, 400) as $chunk) DB::table('chatbot_customer_values')->insert($chunk);
    }

    public function test_IL_1_the_newest_first_list_is_the_same_as_the_grouped_query(): void
    {
        $this->fixture();
        $old = new GroupedInbox(app(PageSettings::class));
        $small = new SmallChunkInbox(app(PageSettings::class));       // 7 row kada basa: maraming hangganan ng chunk
        $new = app(Inbox::class);
        $conditionSets = [
            [],
            [['type' => 'field', 'key' => 'chat status', 'op' => 'contains', 'value' => 'loop']],
            [['type' => 'ordered', 'value' => 'yes']],
            [['type' => 'ordered', 'value' => 'no'], ['type' => 'bot_reply', 'value' => 'failed']],
            [['type' => 'first_chat', 'from' => '2026-10-10']],
            [['type' => 'last_message', 'from' => '2026-10-19']],
            [['type' => 'handler', 'value' => 'human']],
        ];
        $checked = 0;
        foreach (['24h', '7d', '30d'] as $window) {
            foreach (['', 'ordered', 'loop2', 'failed', 'phone'] as $status) {
                foreach (['', 'human', 'bot'] as $tab) {
                    foreach (['', 'ana 1', '80001'] as $q) {
                        foreach ($status === '' && $tab === '' ? $conditionSets : [[]] as $raw) {
                            $conditions = InboxFilters::parse($raw);
                            $old->conditions = $conditions;
                            $old->since = now()->subHours(Inbox::WINDOWS[$window]);
                            $want = $old->listWithCounts('1001', $window, $q, $tab, $status, $conditions);
                            $label = json_encode(compact('window', 'status', 'tab', 'q', 'raw'));
                            $this->assertSame($want, $new->listWithCounts('1001', $window, $q, $tab, $status, $conditions), $label);
                            $this->assertSame($want, $small->listWithCounts('1001', $window, $q, $tab, $status, $conditions), $label);
                            $checked++;
                        }
                    }
                }
            }
        }
        $this->assertSame(189, $checked);

        // Bakit walang palabas kapag may hanap: ang dating only() ay sa Eloquent Collection ng GROUP BY, na pumipili ayon sa
        // id ng model (wala), kaya laging walang laman, kahit ang customer ay nasa papasok din.
        $grouped = \App\Models\ChatbotReply::where('page_id', 1001)->selectRaw('psid, MAX(id) AS last_id')->groupBy('psid')->get()->keyBy(fn ($r) => (string) $r->psid);
        $this->assertTrue($grouped->has('800002'));
        $this->assertCount(0, $grouped->only(['800002', 800002]));
        // Ang fixture ay talagang lampas sa mga limit (hindi pareho dahil lang maliit).
        $all = $new->listWithCounts('1001', '30d');
        $this->assertSame(Inbox::LIST_LIMIT, count($all['rows']));
        $this->assertGreaterThan(Inbox::GROUP_LIMIT, $all['total']);
    }

    public function test_IL_1_the_scan_stops_once_it_has_enough_customers(): void
    {
        // 600 customer na may bagong padala, saka 6,000 lumang padala sa loob pa rin ng 30 araw.
        $rows = [];
        for ($i = 0; $i < 600; $i++) $rows[] = ['page_id' => 1001, 'psid' => 700000 + $i, 'kind' => 'send', 'content' => '[]', 'status' => 'sent', 'sent_at' => now()->subSeconds($i)->format('Y-m-d H:i:s')];
        for ($i = 0; $i < 6000; $i++) $rows[] = ['page_id' => 1001, 'psid' => 710000 + $i, 'kind' => 'send', 'content' => '[]', 'status' => 'sent', 'sent_at' => now()->subDays(2)->subSeconds($i)->format('Y-m-d H:i:s')];
        foreach (array_chunk($rows, 500) as $chunk) DB::table('chatbot_replies')->insert($chunk);
        $this->actingAs($this->user());

        DB::enableQueryLog();
        $res = $this->getJson('/chatbot/inbox/conversations?page_id=1001&window=30d')->assertOk()->json();
        $replies = array_values(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'from "chatbot_replies"')));
        $scans = array_values(array_filter($replies, fn ($q) => str_contains($q['query'], 'order by "sent_at" desc, "id" desc limit')));

        $this->assertCount(1, $scans, 'one read of the newest 1,000 rows finds 500 customers; the 6,000 older rows are never read');
        $this->assertStringContainsString('limit 1000', $scans[0]['query']);
        $this->assertSame(500, $res['total']);
        $this->assertSame(['700000', '700001', '700002'], array_slice(array_column($res['rows'], 'psid'), 0, 3));
        $this->assertNotContains(true, array_map(fn ($q) => str_contains($q['query'], 'group by "psid" order by'), $replies), 'no GROUP BY over the whole window');
    }

    public function test_IL_2_a_slow_list_answers_quickly_with_the_too_slow_state_and_is_not_retried_by_every_poll(): void
    {
        $this->app->instance(Inbox::class, new SlowInbox(app(PageSettings::class)));
        Log::spy();
        $this->actingAs($this->user());

        $start = microtime(true);
        $res = $this->getJson('/chatbot/inbox/conversations?page_id=1001&window=30d&status=ordered')->assertOk();
        $seconds = microtime(true) - $start;

        $this->assertLessThan(2.0, $seconds, 'answers soon after the budget (0.3 s here), not after the slow work');
        $this->assertSame(['rows' => [], 'counts' => [], 'total' => 0, 'slow' => true, 'error' => Inbox::TOO_SLOW], $res->json());
        $this->assertSame(1, SlowInbox::$calls, 'stopped at the first check after the budget');
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($m, $ctx) => $m === 'Chatbot inbox list too slow' && $ctx['page_id'] === '1001' && $ctx['window'] === '30d' && $ctx['status'] === 'ordered');

        // Ang ibang poll ng parehong listahan sa loob ng isang minuto: ang naka-cache na sagot, walang bagong pagbuo.
        $this->getJson('/chatbot/inbox/conversations?page_id=1001&window=30d&status=ordered')->assertOk()->assertJson(['slow' => true]);
        $this->assertSame(1, SlowInbox::$calls);
        $this->travel(Inbox::SLOW_CACHE_SECONDS + 1)->seconds();
        $this->getJson('/chatbot/inbox/conversations?page_id=1001&window=30d&status=ordered')->assertOk()->assertJson(['slow' => true]);
        $this->assertSame(2, SlowInbox::$calls);
    }

    public function test_IL_2_a_mysql_statement_timeout_becomes_the_too_slow_state(): void
    {
        $this->actingAs($this->user());
        $this->app->instance(Inbox::class, new FailingInbox(app(PageSettings::class), 3024));
        $this->getJson('/chatbot/inbox/conversations?page_id=1001')->assertOk()->assertJson(['slow' => true, 'error' => Inbox::TOO_SLOW, 'rows' => []]);
    }

    public function test_IL_2_other_database_errors_are_not_hidden_as_too_slow(): void
    {
        $this->actingAs($this->user());
        $this->app->instance(Inbox::class, new FailingInbox(app(PageSettings::class), 1064));
        $this->withoutExceptionHandling();
        $this->expectException(QueryException::class);
        $this->getJson('/chatbot/inbox/conversations?page_id=1001&window=24h');
    }

    public function test_IL_1_the_same_list_is_built_once_per_15_seconds_and_a_pause_shows_at_once(): void
    {
        DB::table('botcake_chats')->insert(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => '7001', 'name' => 'Ana', 'message' => 'hi', 'payload' => '{}', 'received_at' => now()->subMinute()]);
        (new CustomerFields(1001, 7001))->set(CustomerFields::BOT_PAUSED, '1');
        $this->actingAs($this->user());
        $built = function (string $qs) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $rows = $this->getJson('/chatbot/inbox/conversations?page_id=1001' . $qs)->assertOk()->json('rows');
            $n = count(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'from "botcake_chats"')));
            DB::disableQueryLog();
            return [$n > 0, array_column($rows, 'human')];
        };

        $this->assertSame([true, [true]], $built(''));
        $this->assertSame([false, [true]], $built(''), 'the second poll is the cached answer');
        $this->assertSame([true, [true]], $built('&window=24h'), 'other filters are their own list');

        // Unpause: ang listahan ng page ay binubuo ulit agad (hindi naghihintay ng 15 segundo).
        $this->postJson('/chatbot/inbox/pause', ['page_id' => '1001', 'psid' => '7001', 'paused' => false])->assertOk();
        $this->assertSame([true, [false]], $built(''));
        $this->assertSame([false, [false]], $built(''));
        $this->travel(Inbox::LIST_CACHE_SECONDS + 1)->seconds();
        $this->assertSame([true, [false]], $built(''));
        $this->assertSame([true, [false]], $built('&window=24h'));
    }

    /** Ang inboxListLoop ng page (mula sa rendered HTML) sa Node, may pekeng orasan. */
    public function test_IL_3_the_page_sends_the_next_list_request_only_after_the_last_one_answered(): void
    {
        $this->actingAs($this->user());
        $html = $this->get('/chatbot/inbox')->assertOk()->getContent();
        $this->assertSame(1, preg_match('~// inbox-list-loop:start(.*?)// inbox-list-loop:end~s', $html, $m), 'the loop is on the page');
        // Ang page mismo: ang listahan ay sa loop lang (walang setInterval na kumukuha ng listahan), at may abort ang poll().
        $this->assertStringContainsString('setInterval(poll, POLL);', $html);
        $this->assertStringNotContainsString('loadList(); }, POLL)', $html);
        $this->assertStringContainsString('inboxListLoop({run: fetchList, every: POLL, limit: REQUEST_LIMIT, hidden: () => document.hidden', $html);
        $this->assertStringContainsString('REQUEST_LIMIT = 15000', $html);
        $this->assertStringContainsString('ctl.abort(), REQUEST_LIMIT)', $html);
        $this->assertStringContainsString('if (data.slow)', $html);

        $node = (new ExecutableFinder())->find('node');
        if ($node === null) $this->markTestSkipped('Node is not installed.');
        $script = $m[1] . <<<'JS'

let now = 0, ids = 0, timers = [];
const setT = (fn, ms) => { const id = ++ids; timers.push({id, at: now + ms, fn}); return id; };
const clearT = (id) => { timers = timers.filter(t => t.id !== id); };
const flush = async () => { for (let i = 0; i < 5; i++) await new Promise(r => setImmediate(r)); };
async function advanceTo(end) {
  for (;;) {
    await flush();
    timers.sort((a, b) => a.at - b.at || a.id - b.id);
    const t = timers[0];
    if (!t || t.at > end) break;
    timers.shift(); now = t.at; t.fn();
  }
  now = end; await flush();
}
let hidden = false, answer = 1000, inFlight = 0, maxInFlight = 0;
const log = [];
function run(signal, st) {
  return new Promise((resolve, reject) => {
    const rec = {start: now, end: null, aborted: false, timedOut: false};
    log.push(rec); inFlight++; maxInFlight = Math.max(maxInFlight, inFlight);
    const done = () => { inFlight--; rec.end = now; };
    const id = setT(() => { done(); resolve(); }, answer);
    signal.addEventListener('abort', () => { clearT(id); done(); rec.aborted = true; rec.timedOut = st.timedOut; reject(new Error('AbortError')); });
  });
}
const loop = inboxListLoop({run, every: 5000, limit: 15000, hidden: () => hidden, setTimeout: setT, clearTimeout: clearT, AbortController});
(async () => {
  loop.start();
  await advanceTo(12000);                 // 1 s na sagot: 5,000 → 6,000, susunod 11,000
  answer = 8000; await advanceTo(31000);  // mabagal: 11,000 (1 s), 17,000 (8 s) → 25,000, susunod 30,000
  answer = 60000; await advanceTo(64000); // 30,000 (8 s) → 38,000; 43,000 ay hindi sumasagot: abort sa 58,000, susunod 63,000
  answer = 1000; loop.now();              // bagong filter habang naghihintay ang 63,000: abort, kuha agad
  await advanceTo(70500);
  hidden = true; await advanceTo(90000);  // nakatago: walang kuha
  hidden = false; await advanceTo(92000);
  console.log(JSON.stringify({maxInFlight, requests: log}));
})();
JS;
        $file = tempnam(sys_get_temp_dir(), 'ill') . '.mjs';
        file_put_contents($file, $script);
        $p = new Process([$node, $file]);
        $p->run();
        @unlink($file);
        $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());
        $out = json_decode($p->getOutput(), true);

        $this->assertSame(1, $out['maxInFlight'], 'never two list requests at once');
        $this->assertSame([5000, 11000, 17000, 30000, 43000, 63000, 64000, 70000, 91000], array_column($out['requests'], 'start'));
        $this->assertSame([6000, 12000, 25000, 38000, 58000, 64000, 65000, 71000, 92000], array_column($out['requests'], 'end'));
        $this->assertSame([false, false, false, false, true, true, false, false, false], array_column($out['requests'], 'aborted'));
        $this->assertSame([false, false, false, false, true, false, false, false, false], array_column($out['requests'], 'timedOut'), 'only the hung one timed out; the other was replaced by the filter change');
    }
}

/** Ang dating listahan (GROUP BY psid sa buong window, ang mga kondisyon sa bawat row): ang sukatan ng "pareho". */
class GroupedInbox extends Inbox
{
    public array $conditions = [];
    public ?Carbon $since = null;

    protected function latest($query, string $at, bool $intPsid, ?callable $keep): Collection
    {
        $query = clone $query;
        $inbound = $at === 'received_at';
        if ($this->conditions) (new InboxFilters())->apply($query, $this->conditions, $inbound ? 'botcake_chats.psid' : 'chatbot_replies.psid', $inbound, 1001, $this->since);

        return $query->selectRaw("psid, MAX(id) AS last_id, MAX({$at}) AS last_at")->groupBy('psid')
            ->orderByDesc('last_at')->limit(self::GROUP_LIMIT)->get()->keyBy(fn ($r) => (string) $r->psid);
    }
}

class SmallChunkInbox extends Inbox
{
    protected int $scanChunk = 7;
}

/** Bawat basa ay matagal (0.4 s) at ang budget ay 0.3 s: lampas na pagkatapos ng unang basa. */
class SlowInbox extends Inbox
{
    public static int $calls = 0;

    protected function budget(): float
    {
        return 0.3;
    }

    protected function latest($query, string $at, bool $intPsid, ?callable $keep): Collection
    {
        self::$calls++;
        usleep(400000);

        return parent::latest($query, $at, $intPsid, $keep);
    }
}

/** Ang query ay pinutol ng MySQL (3024 = max_execution_time), o ibang error. */
class FailingInbox extends Inbox
{
    public function __construct(PageSettings $settings, private int $code)
    {
        parent::__construct($settings);
    }

    protected function latest($query, string $at, bool $intPsid, ?callable $keep): Collection
    {
        $pdo = new PDOException('SQLSTATE[HY000]: General error: ' . $this->code);
        $pdo->errorInfo = ['HY000', $this->code, $this->code === 3024 ? 'Query execution was interrupted, maximum statement execution time exceeded' : 'syntax'];
        throw new QueryException('mysql', 'select psid from chatbot_replies', [], $pdo);
    }
}
