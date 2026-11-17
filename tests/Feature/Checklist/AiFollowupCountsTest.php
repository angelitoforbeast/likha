<?php

namespace Tests\Feature\Checklist;

use App\Models\User;
use App\Services\ChecklistBadge;
use App\Services\ChecklistDueToday;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * AF-4: ang follow-up ay hindi checklist item. Bawat bilang at listahan ng task (ChecklistDueToday, badge, staff feed,
 * /checklist, report, range report, manage, history) ay pareho may follow-up rows man o wala, sa bawat review status.
 */
class AiFollowupCountsTest extends AiFollowupTestCase
{
    private User $ceo;

    private User $joy;

    private User $oic;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::table('users', function (Blueprint $t) {
            $t->timestamp('last_seen_at')->nullable();
            $t->string('last_seen_path')->nullable();
            $t->timestamp('last_login_at')->nullable();
        });
        config(['services.staff_feed.key' => 'feed-key']);

        $this->ceo = $this->ceo();
        $this->joy = $this->marketer();
        $this->oic = $this->marketer('Marketing - OIC', 'Ben Cruz');
        $late = $this->lateTask($this->joy, ['department' => 'Marketing']);
        $late->assignedUsers()->sync([$this->joy->id, $this->oic->id]);
        $fix = $this->lateTask($this->joy, ['title' => 'Reply to comments', 'deadline_time' => '18:00']);
        $this->approval($this->submission($fix, $this->joy, '2026-11-18'), 'not_approved', '2026-11-18 14:00:00');
        $this->lateTask($this->joy, ['title' => 'Open one', 'deadline_time' => null]);
        $this->lateTask($this->joy, ['title' => 'Once', 'frequency' => 'once', 'deadline_time' => '16:20']);
    }

    private function snapshot(): array
    {
        Cache::flush();
        $day = now()->startOfDay();
        $feed = $this->withHeader('X-STAFF-FEED-KEY', 'feed-key')->getJson('/api/staff-status')->assertOk()->json();
        unset($feed['generated_at']);

        $index = $this->actingAs($this->joy)->get('/checklist')->assertOk();
        $report = $this->actingAs($this->ceo)->get('/checklist/report?date=2026-11-18')->assertOk();
        $range = $this->actingAs($this->ceo)->get('/checklist/report?view=range&from=2026-11-16&to=2026-11-18')->assertOk();
        $manage = $this->actingAs($this->ceo)->get('/checklist/manage')->assertOk();
        $manageOic = $this->actingAs($this->oic)->get('/checklist/manage')->assertOk();
        $history = $this->actingAs($this->ceo)->get('/checklist/manage/history')->assertOk();

        return [
            'due'        => ChecklistDueToday::rows([$this->joy->id, $this->oic->id, $this->ceo->id], $day),
            'badge_joy'  => ChecklistBadge::forUser($this->joy),
            'badge_ceo'  => ChecklistBadge::forUser($this->ceo),
            'badge_oic'  => ChecklistBadge::forUser($this->oic),
            'feed'       => $feed,
            'index'      => [$index->viewData('tasks')->pluck('id')->all(), $index->viewData('counts'), $index->viewData('totalTasks'), $index->viewData('doneCount')],
            'report'     => [$report->viewData('tasks')->pluck('id')->all(), $report->viewData('totalTasks'), $report->viewData('doneCount')],
            'range'      => [$range->viewData('summary'), collect($range->viewData('taskRows'))->toArray()],
            'manage'     => [$manage->viewData('allTasks')->pluck('id')->all(), $manageOic->viewData('allTasks')->pluck('id')->all()],
            'history'    => [$history->viewData('rows') ? collect($history->viewData('rows'))->count() : null],
            'max_sort'   => \App\Models\ChecklistTask::max('sort_order'),
        ];
    }

    public function test_every_count_and_list_is_the_same_with_followups_present(): void
    {
        $before = $this->snapshot();
        $this->assertNotEmpty($before['due']);
        $this->assertGreaterThan(0, $before['badge_joy']['count']);

        $task = \App\Models\ChecklistTask::where('title', 'Post 3 stories on the main page')->sole();
        $this->followup($this->joy, $task, ['description' => 'Draft one', 'sort_order' => 99]);
        $this->reviewed($this->followup($this->joy, $task, ['description' => 'Approved one']), $this->ceo, 'approved');
        $this->reviewed($this->followup($this->oic, $task, ['description' => 'Rejected one']), $this->ceo, 'rejected', ['review_note' => 'No']);
        $this->followup($this->joy, $task, ['description' => 'Expired one', 'review_status' => 'expired']);
        $this->reviewed($this->followup($this->joy, $task, ['description' => 'Edited', 'deadline_time' => '15:00']), $this->ceo, 'approved');

        $after = $this->snapshot();

        foreach (array_keys($before) as $key) {
            $this->assertEquals($before[$key], $after[$key], "$key changed with follow-up rows present");
        }
    }
}
