<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\ResumeChatbotSequenceSend;
use App\Jobs\RunChatbotSequenceStep;
use App\Services\BotcakeClient;
use App\Services\Chatbot\BotcakeSendQueue;
use App\Services\Chatbot\ChatbotSequences;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowRun;
use App\Services\Chatbot\PageTransfers;
use App\Services\Chatbot\SequenceSendPool;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Spec 215, ang kaligtasan: ayos at isang beses lang, ang step na nasa himpapawid ay hindi kinukuha ulit, ang sagot ng bot
 * ay nauuna, ang mga pagsusuri sa oras ng padala, ang paglipat ng page, ang restart ng pool at ng worker, ang lumang claim,
 * ang walang column, at ang ibang padala sa page (rate, 429, chatblast, puno ang pool).
 */
class SequenceSafetyTest extends SequenceHandoffTestCase
{
    private function sentTo(string $psid): array
    {
        return array_values(array_map(fn ($c) => self::req($c)['text'], array_filter($this->calls, fn ($c) => self::req($c)['psid'] === $psid)));
    }

    // ---- S-215b ----

    public function test_S_215b_1_steps_1_2_3_go_in_order_once_each(): void
    {
        $seq = $this->sequence('Drip', [$this->stepFlow(['One'])->id, $this->stepFlow(['Two'])->id, $this->stepFlow(['Three'])->id]);
        $this->join($seq, '7001');
        for ($i = 0; $i < 4; $i++) {
            $this->steps();
            $this->settle(6);
        }
        $this->assertSame(['One', 'Two', 'Three'], $this->sentTo('7001'));
        $this->assertSame(['sent: One', 'sent: Two', 'sent: Three'], $this->replies('7001'));
        $this->assertSame('completed', $this->sub($seq, '7001')->status);
    }

    public function test_S_215b_2_and_3_a_step_in_flight_gets_no_second_job_or_ticket_even_after_a_12_minute_backlog(): void
    {
        $seq = $this->sequence('Long', [$this->stepFlow(['Hi'])->id]);
        $this->join($seq, '7001');
        $this->steps();
        $this->assertCount(1, $this->tickets());
        foreach ([3, 9] as $minutes) { // 12 minutes in all, inside the ticket's 15-minute deadline
            $this->travel($minutes)->minutes();
            $this->pool->beat(); // the pool is alive, only slow
            $this->replyPool->beat();
            $this->tick();
        }
        Queue::assertPushed(RunChatbotSequenceStep::class, 1);
        $this->assertCount(1, $this->tickets());
        $this->settle(3);
        $this->assertSame(['Hi'], $this->sentTo('7001'));
    }

    public function test_S_215b_4_a_sequence_send_waits_for_the_customers_reply_and_a_reply_never_waits_for_a_sequence(): void
    {
        $seq = $this->sequence('Wait', [$this->stepFlow(['Promo'])->id]);
        $this->join($seq, '7001');
        $this->steps();
        $reply = fn (string $status) => DB::table(BotcakeSendQueue::TABLE)->insertGetId(['request_id' => 'reply-' . uniqid(), 'kind' => 'chatbot', 'priority' => 0,
            'customer' => BotcakeSendQueue::chatbotCustomer(1001, 7001), 'page_id' => 1001, 'page_name' => 'Page Uno', 'psid' => '7001', 'node_id' => 'n2', 'flow_id' => 1,
            'payload' => '{"messages":[]}', 'status' => $status, 'deadline_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now()]);
        $r = $reply('pending');

        // The reply pool takes the reply although the sequence ticket is older.
        $taken = BotcakeSendQueue::claim('reply-pool', 10);
        $this->assertSame([$r], array_map(fn ($t) => (int) $t->id, $taken), 'the reply pool never takes a sequence ticket, and is not held by one');

        for ($i = 0; $i < 6; $i++) $this->round();
        $this->assertSame([], $this->calls, 'the sequence waits while the reply is out');
        DB::table(BotcakeSendQueue::TABLE)->where('id', $r)->update(['status' => 'done']);
        $this->travel(2)->seconds(); // the sequence ticket was put back for 1 s
        $this->roundsUntil(1);
        $this->assertSame(['Promo'], $this->sentTo('7001'));
    }

    public function test_S_215b_5_exit_on_reply_while_in_flight_sends_no_later_message_and_nothing_twice(): void
    {
        $seq = $this->sequence('Exit', [$this->stepFlow(['First', 'Second'])->id, $this->stepFlow(['Later'])->id]);
        // Before the call: the reply ends it, nothing goes out.
        $this->join($seq, '7001');
        $this->steps();
        $this->chat('7001');
        app(ChatbotSequences::class)->exitOnReply(1001, 7001);
        $this->settle(4);
        $this->assertSame([], $this->sentTo('7001'));
        $this->assertSame('unsubscribed', $this->sub($seq, '7001')->status);

        // After the call: it is logged as sent, and the step's next message does not go out.
        $this->join($seq, '7002');
        $this->steps();
        $this->roundsUntil(1);
        $this->chat('7002');
        app(ChatbotSequences::class)->exitOnReply(1001, 7002);
        $this->settle(4);
        $this->assertSame(['First'], $this->sentTo('7002'));
        $this->assertSame(['sent: First'], $this->replies('7002'));
        $sub = $this->sub($seq, '7002');
        $this->assertSame(['unsubscribed', 1, null], [$sub->status, (int) $sub->current_step, $sub->send_request_id]);
        $this->steps();
        $this->settle(3);
        $this->assertSame(['First'], $this->sentTo('7002'), 'no later step');
    }

    public static function sendTimeChecks(): array
    {
        return [
            'page off' => [fn () => app(PageSettings::class)->set('Page Uno', 'chatbot', 'active', '0', false, null, true), 'paused', 'page off', 1],
            'bot paused' => [fn () => (new CustomerFields(1001, 7001))->set(CustomerFields::BOT_PAUSED, '1'), 'active', null, 1],
            'sequence paused' => [fn () => DB::table('chatbot_sequences')->update(['active' => false]), 'active', null, 1],
            '24 h closed' => [fn () => DB::table('botcake_chats')->update(['received_at' => now()->subHours(25)]), 'unsubscribed', 'outside 24h', 1],
        ];
    }

    #[DataProvider('sendTimeChecks')]
    public function test_S_215b_6_pause_page_off_and_the_24_h_window_are_checked_at_send_time(\Closure $change, string $status, ?string $reason, int $step): void
    {
        $seq = $this->sequence('Check', [$this->stepFlow(['Hi'])->id, $this->stepFlow(['Next'])->id]);
        $this->join($seq, '7001');
        $this->steps();
        $change();
        \App\Services\Chatbot\BotConfigCache::forgetSequence($seq->id);
        PageSettings::forget('Page Uno');
        $this->settle(3);

        $this->assertSame([], $this->calls, 'nothing sent');
        $this->assertSame([], $this->replies('7001'));
        $sub = $this->sub($seq, '7001');
        $this->assertSame([$status, $reason, $step, null], [$sub->status, $sub->exit_reason, (int) $sub->current_step, $sub->send_request_id], 'as at the start of the step today');
    }

    public function test_S_215b_8_a_held_customer_lock_or_another_sequence_in_flight_retries_in_1_minute(): void
    {
        $a = $this->sequence('A', [$this->stepFlow(['A'])->id]);
        $b = $this->sequence('B', [$this->stepFlow(['B'])->id]);
        $this->join($a, '7001');
        $this->steps();
        app(ChatbotSequences::class)->subscribe($b->id, 1001, 7001, 'other');
        $this->steps();
        $this->assertSame('2026-11-02 10:01:00', substr((string) $this->sub($b, '7001')->next_send_at, 0, 19), 'one sequence send per customer at a time');

        $lock = Cache::lock('chatbot-run:1001:7002', 300);
        $lock->get();
        $this->join($a, '7002');
        $this->steps();
        $this->assertSame('2026-11-02 10:01:00', substr((string) $this->sub($a, '7002')->next_send_at, 0, 19), 'lock taken: retry in 1 minute');
        $lock->release();
    }

    public function test_S_215b_9_the_ticket_and_its_link_are_written_together_or_not_at_all(): void
    {
        $seq = $this->sequence('Link', [$this->stepFlow(['Hi'])->id]);
        $id = $this->join($seq, '7001');
        $flow = $this->stepFlow(['Hi']);
        $run = new FlowRun($flow->id, $flow->graph, 'Page Uno', '1001', [], new CustomerFields(1001, 7001), null, '', '', '');
        $run->sequenceTicket = ['subscription_id' => $id, 'current_step' => 1, 'step' => 1, 'due' => null, 'name' => 'Link', 'sequence_id' => $seq->id, 'stamp' => '2026-11-02 09:00:00'];

        $this->assertNull(BotcakeSendQueue::submitSequence($run, 'n10', [['type' => 'text', 'text' => 'Hi']]), 'the claim is not the job\'s any more');
        $this->assertCount(0, $this->tickets());

        DB::table('chatbot_sequence_subscriptions')->where('id', $id)->update(['dispatched_at' => '2026-11-02 09:00:00']);
        $levels = [];
        DB::listen(function ($q) use (&$levels) {
            if (str_contains($q->sql, 'insert into "botcake_send_requests"')) $levels[] = DB::transactionLevel();
        });
        $ticket = BotcakeSendQueue::submitSequence($run, 'n10', [['type' => 'text', 'text' => 'Hi']]);
        $this->assertSame([1], $levels, 'inserted inside the same transaction as the link');
        $this->assertSame($ticket, $this->sub($seq, '7001')->send_request_id);
    }

    public function test_S_215b_10_a_re_subscribe_while_in_flight_never_advances_the_new_subscription_with_the_old_result(): void
    {
        $seq = $this->sequence('Again', [$this->stepFlow(['Hi'])->id, [$this->stepFlow(['Two'])->id, 1, 'days']]);
        $this->join($seq, '7001');
        $this->steps();
        $this->roundsUntil(1);
        app(ChatbotSequences::class)->unsubscribe(null, 1001, 7001, 'inbox');
        app(ChatbotSequences::class)->subscribe($seq->id, 1001, 7001, 'other');
        $this->assertNull($this->sub($seq, '7001')->send_request_id, 'the restart clears the link');
        $this->steps(); // the old ticket is still out: the new step waits a minute
        $this->answerAll();
        $this->resumes();
        $this->assertSame(1, (int) $this->sub($seq, '7001')->current_step, 'the old result moves nothing');

        $this->travel(1)->minutes();
        $this->pool->beat();
        $this->replyPool->beat();
        $this->steps();
        $this->settle(4);
        $this->assertSame(['Hi', 'Hi'], $this->sentTo('7001'), 'the old step once, the new step 1 once');
        $this->assertSame(2, (int) $this->sub($seq, '7001')->current_step);
    }

    public function test_S_215b_7_and_11_a_page_move_while_in_flight_sends_each_step_once(): void
    {
        $seq = $this->sequence('Move', [$this->stepFlow(['Hi'])->id, [$this->stepFlow(['Two'])->id, 1, 'hours']]);
        $this->join($seq, '7001');
        $this->join($seq, '7002');
        $this->steps();
        $this->roundsUntil(1); // 7001 is called; 7002 waits for the page's next slot
        $called = self::req($this->calls[0])['psid'];
        $waiting = $called === '7001' ? '7002' : '7001';

        app(PageTransfers::class)->hold('Page Uno', 1001, true, 'T1');

        $a = $this->sub($seq, $called);
        $this->assertSame([2, null], [(int) $a->current_step, $a->send_request_id], 'the called step moves on before the copy');
        $b = $this->sub($seq, $waiting);
        $this->assertSame([1, null], [(int) $b->current_step, $b->send_request_id], 'the uncalled one stays at its step for the new site');
        $this->answerAll();
        $this->settle(4);
        $this->assertCount(1, $this->calls, 'the waiting one is never sent here');
        $this->assertSame(['sent: Hi'], $this->replies($called));
        $this->assertSame(2, (int) $this->sub($seq, $called)->current_step, 'the late result moves nothing more');
    }

    // ---- S-215c ----

    public function test_S_215c_1_a_clean_pool_exit_drains_what_is_in_the_air_and_each_advances_once(): void
    {
        $seq = $this->sequence('Drain', [$this->stepFlow(['Hi'])->id, [$this->stepFlow(['Two'])->id, 1, 'days']]);
        $this->join($seq, '7001');
        $this->join($seq, '8001', '1002');
        $this->steps();
        $this->round();
        $this->assertCount(2, $this->calls);
        foreach ($this->calls as $c) $this->answer($c);
        $this->pool->run(10, fn () => true);
        $this->resumes();
        foreach ([['7001', '1001'], ['8001', '1002']] as [$p, $page]) $this->assertSame(2, (int) $this->sub($seq, $p, $page)->current_step);
        $this->assertFalse(SequenceSendPool::capable(), 'no new hand-off to a stopped pool');
        $this->assertTrue(SequenceSendPool::alive(), 'but it is not taken for dead in the seconds before the next one starts');
        $this->assertTrue($this->newPool()->start(), 'and the next pool starts at once');
    }

    public function test_S_215c_1_the_seconds_between_a_clean_exit_and_the_next_pool_are_not_a_dead_pool(): void
    {
        $seq = $this->sequence('Restart', [$this->stepFlow(['Hi'])->id]);
        $this->join($seq, '7001');
        $this->join($seq, '7002');
        $this->steps();
        $this->roundsUntil(1);
        $this->answerAll();
        $this->resumes();
        $this->pool->stop(); // max-time reached; supervisor starts the next one a few seconds later
        $this->travel(3)->seconds();
        $this->replyPool->beat();
        $this->tick();
        $this->assertSame(0, $this->tickets()->where('status', 'resumed')->whereNotNull('error')->count(), 'nothing cancelled');
        $this->pool = $this->newPool();
        $this->assertTrue($this->pool->start());
        $this->settle(6);
        foreach (['7001', '7002'] as $p) $this->assertSame(['sent: Hi'], $this->replies($p));
        Queue::assertPushed(RunChatbotSequenceStep::class, 2);
    }

    public function test_S_215c_2_and_3_a_killed_pool_never_resends_a_called_step_and_sends_an_uncalled_one_once(): void
    {
        $seq = $this->sequence('Kill', [$this->stepFlow(['Hi'])->id, [$this->stepFlow(['Two'])->id, 1, 'days']]);
        $this->join($seq, '7001');
        $this->join($seq, '7002');
        $this->steps();
        $this->roundsUntil(1);
        $called = self::req($this->calls[0])['psid'];
        $other = $called === '7001' ? '7002' : '7001';
        $old = $this->pool;

        $this->travel(11)->seconds();
        $this->pool = $this->newPool();
        $this->assertTrue($this->pool->start());
        $this->resumes();
        $this->settle(6);
        for ($i = 0; $i < 4; $i++) {
            $this->clock->time += 0.5;
            $old->tick();
        }

        $this->assertSame(['Hi'], $this->sentTo($called), 'not called again');
        $this->assertSame(['failed: Hi (Botcake did not answer (HTTP 0))'], $this->replies($called));
        $this->assertSame(['Hi'], $this->sentTo($other), 'the uncalled one is sent once by the new pool');
        foreach (['7001', '7002'] as $p) $this->assertSame(2, (int) $this->sub($seq, $p)->current_step, 'not stuck');
    }

    public function test_S_215c_4_a_lost_resume_is_sent_again_by_the_sweep_and_a_duplicate_does_nothing(): void
    {
        $seq = $this->sequence('Lost', [$this->stepFlow(['Hi'])->id, [$this->stepFlow(['Two'])->id, 1, 'days']]);
        $this->join($seq, '7001');
        $this->steps();
        $this->roundsUntil(1);
        $this->answerAll();
        $lost = Queue::pushed(ResumeChatbotSequenceSend::class)->first();
        (new UniqueLock(app('cache.store')))->release($lost); // the worker took it, then died
        $this->travel(31)->seconds();
        BotcakeSendQueue::sweepSequence();
        $this->assertCount(2, Queue::pushed(ResumeChatbotSequenceSend::class));
        $this->resumes();
        app()->call([$lost, 'handle']);
        $this->assertSame(2, (int) $this->sub($seq, '7001')->current_step);
        $this->assertSame(['Subscribed to sequence Lost', 'Sequence Lost step 1 ran'], $this->events('7001'));
    }

    public function test_S_215c_6_a_terminate_and_a_pool_restart_together_give_one_row_per_send(): void
    {
        $seq = $this->sequence('Many', [$this->stepFlow(['Hi'])->id]);
        $customers = ['7001' => '1001', '7002' => '1001', '7003' => '1001', '8001' => '1002', '8002' => '1002'];
        foreach ($customers as $p => $page) $this->join($seq, $p, $page);
        $this->steps();
        $this->roundsUntil(2);
        $this->answer($this->calls[0]);
        $this->pool->tick(false);
        // The pool is killed now; the workers restart (a resume job is lost on the way).
        $this->travel(11)->seconds();
        $this->pool = $this->newPool();
        $this->assertTrue($this->pool->start());
        Queue::pushed(ResumeChatbotSequenceSend::class)->each(fn ($j) => (new UniqueLock(app('cache.store')))->release($j));
        $this->travel(31)->seconds();
        $this->settle(12);
        BotcakeSendQueue::sweepSequence();
        $this->resumes();

        foreach ($customers as $p => $page) {
            $this->assertLessThanOrEqual(1, count($this->sentTo($p)), $p . ' called at most once');
            $this->assertCount(1, $this->replies($p), $p . ' has exactly one row (sent or failed)');
            $this->assertSame('completed', $this->sub($seq, $p, $page)->status);
        }
    }

    public function test_S_215c_7_a_ticket_finished_by_a_foreign_process_is_resolved_after_the_deadline_without_a_resend(): void
    {
        $seq = $this->sequence('Foreign', [$this->stepFlow(['Hi'])->id, [$this->stepFlow(['Two'])->id, 1, 'days']]);
        $this->join($seq, '7001');
        $this->steps();
        // An old reply pool took it as a chatblast message: "done", no result, no resume.
        DB::table(BotcakeSendQueue::TABLE)->update(['status' => 'done', 'called_at' => now(), 'done_at' => now()]);
        $this->travel(21)->minutes();
        $this->pool->beat();
        $this->tick();
        $this->resumes();
        $this->assertSame([], $this->calls);
        $sub = $this->sub($seq, '7001');
        $this->assertSame([2, null], [(int) $sub->current_step, $sub->send_request_id]);
        $this->assertContains('Sequence Foreign step 1 failed: ' . ChatbotSequences::NOT_RESENT, $this->events('7001'));
    }

    public function test_S_215c_8_a_dead_sequence_pool_sends_uncalled_steps_inline_once_and_never_resends_called_ones(): void
    {
        Http::fake(['botcake.io/*' => Http::response(['success' => true])]);
        $seq = $this->sequence('Dead', [$this->stepFlow(['Hi'])->id]);
        $this->join($seq, '7001');
        $this->join($seq, '7002');
        $this->steps();
        $this->roundsUntil(1);
        $called = self::req($this->calls[0])['psid'];
        $other = $called === '7001' ? '7002' : '7001';

        $this->travel(61)->seconds(); // no beat for longer than DEAD_POOL_SECONDS
        $this->replyPool->beat();
        $this->tick();
        $this->work(RunChatbotSequenceStep::class);
        $this->resumes();

        $this->assertSame(['failed: Hi (Botcake did not answer (HTTP 0))'], $this->replies($called), 'the called one is failed, not sent again');
        $this->assertSame(['sent: Hi'], $this->replies($other), 'the uncalled one went inline, once');
        Http::assertSentCount(1);
        foreach (['7001', '7002'] as $p) $this->assertSame('completed', $this->sub($seq, $p)->status);
    }

    // ---- S-215d ----

    public function test_S_215d_1_to_3_a_dead_job_is_picked_up_again_in_about_2_minutes_a_queued_one_is_left_and_a_stale_job_leaves_nothing_stuck(): void
    {
        $seq = $this->sequence('Dead job', [$this->stepFlow(['Hi'])->id, [$this->stepFlow(['Two'])->id, 1, 'days']]);
        $id = $this->join($seq, '7001');
        $this->tick();
        $stamp = DB::table('chatbot_sequence_subscriptions')->where('id', $id)->value('dispatched_at');
        $this->travel(30)->minutes();
        $this->pool->beat();
        $this->replyPool->beat();
        $this->tick();
        Queue::assertPushed(RunChatbotSequenceStep::class, 1);
        $this->assertSame($stamp, DB::table('chatbot_sequence_subscriptions')->where('id', $id)->value('dispatched_at'), 'a queued job\'s row is not re-stamped');

        $first = Queue::pushed(RunChatbotSequenceStep::class)->first();
        (new UniqueLock(app('cache.store')))->release($first); // taken by a worker that then died
        $this->tick();
        Queue::assertPushed(RunChatbotSequenceStep::class, 2);
        $second = Queue::pushed(RunChatbotSequenceStep::class)[1];
        $this->assertSame($stamp, $second->earlier);

        app()->call([$first, 'handle']);
        $this->assertSame([], $this->calls, 'the overtaken job does nothing');
        (new UniqueLock(app('cache.store')))->release($second);
        app()->call([$second, 'handle']);
        $this->settle(3);
        $this->assertSame(['Hi'], $this->sentTo('7001'));
        $this->assertSame(2, (int) $this->sub($seq, '7001')->current_step);

        // Re-picked within 2 minutes, not 10.
        $this->travel(1)->days();
        $this->pool->beat();
        $this->replyPool->beat();
        $this->tick();
        $third = Queue::pushed(RunChatbotSequenceStep::class)[2];
        (new UniqueLock(app('cache.store')))->release($third);
        $this->travel(1)->minutes();
        $this->pool->beat();
        $this->replyPool->beat();
        $this->tick();
        Queue::assertPushed(RunChatbotSequenceStep::class, 3);
        $this->travel(65)->seconds();
        $this->pool->beat();
        $this->replyPool->beat();
        $this->tick();
        Queue::assertPushed(RunChatbotSequenceStep::class, 4);
    }

    public function test_S_215d_1_a_job_that_died_after_its_send_is_not_run_again(): void
    {
        $seq = $this->sequence('After send', [$this->stepFlow(['Hi'])->id, [$this->stepFlow(['Two'])->id, 1, 'days']]);
        $id = $this->join($seq, '7001');
        $this->tick();
        $job = Queue::pushed(RunChatbotSequenceStep::class)->first();
        // The earlier run sent the step (a row of this sequence and step), then its job died before advancing.
        \App\Services\Chatbot\BotcakeOutbox::record(1001, 7001, null, 1, 'n10', 'send', [['type' => 'text', 'text' => 'Hi']], 'sent', null, 200, null,
            ['sequence_id' => $seq->id, 'sequence_step' => 1], 'botcake');
        (new UniqueLock(app('cache.store')))->release($job);
        $this->travel(3)->minutes();
        $this->pool->beat();
        $this->replyPool->beat();
        $this->steps();
        $this->settle(2);
        $this->assertSame([], $this->calls, 'not sent again');
        $this->assertSame(2, (int) $this->sub($seq, '7001')->current_step);
        $this->assertContains('Sequence After send step 1 failed: ' . ChatbotSequences::NOT_RESENT, $this->events('7001'));
    }

    public function test_S_215d_4_2000_due_at_once_are_all_queued_within_the_tick(): void
    {
        $seq = $this->sequence('Big', [$this->stepFlow(['Hi'])->id]);
        $rows = [];
        for ($k = 1; $k <= 2000; $k++) {
            $rows[] = ['sequence_id' => $seq->id, 'page_id' => 1001, 'psid' => 900000 + $k, 'status' => 'active', 'current_step' => 1, 'next_send_at' => now()->subMinutes($k % 7),
                'dispatched_at' => null, 'enrolled_via' => 'other', 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 400) as $chunk) DB::table('chatbot_sequence_subscriptions')->insert($chunk);
        $start = microtime(true);
        $this->assertSame(2000, $this->tick());
        $this->assertLessThan(50, microtime(true) - $start);
        $this->assertSame(2000, Queue::pushed(RunChatbotSequenceStep::class)->pluck('subscriptionId')->unique()->count(), 'no row hidden');
    }

    public function test_S_215d_5_a_running_job_is_never_claimed_again_and_a_killed_one_is_picked_up_after_the_window(): void
    {
        $seq = $this->sequence('Running', [$this->stepFlow(['Hi'])->id]);
        $id = $this->join($seq, '7001');
        $this->tick();
        $job = Queue::pushed(RunChatbotSequenceStep::class)->first();
        $this->assertSame(ChatbotSequences::HANDOFF_TIMEOUT, $job->timeout);
        $this->assertLessThan(ChatbotSequences::STALE_MINUTES * 60, $job->timeout, 'the job times out inside the stale window');
        (new UniqueLock(app('cache.store')))->release($job);
        // The job is running (its marker is set by runStep), started 3 minutes after its claim.
        $this->travel(3)->minutes();
        Cache::put(ChatbotSequences::RUNNING . $id, 1, ChatbotSequences::HANDOFF_TIMEOUT + 10);
        DB::table('chatbot_sequence_subscriptions')->where('id', $id)->update(['dispatched_at' => now()->subMinutes(3)]);
        $this->pool->beat();
        $this->replyPool->beat();
        $this->tick();
        Queue::assertPushed(RunChatbotSequenceStep::class, 1);

        // Killed at its timeout: the marker runs out and the row is picked up once.
        $this->travel(ChatbotSequences::HANDOFF_TIMEOUT + 11)->seconds();
        $this->pool->beat();
        $this->replyPool->beat();
        $this->tick();
        $this->tick();
        Queue::assertPushed(RunChatbotSequenceStep::class, 2);
    }

    public function test_S_215d_6_without_the_new_column_the_tick_and_the_step_work_as_before(): void
    {
        Http::fake(['botcake.io/*' => Http::response(['success' => true])]);
        Schema::table('chatbot_sequence_subscriptions', function ($t) {
            $t->dropIndex(['send_request_id']);
            $t->dropColumn('send_request_id');
        });
        ChatbotSequences::forgetSchema();
        $seq = $this->sequence('Old', [$this->stepFlow(['Hi'])->id, $this->stepFlow(['Two'])->id]);
        $this->join($seq, '7001');
        $this->steps();
        $this->steps();
        app(ChatbotSequences::class)->unsubscribe(null, 1001, 7001, 'inbox');
        $this->assertSame(0, $this->tickets()->count());
        $this->assertSame(['sent: Hi', 'sent: Two'], $this->replies('7001'));
        $this->assertSame('completed', $this->sub($seq, '7001')->status);
    }

    // ---- S-215e ----

    public function test_S_215e_4_a_provider_switch_before_the_call_sends_once_on_the_new_provider(): void
    {
        config(['services.gateway.url' => 'https://gw.test', 'services.gateway.key' => 'gk-test-not-real']);
        $seq = $this->sequence('Switch', [$this->stepFlow(['Hi'])->id]);
        $this->join($seq, '7001');
        $this->join($seq, '7002');
        $this->steps();
        $this->roundsUntil(1);
        $first = self::req($this->calls[0])['psid'];
        $second = $first === '7001' ? '7002' : '7001';
        app(PageSettings::class)->set('Page Uno', ChatbotFields::PROVIDER, 'provider', 'gateway');
        PageSettings::forget('Page Uno');
        $this->settle(6);

        $hosts = fn (string $p) => array_values(array_map(fn ($c) => self::req($c)['host'], array_filter($this->calls, fn ($c) => self::req($c)['psid'] === $p)));
        $this->assertSame(['botcake.io'], $hosts($first));
        $this->assertSame(['gw.test'], $hosts($second), 'exactly one provider, once');
    }

    public function test_S_215e_5_and_e_6_the_page_rate_is_shared_and_a_429_backs_off_that_page_only(): void
    {
        $seq = $this->sequence('Rate', [$this->stepFlow(['Hi'])->id]);
        foreach (['7001', '7002', '7003', '7004'] as $p) $this->join($seq, $p);
        $this->join($seq, '8001', '1002');
        $this->join($seq, '8002', '1002');
        // An Inbox send on Page Uno books the next slot through the same rate.
        [, $inbox] = BotcakeClient::slot(sha1('tok-1001'), $this->clock->now(), true);
        $this->steps();
        for ($i = 0; $i < 40; $i++) {
            $this->round();
            foreach ($this->calls as $k => $c) {
                if ($c['p']->getState() !== 'pending') continue;
                $tooMany = self::req($c)['psid'] === '7002' && !isset($this->seen429);
                if ($tooMany) $this->seen429 = $c['t'];
                $this->answer($c, $tooMany ? 429 : 200, $tooMany ? ['error' => 'slow down'] : ['success' => true]);
            }
            $this->pool->tick(false);
            $this->resumes();
            $this->clock->time += 0.1;
        }
        $at = fn (string $page) => array_values(array_map(fn ($c) => $c['t'], array_filter($this->calls, fn ($c) => str_contains(self::req($c)['path'], '/pages/' . $page . '/'))));
        $uno = $at('1001');
        $this->assertGreaterThanOrEqual($inbox + 0.5 - 1e-6, $uno[0], 'after the Inbox send\'s slot');
        for ($i = 1; $i < count($uno); $i++) $this->assertGreaterThanOrEqual(0.5 - 1e-6, $uno[$i] - $uno[$i - 1], 'at most 2 per second on one page');
        $this->assertGreaterThanOrEqual($this->seen429 + 2 - 1e-6, $uno[array_search($this->seen429, $uno, true) + 1], 'the 429 pauses Page Uno');
        $this->assertLessThan($this->seen429 + 1, $at('1002')[1], 'Page Dos does not wait for it');
        foreach (['7001', '7002', '7003', '7004'] as $p) $this->assertSame(['sent: Hi'], $this->replies($p));
    }

    private ?float $seen429 = null;

    public function test_S_215e_8_the_two_pools_never_take_each_others_tickets_and_share_the_page(): void
    {
        $seq = $this->sequence('Share', [$this->stepFlow(['Hi'])->id]);
        foreach (['7001', '7002', '7003'] as $p) $this->join($seq, $p);
        $this->steps();
        $blast = DB::table(BotcakeSendQueue::TABLE)->insertGetId(['request_id' => 'blast-1', 'kind' => 'chatblast', 'priority' => 2, 'customer' => 'chatblast-customer:x',
            'page_id' => 1001, 'page_name' => 'Page Uno', 'psid' => '7999', 'message_id' => 1, 'payload' => '{}', 'status' => 'pending',
            'deadline_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame([$blast], array_map(fn ($t) => (int) $t->id, BotcakeSendQueue::claim('reply-pool', 10)), 'the reply pool takes the chatblast, not the sequences');
        DB::table(BotcakeSendQueue::TABLE)->where('id', $blast)->update(['status' => 'done']);
        $this->assertSame(0, count(BotcakeSendQueue::claim('reply-pool', 10)));
        $this->assertSame(0, BotcakeSendQueue::recover('reply-pool'), 'nor does it recover them');
        $this->settle(10);
        foreach (['7001', '7002', '7003'] as $p) $this->assertSame(['sent: Hi'], $this->replies($p));
    }

    public function test_S_215e_9_a_full_pool_leaves_the_rest_pending_without_failing_them(): void
    {
        config(['services.botcake.sequence_concurrency' => 2]);
        $seq = $this->sequence('Full', [$this->stepFlow(['Hi'])->id]);
        foreach (['7001', '7002', '8001', '8002', '8003'] as $p) $this->join($seq, $p, $p[0] === '7' ? '1001' : '1002');
        $this->steps();
        $this->round();
        $this->assertSame(2, $this->tickets()->where('status', 'sending')->count());
        $this->assertSame(3, $this->tickets()->where('status', 'pending')->count());
        $this->assertSame(0, $this->tickets()->where('status', 'failed')->count());
        $this->settle(20);
        foreach (['7001', '7002'] as $p) $this->assertSame(['sent: Hi'], $this->replies($p));
        foreach (['8001', '8002', '8003'] as $p) $this->assertSame(['sent: Hi'], $this->replies($p));
    }
}
