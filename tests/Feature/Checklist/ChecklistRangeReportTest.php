<?php

namespace Tests\Feature\Checklist;

use App\Models\ChecklistTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * /checklist/report?view=range — ang "Over several days" view.
 *
 * Range ng karamihan ng test: Lun 2026-10-05 hanggang Linggo 2026-10-11 (7 araw).
 * Cells: D = done, M = missing, . = not due.
 */
class ChecklistRangeReportTest extends ChecklistTestCase
{
    private const WEEK = ['from' => '2026-10-05', 'to' => '2026-10-11'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 09:00:00');
    }

    private function range(array $params = [], $as = null)
    {
        return $this->actingAs($as ?? $this->user('CEO'))
            ->get(route('checklist.report', array_merge(['view' => 'range'], self::WEEK, $params)));
    }

    private static function cells(array $row): string
    {
        return implode('', array_map(fn ($c) => ['done' => 'D', 'missing' => 'M', 'none' => '.'][$c], $row['cells']));
    }

    private static function rowOf($response, ChecklistTask $task): ?array
    {
        return collect($response->viewData('taskRows'))->first(fn ($r) => $r['task']->id === $task->id);
    }

    public static function dueRules(): array
    {
        return [
            'daily'                 => [['frequency' => 'daily'], ['2026-10-06'], 'MDMMMMM'],
            'weekly on Wednesday'   => [['frequency' => 'weekly', 'frequency_day' => 3], [], '..M....'],
            'once, done on the 8th' => [['frequency' => 'once'], ['2026-10-08'], 'MMMD...'],
            'created mid-range'     => [['created_at' => '2026-10-08 10:00:00'], [], '...MMMM'],
            'deleted mid-range'     => [['deleted_at' => '2026-10-09 10:00:00'], [], 'MMMM...'],
            'inactive'              => [['is_active' => false], [], null],
        ];
    }

    #[DataProvider('dueRules')]
    public function test_each_day_cell_follows_the_due_rules(array $attrs, array $submittedOn, ?string $expected): void
    {
        $staff = $this->user('Staff');
        $task  = $this->task(array_merge(['created_at' => '2026-09-01 08:00:00'], $attrs), [$staff]);
        foreach ($submittedOn as $date) {
            $this->submission($task, $staff, $date);
        }

        $row = self::rowOf($this->range(), $task);

        $this->assertSame($expected, $row ? self::cells($row) : null);
    }

    public function test_only_the_latest_approval_verdict_of_each_submission_counts(): void
    {
        [$a, $b, $c] = [$this->user('Staff'), $this->user('Staff'), $this->user('Staff')];
        $task = $this->task(['created_at' => '2026-09-01', 'submission_type' => 'individual'], [$a, $b, $c]);
        $this->approval($subA = $this->submission($task, $a, '2026-10-06'), 'not_approved', '2026-10-06 08:00:00');
        $this->approval($subA, 'approved', '2026-10-06 09:00:00');
        $this->approval($subB = $this->submission($task, $b, '2026-10-06'), 'approved', '2026-10-06 08:00:00');
        $this->approval($subB, 'not_approved', '2026-10-06 10:00:00');
        $this->approval($this->submission($task, $c, '2026-10-06'), 'unknown', '2026-10-06 08:00:00');

        $response = $this->range();

        $day = $response->viewData('summary')['2026-10-06'];
        $this->assertSame([1, 1, 1], [$day['approved'], $day['notApproved'], $day['unknown']]);
        $this->assertSame(33, self::rowOf($response, $task)['notApprovedPct']);
        $people = collect($response->viewData('people'))->keyBy(fn ($p) => $p['user']->id);
        $this->assertSame([1, 0], [$people[$a->id]['approved'], $people[$a->id]['notApproved']]);
        $this->assertSame([0, 1], [$people[$b->id]['approved'], $people[$b->id]['notApproved']]);
    }

    public function test_a_non_ceo_sees_only_tasks_assigned_to_them_and_their_people(): void
    {
        [$me, $coworker, $other] = [$this->user('Staff'), $this->user('Staff'), $this->user('Staff')];
        $mine   = $this->task(['title' => 'Akin', 'created_at' => '2026-09-01'], [$me, $coworker]);
        $theirs = $this->task(['title' => 'Hindi akin', 'created_at' => '2026-09-01'], [$other]);
        $this->submission($theirs, $other, '2026-10-06');

        $response = $this->range([], $me);

        $this->assertSame([$mine->id], collect($response->viewData('taskRows'))->map(fn ($r) => $r['task']->id)->all());
        $this->assertEqualsCanonicalizing([$me->id, $coworker->id], collect($response->viewData('people'))->map(fn ($p) => $p['user']->id)->all());
        $this->assertSame(0, $response->viewData('summary')['2026-10-06']['done']);
    }

    public function test_the_department_filter_keeps_only_that_departments_tasks(): void
    {
        $ops   = $this->task(['title' => 'Ops gawain', 'department' => 'Ops', 'created_at' => '2026-09-01']);
        $this->task(['title' => 'Sales gawain', 'department' => 'Sales', 'created_at' => '2026-09-01']);

        $response = $this->range(['dept' => 'ops']);

        $this->assertSame([$ops->id], collect($response->viewData('taskRows'))->map(fn ($r) => $r['task']->id)->all());
        $this->assertSame(['Ops', 'Sales'], $response->viewData('allDepts'));
    }

    public function test_tasks_sort_weakest_first_and_people_most_missed_first(): void
    {
        [$diligent, $absent] = [$this->user('Staff', null, 'Masipag'), $this->user('Staff', null, 'Liban')];
        $strong = $this->task(['title' => 'Malakas', 'created_at' => '2026-09-01'], [$diligent]);
        $weak   = $this->task(['title' => 'Mahina', 'created_at' => '2026-09-01'], [$absent]);
        foreach (['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11'] as $d) {
            $this->submission($strong, $diligent, $d);
        }
        $this->submission($weak, $absent, '2026-10-05');

        $response = $this->range();

        $rows = $response->viewData('taskRows');
        $this->assertSame([$weak->id, $strong->id], [$rows[0]['task']->id, $rows[1]['task']->id]);
        $this->assertSame([14, 100], [$rows[0]['pct'], $rows[1]['pct']]);
        $people = $response->viewData('people');
        $this->assertSame([$absent->id, 6, 7], [$people[0]['user']->id, $people[0]['missed'], $people[0]['dueDays']]);
        $this->assertSame([$diligent->id, 0], [$people[1]['user']->id, $people[1]['missed']]);
    }

    public function test_the_csv_downloads_carry_a_header_and_a_row_per_task_and_per_person(): void
    {
        $staff = $this->user('Staff', null, 'Juana');
        $task  = $this->task(['title' => 'Linisin ang bodega', 'department' => 'Ops', 'created_at' => '2026-10-10 08:00:00'], [$staff]);
        $this->approval($this->submission($task, $staff, '2026-10-10'), 'approved', '2026-10-10 09:00:00');

        $tasks = array_map('str_getcsv', explode("\n", trim($this->range(['csv' => 'tasks'])->assertOk()->streamedContent())));
        $this->assertSame(['Task', 'Department', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11',
            'Due days', 'Done', 'Completion %', 'Submissions', 'Not approved', 'Not approved %'], $tasks[0]);
        $this->assertSame(['Linisin ang bodega', 'Ops', '', '', '', '', '', 'done', 'missing', '2', '1', '50', '1', '0', '0'], $tasks[1]);

        $people = array_map('str_getcsv', explode("\n", trim($this->range(['csv' => 'people'])->assertOk()->streamedContent())));
        $this->assertSame(['Person', 'Submissions', 'Approved', 'Not approved', 'Days with a due task', 'Missed days'], $people[0]);
        $this->assertSame([$staff->name, '1', '1', '0', '2', '1'], $people[1]);
    }

    public function test_the_range_defaults_to_the_last_14_days_and_is_capped_at_62(): void
    {
        $default = $this->actingAs($this->user('CEO'))->get(route('checklist.report', ['view' => 'range']));
        $this->assertSame(['2026-10-02', '2026-10-15', 14], [$default->viewData('from'), $default->viewData('to'), count($default->viewData('days'))]);

        $long = $this->range(['from' => '2026-06-01', 'to' => '2026-10-11']);
        $this->assertSame(['2026-08-11', '2026-10-11', 62], [$long->viewData('from'), $long->viewData('to'), count($long->viewData('days'))]);
    }

    public function test_the_query_count_does_not_grow_with_days_tasks_or_people(): void
    {
        $ceo = $this->user('CEO');
        $count = function (array $params) use ($ceo) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->range($params, $ceo)->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $staff = $this->user('Staff');
        $task  = $this->task(['created_at' => '2026-09-01', 'frequency' => 'once'], [$staff]);
        $this->approval($this->submission($task, $staff, '2026-10-06'), 'approved', '2026-10-06 09:00:00');
        $count([]);   // warm-up: ang user profile at ang nav counts ay naka-cache na pagkatapos ng unang request
        $small = $count(['from' => '2026-10-05', 'to' => '2026-10-07']);

        foreach (range(1, 5) as $i) {
            $u = $this->user('Staff');
            $t = $this->task(['title' => "Gawain $i", 'created_at' => '2026-09-01'], [$u, $staff]);
            $this->approval($this->submission($t, $u, '2026-10-0' . $i), 'not_approved', "2026-10-0$i 09:00:00");
        }
        $big = $count(['from' => '2026-08-11', 'to' => '2026-10-11']);

        $this->assertSame($small, $big);
    }

    public function test_the_day_view_stays_the_default_with_a_link_to_the_range_view(): void
    {
        $this->actingAs($this->user('CEO'))->get(route('checklist.report'))
            ->assertOk()
            ->assertViewIs('checklist.report')
            ->assertSee('Over several days');
    }
}
