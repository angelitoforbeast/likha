<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\ResumeChatbotSequenceSend;
use App\Jobs\RunChatbotSequenceStep;
use App\Services\Chatbot\BotcakeSendQueue;
use App\Services\Chatbot\ChatbotSequences;
use App\Services\Chatbot\SequenceSendPool;
use App\Services\PageSettings;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Queue;

/**
 * Spec 215, ikatlong ikot: ang kalagayan ng takbo ay nasa row (ang cache na nabura ng deploy ay walang inuulit), ang tanda ng
 * tawag ay sa mismong request, ang pool na hindi umuusad, ang page ID na nagbago nang matagal, ang mga query ng backstop at
 * sweep, ang pagbura ng luma, at ilang query ang ginagawa ng pool kada padala.
 */
class SequenceDurabilityTest extends SequenceHandoffTestCase
{
    private function aiStep(string $name, callable $during): object
    {
        config(['services.openai.key' => 'sk-test-not-real']);
        Http::fake(['api.openai.com/*' => function () use ($during) {
            $during();
            return Http::response(['choices' => [['message' => ['content' => 'AI line']]]]);
        }, 'botcake.io/*' => Http::response(['success' => true])]);

        return $this->sequence($name, [$this->stepFlow(['{{line}}'], [['type' => 'ai', 'config' => ['user' => 'hi', 'save_to' => 'line'], 'port' => 'success']])->id]);
    }

    private function aiCalls(): int
    {
        return Http::recorded(fn ($r) => str_contains($r->url(), 'openai'))->count();
    }

    public function test_B_a_cache_cleared_mid_run_repeats_no_effect_node_and_sends_once_with_the_hand_off(): void
    {
        $seq = $this->aiStep('Clear', function () {
            Cache::flush(); // optimize:clear during a deploy: markers, unique locks and the customer lock are gone
            $this->travel(3)->minutes();
            $this->pool->beat();
            $this->replyPool->beat();
            $this->tick();
            $this->work(RunChatbotSequenceStep::class); // a second job would run now if it were claimed
        });
        $this->join($seq, '7001');
        $this->steps();
        $this->pool->beat();
        $this->replyPool->beat();
        $this->settle(4);

        $this->assertSame(1, $this->aiCalls(), 'the AI node ran once');
        $this->assertSame(['sent: AI line'], $this->replies('7001'));
        Queue::assertPushed(RunChatbotSequenceStep::class, 1);
    }

    public function test_B_a_cache_cleared_during_an_inline_call_never_sends_the_step_twice(): void
    {
        config(['services.chatbot.sequence_handoff' => false]);
        $seq = $this->sequence('Inline clear', [$this->stepFlow(['Hi'])->id, [$this->stepFlow(['Two'])->id, 1, 'days']]);
        $id = $this->join($seq, '7001');
        Http::fake(['botcake.io/*' => function () use ($id) {
            Cache::flush();
            $this->travel(11)->minutes(); // past the inline 10-minute window: only the row stops a second run
            $this->tick();
            $this->work(RunChatbotSequenceStep::class);
            return Http::response(['success' => true]);
        }]);
        $this->steps();

        $this->assertCount(1, Http::recorded(fn ($r) => str_contains($r->url(), 'send_content')), 'one send');
        $this->assertSame(['sent: Hi'], $this->replies('7001'));
        $this->assertSame(2, (int) $this->sub($seq, '7001')->current_step);
    }

    public function test_C_the_inline_send_is_marked_at_the_request_not_while_it_waits_for_the_page_slot(): void
    {
        config(['services.chatbot.sequence_handoff' => false]);
        $seq = $this->sequence('Slot', [$this->stepFlow(['Hi'])->id]);
        $id = $this->join($seq, '7001');
        \App\Services\BotcakeClient::slot(sha1('tok-1001'), $this->clock->now(), true); // the page's next slot is taken
        $state = fn () => ChatbotSequences::parseAttempt(DB::table('chatbot_sequence_subscriptions')->where('id', $id)->value('attempt'), 1, '2026-11-02 10:00:00')['sending'] ?? null;
        $seen = [];
        $this->clock->onSleep = function () use (&$seen, $state) {
            $seen['waiting'] = $state(); // a kill here sends nothing
            return false;
        };
        Http::fake(['botcake.io/*' => function () use (&$seen, $state) {
            $seen['calling'] = $state();
            return Http::response(['success' => true]);
        }]);
        $this->steps();
        $this->clock->onSleep = null;
        $this->assertSame(['waiting' => false, 'calling' => true], $seen);
    }

    public function test_E_the_dead_pool_cancel_leaves_tickets_held_by_a_pool_that_just_started(): void
    {
        $seq = $this->sequence('Start', [$this->stepFlow(['Hi'])->id]);
        $this->join($seq, '7001');
        $this->steps();
        $t = $this->tickets()->first();
        DB::table(BotcakeSendQueue::TABLE)->where('id', $t->id)->update(['status' => 'sending', 'claimed_by' => $this->pool->owner]);
        $cancel = (fn (object $t, string $why) => $this->cancel($t, $why))->call(app(ChatbotSequences::class), DB::table(BotcakeSendQueue::TABLE)->find($t->id), 'test');
        $this->assertSame('sending', DB::table(BotcakeSendQueue::TABLE)->where('id', $t->id)->value('status'), 'the live pool keeps it');
        $this->assertNull($cancel);
    }

    public function test_F_a_pool_that_beats_but_does_not_progress_for_5_minutes_is_treated_as_dead_and_logged(): void
    {
        Http::fake(['botcake.io/*' => Http::response(['success' => true])]);
        Log::spy();
        $seq = $this->sequence('Stuck', [$this->stepFlow(['Hi'])->id]);
        $this->join($seq, '7001');
        $this->steps();
        $this->travel(6)->minutes();
        $this->pool->beat(); // the beat is fresh, its progress is 6 minutes old, and a ticket waits
        $this->replyPool->beat();
        $this->assertTrue(SequenceSendPool::stalled());
        $this->assertFalse(SequenceSendPool::capable(), 'no new hand-off to it');
        $this->tick();
        $this->work(RunChatbotSequenceStep::class);
        $this->assertSame(['sent: Hi'], $this->replies('7001'), 'the waiting step went inline');
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'sequence send pool: no progress'))->atLeast()->once();
    }

    public function test_H_and_I_the_resume_job_has_the_step_timeout_and_the_running_marker_names_the_site(): void
    {
        $this->assertSame(280, (new ResumeChatbotSequenceSend(['request_id' => 'x']))->timeout);
        $a = ChatbotSequences::running(5);
        config(['app.url' => 'https://incepxion.example.test']);
        $this->assertNotSame($a, ChatbotSequences::running(5));
    }

    public function test_K_a_page_id_changed_for_24_hours_stops_the_step_instead_of_a_ticket_every_hour(): void
    {
        $seq = $this->sequence('Gone page', [$this->stepFlow(['Hi'])->id]);
        $this->join($seq, '7001');
        $hold = fn (string $at) => DB::table('chatbot_events')->insert(['page_id' => 1001, 'psid' => 7001, 'type' => 'sequence', 'created_at' => $at,
            'detail' => json_encode(['op' => 'held', 'reason' => 'page ID changed', 'name' => 'Gone page', 'step' => 1, 'run' => 'r'])]);
        // Held for the page ID since 23 hours ago: held once more.
        $hold('2026-11-01 11:00:00');
        $this->steps();
        app(PageSettings::class)->set('Page Uno', 'botcake', 'page_id', '1009'); // changed after the hand-off
        PageSettings::forget('Page Uno');
        $this->settle(2);
        $this->assertSame('active', $this->sub($seq, '7001')->status);

        // Since 25 hours ago: recorded and stopped.
        DB::table('chatbot_events')->where('detail', 'like', '%page ID changed%')->update(['created_at' => '2026-11-01 09:00:00']);
        app(PageSettings::class)->set('Page Uno', 'botcake', 'page_id', '1001');
        PageSettings::forget('Page Uno');
        $this->travel(61)->minutes();
        $this->pool->beat();
        $this->replyPool->beat();
        $this->steps();
        app(PageSettings::class)->set('Page Uno', 'botcake', 'page_id', '1009');
        PageSettings::forget('Page Uno');
        $this->settle(2);
        $sub = $this->sub($seq, '7001');
        $this->assertSame(['error', 'page ID changed', null], [$sub->status, $sub->exit_reason, $sub->send_request_id]);
        $this->assertContains('Sequence Gone page step 1 failed: the page ID changed; stopped', $this->events('7001'));
        $this->assertSame([], $this->calls);
    }

    public function test_M_the_backstop_and_the_resume_sweep_read_through_their_indexes(): void
    {
        $sql = [];
        DB::listen(function ($q) use (&$sql) { $sql[] = strtolower($q->sql); });
        $this->tick();
        BotcakeSendQueue::sweepSequence();
        $backstop = collect($sql)->first(fn ($s) => str_contains($s, 'send_request_id" is not null') || str_contains($s, 'send_request_id` is not null'));
        $this->assertNotNull($backstop);
        $this->assertMatchesRegularExpression('/order by [`"]s[`"]\.[`"]send_request_id[`"] asc/', $backstop, 'ordered by the indexed column');
        $sweep = collect($sql)->filter(fn ($s) => str_contains($s, 'botcake_send_requests') && str_contains($s, 'updated_at') && str_contains($s, 'priority'))->values();
        $this->assertNotEmpty($sweep);
        foreach ($sweep as $s) $this->assertMatchesRegularExpression('/where [`"]status[`"] = \? and [`"]priority[`"] = \?/', $s, 'status = ? and priority = ? lead, as the (status, priority, id) index');
    }

    public function test_N_and_6_old_sequence_tickets_are_pruned_up_to_an_id_cutoff_at_most_once_a_minute(): void
    {
        $row = fn (string $status, string $kind, int $days) => ['request_id' => uniqid('', true), 'kind' => $kind, 'priority' => $kind === 'sequence' ? 3 : 0,
            'customer' => 'c', 'page_id' => 1001, 'page_name' => 'Page Uno', 'psid' => '1', 'payload' => '{}', 'status' => $status,
            'deadline_at' => now(), 'created_at' => now()->subDays($days), 'updated_at' => now()->subDays($days)];
        // In the order they were made (ids follow created_at).
        DB::table(BotcakeSendQueue::TABLE)->insert([$row('resumed', 'sequence', 3), $row('failed', 'sequence', 3), $row('done', 'sequence', 3),
            $row('pending', 'sequence', 3), $row('resumed', 'chatbot', 3), $row('resumed', 'sequence', 1)]);
        $sql = [];
        DB::listen(function ($q) use (&$sql) { $sql[] = strtolower($q->sql); });
        $this->assertSame(3, BotcakeSendQueue::sweepSequence()['pruned']);
        $this->assertSame(['pending', 'resumed', 'resumed'], $this->tickets()->pluck('status')->all(), 'the waiting one, the reply pool\'s own and the recent one are left');
        $deletes = collect($sql)->filter(fn ($s) => str_starts_with($s, 'select') && str_contains(str_replace(['"', '`'], '', $s), 'id <= ?') && str_contains($s, 'priority'));
        $this->assertCount(3, $deletes, 'each status read up to the id cutoff, through (status, priority, id)');

        DB::table(BotcakeSendQueue::TABLE)->where('status', 'pending')->update(['status' => 'done']); // the old waiting one is done now
        $this->assertSame(0, BotcakeSendQueue::sweepSequence()['pruned'], 'not again within the minute');
        $this->travel(61)->seconds();
        $this->assertSame(1, BotcakeSendQueue::sweepSequence()['pruned']);
    }

    public function test_R4_1_without_the_column_a_killed_inline_call_is_not_sent_twice(): void
    {
        Schema::table('chatbot_sequence_subscriptions', function ($t) {
            $t->dropIndex(['send_request_id']);
            $t->dropColumn(['send_request_id', 'attempt']);
        });
        ChatbotSequences::forgetSchema();
        $seq = $this->sequence('No column', [$this->stepFlow(['Hi'])->id, [$this->stepFlow(['Two'])->id, 1, 'days']]);
        $id = $this->join($seq, '7001');
        $during = null;
        Http::fake(['botcake.io/*' => function () use ($id, &$during) {
            $during = ChatbotSequences::parseAttempt(ChatbotSequences::readAttempt($id, null), 1, '2026-11-02 10:00:00');
            return Http::response(['success' => true]);
        }]);
        $this->steps();
        $this->assertTrue($during['sending'] ?? false, 'the inline call is marked in the cache when the column is missing');
        $this->assertNull(ChatbotSequences::readAttempt($id, null), 'cleared when the run ends');

        $id2 = $this->join($seq, '7002');
        Cache::put('chatbot-seq-attempt:' . ChatbotSequences::site() . ':' . $id2, ChatbotSequences::attemptValue(1, '2026-11-02 10:00:00', 1, true, now()->subMinutes(10)->getTimestamp()), 3600);
        $this->steps();
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains($r->url(), 'send_content')), 'the step whose job died during its call is not sent again');
        $this->assertSame(2, (int) DB::table('chatbot_sequence_subscriptions')->where('id', $id2)->value('current_step'));
    }

    public function test_R4_2_a_page_hold_during_a_running_step_leaves_its_attempt(): void
    {
        $seen = [];
        $seq = $this->aiStep('Hold', function () use (&$seen) {
            app(\App\Services\Chatbot\PageTransfers::class)->hold('Page Uno', 1001, true, 'T9');
            $seen[] = DB::table('chatbot_sequence_subscriptions')->value('attempt');
        });
        $this->join($seq, '7001');
        $this->steps();
        $this->assertNotNull($seen[0] ?? null, 'the transfer writer does not clear the running step\'s attempt');
        $this->assertNull(DB::table('chatbot_sequence_subscriptions')->value('attempt'), 'the step\'s own end clears it');
    }

    public function test_R4_3_an_idle_pool_reads_for_waiting_tickets_at_most_once_a_minute(): void
    {
        $n = 0;
        DB::listen(function ($q) use (&$n) { if (str_starts_with(strtolower($q->sql), 'select exists') && str_contains($q->sql, 'next_at')) $n++; });
        for ($i = 0; $i < 20; $i++) $this->round();
        $this->assertSame(0, $n, 'fresh progress: no read');
        $this->travel(61)->seconds();
        for ($i = 0; $i < 20; $i++) $this->round();
        $this->assertSame(1, $n, 'one read after a minute, then fresh again');
    }

    public function test_R4_4_and_5_the_stall_is_logged_once_and_its_keys_and_the_beat_name_the_site(): void
    {
        Http::fake(['botcake.io/*' => Http::response(['success' => true])]);
        Log::spy();
        $seq = $this->sequence('Stuck twice', [$this->stepFlow(['Hi'])->id]);
        $this->join($seq, '7001');
        $this->join($seq, '7002');
        $this->steps();
        $this->travel(6)->minutes();
        $this->pool->beat();
        $this->replyPool->beat();
        $this->tick();
        $this->tick();
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'sequence send pool: no progress'))->once();

        [$beat, $stalled] = [SequenceSendPool::beatKey(), SequenceSendPool::stalledKey()];
        $this->assertStringContainsString(ChatbotSequences::site(), $beat);
        config(['app.url' => 'https://incepxion.example.test']);
        $this->assertNotSame([$beat, $stalled], [SequenceSendPool::beatKey(), SequenceSendPool::stalledKey()]);
        $this->assertFalse(SequenceSendPool::alive(), 'the other site does not see this site\'s pool');
    }



    /** @var array<int, string> */
    private array $log = [];

    public function test_O_the_pool_runs_a_stated_number_of_queries_per_send(): void
    {
        $seq = $this->sequence('Count', [$this->stepFlow(['Hi'])->id]);
        // One send first: the per-process schema checks (reply columns, tag table) are made once in a running pool.
        $this->join($seq, '7000');
        $this->steps();
        $this->settle(2);
        $this->join($seq, '7001');
        $this->steps();
        $this->clock->time += 1;
        $n = 0;
        DB::listen(function ($q) use (&$n) { $n++; $this->log[] = substr($q->sql, 0, 140); });
        $this->round(); // claim, checks, call
        $this->answerAll(); // answer, row, resume dispatch
        $pool = $n;
        if (getenv('DBG')) fwrite(STDERR, "\n" . implode("\n", $this->log) . "\n");
        $n = 0;
        $this->resumes(); // the step continues and advances
        $resume = $n;
        fwrite(STDERR, "\n[queries per send] pool {$pool}, resume {$resume}\n");
        $this->assertSame(['sent: Hi'], $this->replies('7001'));
        $this->assertLessThanOrEqual(12, $pool, 'the pool: claim round, checks, call, result');
        $this->assertLessThanOrEqual(12, $resume, 'the resume job (row, events, advance)');
    }
}
