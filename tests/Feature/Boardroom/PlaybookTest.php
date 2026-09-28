<?php

namespace Tests\Feature\Boardroom;

use App\Boardroom\Orchestrator\MeetingOrchestrator;
use App\Boardroom\Orchestrator\OutputInterpreter;
use App\Boardroom\Orchestrator\Playbook;
use App\Models\Boardroom\Lesson;
use App\Models\Boardroom\Message;
use App\Models\Boardroom\Turn;

/**
 * MOCKED — playbook kada role: kusang pagkatuto mula sa chat. Walang totoong model call.
 *
 * Hindi nito sinusukat kung gaano kahusay PUMANSIN ng aral ang totoong model — scripted ang sagot dito.
 * Ang sinusubok ay ang ginagawa ng backend sa sagot: pag-save, saklaw, paggamit sa susunod na call, at undo.
 */
class PlaybookTest extends BoardroomTestCase
{
    private function ask($meeting, $user, string $body, int $cycles = 0): array
    {
        return app(MeetingOrchestrator::class)->postUserMessage($meeting, $user->id, $body, $cycles);
    }

    private function teaching(string $answer, ?array $lesson): string
    {
        return json_encode(['answer' => $answer, 'lesson' => $lesson], JSON_UNESCAPED_UNICODE);
    }

    /** Ang halimbawa ng user: "@CEO, next time na may ganitong problem, ganito dapat ang assessment". */
    public function test_a_role_learns_from_chat_and_uses_it_in_later_calls(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $this->fakeProvider(null, ['direct' => fn ($handle) => $handle === 'CEO'
            ? $this->teaching('Naintindihan. Sa susunod, uunahin ko ang epekto sa customer.', [
                'applies_when' => 'Kapag may duplicate scan sa packing',
                'rule' => 'Unahin sa assessment ang epekto sa customer bago ang gastos.',
                'replaces_lesson_id' => null,
            ])
            : $this->teaching('Sagot ni ' . $handle, null)]);

        $m      = $this->start($this->meeting($user));
        $before = count($this->calls);
        $used   = (int) $m->calls_used;

        $asked = $this->ask($m, $user, '@CEO next time na may ganitong problem, unahin ang epekto sa customer sa assessment.');

        // Isang call lang: ang sagot at ang aral ay galing sa iisang call — walang dagdag na call para matuto.
        $this->assertCount($before + 1, $this->calls);
        $this->assertSame('json_schema', end($this->calls)['body']['text']['format']['type']);

        $lesson = Lesson::firstOrFail();
        $this->assertSame($this->agent('CEO')->id, (int) $lesson->agent_id);
        $this->assertSame($user->id, (int) $lesson->user_id);
        $this->assertSame('active', $lesson->status);
        $this->assertSame('chat', $lesson->source);
        $this->assertNull($lesson->project_id, 'Default: sa lahat ng project.');
        $this->assertSame($m->id, (int) $lesson->meeting_id);
        $this->assertSame($asked['message']->id, (int) $lesson->message_id);

        // Ang sagot sa chat ay ang "answer" lang — hindi ang hilaw na JSON
        $answer = Message::where('meeting_id', $m->id)->where('kind', 'answer')->orderByDesc('id')->first();
        $this->assertSame('Naintindihan. Sa susunod, uunahin ko ang epekto sa customer.', $answer->body);

        // Laging may nakikitang paalala, dahil walang approval step
        $notice = Message::where('meeting_id', $m->id)->where('kind', 'lesson')->firstOrFail();
        $this->assertStringContainsString('Natutunan ni CEO', $notice->body);
        $this->assertStringContainsString('Unahin sa assessment ang epekto sa customer', $notice->body);
        $this->assertSame($lesson->id, (int) $notice->meta['lesson_id']);

        // Hindi ibinawas sa limit ng meeting
        $this->assertSame($used, (int) $m->fresh()->calls_used);

        // SUSUNOD NA MEETING, ibang project: alam na ni CEO — pero hindi ng ibang role
        $this->calls = [];
        $next = $this->start($this->meeting($user, ['title' => 'Ibang topic'], $this->project($user, 'Ibang project')));
        $this->assertSame('completed', $next->status, (string) $next->last_error);

        foreach ($this->calls as $call) {
            $has = str_contains($call['body']['instructions'], 'Unahin sa assessment ang epekto sa customer');
            $this->assertSame($call['handle'] === 'CEO', $has, "Playbook sa call ni {$call['handle']}");
            $this->assertStringNotContainsString('Unahin sa assessment', $call['body']['input'], 'Ang playbook ay nasa system prompt, hindi sa shared context.');
        }
        $ceo = collect($this->calls)->firstWhere('handle', 'CEO')['body']['instructions'];
        $this->assertStringContainsString('=== YOUR PLAYBOOK ===', $ceo);
        $this->assertStringContainsString("[L{$lesson->id}] Kapag may duplicate scan sa packing: Unahin", $ceo);
    }

    /** Karaniwang tanong: walang aral na nase-save. */
    public function test_an_ordinary_question_teaches_nothing(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $this->fakeProvider(null, ['direct' => fn ($h) => $this->teaching("Tatlong tao ang kailangan, ayon kay {$h}.", null)]);
        $m = $this->start($this->meeting($user));

        $this->ask($m, $user, '@COO ilang tao ang kailangan sa pilot?');

        $this->assertSame(0, Lesson::count());
        $this->assertSame(0, Message::where('kind', 'lesson')->count());
        $this->assertSame('Tatlong tao ang kailangan, ayon kay COO.', Message::where('kind', 'answer')->orderByDesc('id')->value('body'));
    }

    /** Hindi JSON ang ibinalik: buong text ang sagot, walang aral, at WALANG repair call. */
    public function test_a_plain_text_answer_is_still_delivered_without_a_repair_call(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $this->fakeProvider(null, ['direct' => 'Plain na sagot. Gamitin ang {order_id} bilang susi.']);
        $m      = $this->start($this->meeting($user));
        $before = count($this->calls);

        $this->ask($m, $user, '@CTO sa susunod, ano ang gagamiting susi?');

        $this->assertCount($before + 1, $this->calls);
        $this->assertSame(0, Turn::where('purpose', 'repair')->count());
        $this->assertSame(0, Lesson::count());
        $this->assertSame('Plain na sagot. Gamitin ang {order_id} bilang susi.', Message::where('kind', 'answer')->orderByDesc('id')->value('body'));
    }

    /** Putol na JSON (naubos ang token): ipinapakita ang nababasang sagot, hindi ang hilaw na JSON. */
    public function test_truncated_json_shows_the_readable_answer(): void
    {
        $i = app(OutputInterpreter::class);

        $cut = $i->answer('{"answer": "Unang linya.\nPangalawang linya na may \"quote\" at naput');
        $this->assertFalse($cut['structured']);
        $this->assertNull($cut['lesson']);
        $this->assertSame("Unang linya.\nPangalawang linya na may \"quote\" at naput", $cut['answer']);

        $cutLater = $i->answer('{"answer": "Buong sagot.", "lesson": {"applies_when": "Kapag');
        $this->assertSame('Buong sagot.', $cutLater['answer']);
        $this->assertNull($cutLater['lesson'], 'Ang putol na aral ay hindi sine-save.');

        $fenced = $i->answer("```json\n{\"answer\": \"Ayos.\", \"lesson\": null}\n```");
        $this->assertTrue($fenced['structured']);
        $this->assertSame('Ayos.', $fenced['answer']);

        $empty = $i->answer('{"answer": "Sige.", "lesson": {"applies_when": "Kapag x", "rule": "  ", "replaces_lesson_id": null}}');
        $this->assertNull($empty['lesson'], 'Walang laman ang rule = walang aral.');
    }

    /** Pinapalitan ng bagong aral ang luma; sarili lang ng role at ng user ang pwedeng palitan. */
    public function test_a_new_lesson_can_replace_an_old_one_but_only_its_own(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $ceo = $this->agent('CEO');
        $cto = $this->agent('CTO');

        $old    = Lesson::create(['agent_id' => $ceo->id, 'user_id' => $user->id, 'rule' => 'Unahin ang gastos.', 'applies_when' => 'Kapag may problema']);
        $others = Lesson::create(['agent_id' => $cto->id, 'user_id' => $user->id, 'rule' => 'Aral ni CTO.']);

        $target = $old->id;
        $this->fakeProvider(null, ['direct' => function () use (&$target) {
            return $this->teaching('Sige, papalitan ko.', ['applies_when' => 'Kapag may problema', 'rule' => 'Unahin ang customer, hindi ang gastos.', 'replaces_lesson_id' => $target]);
        }]);
        $m = $this->start($this->meeting($user));

        $this->ask($m, $user, '@CEO mula ngayon, customer muna bago gastos.');

        $new = Lesson::where('agent_id', $ceo->id)->where('status', 'active')->firstOrFail();
        $this->assertSame('Unahin ang customer, hindi ang gastos.', $new->rule);
        $this->assertSame('replaced', $old->fresh()->status);
        $this->assertSame($new->id, (int) $old->fresh()->replaced_by_id);
        $this->assertStringContainsString('Pinalitan ang dating aral', Message::where('kind', 'lesson')->orderByDesc('id')->value('body'));

        $block = app(Playbook::class)->block(app(Playbook::class)->forContext($ceo->id, $user->id, $m->project_id));
        $this->assertStringContainsString('Unahin ang customer', $block);
        $this->assertStringNotContainsString('Unahin ang gastos.', $block);

        // Sinubukang palitan ang aral ng IBANG role → hindi ginalaw; bagong aral lang ang idinagdag
        \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory());   // bagong fake (nag-i-stack ang Http::fake)
        $this->fakeProvider(null, ['direct' => fn () => $this->teaching('Ok.', ['applies_when' => 'Kapag x', 'rule' => 'Ibang bagong aral.', 'replaces_lesson_id' => $others->id])]);
        $this->ask($m, $user, '@CEO sa susunod, ibang bagay naman.');

        $this->assertSame('active', $others->fresh()->status);
        $this->assertSame('Aral ni CTO.', $others->fresh()->rule);
        $this->assertSame(2, Lesson::where('agent_id', $ceo->id)->where('status', 'active')->count());
    }

    /** Parehong aral na inulit: hindi nadodoble. */
    public function test_the_same_lesson_is_not_saved_twice(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $this->fakeProvider(null, ['direct' => fn () => $this->teaching('Ok.', ['applies_when' => 'Kapag x', 'rule' => 'Laging  magbigay ng TATLONG opsyon.', 'replaces_lesson_id' => null])]);
        $m = $this->start($this->meeting($user));

        $this->ask($m, $user, '@CEO lagi kang magbigay ng tatlong opsyon.');
        $this->ask($m, $user, '@CEO ulitin ko: lagi kang magbigay ng tatlong opsyon.');

        $this->assertSame(1, Lesson::count());
        $this->assertSame(1, Message::where('kind', 'lesson')->count());
    }

    /** I-undo sa chat: hindi na ginagamit ang aral; pwedeng ibalik. */
    public function test_a_lesson_can_be_undone_and_restored_from_the_chat(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $this->fakeProvider(null, ['direct' => fn () => $this->teaching('Ok.', ['applies_when' => 'Kapag y', 'rule' => 'ARAL-NA-IU-UNDO', 'replaces_lesson_id' => null])]);
        $m = $this->start($this->meeting($user));
        $this->ask($m, $user, '@CTO sa susunod, ganito dapat.');
        $lesson = Lesson::firstOrFail();
        $cto    = $this->agent('CTO');

        $state = $this->actingAs($user)->getJson("/boardroom/api/meetings/{$m->id}")->assertOk();
        $row   = collect($state->json('messages'))->firstWhere('kind', 'lesson');
        $this->assertSame(['id' => $lesson->id, 'status' => 'active'], $row['lesson']);

        $undo = $this->actingAs($user)->putJson("/boardroom/api/lessons/{$lesson->id}", ['status' => 'disabled', 'meeting_id' => $m->id])->assertOk();
        $this->assertSame('disabled', collect($undo->json('state.messages'))->firstWhere('kind', 'lesson')['lesson']['status']);
        $this->assertSame('', app(Playbook::class)->block(app(Playbook::class)->forContext($cto->id, $user->id, $m->project_id)));

        $this->actingAs($user)->putJson("/boardroom/api/lessons/{$lesson->id}", ['status' => 'active'])->assertOk();
        $this->assertStringContainsString('ARAL-NA-IU-UNDO', app(Playbook::class)->block(app(Playbook::class)->forContext($cto->id, $user->id, $m->project_id)));
    }

    /** Saklaw: aral na para sa isang project ay hindi ginagamit sa iba; aral ng ibang user ay hindi ginagamit. */
    public function test_lessons_are_scoped_by_project_and_by_owner(): void
    {
        $owner = $this->user('CEO', 'owner@example.test');
        $other = $this->user('CEO', 'other@example.test');
        $a     = $this->project($owner, 'A');
        $b     = $this->project($owner, 'B');
        $ceo   = $this->agent('CEO')->id;

        Lesson::create(['agent_id' => $ceo, 'user_id' => $owner->id, 'rule' => 'PARA-SA-LAHAT']);
        Lesson::create(['agent_id' => $ceo, 'user_id' => $owner->id, 'rule' => 'PARA-SA-A-LANG', 'project_id' => $a->id]);
        Lesson::create(['agent_id' => $ceo, 'user_id' => $other->id, 'rule' => 'ARAL-NG-IBANG-USER']);
        Lesson::create(['agent_id' => $ceo, 'user_id' => $owner->id, 'rule' => 'NAKA-DISABLE', 'status' => 'disabled']);

        $playbook = app(Playbook::class);
        $inA = $playbook->block($playbook->forContext($ceo, $owner->id, $a->id));
        $inB = $playbook->block($playbook->forContext($ceo, $owner->id, $b->id));

        $this->assertStringContainsString('PARA-SA-LAHAT', $inA);
        $this->assertStringContainsString('PARA-SA-A-LANG', $inA);
        $this->assertStringContainsString('PARA-SA-LAHAT', $inB);
        $this->assertStringNotContainsString('PARA-SA-A-LANG', $inB);
        foreach ([$inA, $inB] as $block) {
            $this->assertStringNotContainsString('ARAL-NG-IBANG-USER', $block);
            $this->assertStringNotContainsString('NAKA-DISABLE', $block);
        }
    }

    /** Hanggang 30 pinakabagong aktibong aral lang ang isinasama sa bawat call. */
    public function test_only_the_latest_thirty_lessons_go_into_a_call(): void
    {
        $user = $this->user();
        $ceo  = $this->agent('CEO')->id;
        for ($n = 1; $n <= 35; $n++) {
            Lesson::create(['agent_id' => $ceo, 'user_id' => $user->id, 'rule' => "ARAL-{$n}-X"]);
        }

        $block = app(Playbook::class)->block(app(Playbook::class)->forContext($ceo, $user->id, null));

        $this->assertSame(30, substr_count($block, "\n- [L"));
        $this->assertStringNotContainsString('ARAL-5-X', $block);
        $this->assertStringContainsString('ARAL-6-X', $block);
        $this->assertStringContainsString('ARAL-35-X', $block);

        $list = $this->actingAs($user)->getJson("/boardroom/api/agents/{$ceo}/lessons")->assertOk();
        $this->assertSame(35, $list->json('active'));
        $this->assertFalse(collect($list->json('lessons'))->firstWhere('rule', 'ARAL-1-X')['in_context']);
        $this->assertTrue(collect($list->json('lessons'))->firstWhere('rule', 'ARAL-35-X')['in_context']);
    }

    /** Pamamahala sa Agents page: dagdag, edit, saklaw, bura — at CEO lang, sarili lang. */
    public function test_lessons_can_be_managed_and_are_private_to_their_owner(): void
    {
        $owner   = $this->user('CEO', 'owner@example.test');
        $other   = $this->user('CEO', 'other@example.test');
        $staff   = $this->user('Encoder', 'staff@example.test');
        $project = $this->project($owner, 'Warehouse');
        $cto     = $this->agent('CTO')->id;

        $this->actingAs($owner)->postJson("/boardroom/api/agents/{$cto}/lessons", [
            'applies_when' => 'Kapag pumipili ng database', 'rule' => 'MySQL lang ang gamitin.',
        ])->assertOk()->assertJsonPath('lessons.0.source', 'manual')->assertJsonPath('lessons.0.project', null);
        $lesson = Lesson::firstOrFail();

        $this->actingAs($owner)->putJson("/boardroom/api/lessons/{$lesson->id}", ['rule' => 'MySQL 8 lang ang gamitin.', 'project_id' => $project->id])
            ->assertOk()->assertJsonPath('lessons.0.project', 'Warehouse');
        $this->assertSame('MySQL 8 lang ang gamitin.', $lesson->fresh()->rule);

        // Hindi pwedeng ilagay sa project ng iba, at hindi pwedeng galawin ng iba
        $foreign = $this->project($other, 'Sa iba');
        $this->actingAs($owner)->putJson("/boardroom/api/lessons/{$lesson->id}", ['project_id' => $foreign->id])->assertStatus(404);
        $this->actingAs($other)->putJson("/boardroom/api/lessons/{$lesson->id}", ['rule' => 'Binago ng iba'])->assertStatus(404);
        $this->actingAs($other)->deleteJson("/boardroom/api/lessons/{$lesson->id}")->assertStatus(404);
        $this->assertSame([], $this->actingAs($other)->getJson("/boardroom/api/agents/{$cto}/lessons")->json('lessons'));
        $this->actingAs($staff)->getJson("/boardroom/api/agents/{$cto}/lessons")->assertStatus(403);
        $this->actingAs($staff)->postJson("/boardroom/api/agents/{$cto}/lessons", ['rule' => 'Pasok'])->assertStatus(403);

        $this->actingAs($owner)->deleteJson("/boardroom/api/lessons/{$lesson->id}")->assertOk()->assertJsonPath('lessons', []);
        $this->assertSame(0, Lesson::count());
    }

    /** Magkapareho pa rin ang context ng mga proposal kahit magkaiba ang natutunan ng bawat role. */
    public function test_proposals_still_share_the_same_brief_when_roles_have_different_playbooks(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $this->fakeProvider();
        Lesson::create(['agent_id' => $this->agent('CTO')->id, 'user_id' => $user->id, 'rule' => 'ARAL-NI-CTO-LANG']);

        $m = $this->start($this->meeting($user));

        $calls = $this->callsFor('proposal');
        $this->assertSame($calls[0]['body']['input'], $calls[1]['body']['input']);
        $proposals = Turn::where('meeting_id', $m->id)->where('purpose', 'proposal')->pluck('context_hash')->unique();
        $this->assertCount(1, $proposals);

        $byHandle = collect($calls)->keyBy('handle');
        $this->assertStringContainsString('ARAL-NI-CTO-LANG', $byHandle['CTO']['body']['instructions']);
        $this->assertStringNotContainsString('ARAL-NI-CTO-LANG', $byHandle['COO']['body']['instructions']);
    }

    /** Ang mukhang API key sa loob ng aral ay tinatanggal bago i-save. */
    public function test_secrets_are_redacted_from_lessons(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $this->fakeProvider(null, ['direct' => fn () => $this->teaching('Ok.', [
            'applies_when' => 'Kapag kumokonekta', 'rule' => 'Gamitin ang key na sk-proj-AbCdEf1234567890XyZ sa request.', 'replaces_lesson_id' => null,
        ])]);
        $m = $this->start($this->meeting($user));

        $this->ask($m, $user, '@CTO sa susunod, gamitin ang key na ito.');

        $this->assertStringNotContainsString('sk-proj-AbCdEf1234567890XyZ', Lesson::firstOrFail()->rule);
        $this->assertStringContainsString('[REDACTED]', Lesson::firstOrFail()->rule);
        $this->assertSame(0, Message::where('body', 'like', '%sk-proj-AbCdEf1234567890XyZ%')->where('kind', 'lesson')->count());
    }

    /** Sa "Pag-usapan": natututo sa unang sagot lang; ang binagong sagot ay plain text. */
    public function test_learning_happens_on_the_first_answer_only(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $this->fakeProvider(null, [
            'direct'  => fn ($h) => $this->teaching("Sagot ni {$h}", $h === 'CTO' ? ['applies_when' => 'Kapag z', 'rule' => 'ARAL-MULA-SA-TALAKAYAN', 'replaces_lesson_id' => null] : null),
            'qreview' => fn () => json_encode(['public_message' => 'Kulang pa.', 'verdict' => 'revise']),
        ]);
        $m = $this->start($this->meeting($user));

        $this->ask($m, $user, '@CTO @COO sa susunod, ganito dapat. Pag-usapan.', 2);

        $this->assertSame(1, Lesson::count());
        foreach ($this->callsFor('qrevise') as $call) {
            $this->assertArrayNotHasKey('text', $call['body'], 'Ang binagong sagot ay walang JSON schema.');
        }
        $this->assertNotEmpty($this->callsFor('qrevise'));
    }
}
