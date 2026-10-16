<?php

namespace Tests\Feature\Checklist;

use App\Models\ChecklistSubmission;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * /checklist — nakikita ng staff ang pinakahuling verdict ng AI check sa submission ngayong araw.
 */
class ChecklistVerdictTest extends ChecklistTestCase
{
    private const TODAY = '2026-10-15';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::TODAY . ' 09:00:00');
    }

    private function approvalText(ChecklistSubmission $submission, ?string $verdict, string $text, string $at): void
    {
        DB::table('checklist_analysis_logs')->insert([
            'log_type'        => 'approval',
            'submission_id'   => $submission->id,
            'analysis_result' => $text,
            'verdict'         => $verdict,
            'created_at'      => $at,
            'updated_at'      => $at,
        ]);
    }

    private function states($response): array
    {
        return collect($response->viewData('statusByTask'))->map(fn ($s) => $s['state'])->all();
    }

    public function test_approved_submission_shows_approved(): void
    {
        $staff = $this->user('Staff');
        $task  = $this->task(['title' => 'Bilangin ang stock'], [$staff]);
        $sub   = $this->submission($task, $staff, self::TODAY);
        $this->approval($sub, 'not_approved', self::TODAY . ' 08:00:00');
        $this->approval($sub, 'approved', self::TODAY . ' 08:30:00');

        $response = $this->actingAs($staff)->get(route('checklist.index'));

        $response->assertOk()->assertSee('APPROVED')->assertDontSee('Kailangang ayusin');
        $this->assertSame([$task->id => 'approved'], $this->states($response));
    }

    public function test_not_approved_submission_shows_needs_fix_with_the_escaped_reason_cut_at_300(): void
    {
        $staff  = $this->user('Staff');
        $task   = $this->task([], [$staff]);
        $sub    = $this->submission($task, $staff, self::TODAY);
        $reason = "NOT APPROVED\n<b>Kulang</b> ang screenshot ng bank balance. " . str_repeat('x', 400);
        $this->approvalText($sub, 'not_approved', $reason, self::TODAY . ' 08:30:00');

        $response = $this->actingAs($staff)->get(route('checklist.index'));

        $response->assertOk()
            ->assertSee('Kailangang ayusin')
            ->assertSee('&lt;b&gt;Kulang&lt;/b&gt; ang screenshot', false)
            ->assertDontSee('<b>Kulang</b>', false)
            ->assertDontSee(str_repeat('x', 290), false);
        $status = $response->viewData('statusByTask')[$task->id];
        $this->assertSame('needs_fix', $status['state']);
        $this->assertSame(mb_substr($reason, 0, 300), $status['reason']);
    }

    public function test_submission_without_a_verdict_shows_still_checking(): void
    {
        $staff   = $this->user('Staff');
        $noLog   = $this->task(['title' => 'Walang log'], [$staff]);
        $unknown = $this->task(['title' => 'Hindi malinaw'], [$staff]);
        $this->submission($noLog, $staff, self::TODAY);
        $this->approvalText($this->submission($unknown, $staff, self::TODAY), 'unknown', 'Hmm.', self::TODAY . ' 08:30:00');

        $response = $this->actingAs($staff)->get(route('checklist.index'));

        $response->assertOk()->assertSee('Sinusuri pa');
        $this->assertSame([$noLog->id => 'checking', $unknown->id => 'checking'], $this->states($response));
    }

    public function test_counts_split_and_all_completed_only_when_every_task_is_approved(): void
    {
        $staff    = $this->user('Staff');
        $approved = $this->task(['title' => 'A'], [$staff]);
        $fix      = $this->task(['title' => 'B'], [$staff]);
        $open     = $this->task(['title' => 'C'], [$staff]);
        $this->approval($this->submission($approved, $staff, self::TODAY), 'approved', self::TODAY . ' 08:00:00');
        $fixSub = $this->submission($fix, $staff, self::TODAY);
        $this->approval($fixSub, 'not_approved', self::TODAY . ' 08:00:00');

        $response = $this->actingAs($staff)->get(route('checklist.index'));
        $response->assertOk()->assertDontSee('All tasks completed');
        $this->assertSame(
            ['approved' => 1, 'needsFix' => 1, 'checking' => 0, 'notSubmitted' => 1],
            $response->viewData('counts')
        );

        // Lahat ay may submission pero may isang hindi approved: hindi pa "completed"
        $this->approval($this->submission($open, $staff, self::TODAY), 'approved', self::TODAY . ' 08:00:00');
        $this->actingAs($staff)->get(route('checklist.index'))->assertDontSee('All tasks completed');

        $this->approval($fixSub, 'approved', self::TODAY . ' 08:45:00');
        $this->actingAs($staff)->get(route('checklist.index'))->assertSee('All tasks completed');
    }

    public function test_needs_fix_and_not_submitted_sort_before_checking_and_approved(): void
    {
        $staff    = $this->user('Staff');
        $approved = $this->task(['title' => 'A', 'sort_order' => 1], [$staff]);
        $checking = $this->task(['title' => 'B', 'sort_order' => 2], [$staff]);
        $fix      = $this->task(['title' => 'C', 'sort_order' => 3], [$staff]);
        $open     = $this->task(['title' => 'D', 'sort_order' => 4], [$staff]);
        $this->approval($this->submission($approved, $staff, self::TODAY), 'approved', self::TODAY . ' 08:00:00');
        $this->submission($checking, $staff, self::TODAY);
        $this->approval($this->submission($fix, $staff, self::TODAY), 'not_approved', self::TODAY . ' 08:00:00');

        $response = $this->actingAs($staff)->get(route('checklist.index'));

        $this->assertSame(
            [$fix->id, $open->id, $checking->id, $approved->id],
            $response->viewData('tasks')->pluck('id')->all()
        );
    }
}
