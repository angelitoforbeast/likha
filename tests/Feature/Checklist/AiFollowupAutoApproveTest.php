<?php

namespace Tests\Feature\Checklist;

use App\Models\AppSetting;
use App\Models\ChatMessage;
use App\Models\ChecklistAiFollowup;
use App\Services\AiFollowups;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * MC-2 (spec 299): ang Auto-approve switch. Sarado bilang default; kapag bukas, ang bawat bagong draft ay approved
 * agad, walang reviewer, at napupunta sa staff gaya ng approved (card at chat). Ang On/Off, budget, window at ang
 * 3-a-day ay gaya pa rin ng dati. CEO lang ang makakapagpalit.
 */
class AiFollowupAutoApproveTest extends AiFollowupTestCase
{
    private function autoOn(): void
    {
        AppSetting::set('ai_followups.auto_approve', '1');
    }

    public function test_auto_approve_is_off_by_default_and_a_draft_waits(): void
    {
        $this->assertFalse(AiFollowups::settings()['auto_approve']);
        $this->enable();
        $this->lateTask($this->marketer());

        $this->runFollowups();

        $f = ChecklistAiFollowup::sole();
        $this->assertSame('draft', $f->review_status);
        $this->assertSame(0, ChatMessage::count());
    }

    public function test_only_the_ceo_can_switch_it(): void
    {
        foreach (['Marketing - OIC', 'Marketing', 'Data Encoder - OIC', 'Marketing Assistant'] as $role) {
            $this->actingAs($this->user($role))->postJson('/checklist/ai-followups/settings', ['auto_approve' => true])->assertForbidden();
        }
        $this->assertFalse(AiFollowups::settings()['auto_approve']);

        $ceo = $this->ceo();
        $this->actingAs($ceo)->postJson('/checklist/ai-followups/settings', ['auto_approve' => true])->assertOk()
            ->assertJsonPath('settings.auto_approve', true);
        $this->assertSame('1', AppSetting::where('key', 'ai_followups.auto_approve')->value('value'));
        // ang ibang settings ay hindi nagalaw
        $this->assertFalse(AiFollowups::settings()['enabled']);

        $this->actingAs($ceo)->postJson('/checklist/ai-followups/settings', ['auto_approve' => false])->assertOk();
        $this->assertFalse(AiFollowups::settings()['auto_approve']);
        $this->actingAs($ceo)->postJson('/checklist/ai-followups/settings', ['auto_approve' => 'maybe'])->assertStatus(422);
    }

    public function test_the_switch_form_says_what_happened(): void
    {
        $ceo = $this->ceo();
        $this->actingAs($ceo)->from('/checklist/ai-followups')->post('/checklist/ai-followups/settings', ['auto_approve' => '1'])
            ->assertRedirect('/checklist/ai-followups')
            ->assertSessionHas('success', 'Auto-approve is on. New drafts go to staff at once.');
        $this->actingAs($ceo)->from('/checklist/ai-followups')->post('/checklist/ai-followups/settings', ['auto_approve' => '0'])
            ->assertSessionHas('success', 'Auto-approve is off. New drafts wait for your review.');
    }

    public function test_the_settings_show_the_auto_approve_switch(): void
    {
        $ceo = $this->ceo();
        $this->actingAs($ceo)->get('/checklist/ai-followups')->assertOk()
            ->assertSee('Auto-approve')
            ->assertSee('Applies when the Marketing head is on');
        $this->enable();
        $this->actingAs($ceo)->get('/checklist/ai-followups')->assertOk()
            ->assertSee('Off: drafts wait for your review')
            ->assertSee('Turn on auto-approve?')
            ->assertSee('data-auto-approve="off"', false);

        $this->autoOn();
        $this->actingAs($ceo)->get('/checklist/ai-followups')->assertOk()
            ->assertSee('On: new drafts go to staff at once, no review')
            ->assertSee('data-auto-approve="on"', false);
    }

    public function test_when_on_a_new_draft_is_approved_at_once_with_no_reviewer_and_sent(): void
    {
        $this->enable();
        $this->autoOn();
        $joy = $this->marketer();
        $task = $this->lateTask($joy);

        $this->runFollowups();

        $f = ChecklistAiFollowup::sole();
        $this->assertSame('approved', $f->review_status);
        $this->assertNull($f->reviewed_by);
        $this->assertSame('2026-11-18 16:00:00', $f->reviewed_at->format('Y-m-d H:i:s'));
        $this->assertTrue($f->is_active);
        $this->assertTrue($f->isAuto());
        // ang text ay ang sa AI, buo pa rin ang learning record
        $this->assertSame($f->ai_draft['message'], $f->description);
        // gaya ng approved: card sa checklist at message sa chat
        $this->actingAs($joy)->get('/checklist')->assertOk()
            ->assertSee('From Mira · sent automatically')
            ->assertDontSee('approved by');
        $this->assertSame(1, ChatMessage::where('sender_ai', 'mira')->count());
        $this->assertNotNull($f->chat_message_id);
        // walang tao na nag-approve: walang ai_followup.approved event
        $this->assertSame(0, DB::table('staff_events')->where('kind', 'ai_followup.approved')->count());
        $this->assertSame((string) $task->id, (string) $f->follows_task_id);
    }

    public function test_the_sent_tab_marks_auto_rows_with_an_auto_chip(): void
    {
        $this->enable();
        $this->autoOn();
        $joy = $this->marketer();
        $this->lateTask($joy);
        $this->runFollowups();
        $ben = $this->marketer('Marketing', 'Ben Cruz');
        $ceo = $this->ceo();
        $this->reviewed($this->followup($ben, $this->lateTask($ben, ['title' => 'Reply to comments']), ['description' => 'Hi Ben, by hand.']), $ceo, 'approved');

        $html = $this->actingAs($ceo)->get('/checklist/ai-followups?tab=sent')->assertOk()
            ->assertSee('Auto-approved Nov 18')
            ->getContent();
        $this->assertSame(1, substr_count($html, 'data-auto-chip'));
        $this->assertMatchesRegularExpression('/data-auto-chip[^>]*>\s*Auto\s*</', $html);
        // kahit iba ang text sa ai_draft, ang auto row ay hindi "Edited by you"
        $auto = ChecklistAiFollowup::whereNull('reviewed_by')->sole();
        $auto->forceFill(['description' => 'Changed elsewhere'])->save();
        $this->assertFalse($auto->fresh()->wasEdited());
        // isa lang: ang kay Ben, na binago talaga ng CEO
        $this->assertSame(1, substr_count($this->actingAs($ceo)->get('/checklist/ai-followups?tab=sent')->assertOk()->getContent(), 'Edited by you'));
        // ang paliwanag sa itaas ay sumusunod sa switch
        $this->actingAs($ceo)->get('/checklist/ai-followups')->assertSee('Auto-approve is on: new follow-ups go to staff at once, without your review.')
            ->assertDontSee('Staff see one only after you approve it.');
    }

    public function test_switched_off_nothing_is_drafted_or_sent_even_with_auto_approve_on(): void
    {
        $this->autoOn();
        $this->lateTask($this->marketer());

        $this->runFollowups();

        $this->assertSame(0, ChecklistAiFollowup::count());
        $this->assertSame(0, ChatMessage::count());
        $this->assertCount(0, $this->openAi);
    }

    public function test_budget_window_and_daily_cap_still_hold(): void
    {
        $this->enable();
        $this->autoOn();
        AppSetting::set('ai_followups.monthly_cap_php', '1');
        $ben = $this->marketer('Marketing', 'Ben Cruz');
        $this->followup($ben, $this->lateTask($ben, ['title' => 'Old', 'deadline_time' => '18:00']), ['ai_cost_usd' => 0.02]);
        $this->lateTask($this->marketer());

        $this->runFollowups();
        $this->assertCount(0, $this->openAi);
        $this->assertSame(0, ChatMessage::count());

        // window: 8:45 PM ay wala nang puwang para sa deadline
        AppSetting::set('ai_followups.monthly_cap_php', '3000');
        Carbon::setTestNow('2026-11-18 20:45:00');
        $this->runFollowups();
        $this->assertCount(0, $this->openAi);

        // 3 a day: ang auto-approved ay bilang din
        Carbon::setTestNow('2026-11-18 16:00:00');
        $lea = $this->marketer('Marketing', 'Lea Santos');
        foreach (['A', 'B', 'C', 'D'] as $t) {
            $this->lateTask($lea, ['title' => "Task $t"]);
        }
        $this->runFollowups();
        $leaRows = ChecklistAiFollowup::whereHas('assignedUsers', fn ($q) => $q->where('users.id', $lea->id))->get();
        $this->assertCount(3, $leaRows);
        $this->assertSame(['approved'], $leaRows->pluck('review_status')->unique()->values()->all());
    }

    public function test_drafts_made_while_it_was_off_still_wait_for_review(): void
    {
        $this->enable();
        $joy = $this->marketer();
        $this->lateTask($joy);
        $this->runFollowups();
        $old = ChecklistAiFollowup::sole();

        $this->autoOn();
        $this->runFollowups();

        $this->assertSame('draft', $old->fresh()->review_status);
        $this->assertSame(0, ChatMessage::count());
        $this->actingAs($this->ceo())->get('/checklist/ai-followups')->assertOk()->assertSee('data-card="' . $old->id . '"', false)
            ->assertSee('Made before auto-approve was on. These still wait for you.');
    }

    public function test_auto_approved_rows_are_not_fed_to_the_prompt_as_the_ceos_decisions(): void
    {
        $ceo = $this->ceo();
        $ben = $this->marketer('Marketing', 'Ben Cruz');
        $byCeo = $this->reviewed($this->followup($ben, $this->lateTask($ben, ['title' => 'One']), ['ai_draft' => ['message' => 'CEO approved this']]), $ceo, 'approved');
        $auto = $this->followup($ben, $this->lateTask($ben, ['title' => 'Two']), ['ai_draft' => ['message' => 'Nobody read this']]);
        $auto->forceFill(['review_status' => 'approved', 'reviewed_by' => null, 'reviewed_at' => now(), 'is_active' => true])->save();

        $this->enable();
        $this->lateTask($this->marketer());
        $this->runFollowups();

        $data = json_decode($this->openAi[0]->data()['messages'][1]['content'], true);
        $drafts = array_column($data['ceo_reviews'], 'ai_draft');
        $this->assertContains('CEO approved this', $drafts);
        $this->assertNotContains('Nobody read this', $drafts);
        $this->assertNotNull($byCeo->reviewed_by);
    }
}
