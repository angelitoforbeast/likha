<?php

namespace Tests\Feature\Checklist;

use App\Models\ChecklistTask;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ang Marketing at Marketing - OIC ay puwedeng mag-edit ng task na naka-assign sa kanila o sila
 * ang gumawa, kahit blangko ang department o ibang department. Ang pagbura at bulk ay hindi.
 */
class ChecklistMarketingEditTest extends ChecklistTestCase
{
    private function edit(ChecklistTask $task, array $assignedIds, string $prompt = 'Dapat kita ang resibo.'): array
    {
        return [
            'title'            => $task->title,
            'type'             => 'any',
            'approval_prompt'  => $prompt,
            'assigned_users'   => $assignedIds,
        ];
    }

    public function test_a_marketing_user_assigned_to_a_blank_task_with_no_creator_edits_it_but_cannot_delete_it(): void
    {
        $mkt  = $this->user('Marketing');
        $task = $this->task(['title' => 'I-post ang promo', 'department' => null], [$mkt]);

        $this->actingAs($mkt)->get(route('checklist.manage'))
            ->assertOk()->assertSee('I-post ang promo')
            ->assertSee(route('checklist.history', ['task' => $task->id]), false);

        $this->actingAs($mkt)->from(route('checklist.manage'))
            ->patch(route('checklist.update-task', $task), $this->edit($task, [$mkt->id]))
            ->assertRedirect(route('checklist.manage'))->assertSessionHasNoErrors();
        $this->assertSame('Dapat kita ang resibo.', $task->fresh()->approval_prompt);
        $this->assertSame(1, DB::table('checklist_task_changes')
            ->where('checklist_task_id', $task->id)->where('user_id', $mkt->id)->where('action', 'updated')->count());

        $this->actingAs($mkt)->get(route('checklist.history', ['task' => $task->id]))->assertOk();

        $this->actingAs($mkt)->delete(route('checklist.destroy-task', $task))->assertStatus(403);
        $this->assertNotNull(ChecklistTask::find($task->id));
    }

    /** role => [naka-assign?, status ng update]. Ang task ay "Marketing Assistant", walang gumawa. */
    public static function roles(): array
    {
        return [
            'Marketing - OIC assigned'        => ['Marketing - OIC', true, 302],
            'Marketing-OIC no spaces'         => ['marketing-oic', true, 302],
            'Marketing not assigned'          => ['Marketing', false, 403],
            'Marketing - OIC not assigned'    => ['Marketing - OIC', false, 403],
            'Data Encoder assigned'           => ['Data Encoder', true, 403],
            'Marketing Assistant assigned'    => ['Marketing Assistant', true, 403],
            'CEO not assigned'                => ['CEO', false, 302],
            'OIC of the department'           => ['Marketing Assistant - OIC', false, 302],
        ];
    }

    #[DataProvider('roles')]
    public function test_who_may_edit_a_task_of_another_department(string $role, bool $assigned, int $status): void
    {
        $user = $this->user($role);
        $task = $this->task(['title' => 'Sagutin ang inquiries', 'department' => 'Marketing Assistant'], $assigned ? [$user] : []);

        $this->actingAs($user)
            ->patch(route('checklist.update-task', $task), $this->edit($task, $assigned ? [$user->id] : []))
            ->assertStatus($status);
        $this->assertSame($status === 302 ? 'Dapat kita ang resibo.' : null, $task->fresh()->approval_prompt);
    }
}
