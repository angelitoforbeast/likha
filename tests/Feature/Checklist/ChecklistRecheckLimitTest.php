<?php

namespace Tests\Feature\Checklist;

use App\Models\ChecklistSubmission;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Hanggang 3 approval check lang kada submission (kahit sino ang nagpatakbo, kasama ang awtomatiko
 * sa pagsumite; hindi bilang ang override). Lampas doon, ang CEO at ang OIC ng department ng nagsumite lang ang puwedeng
 * mag-check ulit o mag-override ng verdict (may dahilan, walang AI call).
 */
class ChecklistRecheckLimitTest extends ChecklistTestCase
{
    private const LOCKED = 'Naka-3 check na; OIC o CEO na lang ang pwedeng mag-check o mag-override.';

    private User $mkt;
    private ChecklistSubmission $sub;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-16 11:00:00');
        $this->mkt = $this->user('Marketing');
        $task = $this->task(['title' => 'I-post ang promo'], [$this->mkt]);
        $this->sub = $this->submission($task, $this->mkt, '2026-10-16');
    }

    private function checks(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->approval($this->sub, 'not_approved', '2026-10-16 10:0' . $i . ':00');
        }
    }

    private function check(User $as)
    {
        return $this->actingAs($as)->postJson(route('checklist.approval-check', $this->sub));
    }

    private function override(User $as, array $body)
    {
        return $this->actingAs($as)->postJson(route('checklist.approval-override', $this->sub), $body);
    }

    private function approvalRows(): int
    {
        return DB::table('checklist_analysis_logs')->where('submission_id', $this->sub->id)->where('log_type', 'approval')->count();
    }

    public function test_S_051_24_after_the_automatic_check_a_marketing_users_2nd_and_3rd_checks_run_and_the_4th_is_refused_on_the_server_and_the_page(): void
    {
        $this->checks(1);   // ang awtomatikong check sa pagsumite
        $this->check($this->mkt)->assertOk();
        $this->check($this->mkt)->assertOk();
        $this->check($this->mkt)->assertStatus(403)->assertJson(['ok' => false, 'message' => self::LOCKED]);

        $this->assertSame(3, $this->approvalRows());
        Http::assertSentCount(2);
        $this->actingAs($this->mkt)->get(route('checklist.report', ['date' => '2026-10-16']))->assertOk()
            ->assertSee('canBypassChecks: false', false)
            ->assertSee('data-check-locked', false)
            ->assertSee(self::LOCKED)
            ->assertDontSee('approval-override', false);
    }

    public function test_S_051_30_an_override_does_not_count_toward_the_3_checks(): void
    {
        $this->checks(2);
        $this->override($this->user('CEO'), ['verdict' => 'not_approved', 'reason' => 'Malabo ang litrato.'])->assertOk();

        $this->check($this->mkt)->assertOk();
        $this->check($this->mkt)->assertStatus(403);
        $this->assertSame(4, $this->approvalRows());
    }

    public function test_a_limited_check_is_refused_while_another_check_of_the_same_submission_runs(): void
    {
        $this->checks(1);
        $running = \Illuminate\Support\Facades\Cache::lock('checklist-approval:' . $this->sub->id, 60);
        $this->assertTrue($running->get());

        $this->check($this->mkt)->assertStatus(409)->assertJson(['ok' => false]);
        Http::assertNothingSent();

        $running->release();
        $this->check($this->mkt)->assertOk();
        $this->assertSame(2, $this->approvalRows());
    }

    public function test_S_051_25_the_ceos_4th_and_10th_checks_run(): void
    {
        $ceo = $this->user('CEO');
        $this->checks(3);
        $this->check($ceo)->assertOk();
        $this->checks(5);
        $this->check($ceo)->assertOk();

        $this->assertSame(10, $this->approvalRows());
    }

    public function test_S_051_26_the_departments_oic_runs_a_4th_check_and_overrides_with_a_reason_but_not_without(): void
    {
        $oic = $this->user('Marketing - OIC');
        $task = $this->sub->task;
        $task->assignedUsers()->attach($oic->id);   // para makita niya ang task sa report
        $this->checks(3);

        $this->check($oic)->assertOk();
        $this->override($oic, ['verdict' => 'approved', 'reason' => ''])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->override($oic, ['verdict' => 'maybe', 'reason' => 'Kita ang resibo'])->assertStatus(422)->assertJsonValidationErrors('verdict');
        $this->override($oic, ['verdict' => 'approved', 'reason' => 'Kita ang resibo sa pangalawang litrato.'])->assertOk()
            ->assertJson(['verdict' => 'approved', 'is_override' => true]);

        $this->assertSame(5, $this->approvalRows());
        Http::assertSentCount(1);
        $this->actingAs($oic)->get(route('checklist.report', ['date' => '2026-10-16']))->assertOk()
            ->assertSee('canBypassChecks: true', false)
            ->assertDontSee('data-check-locked', false)
            ->assertSee('approval-override', false);
    }

    public function test_S_051_27_an_oic_of_another_department_and_a_plain_staff_member_cannot_override(): void
    {
        $other = $this->user('Data Encoder - OIC');
        $this->checks(3);

        $this->override($other, ['verdict' => 'approved', 'reason' => 'Ok na'])->assertStatus(403);
        $this->override($this->mkt, ['verdict' => 'approved', 'reason' => 'Ok na'])->assertStatus(403);
        $this->check($other)->assertStatus(403);

        $this->assertSame(3, $this->approvalRows());
    }

    public function test_S_051_28_the_override_row_is_in_the_approval_logs_with_the_marker_who_and_reason_and_the_latest_verdict_follows_it(): void
    {
        $ceo = $this->user('CEO', null, 'Boss');
        $this->checks(2);

        $this->override($ceo, ['verdict' => 'approved', 'reason' => 'Tama ang bilang.'])->assertOk();

        $logs = $this->actingAs($ceo)->getJson(route('checklist.approval-logs', $this->sub))->assertOk()->json('logs');
        $this->assertSame(
            ['verdict' => 'approved', 'analysis' => 'Tama ang bilang.', 'user' => $ceo->name, 'is_override' => true],
            array_intersect_key($logs[0], array_flip(['verdict', 'analysis', 'user', 'is_override']))
        );
        $this->assertFalse($logs[1]['is_override']);
        $this->assertSame('approved', $this->sub->fresh()->latestApproval->verdict);
        Http::assertNothingSent();
    }
}
