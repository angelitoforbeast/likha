<?php

namespace Tests\Feature\Checklist;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AF-4 at AF-5: ang approved na follow-up ay card sa itaas ng /checklist para sa iisang tao, sa araw lang nito at
 * habang Waiting; nawawala kapag na-approve na ang orihinal na task (Done), at ang Done ay event sa staff feed.
 */
class AiFollowupStaffTest extends AiFollowupTestCase
{
    public function test_an_approved_followup_shows_to_its_person_as_a_note_from_mira(): void
    {
        $ceo = $this->ceo();
        DB::table('employee_profiles')->where('user_id', $ceo->id)->update(['name' => 'Angelito Forbes']);
        $joy = $this->marketer();
        $task = $this->lateTask($joy);
        $this->reviewed($this->followup($joy, $task, ['description' => 'Hi Joy, please post the 3 stories by 5:00 PM.']), $ceo, 'approved');

        $html = $this->actingAs($joy)->get('/checklist')->assertOk()
            ->assertSee('From Mira · approved by Angelito')
            ->assertSee('Hi Joy, please post the 3 stories by 5:00 PM.')
            ->assertSee('by 5:00 PM')
            ->assertSee('Open task')
            ->getContent();

        $this->assertStringContainsString('href="#task-' . $task->id . '"', $html);
        $this->assertStringContainsString('id="task-' . $task->id . '"', $html);
        // ang card ay nasa itaas, bago ang table ng mga task
        $this->assertLessThan(strpos($html, 'id="checklist-body-scroll"'), strpos($html, 'data-ai-followup'));
        // hindi checklist item: walang submit form para sa follow-up
        $this->assertStringNotContainsString('/checklist/' . \App\Models\ChecklistAiFollowup::sole()->id . '/submit', $html);
    }

    public function test_another_staff_member_never_sees_it(): void
    {
        $ceo = $this->ceo();
        $joy = $this->marketer();
        $ben = $this->marketer('Marketing', 'Ben Cruz');
        $task = $this->lateTask($joy);
        $task->assignedUsers()->sync([$joy->id, $ben->id]);
        $this->reviewed($this->followup($joy, $task, ['description' => 'Only for Joy']), $ceo, 'approved');

        $this->actingAs($ben)->get('/checklist')->assertOk()->assertDontSee('Only for Joy')->assertDontSee('data-ai-followup', false);
        $this->actingAs($ceo)->get('/checklist')->assertOk()->assertDontSee('Only for Joy');
    }

    public function test_drafts_rejected_expired_and_other_days_are_never_shown(): void
    {
        $ceo = $this->ceo();
        $joy = $this->marketer();
        $task = $this->lateTask($joy);
        $this->followup($joy, $task, ['description' => 'A draft']);
        $this->reviewed($this->followup($joy, $task, ['description' => 'A rejected one']), $ceo, 'rejected', ['review_note' => 'No']);
        $this->followup($joy, $task, ['description' => 'An expired one', 'review_status' => 'expired']);
        $this->reviewed($this->followup($joy, $task, ['description' => 'Yesterday', 'follows_date' => '2026-11-17']), $ceo, 'approved');

        $this->actingAs($joy)->get('/checklist')->assertOk()
            ->assertDontSee('A draft')->assertDontSee('A rejected one')->assertDontSee('An expired one')->assertDontSee('Yesterday')
            ->assertDontSee('data-ai-followup', false);
    }

    public function test_when_the_original_task_is_approved_the_card_goes_and_the_ceo_sees_done(): void
    {
        $ceo = $this->ceo();
        $joy = $this->marketer();
        $task = $this->lateTask($joy);
        $f = $this->reviewed($this->followup($joy, $task, ['description' => 'Please post them']), $ceo, 'approved');
        $sub = $this->submission($task, $joy, '2026-11-18');

        $this->actingAs($joy)->get('/checklist')->assertSee('Please post them');   // naisumite pero hindi pa approved
        $this->approval($sub, 'approved', '2026-11-18 16:20:00');

        $this->actingAs($joy)->get('/checklist')->assertOk()->assertDontSee('Please post them');
        $this->actingAs($ceo)->get('/checklist/ai-followups?tab=sent')->assertOk()->assertSee('data-state="done"', false);

        // Done: isang event sa staff feed para sa tao, kahit ilang beses tumakbo ang command
        $this->runFollowups();
        $this->runFollowups();
        $event = DB::table('staff_events')->where('kind', 'ai_followup.done')->sole();
        $this->assertSame($joy->id, (int) $event->user_id);
        $this->assertSame((string) $f->id, $event->ref);
        $this->assertSame('ai_followups', $event->source);
    }

    public function test_a_group_task_is_done_when_anyone_gets_it_approved(): void
    {
        $ceo = $this->ceo();
        $joy = $this->marketer();
        $ben = $this->marketer('Marketing', 'Ben Cruz');
        $task = $this->lateTask($joy, ['submission_type' => 'group']);
        $task->assignedUsers()->sync([$joy->id, $ben->id]);
        $this->reviewed($this->followup($joy, $task, ['description' => 'Group nudge']), $ceo, 'approved');
        $this->approval($this->submission($task, $ben, '2026-11-18'), 'approved', '2026-11-18 16:20:00');

        $this->actingAs($joy)->get('/checklist')->assertOk()->assertDontSee('Group nudge');
    }

    public function test_the_staff_feed_events_endpoint_carries_approved_and_done(): void
    {
        config(['services.staff_feed.key' => 'feed-key']);
        $ceo = $this->ceo();
        $joy = $this->marketer();
        $task = $this->lateTask($joy);
        $f = $this->followup($joy, $task);
        $this->actingAs($ceo)->postJson("/checklist/ai-followups/{$f->id}/approve")->assertOk();
        $this->approval($this->submission($task, $joy, '2026-11-18'), 'approved', '2026-11-18 16:20:00');
        $this->runFollowups();

        $events = collect($this->withHeader('X-STAFF-FEED-KEY', 'feed-key')
            ->getJson('/api/staff-events?since=2026-11-18')->assertOk()->json('events'))
            ->whereIn('kind', ['ai_followup.approved', 'ai_followup.done'])->values();

        $this->assertSame(['ai_followup.done', 'ai_followup.approved'], $events->pluck('kind')->all());
        $this->assertSame([$joy->id, $ceo->id], $events->pluck('user_id')->all());
        $this->assertSame([(string) $f->id, (string) $f->id], $events->pluck('ref')->all());
        $this->assertSame(['ai_followups', 'ai_followups'], $events->pluck('source')->all());
    }

    public function test_the_followup_row_is_not_a_task_staff_can_submit_to(): void
    {
        $joy = $this->marketer();
        $f = $this->reviewed($this->followup($joy, $this->lateTask($joy)), $this->ceo(), 'approved');

        $this->actingAs($joy)->post("/checklist/{$f->id}/submit", ['notes' => 'done'])->assertNotFound();
        $this->assertSame(0, DB::table('checklist_submissions')->where('checklist_task_id', $f->id)->count());
    }

    public function test_nothing_is_deleted_the_learning_record_stays(): void
    {
        $ceo = $this->ceo();
        $joy = $this->marketer();
        $task = $this->lateTask($joy);
        $a = $this->followup($joy, $task);
        $this->actingAs($ceo)->postJson("/checklist/ai-followups/{$a->id}/edit", ['message' => 'Edited text', 'deadline' => '18:00'])->assertOk();
        $b = $this->followup($joy, $task);
        $this->actingAs($ceo)->postJson("/checklist/ai-followups/{$b->id}/reject", ['note' => 'Not needed'])->assertOk();
        $c = $this->followup($joy, $task, ['follows_date' => '2026-11-17']);
        Carbon::setTestNow('2026-11-19 10:00:00');
        $this->runFollowups();

        $rows = DB::table('checklist_tasks')->where('origin', 'ai')->orderBy('id')->get();
        $this->assertCount(3, $rows);
        $this->assertSame(['approved', 'rejected', 'expired'], $rows->pluck('review_status')->all());
        foreach ($rows as $r) {
            $draft = json_decode($r->ai_draft, true);
            $this->assertSame('Hi Joy, please post the 3 stories by 5:00 PM.', $draft['message']);
            $this->assertSame('gpt-4o-mini', $draft['model']);
            $this->assertNotNull($r->ai_cost_usd);
            $this->assertNull($r->deleted_at);
        }
        $this->assertSame('Edited text', $rows[0]->description);
        $this->assertSame('Not needed', $rows[1]->review_note);
        $this->assertSame([$ceo->id, $ceo->id, null], $rows->pluck('reviewed_by')->map(fn ($v) => $v === null ? null : (int) $v)->all());
    }

    public function test_the_checklist_header_counts_are_in_english(): void
    {
        $joy = $this->marketer();
        $this->lateTask($joy);

        $this->actingAs($joy)->get('/checklist')->assertOk()
            ->assertSee('need a fix')->assertSee('not submitted')
            ->assertDontSee('kailangang ayusin')->assertDontSee('hindi pa naisumite');

        $this->approval($this->submission($this->lateTask($joy, ['title' => 'Checked']), $joy, '2026-11-18'), 'unknown', '2026-11-18 15:00:00');
        $this->actingAs($joy)->get('/checklist')->assertSee('checking')->assertDontSee('sinusuri pa');
    }
}
