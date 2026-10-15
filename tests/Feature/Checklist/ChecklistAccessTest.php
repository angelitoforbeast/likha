<?php

namespace Tests\Feature\Checklist;

use App\Models\ChecklistSubmission;
use App\Models\ChecklistTask;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Sino ang puwedeng gumawa ng ano sa /checklist (server-side, hindi lang sa listahan).
 *
 * Fixture: isang task ng "Ops" department na ginawa ni creator at naka-assign kay owner,
 * at isang submission ni owner ngayong araw.
 */
class ChecklistAccessTest extends ChecklistTestCase
{
    /** @var array<string, User> */
    private array $actors;
    private ChecklistTask $theTask;
    private ChecklistSubmission $theSubmission;

    private const AJAX = ['reorder', 'analyze', 'analysis-logs', 'approval-check', 'approval-logs'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actors = [
            'ceo'       => $this->user('CEO'),
            'oic_same'  => $this->user('Ops - OIC', 'Ops'),
            'oic_other' => $this->user('Sales - OIC', 'Sales'),
            'creator'   => $this->user('Staff', 'Ops'),
            'staff'     => $this->user('Staff', 'Ops'),
            'owner'     => $this->user('Staff', 'Ops'),
        ];
        $this->theTask = $this->task([
            'department' => 'Ops',
            'created_by' => $this->actors['creator']->id,
        ], [$this->actors['owner']]);
        $this->theSubmission = $this->submission($this->theTask, $this->actors['owner'], now()->toDateString());
    }

    /** route => mga actor na pinapayagan; ang iba ay 403. */
    public static function accessMatrix(): array
    {
        $rules = [
            'store-task'           => ['ceo', 'oic_same', 'oic_other'],
            'update-task'          => ['ceo', 'oic_same', 'creator'],
            'destroy-task'         => ['ceo', 'oic_same', 'creator'],
            'duplicate-task'       => ['ceo', 'oic_same', 'creator'],
            'reorder'              => ['ceo', 'oic_same'],
            'bulk-assign'          => ['ceo', 'oic_same'],
            'bulk-type'            => ['ceo', 'oic_same'],
            'bulk-submission-type' => ['ceo', 'oic_same'],
            'analyze'              => ['ceo', 'oic_same', 'owner'],
            'analysis-logs'        => ['ceo', 'oic_same', 'owner'],
            'approval-check'       => ['ceo', 'oic_same', 'owner'],
            'approval-logs'        => ['ceo', 'oic_same', 'owner'],
        ];
        $cases = [];
        foreach ($rules as $route => $allowed) {
            foreach (['ceo', 'oic_same', 'oic_other', 'creator', 'staff', 'owner'] as $actor) {
                $cases["$route as $actor"] = [$route, $actor, in_array($actor, $allowed, true)];
            }
        }

        return $cases;
    }

    #[DataProvider('accessMatrix')]
    public function test_each_checklist_write_and_ai_route_allows_only_its_roles(string $route, string $actor, bool $allowed): void
    {
        $before = $this->fingerprint();

        $response = $this->hit($route, $this->actors[$actor]);

        if ($allowed) {
            $this->assertContains($response->status(), [200, 302], "$route as $actor dapat payagan");
            return;
        }

        $response->assertStatus(403);
        if (in_array($route, self::AJAX, true)) {
            $response->assertJson(['ok' => false]);
            $this->assertNotEmpty($response->json('message'));
        }
        $this->assertSame($before, $this->fingerprint(), "$route as $actor: walang dapat nagbago");
        Http::assertNothingSent();
    }

    public function test_bulk_change_by_an_oic_is_refused_whole_when_one_task_is_of_another_department(): void
    {
        $sales = $this->task(['title' => 'Bilangin ang stock', 'department' => 'Sales']);
        $before = $this->fingerprint();

        $this->actingAs($this->actors['oic_same'])
            ->post(route('checklist.bulk-type'), ['task_ids' => [$this->theTask->id, $sales->id], 'type' => 'photo'])
            ->assertStatus(403);

        $this->assertSame($before, $this->fingerprint());
    }

    /** Iisang CEO comparison: trimmed at case-insensitive, pati sa pagbura ng submission. */
    public static function ceoSpellings(): array
    {
        return [
            'CEO'        => ['CEO', 302],
            'lowercase'  => [' ceo ', 302],
            'plain staff' => ['Staff', 403],
        ];
    }

    #[DataProvider('ceoSpellings')]
    public function test_deleting_someone_elses_submission_needs_the_ceo_role_in_any_spelling(string $role, int $status): void
    {
        $this->actingAs($this->user($role))
            ->delete(route('checklist.delete-submission', $this->theSubmission))
            ->assertStatus($status);
    }

    public function test_the_read_pages_stay_open_to_plain_staff(): void
    {
        $staff = $this->actors['owner'];
        foreach (['checklist.index', 'checklist.report', 'checklist.manage'] as $name) {
            $this->actingAs($staff)->get(route($name))->assertOk();
        }
    }

    private function hit(string $route, User $user)
    {
        $task = $this->theTask;
        $sub  = $this->theSubmission;
        $as   = $this->actingAs($user);

        return match ($route) {
            'store-task'           => $as->post(route('checklist.store-task'), ['title' => 'Bagong gawain', 'type' => 'any']),
            'update-task'          => $as->patch(route('checklist.update-task', $task), ['title' => 'Pinalitang pangalan', 'type' => 'any']),
            'destroy-task'         => $as->delete(route('checklist.destroy-task', $task)),
            'duplicate-task'       => $as->post(route('checklist.duplicate-task', $task)),
            'reorder'              => $as->postJson(route('checklist.reorder'), ['order' => [$task->id]]),
            'bulk-assign'          => $as->post(route('checklist.bulk-assign'), ['task_ids' => [$task->id], 'user_ids' => [$this->actors['staff']->id]]),
            'bulk-type'            => $as->post(route('checklist.bulk-type'), ['task_ids' => [$task->id], 'type' => 'photo']),
            'bulk-submission-type' => $as->post(route('checklist.bulk-submission-type'), ['task_ids' => [$task->id], 'submission_type' => 'individual']),
            // Gaya ng page: fetch() na walang Accept header.
            'analyze'              => $as->post(route('checklist.analyze', $sub), [], ['Content-Type' => 'application/json']),
            'analysis-logs'        => $as->get(route('checklist.analysis-logs', $sub)),
            'approval-check'       => $as->post(route('checklist.approval-check', $sub), [], ['Content-Type' => 'application/json']),
            'approval-logs'        => $as->get(route('checklist.approval-logs', $sub)),
        };
    }

    private function fingerprint(): string
    {
        return json_encode([
            DB::table('checklist_tasks')->orderBy('id')->get(),
            DB::table('checklist_task_users')->orderBy('id')->get(['checklist_task_id', 'user_id']),
            DB::table('checklist_analysis_logs')->count(),
        ]);
    }
}
