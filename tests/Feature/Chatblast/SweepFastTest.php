<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\ResumeChatbotAi;
use App\Jobs\ResumeChatbotSend;
use App\Jobs\ResumeChatbotWait;
use App\Services\Chatbot\BotcakeSendPool;
use App\Services\Chatbot\BotcakeSendQueue;
use App\Services\Chatbot\ChatbotAiQueue;
use App\Services\Chatbot\SweepLimit;
use App\Services\OrderEncode\FlowEncode;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Spec 290: the clean-up queries of the ticket queues (ChatbotAiQueue::sweep, BotcakeSendQueue::sweep and
 * sweepSequence, the AI claim) pick the same rows as before but read them through an index, every SELECT carries a
 * MySQL time limit, and a sweep that hits the limit logs one warning and returns instead of holding the pool.
 */
class SweepFastTest extends ChatblastTestCase
{
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
            'database/migrations/2026_10_29_100000_create_botcake_send_requests_table.php',
            'database/migrations/2026_11_22_100000_add_kind_status_updated_at_index_to_botcake_send_requests.php',
        ]]);
        $this->page('Page Uno', '1001');
        $this->freezeSecond();
        Queue::fake();
        // The send pool is alive: the send sweep leaves "sending" rows to it (no recover), as on a running site.
        Cache::put(BotcakeSendPool::beatKey(), ['owner' => 'live-pool', 'at' => now()->getTimestamp()], 600);
    }

    private function record(): void
    {
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_11_10_100000_add_provider_record_to_chatbot_ai_requests.php',
            'database/migrations/2026_11_15_100000_add_key_and_cost_to_chatbot_ai_requests.php',
        ]]);
    }

    /** An AI ticket; $created / $updated are seconds ago, $deadline seconds from now. @return int id */
    private function ai(string $status, int $created = 0, int $deadline = 60, ?int $updated = null, array $extra = []): int
    {
        static $n = 0;
        $n++;

        return DB::table(ChatbotAiQueue::TABLE)->insertGetId($extra + [
            'request_id' => (string) Str::uuid(), 'page_id' => 1001, 'psid' => 7000 + $n, 'chat_id' => null, 'flow_id' => 1, 'node_id' => 'n2',
            'page_name' => 'Page Uno', 'model' => 'gpt-4o-mini', 'messages' => '[{"role":"user","content":"hi"}]', 'max_tokens' => 100, 'context' => '{}',
            'status' => $status, 'deadline_at' => now()->addSeconds($deadline), 'created_at' => now()->subSeconds($created),
            'updated_at' => now()->subSeconds($updated ?? $created),
        ]);
    }

    /** A send ticket ([kind, status, seconds ago]); the deadline is 10 minutes after it. @return int id */
    private function send(string $kind, string $status, int $ago): int
    {
        static $n = 0;
        $n++;
        $at = now()->subSeconds($ago);

        return DB::table(BotcakeSendQueue::TABLE)->insertGetId([
            'request_id' => (string) Str::uuid(), 'kind' => $kind, 'priority' => $kind === BotcakeSendQueue::SEQUENCE ? BotcakeSendQueue::SEQUENCE_PRIORITY : ($kind === 'chatblast' ? 2 : 0),
            'customer' => $kind . ':1001:' . $n, 'page_id' => 1001, 'page_name' => 'Page Uno', 'psid' => (string) (8000 + $n), 'chat_id' => null, 'flow_id' => 1, 'node_id' => 'n1',
            'payload' => '{"messages":[]}', 'status' => $status, 'deadline_at' => $at->copy()->addMinutes(10), 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    /** The request ids a resume job of $class was dispatched for, in order. */
    private function resumed(string $class): array
    {
        return Queue::pushed($class)->map(fn ($job) => (new \ReflectionProperty($job, 'ticket'))->getValue($job)['request_id'])->values()->all();
    }

    private function requestIds(string $table, array $ids): array
    {
        return array_map(fn ($id) => DB::table($table)->where('id', $id)->value('request_id'), $ids);
    }

    private function statuses(array $ids): array
    {
        return array_map(fn ($id) => DB::table(ChatbotAiQueue::TABLE)->where('id', $id)->value('status'), $ids);
    }

    /** The MySQL error of a SELECT past its MAX_EXECUTION_TIME, thrown for the first query that $match picks. */
    private function slowQuery(callable $match): void
    {
        DB::connection()->beforeExecuting(function (string $sql, array $bindings, $connection) use ($match) {
            if (!$match($sql)) return;
            $pdo = new \PDOException('SQLSTATE[HY000]: General error: 3024 Query execution was interrupted, maximum statement execution time exceeded');
            $pdo->errorInfo = ['HY000', 3024, 'Query execution was interrupted, maximum statement execution time exceeded'];
            throw new QueryException($connection->getName(), $sql, $bindings, $pdo);
        });
    }

    /**
     * Runs $then as if the connection were MySQL (the SQL still runs on SQLite, which reads the hint as a comment) and
     * returns each SELECT and DELETE as MySQL would receive it, bindings filled in.
     * @return array<int, string>
     */
    private function asMySql(callable $then): array
    {
        $connection = DB::connection();
        $config = new \ReflectionProperty($connection, 'config');
        $was = $config->getValue($connection);
        $config->setValue($connection, ['driver' => 'mysql'] + $was);
        $grammar = $connection->getQueryGrammar();
        $sql = [];
        $connection->setQueryGrammar(new class($connection, $sql) extends SQLiteGrammar {
            public function __construct($connection, private array &$sql) { parent::__construct($connection); }
            private function keep(Builder $query, string $mysql): void
            {
                // The time left changes per run; the first column after the hint was quoted by the SQLite grammar of the
                // builder (on MySQL the builder's grammar quotes it with backticks).
                $mysql = preg_replace(['/MAX_EXECUTION_TIME\(\d+\)/', '/(\*\/ )"([a-z_]+)"/'], ['MAX_EXECUTION_TIME(n)', '$1`$2`'], $mysql);
                $this->sql[] = (new MySqlGrammar($this->connection))->substituteBindingsIntoRawSql($mysql, $this->connection->prepareBindings($query->getBindings()));
            }
            public function compileSelect(Builder $query)
            {
                if ($query->from !== null && !$query->aggregate) $this->keep($query, (new MySqlGrammar($this->connection))->compileSelect($query));
                return parent::compileSelect($query);
            }
            public function compileDelete(Builder $query)
            {
                $this->keep($query, (new MySqlGrammar($this->connection))->compileDelete($query));
                return parent::compileDelete($query);
            }
        });
        try {
            $then();
        } finally {
            $connection->setQueryGrammar($grammar);
            $config->setValue($connection, $was);
        }

        return array_values(array_filter($sql, fn ($s) => preg_match('/(chatbot_ai_requests|botcake_send_requests|chatbot_waits)/', $s)));
    }

    // ── SW-1: same rows ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_SW1_the_ai_sweep_fails_redispatches_and_prunes_exactly_the_same_rows(): void
    {
        // A busy table: old finished rows, none of them touched.
        foreach (range(1, 300) as $_) $this->ai('resumed', 86400, -86000);
        $late = [$this->ai('pending', 120, -1), $this->ai('sending', 120, -30)];
        $early = $this->ai('pending', 10, 50);                                         // not past its deadline
        $encoded = $this->ai(FlowEncode::STATUS, 600, -5, extra: ['answer' => '{"ok":true}']);
        $notEncoded = $this->ai(FlowEncode::STATUS, 600, -5);
        $encoding = $this->ai(FlowEncode::STATUS, 10, 100);                             // still within "Wait up to"
        $stuck = $this->ai('answered', 120, -60, 40);                                   // answered 40 s ago, never resumed
        $fresh = $this->ai('failed', 120, -60, 10);                                     // its resume job may still be on its way
        $old = [$this->ai('resumed', 3 * 86400, -3 * 86000), $this->ai('failed', 3 * 86400, -3 * 86000, 3 * 86000), $this->ai('answered', 3 * 86400, -3 * 86000, 3 * 86000)];
        $kept = $this->ai('sync', 3 * 86400, -3 * 86000);                               // not a status the prune removes
        $expected = $this->requestIds(ChatbotAiQueue::TABLE, [...$late, $encoded, $notEncoded, $stuck, $old[1], $old[2]]);

        $out = ChatbotAiQueue::sweep();

        // The old failed and answered rows are unresumed results too: as before they get one more resume job, then the prune removes them.
        $this->assertSame(['failed' => 4, 'redispatched' => 3, 'pruned' => 3], $out);
        $this->assertSame(['failed', 'failed'], $this->statuses($late));
        $this->assertSame(['pending', 'answered', 'failed', FlowEncode::STATUS, 'failed'], $this->statuses([$early, $encoded, $notEncoded, $encoding, $fresh]));
        $this->assertSame($expected, $this->resumed(ResumeChatbotAi::class), 'late rows in id order, then the encodes, then the stuck ones');
        $this->assertSame(0, DB::table(ChatbotAiQueue::TABLE)->whereIn('id', $old)->count());
        $this->assertSame('sync', $this->statuses([$kept])[0]);
        $this->assertSame(300, DB::table(ChatbotAiQueue::TABLE)->where('status', 'resumed')->count());
    }

    public function test_SW1_the_ai_sweep_with_the_record_prunes_the_same_rows_and_reads_only_new_rows_for_the_texts(): void
    {
        $this->record();
        $day = 86400;
        $failedOld = $this->ai('failed', 3 * $day, -3 * $day, 3 * $day);
        $usagePending = $this->ai('answered', 3 * $day, -3 * $day, 3 * $day, ['usage_status' => 'pending']);
        $texts = [$this->ai('resumed', 3 * $day, -3 * $day), $this->ai('resumed', 6 * $day, -6 * $day)];
        $tooOld = $this->ai('resumed', 8 * $day, -8 * $day);   // past the 7-day window: its text is left (as before)
        $recent = $this->ai('resumed', $day, -$day);
        $expired = $this->ai('resumed', 91 * $day, -91 * $day);
        $billing = $this->ai('sync', 91 * $day, -91 * $day, extra: ['usage_status' => 'pending']);

        ChatbotAiQueue::sweep();

        $this->assertNull(DB::table(ChatbotAiQueue::TABLE)->find($failedOld));
        $this->assertNotNull(DB::table(ChatbotAiQueue::TABLE)->find($usagePending), 'usage still to be sent: kept');
        $this->assertSame([null, null], DB::table(ChatbotAiQueue::TABLE)->whereIn('id', $texts)->pluck('messages')->all());
        $this->assertNotNull(DB::table(ChatbotAiQueue::TABLE)->find($tooOld)->messages);
        $this->assertNotNull(DB::table(ChatbotAiQueue::TABLE)->find($recent)->messages);
        $this->assertNull(DB::table(ChatbotAiQueue::TABLE)->find($expired));
        $this->assertNotNull(DB::table(ChatbotAiQueue::TABLE)->find($billing));
        $cursor = Cache::get(ChatbotAiQueue::TEXTS_FROM);
        $this->assertSame(now()->subDays(2)->format('Y-m-d H:i:s'), $cursor, 'checked up to the 2-day mark');

        // 20 s later: a row that has just crossed the 2-day mark is cleared, and the search starts at the last mark.
        $crossing = $this->ai('resumed', 2 * $day - 10, -2 * $day);
        $this->travel(20)->seconds();
        $reads = [];
        DB::listen(function ($q) use (&$reads) {
            if (str_contains($q->sql, '"created_at" between ? and ?')) $reads[] = array_map(fn ($b) => $b instanceof \DateTimeInterface ? $b->format('Y-m-d H:i:s') : $b, $q->bindings);
        });
        ChatbotAiQueue::sweep();
        $this->assertNull(DB::table(ChatbotAiQueue::TABLE)->find($crossing)->messages);
        $this->assertCount(1, $reads, 'one slice');
        $this->assertSame([$cursor, now()->subDays(2)->format('Y-m-d H:i:s')], array_slice($reads[0], 0, 2), 'only the 20 s since the last sweep');
    }

    public function test_SW1_the_text_clearing_without_a_mark_catches_up_from_7_days_back_hour_by_hour(): void
    {
        $this->record();
        $ids = [];
        foreach ([7 * 86400 - 60, 5 * 86400, 2 * 86400 + 60] as $ago) $ids[] = $this->ai('resumed', $ago, -$ago);
        $reads = 0;
        DB::listen(function ($q) use (&$reads) {
            if (str_contains($q->sql, '"created_at" between ? and ?')) $reads++;
        });

        ChatbotAiQueue::sweep();

        $this->assertSame([null, null, null], DB::table(ChatbotAiQueue::TABLE)->whereIn('id', $ids)->pluck('messages')->all(), 'the whole window in one sweep, as before');
        $this->assertSame(120, $reads, '5 days in one-hour slices');
    }

    public function test_SW1_the_ai_claim_takes_the_same_rows_in_id_order(): void
    {
        $a = $this->ai('pending', 5, 55);
        $this->ai('pending', 5, -1);                                              // past its deadline: the sweep's
        $later = $this->ai('pending', 5, 55, extra: ['next_at' => now()->addSeconds(30)]); // 429: not before next_at
        $b = $this->ai('pending', 5, 55, extra: ['next_at' => now()->subSecond()]);
        $this->ai('sending', 5, 55);
        $c = $this->ai('pending', 5, 55);

        $this->assertSame([$a], array_map(fn ($r) => $r->id, ChatbotAiQueue::claim('pool-a', 1)));
        $this->assertSame([$b, $c], array_map(fn ($r) => $r->id, ChatbotAiQueue::claim('pool-a', 10)));
        $this->assertSame('pending', $this->statuses([$later])[0]);
    }

    public function test_SW1_the_send_sweep_redispatches_the_lowest_500_ids_across_both_statuses_and_prunes_the_same_rows(): void
    {
        $ids = [];
        foreach (range(1, 300) as $i) {
            $ids[] = $this->send('chatbot', 'done', 120);
            $ids[] = $this->send('chatbot', 'failed', 120);
        }
        $old = [$this->send('chatbot', 'resumed', 3 * 86400), $this->send('chatblast', 'done', 3 * 86400), $this->send('chatbot', 'failed', 3 * 86400 + 60)];
        $keep = [$this->send(BotcakeSendQueue::SEQUENCE, 'resumed', 3 * 86400), $this->send('chatblast', 'sending', 3 * 86400), $this->send('chatbot', 'resumed', 86400)];

        $out = BotcakeSendQueue::sweep();

        $this->assertSame(500, $out['redispatched']);
        // The lowest 500 ids, in id order, done and failed mixed, as before (the 3-day-old failed row has a higher id).
        $this->assertSame($this->requestIds(BotcakeSendQueue::TABLE, array_slice($ids, 0, 500)), $this->resumed(ResumeChatbotSend::class));
        $this->assertSame(3, $out['pruned']);
        $this->assertSame(0, DB::table(BotcakeSendQueue::TABLE)->whereIn('id', $old)->count());
        $this->assertSame(3, DB::table(BotcakeSendQueue::TABLE)->whereIn('id', $keep)->count());
    }

    // ── SW-1 evidence: the SQL MySQL receives ───────────────────────────────────────────────────────────────────

    public function test_SW1_every_changed_query_carries_the_time_limit_and_its_index_on_mysql(): void
    {
        $this->record();
        $this->ai('pending', 120, -1);
        $this->ai('failed', 3 * 86400, -3 * 86400, 3 * 86400);
        $this->ai('resumed', 3 * 86400, -3 * 86400);
        $this->ai('resumed', 91 * 86400, -91 * 86400);
        $this->send('chatbot', 'resumed', 3 * 86400);
        $this->send(BotcakeSendQueue::SEQUENCE, 'resumed', 3 * 86400);
        Cache::forget(BotcakeSendPool::beatKey()); // a dead send pool: the sweep also takes back its "sending" rows
        Cache::forever(ChatbotAiQueue::TEXTS_FROM, now()->subDays(2)->subMinute()->format('Y-m-d H:i:s')); // the steady state: one slice

        $sql = $this->asMySql(function () {
            ChatbotAiQueue::claim('pool-a', 10);
            ChatbotAiQueue::sweep();
            BotcakeSendQueue::sweep();
            BotcakeSendQueue::sweepSequence();
        });
        if ($file = getenv('SWEEP_SQL_OUT')) file_put_contents($file, implode("\n", $sql) . "\n");

        $selects = array_values(array_filter($sql, fn ($s) => str_starts_with($s, 'select')));
        $deletes = array_values(array_filter($sql, fn ($s) => str_starts_with($s, 'delete')));
        // The claim is not a clean-up query: it gets the index, not the time limit.
        $this->assertStringStartsWith('select `id` from `chatbot_ai_requests` use index (chatbot_ai_requests_status_next_at_index) where `status` = \'pending\'', array_shift($selects));
        foreach ($selects as $s) $this->assertStringStartsWith('select /*+ MAX_EXECUTION_TIME(n) */ ', $s);
        $this->assertSame([
            'chatbot_ai_requests_status_next_at_index', 'chatbot_ai_requests_status_next_at_index', 'chatbot_ai_requests_status_next_at_index', // late, encode, redispatch
            'chatbot_ai_requests_status_next_at_index', 'chatbot_ai_requests_created_at_index', 'chatbot_ai_requests_created_at_index',          // prune, texts, 90 days
            '-', 'botcake_send_requests_status_priority_id_index', '-', '-', 'botcake_send_requests_created_at_index',                         // recover, late, done, failed, prune
            'botcake_send_requests_status_priority_id_index', 'botcake_send_requests_status_priority_id_index',                                 // sequence done, failed
            'botcake_send_requests_created_at_index', 'botcake_send_requests_status_priority_id_index', 'botcake_send_requests_status_priority_id_index', 'botcake_send_requests_status_priority_id_index',
        ], array_map(fn ($s) => preg_match('/use index \(([a-z_]+)\)/', $s, $m) ? $m[1] : '-', $selects));
        $this->assertStringContainsString("where `kind` = 'chatbot' and `status` = 'done' and `updated_at` <= ", $selects[8]);
        $this->assertStringContainsString('order by `updated_at` asc, `id` asc limit 500', $selects[8]);
        $this->assertNotEmpty($deletes);
        foreach ($deletes as $d) {
            $this->assertMatchesRegularExpression('/^delete from `[a-z_]+` where `id` in \(/', $d, 'deletes go by primary key');
            $this->assertStringNotContainsString('order by', $d);
        }
    }

    // ── SW-2: time limit ─────────────────────────────────────────────────────────────────────────────────────────

    public static function slowSweeps(): array
    {
        return [
            'AI sweep' => ['chatbot AI sweep', fn () => ChatbotAiQueue::sweep(), '"chatbot_ai_requests"'],
            'send sweep' => ['botcake send sweep', fn () => BotcakeSendQueue::sweep(), '"botcake_send_requests"'],
            'sequence sweep' => ['sequence send sweep', fn () => BotcakeSendQueue::sweepSequence(), '"botcake_send_requests"'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('slowSweeps')]
    public function test_SW2_a_select_past_the_mysql_time_limit_ends_the_sweep_with_one_warning(string $name, \Closure $sweep, string $table): void
    {
        $slow = true;
        $this->slowQuery(function (string $sql) use (&$slow, $table) {
            return $slow && str_starts_with($sql, 'select') && str_contains($sql, $table);
        });
        Log::spy();

        $out = $sweep();

        $this->assertSame(0, array_sum($out));
        Log::shouldHaveReceived('warning')->once()->with("$name stopped at its 10000 ms limit; the next sweep tries again");
        $slow = false;
        $sweep(); // the next sweep runs normally
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_SW2_a_sweep_stops_when_its_limit_is_used_up_and_keeps_what_it_did(): void
    {
        config(['queue.pools.sweep_ms' => 100]);
        $late = $this->ai('pending', 120, -1);
        $stuck = $this->ai('answered', 120, -60, 40);
        // The deadline query takes the whole limit.
        DB::connection()->beforeExecuting(function (string $sql) {
            if (str_contains($sql, '"chatbot_ai_requests"') && str_contains($sql, '"status" in (?, ?) and "deadline_at" <= ?')) usleep(150000);
        });
        Log::spy();

        $out = ChatbotAiQueue::sweep();

        $this->assertSame(['failed' => 1, 'redispatched' => 0, 'pruned' => 0], $out, 'the late row was failed; nothing after it ran');
        $this->assertSame(['failed', 'answered'], $this->statuses([$late, $stuck]));
        Log::shouldHaveReceived('warning')->once()->with('chatbot AI sweep stopped at its 100 ms limit; the next sweep tries again');
    }

    public function test_SW2_the_hint_carries_the_time_left_and_only_on_mysql(): void
    {
        config(['queue.pools.sweep_ms' => 5000]);
        $limit = new SweepLimit('test');
        $this->assertSame('select * from "chatbot_ai_requests"', $limit->cap(DB::table(ChatbotAiQueue::TABLE))->toSql(), 'SQLite: no hint');

        $connection = DB::connection();
        $config = new \ReflectionProperty($connection, 'config');
        $was = $config->getValue($connection);
        $config->setValue($connection, ['driver' => 'mysql'] + $was);
        try {
            $q = $limit->cap((new Builder($connection, new MySqlGrammar($connection)))->from(ChatbotAiQueue::TABLE), ['id', 'created_at']);
        } finally {
            $config->setValue($connection, $was);
        }
        $mysql = $q->toSql();
        $this->assertMatchesRegularExpression('/^select \/\*\+ MAX_EXECUTION_TIME\((\d+)\) \*\/ `id`, `created_at` from `chatbot_ai_requests`$/', $mysql);
        preg_match('/\((\d+)\)/', $mysql, $m);
        $this->assertGreaterThan(4000, (int) $m[1]);
        $this->assertLessThanOrEqual(5000, (int) $m[1]);

        // An index this database does not have is never named (MySQL would refuse every query).
        $this->assertNull(SweepLimit::useIndex(DB::table(ChatbotAiQueue::TABLE), ChatbotAiQueue::TABLE, 'chatbot_ai_requests_no_such_index')->indexHint);
        $this->assertSame(ChatbotAiQueue::STATUS_INDEX, SweepLimit::useIndex(DB::table(ChatbotAiQueue::TABLE), ChatbotAiQueue::TABLE, ChatbotAiQueue::STATUS_INDEX)->indexHint->index);

        config(['queue.pools.sweep_ms' => 0]);
        $this->assertSame(100, SweepLimit::ms());
        config(['queue.pools.sweep_ms' => 3600000]);
        $this->assertSame(60000, SweepLimit::ms());
    }

    public function test_SW2_other_database_errors_are_still_thrown(): void
    {
        DB::connection()->beforeExecuting(function (string $sql, array $bindings, $connection) {
            if (!str_contains($sql, '"chatbot_ai_requests"')) return;
            $pdo = new \PDOException('SQLSTATE[42S22]: Column not found: 1054 Unknown column');
            $pdo->errorInfo = ['42S22', 1054, 'Unknown column'];
            throw new QueryException($connection->getName(), $sql, $bindings, $pdo);
        });

        $this->expectException(QueryException::class);
        ChatbotAiQueue::sweep();
    }

    public function test_SW2_the_minute_command_always_ends_before_its_overlap_lock_expires(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'chatbot:resume-skipped'));
        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(5, $event->expiresAt, 'minutes');
        // Two sweeps, the waits loop and its last batch, each at its limit, still end inside the lock.
        $worst = 2 * SweepLimit::ms() / 1000 + \App\Console\Commands\ResumeSkippedChatbotWaits::SECONDS + SweepLimit::ms() / 1000;
        $this->assertLessThan(5 * 60, $worst);
        config(['queue.pools.sweep_ms' => 3600000]); // even at the highest setting (60 s)
        $this->assertLessThan(5 * 60, 3 * SweepLimit::ms() / 1000 + \App\Console\Commands\ResumeSkippedChatbotWaits::SECONDS);

        // A slow AI sweep does not stop the rest of the tick.
        config(['queue.pools.sweep_ms' => 10000]);
        $this->slowQuery(fn (string $sql) => str_starts_with($sql, 'select') && str_contains($sql, '"chatbot_ai_requests"'));
        $stuck = $this->send('chatbot', 'done', 120);
        DB::table('chatbot_waits')->insert(['page_id' => 1001, 'psid' => 7001, 'flow_id' => 1, 'node_id' => 'n3', 'due_at' => now()->subMinute(), 'created_at' => now()]);
        Log::spy();

        $this->assertSame(0, Artisan::call('chatbot:resume-skipped'));

        Log::shouldHaveReceived('warning')->once()->with('chatbot AI sweep stopped at its 10000 ms limit; the next sweep tries again');
        $this->assertSame($this->requestIds(BotcakeSendQueue::TABLE, [$stuck]), $this->resumed(ResumeChatbotSend::class), 'the send sweep still ran');
        Queue::assertPushed(ResumeChatbotWait::class, 1);
    }
}
