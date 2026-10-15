<?php

namespace Tests\Feature\Checklist;

use App\Models\ChecklistTask;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Bawat pagbago sa checklist task ay may row sa checklist_task_changes (sino, ano, dati → bago),
 * at ang kasaysayan ay nakikita lang ng puwedeng mag-edit ng task.
 *
 * Fixture: isang "Ops" task (sort_order 5, type any, group) na ginawa ni creator at naka-assign kay owner.
 */
class ChecklistTaskChangeLogTest extends ChecklistTestCase
{
    /** @var array<string, User> */
    private array $actors;
    private ChecklistTask $theTask;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actors = [
            'ceo'       => $this->user('CEO', null, 'Boss'),
            'oic_same'  => $this->user('Ops - OIC', 'Ops'),
            'oic_other' => $this->user('Sales - OIC', 'Sales'),
            'creator'   => $this->user('Staff', 'Ops'),
            'staff'     => $this->user('Staff', 'Ops'),
            'owner'     => $this->user('Staff', 'Ops'),
        ];
        $this->theTask = $this->task([
            'department' => 'Ops',
            'created_by' => $this->actors['creator']->id,
            'sort_order' => 5,
        ], [$this->actors['owner']]);
    }

    /** route => [action, task ('same' | 'new'), changes] */
    public static function writes(): array
    {
        return [
            'store-task'           => ['store-task', 'created', 'new', ['title' => [null, 'Bagong gawain'], 'type' => [null, 'any']]],
            'update-task'          => ['update-task', 'updated', 'same', ['title' => ['Linisin ang bodega', 'Pinalitang pangalan']]],
            'disable'              => ['disable', 'deactivated', 'same', ['is_active' => [true, false]]],
            'destroy-task'         => ['destroy-task', 'deleted', 'same', ['deleted' => [false, true]]],
            'duplicate-task'       => ['duplicate-task', 'duplicated', 'new', ['title' => [null, 'Linisin ang bodega (Copy)']]],
            'reorder'              => ['reorder', 'reordered', 'same', ['sort_order' => [5, 0]]],
            'bulk-assign'          => ['bulk-assign', 'bulk_assigned', 'same', ['assigned_users' => ['owner', 'staff']]],
            'bulk-type'            => ['bulk-type', 'bulk_type', 'same', ['type' => ['any', 'photo']]],
            'bulk-submission-type' => ['bulk-submission-type', 'bulk_submission_type', 'same', ['submission_type' => ['group', 'individual']]],
        ];
    }

    #[DataProvider('writes')]
    public function test_each_task_write_logs_who_did_it_and_what_changed(string $route, string $action, string $which, array $expected): void
    {
        $ceo = $this->actors['ceo'];
        $this->hit($route, $ceo)->assertSessionHasNoErrors();

        $rows = DB::table('checklist_task_changes')->get();
        $this->assertCount(1, $rows, "$route: isang row");
        $row = $rows->first();
        $this->assertSame($action, $row->action);
        $this->assertSame($ceo->id, (int) $row->user_id);
        $this->assertNotNull($row->created_at);
        $taskId = $which === 'same' ? $this->theTask->id : ChecklistTask::max('id');
        $this->assertSame($taskId, (int) $row->checklist_task_id);

        $changes = json_decode($row->changes, true);
        if ($route === 'bulk-assign') {
            $expected = ['assigned_users' => [[$this->actors['owner']->id], [$this->actors['staff']->id]]];
        }
        if ($action === 'created' || $action === 'duplicated') {
            // Ang bagong task: lahat ng naitakda ay [null, value]; tinitingnan ang mga mahalaga.
            foreach ($expected as $field => $pair) {
                $this->assertSame($pair, $changes[$field] ?? null, "$route: $field");
            }
        } else {
            $this->assertSame($expected, $changes);
        }
    }

    public function test_a_save_that_changes_nothing_writes_nothing(): void
    {
        $ceo  = $this->actors['ceo'];
        $task = $this->theTask;

        $this->actingAs($ceo)->patch(route('checklist.update-task', $task), [
            'title' => $task->title, 'type' => $task->type, 'is_active' => '1',
            'submission_type' => 'group', 'frequency' => 'daily', 'required_photos' => '0',
            'assigned_users' => [$this->actors['owner']->id],
        ])->assertSessionHasNoErrors();
        $this->actingAs($ceo)->post(route('checklist.bulk-type'), ['task_ids' => [$task->id], 'type' => 'any']);
        $this->actingAs($ceo)->post(route('checklist.bulk-assign'), ['task_ids' => [$task->id], 'user_ids' => [$this->actors['owner']->id]]);
        $this->actingAs($ceo)->postJson(route('checklist.reorder'), ['order' => [5 => $task->id]]);

        $this->assertSame(0, DB::table('checklist_task_changes')->count());
    }

    public function test_an_edit_that_also_switches_the_task_off_logs_deactivated_with_every_change(): void
    {
        $this->actingAs($this->actors['oic_same'])->patch(route('checklist.update-task', $this->theTask), [
            'title' => 'Bagong pangalan', 'type' => 'any', 'is_active' => '0',
            'assigned_users' => [$this->actors['owner']->id, $this->actors['staff']->id],
        ])->assertSessionHasNoErrors();

        $row = DB::table('checklist_task_changes')->sole();
        $this->assertSame('deactivated', $row->action);
        $this->assertSame([
            'title'          => ['Linisin ang bodega', 'Bagong pangalan'],
            'is_active'      => [true, false],
            'assigned_users' => [[$this->actors['owner']->id], [$this->actors['staff']->id, $this->actors['owner']->id]],   // ayos ayon sa id
        ], json_decode($row->changes, true));
    }

    public static function refusedWrites(): array
    {
        return [
            'update-task as staff'  => ['update-task', 'staff'],
            'disable as oic_other'  => ['disable', 'oic_other'],
            'destroy-task as staff' => ['destroy-task', 'staff'],
            'reorder as creator'    => ['reorder', 'creator'],
            'bulk-type as staff'    => ['bulk-type', 'staff'],
        ];
    }

    #[DataProvider('refusedWrites')]
    public function test_a_refused_write_logs_nothing(string $route, string $actor): void
    {
        $this->hit($route, $this->actors[$actor])->assertStatus(403);

        $this->assertSame(0, DB::table('checklist_task_changes')->count());
    }

    /** Sino ang nakakakita ng kasaysayan ng Ops task (sa ?task= at sa buong listahan). */
    public static function historyViewers(): array
    {
        return [
            'ceo'       => ['ceo', true],
            'oic_same'  => ['oic_same', true],
            'creator'   => ['creator', true],
            'oic_other' => ['oic_other', false],
            'staff'     => ['staff', false],
            'owner'     => ['owner', false],   // naka-assign pero hindi puwedeng mag-edit
        ];
    }

    #[DataProvider('historyViewers')]
    public function test_history_is_visible_only_to_who_may_edit_the_task(string $actor, bool $sees): void
    {
        $this->hit('disable', $this->actors['ceo']);
        $sales = $this->task(['title' => 'Bilangin ang stock', 'department' => 'Sales']);
        $this->actingAs($this->actors['ceo'])->post(route('checklist.bulk-type'), ['task_ids' => [$sales->id], 'type' => 'note']);

        $viewer = $this->actors[$actor];
        $one = $this->actingAs($viewer)->get(route('checklist.history', ['task' => $this->theTask->id]));
        $all = $this->actingAs($viewer)->get(route('checklist.history'));

        $all->assertOk();
        if ($sees) {
            $one->assertOk()->assertSee('Disabled');
            $all->assertSee('Linisin ang bodega');
        } else {
            $one->assertStatus(403);
            $all->assertDontSee('Linisin ang bodega');
        }
        // Ang Sales task ay sa CEO at sa OIC ng Sales lang.
        in_array($actor, ['ceo', 'oic_other'], true)
            ? $all->assertSee('Bilangin ang stock')
            : $all->assertDontSee('Bilangin ang stock');
    }

    public function test_history_shows_who_when_and_before_to_after_with_readable_labels_newest_first(): void
    {
        $ceo = $this->actors['ceo'];
        $this->travelTo(now()->subDays(3));
        $this->hit('bulk-assign', $ceo);
        $this->travelBack();
        $this->hit('disable', $ceo);

        $page = $this->actingAs($ceo)->get(route('checklist.history', ['task' => $this->theTask->id]));

        $page->assertOk()->assertSeeInOrder([
            $ceo->name, 'Disabled', 'Active', 'Yes', '→', 'No',
            'Bulk assign', 'Assigned to', $this->actors['owner']->name, '→', $this->actors['staff']->name,
        ]);
    }

    public function test_the_history_page_filters_by_date_range_and_by_user(): void
    {
        $this->travelTo(now()->subDays(10));
        $this->hit('bulk-type', $this->actors['ceo']);
        $this->travelBack();
        $this->hit('bulk-submission-type', $this->actors['oic_same']);

        $ceo = $this->actors['ceo'];
        $this->actingAs($ceo)->get(route('checklist.history'))
            ->assertSee('Bulk submission type')->assertSee('Bulk type');
        $this->actingAs($ceo)->get(route('checklist.history', ['from' => now()->subDays(2)->toDateString()]))
            ->assertSee('Bulk submission type')->assertDontSee('Bulk type<', false);
        $this->actingAs($ceo)->get(route('checklist.history', ['user' => $ceo->id]))
            ->assertSee('Bulk type<', false)->assertDontSee('Bulk submission type');
    }

    private function hit(string $route, User $user)
    {
        $task = $this->theTask;
        $as   = $this->actingAs($user);

        return match ($route) {
            'store-task'           => $as->post(route('checklist.store-task'), ['title' => 'Bagong gawain', 'type' => 'any']),
            'update-task'          => $as->patch(route('checklist.update-task', $task), [
                'title' => 'Pinalitang pangalan', 'type' => 'any', 'assigned_users' => [$this->actors['owner']->id],
            ]),
            // Ang "Disable" button ng manage page: buong form na naka-hidden, iba lang ang is_active.
            'disable'              => $as->patch(route('checklist.update-task', $task), [
                'title' => $task->title, 'type' => $task->type, 'is_active' => '0', 'submission_type' => 'group',
                'frequency' => 'daily', 'required_photos' => '0', 'department' => 'Ops',
                'assigned_users' => [$this->actors['owner']->id],
            ]),
            'destroy-task'         => $as->delete(route('checklist.destroy-task', $task)),
            'duplicate-task'       => $as->post(route('checklist.duplicate-task', $task)),
            'reorder'              => $as->postJson(route('checklist.reorder'), ['order' => [$task->id]]),
            'bulk-assign'          => $as->post(route('checklist.bulk-assign'), ['task_ids' => [$task->id], 'user_ids' => [$this->actors['staff']->id]]),
            'bulk-type'            => $as->post(route('checklist.bulk-type'), ['task_ids' => [$task->id], 'type' => 'photo']),
            'bulk-submission-type' => $as->post(route('checklist.bulk-submission-type'), ['task_ids' => [$task->id], 'submission_type' => 'individual']),
        };
    }
}
