<?php

namespace Tests\Feature\Checklist;

use App\Models\AppSetting;
use App\Models\ChecklistAiFollowup;
use App\Services\AiFollowups;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AF-3: ang CEO lang ang nagre-review ng drafts sa /checklist/ai-followups (approve, edit and approve, reject na may
 * dahilan), nakikita ang Sent na may Waiting / Done / Expired, at ang switch at budget. 403 sa iba.
 */
class AiFollowupReviewTest extends AiFollowupTestCase
{
    public static function notCeo(): array
    {
        return [
            'Marketing OIC'       => ['Marketing - OIC'],
            'Marketing staff'     => ['Marketing'],
            'Data Encoder OIC'    => ['Data Encoder - OIC'],
            'Marketing Assistant' => ['Marketing Assistant'],
        ];
    }

    /** @dataProvider notCeo */
    public function test_the_page_and_every_action_are_ceo_only(string $role): void
    {
        $joy = $this->marketer();
        $f = $this->followup($joy, $this->lateTask($joy));
        $user = $this->user($role);

        $this->actingAs($user)->get('/checklist/ai-followups')->assertForbidden();
        $this->actingAs($user)->postJson("/checklist/ai-followups/{$f->id}/approve")->assertForbidden();
        $this->actingAs($user)->postJson("/checklist/ai-followups/{$f->id}/edit", ['message' => 'Hi Joy, go.', 'deadline' => '18:00'])->assertForbidden();
        $this->actingAs($user)->postJson("/checklist/ai-followups/{$f->id}/reject", ['note' => 'No'])->assertForbidden();
        $this->actingAs($user)->postJson('/checklist/ai-followups/settings', ['enabled' => true, 'monthly_cap_php' => 99999])->assertForbidden();

        $this->assertSame('draft', $f->fresh()->review_status);
        $this->assertFalse(AiFollowups::settings()['enabled']);
        $this->assertEquals(3000, AiFollowups::settings()['monthly_cap_php']);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/checklist/ai-followups')->assertRedirect();
    }

    public function test_the_drafts_tab_shows_person_task_why_message_deadline_and_cost(): void
    {
        $joy = $this->marketer('Marketing', 'Joy Ramos');
        $late = $this->lateTask($joy);
        $this->followup($joy, $late, ['description' => 'Hi Joy, please post the stories by 5:00 PM.', 'ai_cost_usd' => 0.0005]);
        $fix = $this->lateTask($joy, ['title' => 'Reply to comments']);
        $this->followup($joy, $fix, ['follows_reason' => 'not_approved', 'deadline_time' => '18:30',
            'ai_draft' => ['message' => 'x', 'deadline' => '18:30', 'verdict_reason' => 'Two comments have no reply.']]);

        $this->actingAs($this->ceo())->get('/checklist/ai-followups')->assertOk()
            ->assertSee('Joy Ramos')
            ->assertSee('JR')
            ->assertSee('Post 3 stories on the main page')
            ->assertSee('Late since 3:30 PM')
            ->assertSee('Hi Joy, please post the stories by 5:00 PM.')
            ->assertSee('by 5:00 PM')
            ->assertSee('₱0.03')            // 0.0005 × 63.841
            ->assertSee('Not approved')
            ->assertSee('Two comments have no reply.')
            ->assertSee('by 6:30 PM')
            ->assertSee('Drafts')
            ->assertSee('Off: the Marketing head drafts nothing');
    }

    public function test_html_in_ai_or_staff_text_is_escaped(): void
    {
        $joy = $this->marketer();
        $this->followup($joy, $this->lateTask($joy), ['description' => '<script>alert(1)</script>']);

        $this->actingAs($this->ceo())->get('/checklist/ai-followups')->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_approve_records_who_when_and_writes_a_staff_event(): void
    {
        $ceo = $this->ceo();
        $joy = $this->marketer();
        $f = $this->followup($joy, $this->lateTask($joy));

        $this->actingAs($ceo)->postJson("/checklist/ai-followups/{$f->id}/approve")->assertOk()->assertJson(['ok' => true]);

        $f = $f->fresh();
        $this->assertSame('approved', $f->review_status);
        $this->assertSame($ceo->id, (int) $f->reviewed_by);
        $this->assertSame('2026-11-18 16:00:00', $f->reviewed_at->format('Y-m-d H:i:s'));
        $this->assertTrue($f->is_active);
        $this->assertSame('Hi Joy, please post the 3 stories by 5:00 PM.', $f->description);
        $event = DB::table('staff_events')->where('kind', 'ai_followup.approved')->sole();
        $this->assertSame($ceo->id, (int) $event->user_id);
        $this->assertSame((string) $f->id, $event->ref);
        $this->assertSame('ai_followups', $event->source);
    }

    public function test_approving_a_draft_whose_deadline_already_passed_moves_it_to_now_plus_thirty(): void
    {
        $joy = $this->marketer();
        $f = $this->followup($joy, $this->lateTask($joy), ['deadline_time' => '16:10']);

        $this->actingAs($this->ceo())->postJson("/checklist/ai-followups/{$f->id}/approve")->assertOk();

        $this->assertSame('16:30', $f->fresh()->deadline_time);
    }

    public function test_approving_after_twenty_thirty_is_refused_because_no_deadline_is_left(): void
    {
        Carbon::setTestNow('2026-11-18 20:40:00');
        $joy = $this->marketer();
        $f = $this->followup($joy, $this->lateTask($joy), ['deadline_time' => '20:45']);

        $this->actingAs($this->ceo())->postJson("/checklist/ai-followups/{$f->id}/approve")->assertStatus(422);

        $this->assertSame('draft', $f->fresh()->review_status);
    }

    public function test_edit_and_approve_keeps_the_ai_draft_and_stores_the_final_text(): void
    {
        $ceo = $this->ceo();
        $joy = $this->marketer();
        $f = $this->followup($joy, $this->lateTask($joy));

        $this->actingAs($ceo)->postJson("/checklist/ai-followups/{$f->id}/edit", [
            'message' => '  Hi Joy, please post the 3 stories with prices by 6 PM.  ', 'deadline' => '18:00',
        ])->assertOk();

        $f = $f->fresh();
        $this->assertSame('approved', $f->review_status);
        $this->assertSame('Hi Joy, please post the 3 stories with prices by 6 PM.', $f->description);
        $this->assertSame('18:00', $f->deadline_time);
        $this->assertSame('Hi Joy, please post the 3 stories by 5:00 PM.', $f->ai_draft['message']);
        $this->assertSame('17:00', $f->ai_draft['deadline']);
        $this->assertTrue($f->wasEdited());
        $this->assertSame(1, DB::table('staff_events')->where('kind', 'ai_followup.approved')->count());
    }

    public static function badEdits(): array
    {
        return [
            'empty message'    => [['message' => '   ', 'deadline' => '18:00'], 'message'],
            '241 characters'   => [['message' => str_repeat('a', 241), 'deadline' => '18:00'], 'message'],
            'no deadline'      => [['message' => 'Hi Joy.'], 'deadline'],
            'not a time'       => [['message' => 'Hi Joy.', 'deadline' => '6pm'], 'deadline'],
            'before now + 30'  => [['message' => 'Hi Joy.', 'deadline' => '16:20'], 'deadline'],
            'after 21:00'      => [['message' => 'Hi Joy.', 'deadline' => '21:30'], 'deadline'],
        ];
    }

    /** @dataProvider badEdits */
    public function test_edit_has_the_same_limits_as_the_ai(array $input, string $field): void
    {
        $joy = $this->marketer();
        $f = $this->followup($joy, $this->lateTask($joy));

        $this->actingAs($this->ceo())->postJson("/checklist/ai-followups/{$f->id}/edit", $input)
            ->assertStatus(422)->assertJsonValidationErrors($field);

        $this->assertSame('draft', $f->fresh()->review_status);
    }

    public function test_reject_needs_a_reason_of_at_most_300_characters(): void
    {
        $ceo = $this->ceo();
        $joy = $this->marketer();
        $f = $this->followup($joy, $this->lateTask($joy));

        $this->actingAs($ceo)->postJson("/checklist/ai-followups/{$f->id}/reject", [])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->actingAs($ceo)->postJson("/checklist/ai-followups/{$f->id}/reject", ['note' => '   '])->assertStatus(422);
        $this->actingAs($ceo)->postJson("/checklist/ai-followups/{$f->id}/reject", ['note' => str_repeat('a', 301)])->assertStatus(422);
        $this->assertSame('draft', $f->fresh()->review_status);

        $this->actingAs($ceo)->postJson("/checklist/ai-followups/{$f->id}/reject", ['note' => 'Too pushy for a first reminder.'])->assertOk();

        $f = $f->fresh();
        $this->assertSame('rejected', $f->review_status);
        $this->assertSame('Too pushy for a first reminder.', $f->review_note);
        $this->assertSame($ceo->id, (int) $f->reviewed_by);
        $this->assertFalse($f->is_active);
        $this->assertSame(0, DB::table('staff_events')->where('kind', 'like', 'ai_followup.%')->count());
        $this->actingAs($ceo)->get('/checklist/ai-followups?tab=rejected')->assertOk()->assertSee('Too pushy for a first reminder.');
    }

    public function test_a_reviewed_draft_cannot_be_reviewed_again(): void
    {
        $ceo = $this->ceo();
        $joy = $this->marketer();
        $f = $this->reviewed($this->followup($joy, $this->lateTask($joy)), $ceo, 'rejected', ['review_note' => 'No']);

        $this->actingAs($ceo)->postJson("/checklist/ai-followups/{$f->id}/approve")->assertStatus(409);
        $this->actingAs($ceo)->postJson("/checklist/ai-followups/{$f->id}/edit", ['message' => 'Hi', 'deadline' => '18:00'])->assertStatus(409);

        $this->assertSame('rejected', $f->fresh()->review_status);
    }

    public function test_a_normal_checklist_task_id_is_not_a_followup(): void
    {
        $joy = $this->marketer();
        $task = $this->lateTask($joy);

        $this->actingAs($this->ceo())->postJson("/checklist/ai-followups/{$task->id}/approve")->assertNotFound();
    }

    public function test_a_draft_not_reviewed_by_the_end_of_its_day_expires_and_cannot_be_approved(): void
    {
        $ceo = $this->ceo();
        $joy = $this->marketer();
        $f = $this->followup($joy, $this->lateTask($joy));

        Carbon::setTestNow('2026-11-18 23:59:00');
        AiFollowups::expireStale(now());
        $this->assertSame('draft', $f->fresh()->review_status);

        Carbon::setTestNow('2026-11-19 00:00:01');
        $this->actingAs($ceo)->get('/checklist/ai-followups')->assertOk()->assertDontSee('Hi Joy, please post the 3 stories by 5:00 PM.');
        $this->assertSame('expired', $f->fresh()->review_status);
        $this->assertNull($f->fresh()->reviewed_by);
        $this->actingAs($ceo)->postJson("/checklist/ai-followups/{$f->id}/approve")->assertStatus(409);
        $this->actingAs($ceo)->get('/checklist/ai-followups?tab=rejected')->assertOk()->assertSee('Expired, not reviewed');
    }

    public function test_the_command_also_expires_yesterdays_drafts(): void
    {
        $joy = $this->marketer();
        $f = $this->followup($joy, $this->lateTask($joy), ['follows_date' => '2026-11-17']);

        $this->runFollowups();

        $this->assertSame('expired', $f->fresh()->review_status);
    }

    public function test_sent_shows_waiting_done_and_expired(): void
    {
        $ceo = $this->ceo();
        $joy = $this->marketer();
        $waitingTask = $this->lateTask($joy, ['title' => 'Waiting task']);
        $doneTask = $this->lateTask($joy, ['title' => 'Done task']);
        $this->reviewed($this->followup($joy, $waitingTask, ['description' => 'Waiting message']), $ceo, 'approved');
        $this->reviewed($this->followup($joy, $doneTask, ['description' => 'Done message']), $ceo, 'approved');
        $this->approval($this->submission($doneTask, $joy, '2026-11-18'), 'approved', '2026-11-18 16:30:00');
        $this->reviewed($this->followup($joy, $waitingTask, ['description' => 'Old message', 'follows_date' => '2026-11-17']), $ceo, 'approved');

        $html = $this->actingAs($ceo)->get('/checklist/ai-followups?tab=sent')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/Waiting message.*?data-state="waiting"/s', $html);
        $this->assertMatchesRegularExpression('/Done message.*?data-state="done"/s', $html);
        $this->assertMatchesRegularExpression('/Old message.*?data-state="expired"/s', $html);
        $this->assertStringContainsString('>Waiting<', $html);
        $this->assertStringContainsString('>Done<', $html);
        $this->assertStringContainsString('>Expired<', $html);
    }

    public function test_each_tab_has_an_empty_state(): void
    {
        $ceo = $this->ceo();
        $this->actingAs($ceo)->get('/checklist/ai-followups')->assertOk()->assertSee('No drafts.')
            ->assertSee('The Marketing head is off.');
        $this->enable();
        $this->actingAs($ceo)->get('/checklist/ai-followups')->assertOk()
            ->assertSee('The Marketing head checks every 30 minutes from 9 AM to 9 PM.');
        $this->actingAs($ceo)->get('/checklist/ai-followups?tab=sent')->assertOk()->assertSee('Nothing sent yet.');
        $this->actingAs($ceo)->get('/checklist/ai-followups?tab=rejected')->assertOk()->assertSee('Nothing rejected.');
    }

    public function test_the_switch_and_the_settings_are_saved_by_the_ceo(): void
    {
        $ceo = $this->ceo();

        $this->actingAs($ceo)->postJson('/checklist/ai-followups/settings', ['enabled' => true])->assertOk();
        $this->assertTrue(AiFollowups::settings()['enabled']);

        $this->actingAs($ceo)->postJson('/checklist/ai-followups/settings', [
            'monthly_cap_php' => 5000, 'php_per_usd' => 58.5, 'input_usd_per_1m' => 0.2, 'output_usd_per_1m' => 0.8,
        ])->assertOk();
        $s = AiFollowups::settings();
        $this->assertTrue($s['enabled']);
        $this->assertEquals(5000, $s['monthly_cap_php']);
        $this->assertEquals(58.5, $s['php_per_usd']);
        $this->assertEquals(0.2, $s['input_usd_per_1m']);
        $this->assertEquals(0.8, $s['output_usd_per_1m']);

        $this->actingAs($ceo)->postJson('/checklist/ai-followups/settings', ['enabled' => false])->assertOk();
        $this->assertFalse(AiFollowups::settings()['enabled']);
        $this->actingAs($ceo)->postJson('/checklist/ai-followups/settings', ['monthly_cap_php' => -1])->assertStatus(422);
        $this->actingAs($ceo)->postJson('/checklist/ai-followups/settings', ['php_per_usd' => 0])->assertStatus(422);
    }

    public function test_settings_defaults(): void
    {
        $s = AiFollowups::settings();
        $this->assertFalse($s['enabled']);
        $this->assertEquals(3000, $s['monthly_cap_php']);
        $this->assertEquals(63.841, $s['php_per_usd']);
        $this->assertEquals(0.15, $s['input_usd_per_1m']);
        $this->assertEquals(0.60, $s['output_usd_per_1m']);
        $this->assertSame('gpt-4o-mini', $s['model']);
    }

    public function test_the_budget_meter_shows_this_months_spend(): void
    {
        AppSetting::set('ai_followups.monthly_cap_php', '10');
        $joy = $this->marketer();
        // $0.13 × 63.841 = ₱8.30 of ₱10: amber
        $this->followup($joy, $this->lateTask($joy), ['ai_cost_usd' => 0.13]);

        $this->actingAs($this->ceo())->get('/checklist/ai-followups')->assertOk()
            ->assertSee('₱8.30 of ₱10 this month')
            ->assertSee('data-budget="amber"', false)
            ->assertSee('Close to the cap');
    }

    public function test_the_nav_link_migration_shows_the_page_to_the_ceo_only(): void
    {
        Schema::create('nav_links', function (Blueprint $t) {
            $t->id();
            $t->string('key', 80)->unique();
            $t->string('label', 100);
            $t->string('route_url', 255);
            $t->string('icon', 100)->nullable();
            $t->string('active_pattern', 255)->nullable();
            $t->integer('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('nav_link_role_visibility', function (Blueprint $t) {
            $t->id();
            $t->foreignId('nav_link_id');
            $t->string('role', 50);
            $t->boolean('is_visible')->default(false);
            $t->timestamps();
        });
        DB::table('nav_links')->insert(['key' => 'checklist', 'label' => 'Checklist', 'route_url' => '/checklist', 'sort_order' => 7]);
        $migration = require database_path('migrations/2026_11_27_100100_add_ai_followups_nav_link.php');

        $migration->up();
        $migration->up();

        $link = DB::table('nav_links')->where('key', 'checklist_ai_followups')->sole();
        $this->assertSame('/checklist/ai-followups', $link->route_url);
        $this->assertSame(7, (int) $link->sort_order);
        $this->assertSame(['CEO'], DB::table('nav_link_role_visibility')->where('nav_link_id', $link->id)->where('is_visible', true)->pluck('role')->all());
        $migration->down();
        $this->assertSame(0, DB::table('nav_links')->where('key', 'checklist_ai_followups')->count());
    }
}
