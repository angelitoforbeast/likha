<?php

namespace Tests\Feature\Boardroom;

use App\Boardroom\Orchestrator\MeetingOrchestrator;
use App\Boardroom\Orchestrator\TurnRunner;
use App\Models\Boardroom\Message;
use App\Models\Boardroom\Round;
use App\Models\Boardroom\Turn;
use Illuminate\Support\Facades\Queue;

/**
 * MOCKED — mga tanong ng user ("question rounds"). Walang totoong model call.
 *
 * Patakaran: ang limit ng meeting ay para lang sa KUSANG takbo nito. Kapag ang user ang nagtanong,
 * makakasagot ang mga role kahit ubos na ang limit, at may sariling hangganan ang bawat tanong.
 */
class QuestionRoundTest extends BoardroomTestCase
{
    private function ask($meeting, $user, string $body, int $cycles = 0): array
    {
        return app(MeetingOrchestrator::class)->postUserMessage($meeting, $user->id, $body, $cycles);
    }

    /** @return array<int, string> mga task ng mga call mula sa index na $from */
    private function tasksSince(int $from): array
    {
        return array_column(array_slice($this->calls, $from), 'task');
    }

    /** Ang eksaktong sitwasyon sa screenshot: ubos na ang model calls ng meeting, pero nagtanong ang user. */
    public function test_questions_are_answered_after_the_meeting_call_limit_is_used_up(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $cto = $this->agent('CTO')->id;
        $this->fakeProvider(null, ['route' => fn () => json_encode([
            'action' => 'ask_agent', 'recipient_agent_id' => $cto, 'reply_to_message_id' => null, 'issue_id' => null,
            'public_message' => 'Gusto ko pang magtanong.', 'discussion_status' => 'continuing',
        ])]);

        $m = $this->start($this->meeting($user, ['max_calls' => 6]));
        $this->assertSame('completed', $m->status);
        $this->assertSame(6, (int) $m->calls_used, 'Ubos na ang limit ng meeting.');
        $before = count($this->calls);

        $result = $this->ask($m, $user, '@CTO paano natin ito sisimulan?');

        $this->assertSame([], $result['notes']);
        $this->assertSame(['direct'], $this->tasksSince($before));
        $this->assertSame($this->keys['CTO'], end($this->calls)['key']);
        $this->assertStringContainsString('@CTO paano natin ito sisimulan?', end($this->calls)['body']['input']);

        $m->refresh();
        $this->assertSame(6, (int) $m->calls_used, 'Hindi nadagdagan ang bilang ng meeting.');
        $this->assertSame(1, (int) $m->question_calls);
        $this->assertSame('completed', $m->status);
        $this->assertSame(0, Message::where('meeting_id', $m->id)->where('body', 'like', '%naabot na ang limit%')->where('id', '>', $result['message']->id)->count());

        $answer = Message::where('meeting_id', $m->id)->orderByDesc('id')->first();
        $this->assertSame($cto, (int) $answer->agent_id);
        $this->assertSame($result['message']->id, (int) $answer->reply_to_message_id);

        // Hiwalay din ang usage: hindi kinakain ng tanong ang token/gastos na binibilang para sa limit ng meeting
        $this->assertSame(6 * 200, (int) $m->tokens_out);
        $this->assertSame(200, (int) $m->question_tokens_out);
        $this->assertGreaterThan(0, (float) $m->question_cost_usd);
    }

    /** "Sagot lang": tig-isang sagot ang bawat na-mention — walang review, walang buod. */
    public function test_answer_only_mode_gives_one_answer_per_mentioned_role(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $this->fakeProvider();
        $m      = $this->start($this->meeting($user));
        $before = count($this->calls);
        $used   = (int) $m->calls_used;

        $this->ask($m, $user, '@REVIEWER @COO @CTO @CEO ano ang susunod na hakbang?');

        $this->assertSame(['direct', 'direct', 'direct', 'direct'], $this->tasksSince($before));
        $handles = array_column(array_slice($this->calls, $before), 'handle');
        sort($handles);
        $this->assertSame(['CEO', 'COO', 'CTO', 'REVIEWER'], $handles, 'Bawat role ay sumagot gamit ang sarili nitong key.');

        $round = Round::where('meeting_id', $m->id)->firstOrFail();
        $this->assertSame('answer', $round->mode);
        $this->assertSame('completed', $round->status);
        $this->assertSame(4, (int) $round->calls_used);
        $this->assertSame($used, (int) $m->fresh()->calls_used);
        $this->assertSame(4, (int) $m->fresh()->question_calls);
    }

    /** Walang @mention at "Sagot lang" = instruction lang, walang model call. */
    public function test_a_message_without_mention_costs_nothing(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $this->fakeProvider();
        $m      = $this->start($this->meeting($user));
        $before = count($this->calls);

        $this->ask($m, $user, 'Tandaan: walang bagong hardware ngayong buwan.');

        $this->assertCount($before, $this->calls);
        $this->assertSame(0, Round::count());
    }

    /** "Pag-usapan": hindi lalampas sa 3 cycle kahit laging "revise" ang reviewer. */
    public function test_discussion_mode_is_capped_at_three_cycles(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $this->fakeProvider(null, ['qreview' => fn () => json_encode(['public_message' => 'Kulang pa.', 'verdict' => 'revise'])]);
        $m      = $this->start($this->meeting($user));
        $before = count($this->calls);
        $used   = (int) $m->calls_used;

        $this->ask($m, $user, '@CTO @COO pag-usapan natin ang budget ng pilot.', 3);

        $this->assertSame(
            ['direct', 'direct', 'qreview', 'qrevise', 'qrevise', 'qreview', 'qrevise', 'qrevise', 'qreview', 'qsummary'],
            $this->tasksSince($before)
        );
        $round = Round::where('meeting_id', $m->id)->firstOrFail();
        $this->assertSame(3, (int) $round->cycle);
        $this->assertSame(3, (int) $round->max_cycles);
        $this->assertSame('completed', $round->status);
        $this->assertSame(10, (int) $round->calls_used);

        $calls = array_slice($this->calls, $before);
        $this->assertSame($this->keys['REVIEWER'], $calls[2]['key']);
        $this->assertSame($this->keys['CEO'], end($calls)['key'], 'Ang moderator ang gumagawa ng buod.');
        $this->assertSame('summary', Message::where('meeting_id', $m->id)->orderByDesc('id')->value('kind'));

        $this->assertSame($used, (int) $m->fresh()->calls_used);
        $this->assertSame(10, (int) $m->fresh()->question_calls);
    }

    /** Hindi tinatanggap ang higit sa 3 cycle, kahit sa API. */
    public function test_more_than_three_cycles_is_rejected(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $this->fakeProvider();
        $m = $this->start($this->meeting($user));

        $this->actingAs($user)->postJson("/boardroom/api/meetings/{$m->id}/messages", ['body' => '@CTO hello', 'cycles' => 4])->assertStatus(422);
        $this->actingAs($user)->postJson("/boardroom/api/meetings/{$m->id}/messages", ['body' => '@CTO hello', 'cycles' => 2])
            ->assertOk()->assertJsonPath('meeting.question_calls', 3);   // sagot + review + buod

        $this->assertSame(2, (int) Round::first()->max_cycles);
    }

    /** Tumitigil nang mas maaga kapag ayos na sa reviewer. */
    public function test_discussion_stops_early_when_the_reviewer_is_satisfied(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $this->fakeProvider();
        $m      = $this->start($this->meeting($user));
        $before = count($this->calls);

        $this->ask($m, $user, '@CTO @COO sapat na ba ang isang station para sa pilot?', 3);

        $this->assertSame(['direct', 'direct', 'qreview', 'qsummary'], $this->tasksSince($before));
        $this->assertSame(1, (int) Round::first()->cycle);
    }

    /** "Pag-usapan" na walang @mention: lahat ng contributor ang sasagot. */
    public function test_discussion_without_mention_asks_all_contributors(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $this->fakeProvider();
        $m      = $this->start($this->meeting($user));
        $before = count($this->calls);

        $this->ask($m, $user, 'Pag-usapan natin kung kailan sisimulan ang pilot.', 1);

        $calls = array_slice($this->calls, $before);
        $this->assertSame(['direct', 'direct', 'qreview', 'qsummary'], array_column($calls, 'task'));
        $this->assertEqualsCanonicalizing(['CTO', 'COO'], [$calls[0]['handle'], $calls[1]['handle']]);
        $this->assertStringContainsString('Pag-usapan natin kung kailan sisimulan ang pilot.', $calls[0]['body']['input']);
    }

    /** Ang nabigong sagot sa tanong ay hindi nagpapahinto sa meeting. */
    public function test_a_failed_answer_does_not_change_the_meeting_status(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $this->fakeProvider(fn ($request, $n, $task, $handle) => ($task === 'direct' && $handle === 'COO')
            ? $this->openaiError(401, 'Incorrect API key provided', 'invalid_request_error', 'invalid_api_key') : null);
        $m = $this->start($this->meeting($user));

        $this->ask($m, $user, '@COO @CTO kumusta ang plano?');

        $m->refresh();
        $this->assertSame('completed', $m->status);
        $this->assertNull($m->last_error);
        $this->assertSame('failed', Round::first()->status);
        $this->assertSame(1, Message::where('meeting_id', $m->id)->where('author_type', 'system')->where('body', 'like', 'Hindi nakasagot si COO%')->count());
        // Nakasagot pa rin ang isa
        $this->assertSame(1, Message::where('meeting_id', $m->id)->where('agent_id', $this->agent('CTO')->id)->where('body', 'like', 'DIRECT-BY-CTO%')->count());

        // Ang nabigong turn ng tanong ay hindi ipinapakita bilang nakabinbing bubble (may paalala na sa chat)
        $state = $this->actingAs($user)->getJson("/boardroom/api/meetings/{$m->id}")->assertOk();
        $this->assertSame([], $state->json('pending'));
    }

    /** Hindi pinipigilan ng token limit o spending limit ng meeting ang mga tanong ng user. */
    public function test_questions_ignore_the_meeting_token_and_spending_limits(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $this->fakeProvider();

        // Spending limit na mas mababa sa worst-case ng kahit isang call → hindi makakapagsimula ang meeting
        $m = $this->start($this->meeting($user, ['spend_limit_usd' => 0.5]));
        $this->assertSame('stopped', $m->status);
        $this->assertSame('limit:spend_limit', $m->stop_reason);
        $this->assertCount(0, $this->calls);

        $this->ask($m, $user, '@CTO may tanong pa rin ako.');

        $this->assertSame(['direct'], $this->tasksSince(0));
        $this->assertSame(1, (int) $m->fresh()->question_calls);
        $this->assertSame(0, (int) $m->fresh()->calls_used);
        $this->assertSame(0.0, (float) $m->fresh()->est_cost_usd, 'Ang gastos ng tanong ay hindi ibinibilang sa gastos na may limit.');
    }

    /** Naka-pause: maghihintay hanggang Resume. Naka-stop: makakasagot pa rin sa BAGONG tanong. */
    public function test_questions_wait_while_paused_and_still_work_after_stop(): void
    {
        Queue::fake();
        $user = $this->user();
        $this->giveKeys();
        $this->fakeProvider();
        $orch = app(MeetingOrchestrator::class);

        $m     = $this->start($this->meeting($user));
        $brief = Turn::where('meeting_id', $m->id)->where('purpose', 'brief')->firstOrFail();
        app(TurnRunner::class)->run($brief->id);
        $this->assertCount(1, $this->calls);

        // PAUSE → tanong → walang model call hangga't hindi nagre-resume
        $orch->pause($m->fresh());
        $result = $this->ask($m->fresh(), $user, '@CTO may tanong ako habang naka-pause.');
        $this->assertStringContainsString('Naka-pause', $result['notes'][0]);
        $question = Turn::whereNotNull('round_id')->firstOrFail();
        app(TurnRunner::class)->run($question->id);
        $this->assertCount(1, $this->calls);
        $this->assertSame('paused', $question->fresh()->status);

        $orch->resume($m->fresh());
        app(TurnRunner::class)->run($question->id);
        $this->assertCount(2, $this->calls);
        $this->assertSame('completed', $question->fresh()->status);

        // Ang nakabinbing tanong ay hindi humaharang sa kusang takbo ng meeting
        $this->assertSame(2, Turn::where('meeting_id', $m->id)->where('purpose', 'proposal')->count());

        // STOP → ang mga naka-queue ay hindi na tatakbo, pero ang BAGONG tanong ay sasagutin
        $orch->stop($m->fresh());
        $this->ask($m->fresh(), $user, '@COO tanong pagkatapos ng stop.');
        $after = Turn::whereNotNull('round_id')->orderByDesc('id')->firstOrFail();
        app(TurnRunner::class)->run($after->id);

        $this->assertCount(3, $this->calls);
        $this->assertSame($this->keys['COO'], end($this->calls)['key']);
        $this->assertSame('stopped', $m->fresh()->status);
        $this->assertSame(['stopped', 'stopped'], Turn::where('purpose', 'proposal')->pluck('status')->all());
    }

    /** Hindi valid na JSON ng review: isang repair lang, hindi rin ibinabawas sa meeting. */
    public function test_an_invalid_question_review_is_repaired_once(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $this->fakeProvider(null, [
            'qreview' => 'hindi ito JSON',
            'repair'  => json_encode(['public_message' => 'Ayos na.', 'verdict' => 'ok']),
        ]);
        $m      = $this->start($this->meeting($user));
        $before = count($this->calls);
        $used   = (int) $m->calls_used;

        $this->ask($m, $user, '@CTO tama ba ang plano?', 2);

        $this->assertSame(['direct', 'qreview', 'repair', 'qsummary'], $this->tasksSince($before));
        $this->assertSame('completed', Round::first()->status);
        $this->assertSame($used, (int) $m->fresh()->calls_used);
        $this->assertSame(4, (int) $m->fresh()->question_calls);
    }

    /** Bago magsimula ang meeting: walang round; isasama ang message sa brief. */
    public function test_questions_before_start_become_part_of_the_brief(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $this->fakeProvider();
        $m = $this->meeting($user);

        $result = $this->ask($m, $user, '@CTO unahin ang duplicate scan.', 2);

        $this->assertCount(0, $this->calls);
        $this->assertSame(0, Round::count());
        $this->assertStringContainsString('isasama', $result['notes'][0]);

        $this->start($m);
        $this->assertStringContainsString('unahin ang duplicate scan', $this->callsFor('brief')[0]['body']['input']);
    }
}
