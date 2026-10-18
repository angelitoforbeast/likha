<?php

namespace Tests\Feature\Checklist;

use App\Models\ChecklistTask;
use Illuminate\Support\Facades\DB;

/**
 * Ang deadline ng task (deadline_time): naitatakda sa /checklist/manage ng parehong mga puwedeng
 * mag-edit ng task, at may row sa checklist_task_changes gaya ng ibang field.
 */
class ChecklistDeadlineFieldTest extends ChecklistTestCase
{
    private function form(ChecklistTask $task, ?string $deadline): array
    {
        return [
            'title'          => $task->title,
            'type'           => 'any',
            'scheduled_time' => '10:00',
            'deadline_time'  => $deadline,
        ];
    }

    private function changes(ChecklistTask $task): array
    {
        return DB::table('checklist_task_changes')->where('checklist_task_id', $task->id)->pluck('changes')
            ->map(fn ($c) => json_decode($c, true))->all();
    }

    public function test_S_051_1_a_ceo_sets_the_deadline_and_it_is_saved_shown_and_logged(): void
    {
        $ceo  = $this->user('CEO');
        $task = $this->task(['title' => 'Bilangin ang stock', 'scheduled_time' => '10:00']);

        $this->actingAs($ceo)->from(route('checklist.manage'))
            ->patch(route('checklist.update-task', $task), $this->form($task, '10:30'))
            ->assertRedirect(route('checklist.manage'))->assertSessionHasNoErrors();

        $this->assertSame('10:30', $task->fresh()->deadline_time);
        $this->assertSame([['deadline_time' => [null, '10:30']]], $this->changes($task));
        $this->actingAs($ceo)->get(route('checklist.manage'))->assertOk()
            ->assertSee('name="deadline_time" value="10:30"', false)
            ->assertSee('⏰ 10:30 AM');
        $this->actingAs($ceo)->get(route('checklist.history', ['task' => $task->id]))->assertOk()->assertSee('Deadline');
    }

    public function test_S_051_2_a_marketing_user_without_edit_rights_cannot_set_it(): void
    {
        $mkt  = $this->user('Marketing');
        $task = $this->task(['title' => 'Bilangin ang stock', 'scheduled_time' => '10:00']);

        $this->actingAs($mkt)->patch(route('checklist.update-task', $task), $this->form($task, '10:30'))
            ->assertStatus(403);

        $this->assertNull($task->fresh()->deadline_time);
        $this->assertSame([], $this->changes($task));
    }

    public function test_S_051_3_clearing_the_deadline_saves_null_and_logs_it(): void
    {
        $ceo  = $this->user('CEO');
        $task = $this->task(['title' => 'Bilangin ang stock', 'scheduled_time' => '10:00', 'deadline_time' => '10:30']);

        $this->actingAs($ceo)->patch(route('checklist.update-task', $task), $this->form($task, ''))
            ->assertSessionHasNoErrors();

        $this->assertNull($task->fresh()->deadline_time);
        $this->assertSame([['deadline_time' => ['10:30', null]]], $this->changes($task));
    }

    public function test_S_051_4_an_invalid_time_is_refused_with_the_forms_validation_message(): void
    {
        $ceo  = $this->user('CEO');
        $task = $this->task(['title' => 'Bilangin ang stock', 'scheduled_time' => '10:00', 'deadline_time' => '10:30']);

        foreach (['25:00', 'bukas', '10:30 PM; DROP'] as $bad) {
            $this->actingAs($ceo)->from(route('checklist.manage'))
                ->patch(route('checklist.update-task', $task), $this->form($task, $bad))
                ->assertRedirect(route('checklist.manage'))
                ->assertSessionHasErrors(['deadline_time' => 'Ang Deadline ay dapat oras, hal. 10:30.']);
            $this->actingAs($ceo)->post(route('checklist.store-task'), $this->form($task, $bad))
                ->assertSessionHasErrors('deadline_time');
        }

        $this->assertSame('10:30', $task->fresh()->deadline_time);
        $this->assertSame(1, ChecklistTask::count());
        $this->assertSame([], $this->changes($task));
    }

    public function test_a_new_task_and_its_copy_keep_the_deadline(): void
    {
        $ceo = $this->user('CEO');

        $this->actingAs($ceo)->post(route('checklist.store-task'), [
            'title' => 'Bagong gawain', 'type' => 'any', 'scheduled_time' => '09:00', 'deadline_time' => '09:40',
        ])->assertSessionHasNoErrors();
        $task = ChecklistTask::firstWhere('title', 'Bagong gawain');
        $this->assertSame('09:40', $task->deadline_time);
        $this->assertSame([null, '09:40'], $this->changes($task)[0]['deadline_time'] ?? null);

        $this->actingAs($ceo)->post(route('checklist.duplicate-task', $task));
        $this->assertSame('09:40', ChecklistTask::firstWhere('title', 'Bagong gawain (Copy)')->deadline_time);
    }
}
