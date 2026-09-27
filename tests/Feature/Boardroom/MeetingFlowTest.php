<?php

namespace Tests\Feature\Boardroom;

use App\Boardroom\Orchestrator\MeetingOrchestrator;
use App\Boardroom\Orchestrator\TurnRunner;
use App\Jobs\Boardroom\RunTurnJob;
use App\Models\Boardroom\Decision;
use App\Models\Boardroom\Issue;
use App\Models\Boardroom\Message;
use App\Models\Boardroom\Turn;
use Illuminate\Support\Facades\Queue;

/**
 * MOCKED — daloy ng meeting gamit ang pekeng provider (Http::fake). Walang totoong model call.
 */
class MeetingFlowTest extends BoardroomTestCase
{
    /** Test 10: natatapos ang buong meeting (sample: warehouse packing monitoring). */
    public function test_full_meeting_completes_with_mocked_providers(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $this->fakeProvider();

        $m = $this->start($this->meeting($user));

        $this->assertSame('completed', $m->status, (string) $m->last_error);
        $this->assertSame('done', $m->phase);
        $this->assertSame(2, (int) $m->cycle);

        // brief 1 + proposals 2 + review 1 + route 1 + answer 1 + route 1 + revisions 2 + review 1 + final 1
        $this->assertCount(11, $this->calls);
        $this->assertSame(11, (int) $m->calls_used);
        $this->assertSame(0, (int) $m->reserved_calls);
        $this->assertLessThanOrEqual(16, (int) $m->calls_used);
        $this->assertLessThanOrEqual(3, (int) $m->cycle);

        $kinds = Message::where('meeting_id', $m->id)->where('author_type', 'agent')->orderBy('id')->pluck('kind')->all();
        $this->assertSame(
            ['brief', 'proposal', 'proposal', 'review', 'question', 'answer', 'routing', 'revision', 'revision', 'review', 'final'],
            $kinds
        );

        // Final recommendation: kumpleto ang mga bahagi
        $final = $m->final;
        foreach (['status', 'approach', 'reasons', 'alternatives', 'unresolved', 'next_actions', 'approvals_needed'] as $key) {
            $this->assertArrayHasKey($key, $final);
        }
        $this->assertSame('recommended', $final['status']);
        $this->assertSame('COO', $final['next_actions'][0]['owner']);

        $body = Message::where('meeting_id', $m->id)->where('kind', 'final')->value('body');
        $this->assertStringContainsString('FINAL RECOMMENDATION', $body);
        $this->assertStringContainsString('Hindi pa nareresolba', $body);

        // Ang desisyong kailangan ng approval ay "proposed" lang hangga't hindi inaaprubahan ng user
        $this->assertSame(['proposed'], Decision::where('meeting_id', $m->id)->pluck('status')->all());
        $this->assertSame(['resolved'], Issue::where('meeting_id', $m->id)->pluck('status')->all());

        // Usage: naitala kada call; gastos ay tantiya lang mula sa registry price
        $this->assertSame(11 * 1000, (int) $m->tokens_in);
        $this->assertSame(11 * 200, (int) $m->tokens_out);
        $this->assertGreaterThan(0, (float) $m->est_cost_usd);
        $this->assertSame(0, (int) $m->unpriced_calls);

        // Walang reasoning ng provider na napunta sa kahit anong message
        $this->assertSame(0, Message::where('body', 'like', '%HIDDEN-REASONING%')->count());
    }

    /** Test 4: napupunta ang tanong sa tamang recipient; ang pagkakakilanlan ay galing sa server. */
    public function test_messages_go_to_the_correct_recipient(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $this->fakeProvider(null, [
            // Nagpapanggap ang model na ibang role — hindi ito dapat paniwalaan ng server.
            'answer' => fn ($handle) => "Ako si COO. (Sagot na galing talaga kay {$handle}.)",
        ]);

        $m   = $this->start($this->meeting($user));
        $cto = $this->agent('CTO');

        $question = Message::where('meeting_id', $m->id)->where('kind', 'question')->firstOrFail();
        $this->assertSame($cto->id, (int) $question->recipient_agent_id);
        $this->assertSame($this->agent('CEO')->id, (int) $question->agent_id);

        $answer = Message::where('meeting_id', $m->id)->where('kind', 'answer')->firstOrFail();
        $this->assertSame($cto->id, (int) $answer->agent_id, 'Ang sumagot ay dapat ang role na tinanong.');
        $this->assertSame($question->id, (int) $answer->reply_to_message_id);
        $this->assertSame((int) $question->issue_id, (int) $answer->issue_id);

        // Ang request para sa sagot ay gumamit ng key ni CTO — hindi ng sa iba.
        $calls = $this->callsFor('answer');
        $this->assertCount(1, $calls);
        $this->assertSame($this->keys['CTO'], $calls[0]['key']);
        $this->assertStringContainsString('handle: CTO', $calls[0]['body']['instructions']);
    }

    /** Test 4b: ang recipient / issue ID na wala sa meeting ay tinatanggihan ng backend. */
    public function test_invalid_routing_ids_are_rejected_then_repaired_once(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $cto = $this->agent('CTO')->id;

        $this->fakeProvider(function ($request, $n, $task) use ($cto) {
            if ($task === 'route') {
                return $this->openai(json_encode([
                    'action' => 'ask_agent', 'recipient_agent_id' => 987654, 'reply_to_message_id' => null, 'issue_id' => 555555,
                    'public_message' => 'Tanong sa role na wala sa room.', 'discussion_status' => 'continuing',
                ]));
            }
            if ($task === 'repair') {
                return $this->openai(json_encode([
                    'action' => 'finalize', 'recipient_agent_id' => null, 'reply_to_message_id' => null, 'issue_id' => null,
                    'public_message' => 'Sapat na; didiretso na sa final.', 'discussion_status' => 'ready_for_final',
                ]));
            }

            return null;
        });

        $m = $this->start($this->meeting($user));

        $this->assertSame('completed', $m->status, (string) $m->last_error);

        $route = Turn::where('meeting_id', $m->id)->where('purpose', 'route')->firstOrFail();
        $this->assertSame('repaired', $route->finish);
        $this->assertSame('finalize', $route->outcome['action']);

        $repair = Turn::where('meeting_id', $m->id)->where('purpose', 'repair')->get();
        $this->assertCount(1, $repair, 'Isang repair call lang ang pinapayagan.');
        $this->assertStringContainsString('issue_id 555555 does not exist', $this->callsFor('repair')[0]['body']['input']);

        // Walang turn na ginawa para sa hindi miyembro, at binilang ang repair sa limit.
        $this->assertSame(0, Turn::where('meeting_id', $m->id)->where('agent_id', 987654)->count());
        $this->assertSame(count($this->calls), (int) $m->calls_used);
    }

    /** Test 5: iisang brief snapshot ang gamit ng mga independent proposal. */
    public function test_independent_proposals_use_the_same_brief_snapshot(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $this->fakeProvider();

        $m = $this->start($this->meeting($user));

        $proposals = Turn::where('meeting_id', $m->id)->where('purpose', 'proposal')->orderBy('id')->get();
        $this->assertCount(2, $proposals);
        $this->assertNotEmpty($proposals[0]->context_hash);
        $this->assertSame($proposals[0]->context_hash, $proposals[1]->context_hash);

        $calls = $this->callsFor('proposal');
        $this->assertCount(2, $calls);
        $this->assertSame($calls[0]['body']['input'], $calls[1]['body']['input'], 'Magkapareho dapat ang context ng dalawang proposal.');
        $this->assertStringContainsString($m->brief_snapshot['context'], $calls[0]['body']['input']);
        $this->assertStringContainsString('BRIEF-MARKER', $calls[0]['body']['input']);
        $this->assertSame(hash('sha256', $calls[1]['body']['input']), $proposals[1]->context_hash);

        // Hindi nakikita ng pangalawang contributor ang proposal ng una.
        $this->assertStringNotContainsString('PROPOSAL-BY-', $calls[1]['body']['input']);
        $this->assertNotSame($calls[0]['key'], $calls[1]['key']);

        // Pagkatapos ng proposals, nakikita na ng reviewer ang dalawa.
        $review = $this->callsFor('review')[0]['body']['input'];
        $this->assertStringContainsString('PROPOSAL-BY-CTO', $review);
        $this->assertStringContainsString('PROPOSAL-BY-COO', $review);
    }

    /** Test 6: hindi nagbabahagi ng context ang magkaibang room / project. */
    public function test_rooms_do_not_share_context(): void
    {
        $user = $this->user();
        $this->giveKeys();

        $room = 'A';
        $this->fakeProvider(null, ['brief' => function () use (&$room) {
            return $room === 'A' ? 'BRIEF-OF-ROOM-A: ALPHA-SECRET-123 detalye ng unang room.' : 'BRIEF-OF-ROOM-B: ibang usapan.';
        }]);

        $a = $this->start($this->meeting($user, ['objective' => 'ALPHA-SECRET-123 plano ng Room A'], $this->project($user, 'Project A')));
        $this->assertSame('completed', $a->status, (string) $a->last_error);
        Decision::where('meeting_id', $a->id)->update(['status' => 'approved']);   // aprubadong desisyon ng Project A

        $before = count($this->calls);
        $room   = 'B';
        $b = $this->start($this->meeting($user, ['objective' => 'BETA plano ng Room B', 'title' => 'Room B'], $this->project($user, 'Project B')));
        $this->assertSame('completed', $b->status, (string) $b->last_error);

        $roomB = array_slice($this->calls, $before);
        $this->assertNotEmpty($roomB);
        foreach ($roomB as $call) {
            $payload = json_encode($call['body'], JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('ALPHA-SECRET-123', $payload);
            $this->assertStringNotContainsString('BRIEF-OF-ROOM-A', $payload);
            $this->assertStringNotContainsString('Aprubahan ang pilot', $payload, 'Ang approved decision ng ibang project ay hindi dapat makita.');
        }

        // Bawat message ay nasa sarili nitong meeting lang.
        $this->assertSame(0, Message::where('meeting_id', $b->id)->where('body', 'like', '%ALPHA-SECRET-123%')->count());
        $this->assertSame([$a->id], Issue::where('meeting_id', $a->id)->distinct()->pluck('meeting_id')->all());
    }

    /** Aprubadong desisyon at knowledge ng PAREHONG project ay kasama sa context, may tamang label. */
    public function test_approved_knowledge_and_decisions_are_included_only_when_approved(): void
    {
        $user    = $this->user();
        $project = $this->project($user, 'Project K');
        $this->giveKeys();
        $this->fakeProvider();

        \App\Models\Boardroom\Knowledge::create(['user_id' => $user->id, 'title' => 'Aprubado', 'body' => 'KNOW-APPROVED', 'approved' => true, 'approved_at' => now()]);
        \App\Models\Boardroom\Knowledge::create(['user_id' => $user->id, 'title' => 'Draft', 'body' => 'KNOW-DRAFT', 'approved' => false]);

        $this->start($this->meeting($user, [], $project));

        $brief = $this->callsFor('brief')[0]['body']['input'];
        $this->assertStringContainsString('APPROVED COMPANY KNOWLEDGE', $brief);
        $this->assertStringContainsString('KNOW-APPROVED', $brief);
        $this->assertStringNotContainsString('KNOW-DRAFT', $brief);
    }

    /** Test 7: ang stop at pause ay pumipigil sa pag-schedule at sa model call. */
    public function test_stop_and_pause_prevent_model_calls(): void
    {
        Queue::fake();
        $user = $this->user();
        $this->giveKeys();
        $this->fakeProvider();
        $orch = app(MeetingOrchestrator::class);

        $m = $this->start($this->meeting($user));
        Queue::assertPushed(RunTurnJob::class, 1);
        $brief = Turn::where('meeting_id', $m->id)->where('purpose', 'brief')->firstOrFail();

        // PAUSE bago tumakbo ang job → walang model call, naka-park ang turn
        $orch->pause($m);
        app(TurnRunner::class)->run($brief->id);
        $this->assertCount(0, $this->calls);
        $this->assertSame('paused', $brief->fresh()->status);

        // RESUME → iyon ding turn ang itutuloy
        $orch->resume($m->fresh());
        $this->assertSame('queued', $brief->fresh()->status);
        app(TurnRunner::class)->run($brief->id);
        $this->assertCount(1, $this->calls);
        $this->assertSame('completed', $brief->fresh()->status);

        // Na-schedule ang 2 proposal (naka-queue, hindi pa tumatakbo)
        $proposals = Turn::where('meeting_id', $m->id)->where('purpose', 'proposal')->get();
        $this->assertCount(2, $proposals);
        $this->assertSame(2, (int) $m->fresh()->reserved_calls, 'Nakareserba ang budget bago ang parallel na calls.');

        // STOP → kahit tumakbo ang mga job, walang model call at walang bagong turn
        $orch->stop($m->fresh());
        foreach ($proposals as $p) {
            app(TurnRunner::class)->run($p->id);
        }
        $orch->advance($m->id);

        $this->assertCount(1, $this->calls, 'Walang bagong model call pagkatapos ng stop.');
        $this->assertSame(['stopped', 'stopped'], Turn::where('purpose', 'proposal')->pluck('status')->all());
        $this->assertSame(3, Turn::where('meeting_id', $m->id)->count(), 'Walang bagong turn na na-schedule.');
        $this->assertSame('stopped', $m->fresh()->status);
        $this->assertSame(0, (int) $m->fresh()->reserved_calls);
        $this->assertSame(1, (int) $m->fresh()->calls_used);
    }

    /** Test 7b: ang call limit ay ipinapatupad; may nakatabing call para sa final summary. */
    public function test_call_limit_is_enforced_and_final_is_reserved(): void
    {
        $user = $this->user();
        $this->giveKeys();
        // Laging "ask_agent" ang gusto ng moderator — pero wala nang budget para doon.
        $cto = $this->agent('CTO')->id;
        $this->fakeProvider(null, ['route' => fn () => json_encode([
            'action' => 'ask_agent', 'recipient_agent_id' => $cto, 'reply_to_message_id' => null, 'issue_id' => null,
            'public_message' => 'Gusto ko pang magtanong.', 'discussion_status' => 'continuing',
        ])]);

        $m = $this->start($this->meeting($user, ['max_calls' => 6]));

        $this->assertSame('completed', $m->status, (string) $m->last_error);
        $this->assertSame(6, (int) $m->calls_used);
        $this->assertCount(6, $this->calls);
        $this->assertLessThanOrEqual((int) $m->max_calls, (int) $m->calls_used);

        $this->assertSame(0, Turn::where('meeting_id', $m->id)->where('purpose', 'answer')->count(), 'Hindi na-schedule ang tanong na lampas sa limit.');
        $this->assertSame(1, Turn::where('meeting_id', $m->id)->where('purpose', 'final')->where('status', 'completed')->count());

        // Sinabihan ang moderator kung ano lang ang pwede, at may nakikitang paliwanag sa chat
        $this->assertSame(1, preg_match('/Allowed actions right now: (.*)/', $this->callsFor('route')[0]['body']['input'], $allowed));
        $this->assertStringNotContainsString('ask_agent', $allowed[1]);
        $this->assertStringNotContainsString('request_revision', $allowed[1]);
        $this->assertStringContainsString('finalize', $allowed[1]);
        $this->assertSame(1, Message::where('meeting_id', $m->id)->where('author_type', 'system')->where('body', 'like', '%hindi na ito pwede dahil sa limit%')->count());
        $this->assertStringContainsString('ended early', $this->callsFor('final')[0]['body']['input']);

        // Ipinapakita kung ano ang hindi pa resolved
        $this->assertSame(['unresolved'], Issue::where('meeting_id', $m->id)->pluck('status')->all());
    }

    /** Test 7c: hindi lalampas sa 3 review/revision cycle. */
    public function test_review_revision_cycles_are_capped(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $this->fakeProvider(null, [
            'review' => fn () => json_encode([
                'public_message' => 'May kulang pa rin.', 'verdict' => 'revise',
                'issues' => [['title' => 'Kulang pa', 'detail' => 'x', 'severity' => 'medium', 'target_agent_id' => null]],
                'resolved_issue_ids' => [],
            ]),
            'route' => fn () => json_encode([
                'action' => 'request_revision', 'recipient_agent_id' => $this->agent('CTO')->id, 'reply_to_message_id' => null, 'issue_id' => null,
                'public_message' => 'CTO, i-revise.', 'discussion_status' => 'ready_for_revision',
            ]),
        ]);

        $m = $this->start($this->meeting($user));

        $this->assertSame('completed', $m->status, (string) $m->last_error);
        $this->assertSame(3, Turn::where('meeting_id', $m->id)->where('purpose', 'review')->count());
        $this->assertSame(3, (int) $m->cycle);
        $this->assertLessThanOrEqual(16, (int) $m->calls_used);
        // Isang contributor lang ang pinili ng moderator → isang revision kada cycle
        $this->assertSame(3, Turn::where('meeting_id', $m->id)->where('purpose', 'revision')->count());
        $this->assertSame([$this->agent('CTO')->id], Turn::where('purpose', 'revision')->distinct()->pluck('agent_id')->all());
        $this->assertSame('cycle_limit', $m->stop_reason);
    }

    /** Test 8: ang dobleng job, retry, at resume ay hindi gumagawa ng dobleng turn o message. */
    public function test_retries_and_resume_do_not_duplicate_turns(): void
    {
        Queue::fake();
        $user = $this->user();
        $this->giveKeys();
        $orch = app(MeetingOrchestrator::class);

        $n = 0;
        $this->fakeProvider(function ($request, $count, $task) use (&$n) {
            if ($task !== 'brief') {
                return null;
            }
            $n++;
            if ($n === 1) {
                return $this->openaiError(429, 'Rate limit reached', 'rate_limit_error');      // retryable → uulitin sa loob ng turn
            }
            if ($n === 2) {
                return $this->openaiError(401, 'Incorrect API key provided', 'invalid_request_error', 'invalid_api_key');   // hindi retryable
            }

            return null;
        });

        $m = $this->start($this->meeting($user));
        $orch->start($m);   // "page refresh": hindi dapat mag-restart
        $orch->start($m->fresh());
        $this->assertSame(1, Turn::where('meeting_id', $m->id)->count());
        Queue::assertPushed(RunTurnJob::class, 1);

        $brief = Turn::where('meeting_id', $m->id)->firstOrFail();
        app(TurnRunner::class)->run($brief->id);

        $brief->refresh();
        $this->assertSame('failed', $brief->status);
        $this->assertSame('invalid_credentials', $brief->error_code);
        $this->assertFalse($brief->retryable);
        $this->assertSame(2, (int) $brief->requests, '429 → isang retry → 401 (hindi na inulit).');
        $this->assertSame('failed', $m->fresh()->status);
        $this->assertSame(0, Message::where('meeting_id', $m->id)->where('author_type', 'agent')->count());

        // Dobleng job habang failed: walang epekto
        app(TurnRunner::class)->run($brief->id);
        $this->assertCount(2, $this->calls);

        // RETRY ng user → PAREHONG turn row
        $orch->retry($m->fresh());
        $this->assertSame('queued', $brief->fresh()->status);
        app(TurnRunner::class)->run($brief->id);
        app(TurnRunner::class)->run($brief->id);   // dobleng job: pangalawa ay walang gagawin

        $this->assertSame(1, Turn::where('meeting_id', $m->id)->where('dedupe_key', 'brief')->count());
        $this->assertSame('completed', $brief->fresh()->status);
        $this->assertSame(2, (int) $brief->fresh()->attempts);
        $this->assertSame(1, Message::where('meeting_id', $m->id)->where('kind', 'brief')->count());
        $this->assertCount(3, $this->calls);
        $this->assertSame(3, (int) $m->fresh()->calls_used, 'Bawat request na naipadala ay binibilang.');

        // Ang advance() na tinawag nang paulit-ulit ay hindi nagdodoble ng proposals
        $orch->advance($m->id);
        $orch->advance($m->id);
        $this->assertSame(2, Turn::where('meeting_id', $m->id)->where('purpose', 'proposal')->count());
    }

    /** Naubos ang retries sa retryable na error → failed pero pwedeng i-retry; walang fallback na model. */
    public function test_retryable_failures_are_bounded_and_never_fall_back(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $this->fakeProvider(fn ($request, $n, $task) => $task === 'brief' ? $this->openaiError(503, 'Service unavailable', 'server_error') : null);

        $m = $this->start($this->meeting($user));

        $brief = Turn::where('meeting_id', $m->id)->firstOrFail();
        $this->assertSame('failed', $m->status);
        $this->assertSame('provider_error', $brief->error_code);
        $this->assertTrue($brief->retryable);
        $this->assertCount(3, $this->calls, '1 subok + 2 retry, tapos hinto.');
        foreach ($this->calls as $call) {
            $this->assertSame('gpt-6-astra', $call['body']['model'], 'Walang tahimik na paglipat sa ibang model.');
            $this->assertSame($this->keys['CEO'], $call['key'], 'Walang tahimik na paglipat sa ibang key.');
        }
    }

    /** Putol na sagot: tinatanggap ang may laman (may marka), bigo ang walang laman. */
    public function test_truncated_and_refused_responses_are_handled(): void
    {
        $user = $this->user();
        $this->giveKeys();

        $this->fakeProvider(function ($request, $n, $task, $handle) {
            if ($task === 'proposal' && $handle === 'CTO') {
                return $this->openai('Putol na proposal ni CTO', ['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens']]);
            }
            if ($task === 'proposal' && $handle === 'COO') {
                return $this->openai('', ['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'], 'output' => []]);
            }

            return null;
        });

        $m = $this->start($this->meeting($user));

        $this->assertSame('failed', $m->status);
        $cto = Message::where('meeting_id', $m->id)->where('agent_id', $this->agent('CTO')->id)->where('kind', 'proposal')->firstOrFail();
        $this->assertTrue((bool) $cto->meta['truncated']);

        $coo = Turn::where('meeting_id', $m->id)->where('agent_id', $this->agent('COO')->id)->firstOrFail();
        $this->assertSame('truncated_empty', $coo->error_code);
        $this->assertStringContainsString('Max output tokens', $coo->error_message);
    }

    /** @HANDLE mula sa user = direktang tanong sa role na iyon, gamit ang sarili nitong key. */
    public function test_user_can_address_a_role_directly(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $this->fakeProvider();

        $m      = $this->start($this->meeting($user));
        $before = count($this->calls);

        $result = app(MeetingOrchestrator::class)->postUserMessage($m, $user->id, '@COO ilang tao ang kailangan sa pilot?');

        $this->assertSame($this->agent('COO')->id, (int) $result['message']->recipient_agent_id);
        $this->assertCount($before + 1, $this->calls);
        $call = end($this->calls);
        $this->assertSame('direct', $call['task']);
        $this->assertSame($this->keys['COO'], $call['key']);

        $reply = Message::where('meeting_id', $m->id)->orderByDesc('id')->firstOrFail();
        $this->assertSame($this->agent('COO')->id, (int) $reply->agent_id);
        $this->assertSame($result['message']->id, (int) $reply->reply_to_message_id);
        $this->assertSame($before + 1, (int) $m->fresh()->calls_used);
    }

    /** needs_user_input: humihinto ang meeting hanggang sumagot ang user. */
    public function test_meeting_waits_for_user_input_then_continues(): void
    {
        $user = $this->user();
        $this->giveKeys();

        $asked = false;
        $this->fakeProvider(null, ['route' => function () use (&$asked) {
            if (! $asked) {
                $asked = true;

                return json_encode([
                    'action' => 'needs_user_input', 'recipient_agent_id' => null, 'reply_to_message_id' => null, 'issue_id' => null,
                    'public_message' => 'Ilang packing station ang mayroon kayo?', 'discussion_status' => 'needs_user_input',
                ]);
            }

            return json_encode([
                'action' => 'finalize', 'recipient_agent_id' => null, 'reply_to_message_id' => null, 'issue_id' => null,
                'public_message' => 'Salamat. Didiretso na sa final.', 'discussion_status' => 'ready_for_final',
            ]);
        }]);

        $m = $this->start($this->meeting($user));
        $this->assertSame('needs_input', $m->status);
        $paused = count($this->calls);

        app(MeetingOrchestrator::class)->advance($m->id);
        $this->assertCount($paused, $this->calls, 'Walang model call habang naghihintay sa user.');

        app(MeetingOrchestrator::class)->postUserMessage($m, $user->id, 'Apat na station.');
        $m->refresh();

        $this->assertSame('completed', $m->status, (string) $m->last_error);
        $this->assertStringContainsString('Apat na station.', $this->callsFor('final')[0]['body']['input']);
    }
}
