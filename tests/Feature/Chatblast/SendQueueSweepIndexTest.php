<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\ResumeChatbotSend;
use App\Services\Chatbot\BotcakeSendQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Spec 251: the send-queue sweep's redispatch query (chatbot results whose resume job was lost) reads an index on
 * (kind, status, updated_at) instead of walking a table that is mostly old resumed rows. Its result stays the same.
 */
class SendQueueSweepIndexTest extends TestCase
{
    private const TABLE_MIGRATION = 'database/migrations/2026_10_29_100000_create_botcake_send_requests_table.php';
    private const INDEX_MIGRATION = 'database/migrations/2026_11_22_100000_add_kind_status_updated_at_index_to_botcake_send_requests.php';
    private const INDEX = 'botcake_send_requests_kind_status_updated_at_idx';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => array_values(array_filter([self::TABLE_MIGRATION, self::INDEX_MIGRATION], fn ($p) => is_file(base_path($p))))]);
        Queue::fake();
    }

    /** One row per entry of $rows ([kind, status, seconds ago]); returns the ids in insert order. */
    private function rows(array $rows): array
    {
        $ids = [];
        foreach (array_chunk($rows, 200) as $chunk) {
            $insert = [];
            foreach ($chunk as [$kind, $status, $ago]) {
                $at = now()->subSeconds($ago);
                $insert[] = [
                    'request_id' => (string) Str::uuid(), 'kind' => $kind, 'priority' => $kind === BotcakeSendQueue::SEQUENCE ? BotcakeSendQueue::SEQUENCE_PRIORITY : 0,
                    'customer' => $kind . ':1001:' . count($ids), 'page_id' => 1001, 'page_name' => 'Page Uno', 'psid' => (string) (7000 + count($ids)),
                    'chat_id' => null, 'flow_id' => 1, 'node_id' => 'n1', 'payload' => '{"messages":[]}', 'status' => $status,
                    'deadline_at' => $at->copy()->addMinutes(10), 'created_at' => $at, 'updated_at' => $at,
                ];
                $ids[] = null;
            }
            DB::table(BotcakeSendQueue::TABLE)->insert($insert);
        }

        return DB::table(BotcakeSendQueue::TABLE)->orderBy('id')->pluck('id')->slice(-count($rows))->values()->all();
    }

    /** The request ids the sweep sent back to the resume job, in dispatch order. */
    private function redispatched(): array
    {
        return Queue::pushed(ResumeChatbotSend::class)->map(fn ($job) => (new \ReflectionProperty($job, 'ticket'))->getValue($job)['request_id'])->values()->all();
    }

    public function test_S1_the_sweep_redispatches_exactly_the_old_chatbot_results_among_many_resumed_rows_in_id_order(): void
    {
        // Mostly old resumed rows, as on a busy site: none of them is touched.
        $this->rows(array_merge(array_fill(0, 600, [BotcakeSendQueue::SEQUENCE, 'resumed', 3600]), array_fill(0, 300, ['chatbot', 'resumed', 3600])));
        $wanted = $this->rows([['chatbot', 'failed', 120], ['chatbot', 'done', 40]]);
        $this->rows([
            ['chatbot', 'done', 10],                   // not 30 s old yet: its resume job may still be on its way
            ['chatblast', 'done', 120],                // chatblast results have no resume job
            [BotcakeSendQueue::SEQUENCE, 'done', 120], // sequence results belong to sweepSequence()
            ['chatbot', 'pending', 120], ['chatbot', 'sending', 120],
        ]);
        $wanted[] = $this->rows([['chatbot', 'done', 31]])[0];

        $out = BotcakeSendQueue::sweep();

        $this->assertSame(3, $out['redispatched']);
        $request = DB::table(BotcakeSendQueue::TABLE)->whereIn('id', $wanted)->orderBy('id')->pluck('request_id')->all();
        $this->assertSame($request, $this->redispatched(), 'exactly those rows, ordered by id');
        $this->assertSame(3, DB::table(BotcakeSendQueue::TABLE)->whereIn('id', $wanted)->where('updated_at', '>', now()->subSeconds(5))->count(), 'each is stamped so the next sweep waits again');
        $this->assertSame(0, BotcakeSendQueue::sweep()['redispatched'], 'and the next sweep does not send them again right away');
    }

    public function test_S1_the_sweep_redispatches_at_most_500_results_per_run_lowest_id_first(): void
    {
        $ids = $this->rows(array_fill(0, 501, ['chatbot', 'done', 120]));

        $this->assertSame(500, BotcakeSendQueue::sweep()['redispatched']);
        $this->assertSame(DB::table(BotcakeSendQueue::TABLE)->whereIn('id', array_slice($ids, 0, 500))->orderBy('id')->pluck('request_id')->all(), $this->redispatched());
    }

    public function test_S1_the_redispatch_query_of_the_sweep_reads_the_kind_status_updated_at_index(): void
    {
        $this->rows(array_fill(0, 50, ['chatbot', 'resumed', 3600]));
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            if (str_contains($q->sql, '"kind" = ?') && str_contains($q->sql, '"updated_at" <= ?')) $queries[] = $q;
        });

        BotcakeSendQueue::sweep();

        $this->assertCount(1, $queries, 'the redispatch query ran once');
        $plan = collect(DB::select('EXPLAIN QUERY PLAN ' . $queries[0]->sql, $queries[0]->bindings))->pluck('detail')->implode(' | ');
        $this->assertStringContainsString('USING INDEX ' . self::INDEX . ' (kind=? AND status=? AND updated_at<?)', $plan);
    }

    public function test_the_late_sweep_fails_exactly_the_late_pending_chatbot_rows_and_reads_the_status_priority_id_index(): void
    {
        // The tables a failed chatbot send writes to (the outbox reply).
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
        ]]);
        // Mostly old resumed rows, as on a busy site: none of them is touched.
        $this->rows(array_merge(array_fill(0, 600, [BotcakeSendQueue::SEQUENCE, 'resumed', 3600]), array_fill(0, 300, ['chatbot', 'resumed', 3600])));
        // deadline_at is 10 minutes after the row: 900 s ago is late, 120 s ago is not.
        $late = $this->rows([['chatbot', 'pending', 900], ['chatbot', 'pending', 700]]);
        $kept = $this->rows([
            ['chatbot', 'pending', 120],                  // not late yet
            [BotcakeSendQueue::SEQUENCE, 'pending', 900], // a sequence ticket is never failed for being late (sweepSequence)
        ]);
        $hints = [];
        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        $connection->setQueryGrammar(new class($connection, $hints) extends \Illuminate\Database\Query\Grammars\SQLiteGrammar {
            public function __construct($connection, private array &$hints) { parent::__construct($connection); }
            protected function compileIndexHint(\Illuminate\Database\Query\Builder $query, $indexHint)
            {
                $this->hints[] = (new \Illuminate\Database\Query\Grammars\MySqlGrammar($this->connection))->compileSelect($query);
                return parent::compileIndexHint($query, $indexHint);
            }
        });

        try {
            $out = BotcakeSendQueue::sweep();
        } finally {
            $connection->setQueryGrammar($grammar);
        }

        $this->assertSame(2, $out['failed']);
        $this->assertSame(0, $out['requeued']);
        $this->assertSame(['failed', 'failed'], DB::table(BotcakeSendQueue::TABLE)->whereIn('id', $late)->orderBy('id')->pluck('status')->all());
        $this->assertSame(['pending', 'pending'], DB::table(BotcakeSendQueue::TABLE)->whereIn('id', $kept)->orderBy('id')->pluck('status')->all());
        $this->assertSame(900, DB::table(BotcakeSendQueue::TABLE)->where('status', 'resumed')->count(), 'the resumed rows stay as they are');
        $this->assertCount(2, Queue::pushed(ResumeChatbotSend::class), 'each failed chatbot send goes on to its resume job');

        $this->assertCount(1, $hints, 'only the late query carries an index hint');
        $this->assertStringContainsString('from `botcake_send_requests` use index (botcake_send_requests_status_priority_id_index) where `status` = ? and `priority` < ?', $hints[0]);
    }

    public function test_the_migration_adds_the_index_once_and_down_removes_it(): void
    {
        $this->assertTrue(Schema::hasIndex(BotcakeSendQueue::TABLE, self::INDEX));
        $this->assertTrue(Schema::hasIndex(BotcakeSendQueue::TABLE, ['kind', 'status', 'updated_at']), 'columns in this order: the equalities, then the range');

        $migration = require base_path(self::INDEX_MIGRATION);
        $migration->up(); // already there: skips instead of failing
        $this->assertTrue(Schema::hasIndex(BotcakeSendQueue::TABLE, self::INDEX));

        $migration->down();
        $this->assertFalse(Schema::hasIndex(BotcakeSendQueue::TABLE, self::INDEX));
        $migration->down(); // already gone: no error

        $migration->up();
        $this->assertTrue(Schema::hasIndex(BotcakeSendQueue::TABLE, self::INDEX));
    }
}
