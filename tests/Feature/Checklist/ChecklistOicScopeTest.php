<?php

namespace Tests\Feature\Checklist;

use App\Models\ChecklistSubmission;
use Illuminate\Support\Carbon;

/**
 * Individual tasks (bawat naka-assign ay may sariling submission) at ang saklaw ng OIC sa
 * mga report at sa Manage: department niya + mga task na ginawa niya o naka-assign sa kanya.
 */
class ChecklistOicScopeTest extends ChecklistTestCase
{
    private const DAY = '2026-10-14';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::DAY . ' 09:00:00');
    }

    public function test_two_assignees_each_submit_an_individual_task_with_their_own_verdict(): void
    {
        $first  = $this->user('Staff', null, 'Encoder Uno');
        $second = $this->user('Staff', null, 'Encoder Dos');
        $task   = $this->task(['submission_type' => 'individual'], [$first, $second]);

        $this->aiAnswer = 'APPROVED';
        $this->actingAs($first)->post(route('checklist.submit', $task), ['notes' => 'Akin ito'])->assertRedirect();
        $this->aiAnswer = "NOT APPROVED\nWalang screenshot.";
        $this->actingAs($second)->post(route('checklist.submit', $task), ['notes' => 'Akin naman ito'])->assertRedirect();

        $subs = ChecklistSubmission::with('latestApproval')->where('checklist_task_id', $task->id)->get()->keyBy('user_id');
        $this->assertCount(2, $subs);
        $this->assertSame('Akin ito', $subs[$first->id]->notes);
        $this->assertSame('approved', $subs[$first->id]->latestApproval->verdict);
        $this->assertSame('not_approved', $subs[$second->id]->latestApproval->verdict);

        // Bawat isa ay sariling verdict ang nakikita sa /checklist. 'Y-m-d' lang, gaya ng DATE sa
        // MySQL (sa sqlite, may oras ang naka-save ng date cast).
        \Illuminate\Support\Facades\DB::table('checklist_submissions')->update(['date' => self::DAY]);
        $this->assertSame('approved', $this->actingAs($first)->get(route('checklist.index'))->viewData('statusByTask')[$task->id]['state']);
        $this->assertSame('needs_fix', $this->actingAs($second)->get(route('checklist.index'))->viewData('statusByTask')[$task->id]['state']);
    }

    public function test_an_assignee_who_did_not_submit_an_individual_task_is_listed_in_both_reports(): void
    {
        $oic     = $this->user('Encoder - OIC');
        $done    = $this->user('Staff', null, 'Nagsumite');
        $missing = $this->user('Staff', null, 'Hindi Nagsumite');
        $created = ['created_at' => '2026-10-01 08:00:00', 'department' => 'Encoder'];
        $own     = $this->task($created + ['title' => 'Sariling bilang', 'submission_type' => 'individual'], [$done, $missing]);
        $team    = $this->task($created + ['title' => 'Pang-team'], [$done, $missing]);
        $this->approval($this->submission($own, $done, self::DAY), 'approved', self::DAY . ' 08:00:00');
        // Nagsumite ang isa ng group task: hindi nito tinatakpan ang individual task na hindi niya ginawa
        $this->submission($team, $missing, self::DAY);

        $this->actingAs($oic)->get(route('checklist.report', ['date' => self::DAY]))
            ->assertOk()
            ->assertSee($missing->name . ' — not yet submitted')
            ->assertDontSee($done->name . ' — not yet submitted');

        $range  = $this->actingAs($oic)->get(route('checklist.report', ['view' => 'range', 'from' => self::DAY, 'to' => self::DAY]));
        $missed = collect($range->viewData('people'))->mapWithKeys(fn ($p) => [$p['user']->name => $p['missed']])->all();
        $this->assertSame([$missing->name => 1, $done->name => 0], $missed);
        $row = collect($range->viewData('taskRows'))->first(fn ($r) => $r['task']->id === $own->id);
        $this->assertSame([self::DAY => [$missing->name]], $row['notSubmitted']);
        $range->assertSee($missing->name);
    }

    /** Mga task: [title => department, gumawa, naka-assign]. */
    private function scopeTasks(): array
    {
        $oic   = $this->user('Marketing - OIC');
        $staff = $this->user('Staff');
        $other = $this->user('Encoder - OIC');
        $at    = ['created_at' => '2026-10-01 08:00:00'];
        $tasks = [
            'Marketing, hindi assigned' => $this->task($at + ['title' => 'Marketing, hindi assigned', 'department' => 'Marketing', 'created_by' => $other->id], [$staff]),
            'Encoder, iba ang gumawa'   => $this->task($at + ['title' => 'Encoder, iba ang gumawa', 'department' => 'Encoder', 'created_by' => $other->id], [$staff]),
            'Encoder, OIC ang gumawa'   => $this->task($at + ['title' => 'Encoder, OIC ang gumawa', 'department' => 'Encoder', 'created_by' => $oic->id], [$staff]),
            'Walang dept, assigned'     => $this->task($at + ['title' => 'Walang dept, assigned'], [$oic]),
        ];

        return [$oic, $staff, $tasks];
    }

    public function test_report_scope_per_role_in_the_day_and_range_views_and_the_csv(): void
    {
        [$oic, $staff, $tasks] = $this->scopeTasks();
        $ceo      = $this->user('CEO');
        $expected = [
            'oic'   => [$oic, ['Marketing, hindi assigned', 'Encoder, OIC ang gumawa', 'Walang dept, assigned']],
            'staff' => [$staff, ['Marketing, hindi assigned', 'Encoder, iba ang gumawa', 'Encoder, OIC ang gumawa']],
            'ceo'   => [$ceo, array_keys($tasks)],
        ];

        foreach ($expected as $who => [$user, $titles]) {
            sort($titles);
            $day = $this->actingAs($user)->get(route('checklist.report', ['date' => self::DAY]))
                ->viewData('tasks')->pluck('title')->sort()->values()->all();
            $this->assertSame($titles, $day, "day view: $who");

            $range = collect($this->actingAs($user)->get(route('checklist.report', ['view' => 'range', 'from' => self::DAY, 'to' => self::DAY]))
                ->viewData('taskRows'))->map(fn ($r) => $r['task']->title)->sort()->values()->all();
            $this->assertSame($titles, $range, "range view: $who");

            $csv = $this->actingAs($user)->get(route('checklist.report', ['view' => 'range', 'from' => self::DAY, 'to' => self::DAY, 'csv' => 'tasks']))
                ->streamedContent();
            foreach (array_keys($tasks) as $title) {
                in_array($title, $titles)
                    ? $this->assertStringContainsString($title, $csv, "csv: $who")
                    : $this->assertStringNotContainsString($title, $csv, "csv: $who");
            }
        }
    }

    public function test_an_oic_may_check_submissions_of_a_task_they_created_for_another_department(): void
    {
        [$oic, $staff, $tasks] = $this->scopeTasks();
        $mine   = $this->submission($tasks['Encoder, OIC ang gumawa'], $staff, self::DAY);
        $theirs = $this->submission($tasks['Encoder, iba ang gumawa'], $staff, self::DAY);

        $this->actingAs($oic)->getJson(route('checklist.approval-logs', $mine))->assertOk();
        $this->actingAs($oic)->getJson(route('checklist.approval-logs', $theirs))->assertForbidden();
    }

    public function test_manage_lists_the_oic_department_plus_tasks_they_created_and_for_marketing_the_assigned(): void
    {
        [$oic, , $tasks] = $this->scopeTasks();

        $titles = $this->actingAs($oic)->get(route('checklist.manage'))
            ->viewData('allTasks')->pluck('title')->sort()->values()->all();

        $this->assertSame(['Encoder, OIC ang gumawa', 'Marketing, hindi assigned', 'Walang dept, assigned'], $titles);
    }
}
