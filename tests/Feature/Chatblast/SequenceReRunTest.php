<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\RunChatbotSequenceStep;
use App\Services\Chatbot\BotcakeSendQueue;
use App\Services\Chatbot\ChatbotSequences;
use App\Services\Chatbot\ChatbotSheet;
use App\Services\PageSettings;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Spec 215, ang mga ayos pagkatapos ng review at QA: ang step na namatay bago ang unang padala (o sa gitna ng tawag) ay hindi
 * inuulit nang walang hangganan at hindi dobleng nagpapadala; ang sequence ticket ay hindi binibigo dahil huli; ang pumalyang
 * pagsulat ng ticket ay hindi nawawalang step; ang ibang ayos (may hawak sa tawag, page ID na nagbago, query ng tick, MySQL).
 */
class SequenceReRunTest extends SequenceHandoffTestCase
{
    /** @var array<int, array> */
    public array $sheetRows = [];

    protected function setUp(): void
    {
        parent::setUp();
        $test = $this;
        $this->app->instance(ChatbotSheet::class, new class($test) extends ChatbotSheet {
            public function __construct(private $test) {}
            public function append(string $spreadsheetId, string $tab, array $row): ?string { $this->test->sheetRows[] = $row; return null; }
        });
    }

    private function sheetStep(): array
    {
        return [['type' => 'sheet', 'config' => ['sheet' => '1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcd', 'tab' => 'Orders', 'columns' => ['{{psid}}']]]];
    }

    /** The attempt a killed job leaves in its row (its finally never ran), started long enough ago to be dead. */
    private function leftOver(object $seq, string $psid, array $marker): void
    {
        $sub = $this->sub($seq, $psid);
        DB::table('chatbot_sequence_subscriptions')->where('id', $sub->id)->update(['attempt' => ChatbotSequences::attemptValue(1, substr((string) $sub->next_send_at, 0, 19),
            $marker['attempts'], $marker['sending'], now()->subMinutes(10)->getTimestamp())]);
    }

    private function attempt(int $id): ?array
    {
        $a = ChatbotSequences::parseAttempt(DB::table('chatbot_sequence_subscriptions')->where('id', $id)->value('attempt'), 1, '2026-11-02 10:00:00');

        return $a === null ? null : ['attempts' => $a['attempts'], 'sending' => $a['sending']];
    }

    public function test_S_215_8_and_1b_a_step_killed_after_its_sheet_row_and_before_its_send_is_not_run_again(): void
    {
        $seq = $this->sequence('Sheet', [$this->stepFlow(['Hi'], $this->sheetStep())->id, [$this->stepFlow(['Two'])->id, 1, 'days']]);
        $this->join($seq, '7001');
        $this->sheetRows = [['earlier attempt']]; // the dead job wrote its row
        $this->leftOver($seq, '7001', ['attempts' => 1, 'sending' => false]);
        $this->steps();
        $this->settle(3);

        $this->assertSame([['earlier attempt']], $this->sheetRows, 'no second sheet row');
        $this->assertSame([], $this->calls, 'and no send');
        $this->assertSame(2, (int) $this->sub($seq, '7001')->current_step, 'recorded and advanced');
        $this->assertContains('Sequence Sheet step 1 failed: ' . ChatbotSequences::NOT_RERUN, $this->events('7001'));
    }

    public function test_1b_a_live_run_keeps_its_marker_only_while_it_runs(): void
    {
        $seq = $this->sequence('Mark', [$this->stepFlow(['Hi'], $this->sheetStep())->id]);
        $id = $this->join($seq, '7001');
        $this->app->instance(ChatbotSheet::class, new class($this, $id) extends ChatbotSheet {
            public function __construct(private $test, private int $id) {}
            public function append(string $spreadsheetId, string $tab, array $row): ?string { $this->test->sheetRows[] = $this->test->attemptOf($this->id); return null; }
        });
        $this->steps();
        $this->assertSame([['attempts' => 1, 'sending' => false]], $this->sheetRows, 'written in the row when the step starts');
        $this->assertNull($this->attempt($id), 'cleared once the run ends normally (handed off)');
    }

    public function test_1b_a_step_with_only_repeatable_nodes_is_run_again_once_then_recorded(): void
    {
        $before = [['type' => 'set', 'config' => ['ops' => [['op' => 'set', 'field' => 'seen', 'value' => 'yes']]]], ['type' => 'condition', 'config' => ['rows' => []], 'port' => 'no']];
        $seq = $this->sequence('Plain', [$this->stepFlow(['Hi'], $before)->id, [$this->stepFlow(['Two'])->id, 1, 'days']]);
        $this->join($seq, '7001');
        $this->join($seq, '7002');
        $this->leftOver($seq, '7001', ['attempts' => 1, 'sending' => false]); // died once before its send
        $this->leftOver($seq, '7002', ['attempts' => 2, 'sending' => false]); // died twice
        $this->steps();
        $this->settle(4);

        $this->assertSame(['sent: Hi'], $this->replies('7001'), 'run again once, sent once');
        $this->assertSame([], $this->replies('7002'), 'not a third time');
        foreach (['7001', '7002'] as $p) $this->assertSame(2, (int) $this->sub($seq, $p)->current_step);
        $this->assertContains('Sequence Plain step 1 failed: ' . ChatbotSequences::NOT_RERUN, $this->events('7002'), 'its own reason: nothing was sent');
    }

    public function attemptOf(int $id): ?array
    {
        return $this->attempt($id);
    }

    public function test_2_a_job_killed_during_an_inline_call_never_sends_the_step_again(): void
    {
        $seq = $this->sequence('Inline', [$this->stepFlow(['Hi'])->id, [$this->stepFlow(['Two'])->id, 1, 'days']]);
        $id = $this->join($seq, '7001');
        $this->pool->stop(); // the pool dropped: the step sends inline
        $this->travel(2)->seconds();
        $during = null;
        Http::fake(['botcake.io/*' => function () use ($id, &$during) {
            $during = $this->attempt($id);
            return Http::response(['success' => true]);
        }]);
        $this->steps();
        $this->assertSame(['attempts' => 1, 'sending' => true], ['attempts' => $during['attempts'] ?? null, 'sending' => $during['sending'] ?? null], 'the inline call is marked before it goes out');

        // A second customer: the job dies during the call (message out, no row, marker left).
        $this->join($seq, '7002');
        $this->leftOver($seq, '7002', ['attempts' => 1, 'sending' => true]);
        $this->steps();
        Http::assertSentCount(1);
        $this->assertSame([], $this->replies('7002'));
        $this->assertSame(2, (int) $this->sub($seq, '7002')->current_step, 'recorded and advanced, not sent again');
    }

    public function test_3_2500_steps_on_one_page_at_2_per_second_are_all_sent_late_and_none_failed(): void
    {
        $seq = $this->sequence('Big', [$this->stepFlow(['Hi'])->id]);
        $chats = [];
        $subs = [];
        for ($k = 1; $k <= 2500; $k++) {
            $chats[] = ['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => (string) (600000 + $k), 'name' => 'Ana', 'message' => 'Hi', 'payload' => '[]', 'received_at' => now()->subMinute()];
            $subs[] = ['sequence_id' => $seq->id, 'page_id' => 1001, 'psid' => 600000 + $k, 'status' => 'active', 'current_step' => 1, 'next_send_at' => now(),
                'dispatched_at' => null, 'enrolled_via' => 'other', 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($chats, 400) as $c) DB::table('botcake_chats')->insert($c);
        foreach (array_chunk($subs, 400) as $c) DB::table('chatbot_sequence_subscriptions')->insert($c);
        $this->steps(); // 2,500 hand-offs at 10:00
        $this->assertSame(2500, $this->tickets()->count());

        $base = \Carbon\Carbon::parse('2026-11-02 10:00:00');
        for ($i = 0; $i <= 15000; $i++) {
            $t = $i / 10;
            $this->clock->time = 1000 + $t;
            if ($i % 10 === 0) {
                \Carbon\Carbon::setTestNow($base->copy()->addSeconds((int) $t));
                $this->pool->beat();
                $this->replyPool->beat();
            }
            if ($i % 600 === 0) $this->tick(); // the minute tick: sweep, dead pool, backstop
            $this->pool->tick();
            // The API answers after 2.9 s: at any time some calls are live, also past 20 minutes.
            foreach ($this->calls as $c) if ($c['p']->getState() === 'pending' && $this->clock->now() >= $c['t'] + 2.9) $this->answer($c);
            $this->pool->tick(false);
            $this->resumes();
            if (DB::table('chatbot_sequence_subscriptions')->where('status', 'completed')->count() === 2500) break;
        }

        $this->assertSame(2500, DB::table('chatbot_replies')->where('status', 'sent')->count(), 'all sent');
        $this->assertSame(0, DB::table('chatbot_replies')->where('status', 'failed')->count(), 'none failed for being late');
        $this->assertSame(2500, DB::table('chatbot_sequence_subscriptions')->where('status', 'completed')->count());
        $this->assertGreaterThan(20 * 60, $t, 'the last ones went out past the old deadline and past the 20-minute backstop');
        $this->assertSame(0, DB::table(BotcakeSendQueue::TABLE)->where('error', 'like', '%did not answer%')->count(), 'no live call was failed as "no answer"');
    }

    public function test_3_a_dead_pool_cancels_waiting_tickets_and_the_tick_sends_them_inline(): void
    {
        Http::fake(['botcake.io/*' => Http::response(['success' => true])]);
        $plain = $this->sequence('Plain', [$this->stepFlow(['Hi'])->id]);
        $effect = $this->sequence('Effect', [$this->stepFlow(['Hi'], [['type' => 'set', 'config' => ['ops' => [['op' => 'append', 'field' => 'n', 'value' => 'x']]]]])->id]);
        $this->join($plain, '7001');
        $this->join($effect, '7002');
        $this->steps();
        $this->travel(61)->seconds(); // the pool is gone
        $this->replyPool->beat();
        $this->tick();
        $this->work(RunChatbotSequenceStep::class);

        $this->assertSame(['sent: Hi'], $this->replies('7001'), 'the waiting step went inline');
        $this->assertSame([], $this->replies('7002'), 'side effects already ran: recorded, not run again');
        $this->assertSame('completed', $this->sub($effect, '7002')->status);
        $this->assertContains('Sequence Effect step 1 failed: ' . ChatbotSequences::NOT_RERUN, $this->events('7002'));
    }

    public function test_5_a_ticket_that_cannot_be_written_gives_the_step_back_to_the_tick_or_records_it(): void
    {
        // The ticket write fails (the same trigger idea on each database).
        DB::statement(DB::getDriverName() === 'mysql'
            ? "CREATE TRIGGER no_ticket BEFORE INSERT ON botcake_send_requests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'database is locked'"
            : "CREATE TRIGGER no_ticket BEFORE INSERT ON botcake_send_requests BEGIN SELECT RAISE(ABORT, 'database is locked'); END");
        $plain = $this->sequence('Plain', [$this->stepFlow(['Hi'])->id, [$this->stepFlow(['Two'])->id, 1, 'days']]);
        $effect = $this->sequence('Effect', [$this->stepFlow(['Hi'], [['type' => 'set', 'config' => ['ops' => [['op' => 'append', 'field' => 'n', 'value' => 'x']]]]])->id,
            [$this->stepFlow(['Two'])->id, 1, 'days']]);
        $this->join($plain, '7001');
        $this->join($effect, '7002');
        $this->steps();

        $a = $this->sub($plain, '7001');
        $this->assertSame([1, null, null], [(int) $a->current_step, $a->dispatched_at, $a->send_request_id], 'given back to the tick, not advanced');
        $this->assertNotContains('Sequence Plain step 1 ran', $this->events('7001'));
        $this->assertSame(2, (int) $this->sub($effect, '7002')->current_step, 'with side effects: recorded, not run again');

        DB::statement('DROP TRIGGER no_ticket');
        $this->steps();
        $this->settle(4);
        $this->assertSame(['sent: Hi'], $this->replies('7001'), 'sent once on the next tick');
    }

    public function test_6_the_call_is_recorded_only_while_the_subscription_still_holds_the_ticket(): void
    {
        $seq = $this->sequence('Holder', [$this->stepFlow(['Hi'])->id]);
        $this->join($seq, '7001');
        $this->steps();
        $row = $this->tickets()->first();
        DB::table(BotcakeSendQueue::TABLE)->where('id', $row->id)->update(['status' => 'sending', 'claimed_by' => 'pool-x']);
        app(ChatbotSequences::class)->unsubscribe(null, 1001, 7001, 'reply'); // committed between the check and the call
        $this->assertFalse(BotcakeSendQueue::calledSequence($row, 'pool-x', 'botcake', 'likha-ref'));
        $this->assertNull(DB::table(BotcakeSendQueue::TABLE)->where('id', $row->id)->value('called_at'));
    }

    public function test_7_a_page_id_change_before_the_send_holds_the_step_instead_of_making_a_new_ticket_every_minute(): void
    {
        $seq = $this->sequence('Moved', [$this->stepFlow(['Hi'])->id]);
        $this->join($seq, '7001');
        $this->steps();
        app(PageSettings::class)->set('Page Uno', 'botcake', 'page_id', '1009');
        PageSettings::forget('Page Uno');
        $this->settle(3);
        $sub = $this->sub($seq, '7001');
        $this->assertSame(['active', 1, null, '2026-11-02 11:00:00'], [$sub->status, (int) $sub->current_step, $sub->send_request_id, substr((string) $sub->next_send_at, 0, 19)]);
        $this->assertContains('Sequence Moved step 1 held (page ID changed)', $this->events('7001'));
        $this->assertSame([], $this->calls);
    }

    public function test_8_the_ran_event_of_a_step_that_ended_elsewhere_carries_the_send_time(): void
    {
        $seq = $this->sequence('Exit', [$this->stepFlow(['Hi'])->id]);
        $this->join($seq, '7001');
        $this->steps();
        $this->roundsUntil(1);
        app(ChatbotSequences::class)->unsubscribe(null, 1001, 7001, 'reply');
        $this->travelTo('2026-11-02 10:03:00');
        $this->pool->beat();
        $this->answerAll();
        $this->ChatbotEventsElsewhere();
        $this->resumes();
        $ran = DB::table('chatbot_events')->where('psid', 7001)->where('type', 'sequence')->get()->first(fn ($e) => json_decode($e->detail, true)['op'] === 'ran');
        $this->assertSame('2026-11-02 10:03:01', substr((string) $ran->created_at, 0, 19), 'the send time (+1 s), on its own row');
    }

    /** Another customer's event written in between (the old max(id) re-dating would move it). */
    private function ChatbotEventsElsewhere(): void
    {
        \App\Services\Chatbot\ChatbotEvents::write(1001, 7999, 'sequence', ['op' => 'subscribed', 'name' => 'Other']);
    }

    public function test_9_the_tick_query_does_not_filter_on_the_new_column(): void
    {
        $seq = $this->sequence('Plan', [$this->stepFlow(['Hi'])->id]);
        $this->join($seq, '7001');
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            if (str_contains($q->sql, 'chatbot_sequences') && str_contains($q->sql, 'next_send_at') && str_starts_with(strtolower(ltrim($q->sql)), 'select')) $sql[] = $q->sql;
        });
        $this->tick();
        $this->assertNotEmpty($sql);
        $where = substr($sql[0], (int) stripos($sql[0], ' where '));
        $this->assertStringNotContainsString('send_request_id', $where, 'the due query keeps the (status, next_send_at) plan');
        Queue::assertPushed(RunChatbotSequenceStep::class, 1);
    }

    public function test_14_mysql_only_a_job_started_in_the_same_second_as_its_claim_runs(): void
    {
        if (DB::getDriverName() !== 'mysql') $this->markTestSkipped('MySQL counts changed rows, not matched rows: run against MySQL.');
        $seq = $this->sequence('Same second', [$this->stepFlow(['Hi'])->id]);
        $this->join($seq, '7001');
        $this->tick();
        $job = Queue::pushed(RunChatbotSequenceStep::class)->first();
        (new UniqueLock(app('cache.store')))->release($job);
        app()->call([$job, 'handle']); // the clock is frozen: the same second as the claim
        $this->assertCount(1, $this->tickets(), 'handed off, not "gone"');
    }
}
