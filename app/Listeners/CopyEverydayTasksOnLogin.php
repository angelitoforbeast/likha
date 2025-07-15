<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Auth;
use App\Models\EverydayTask;
use App\Models\Task;
use Carbon\Carbon;

class CopyEverydayTasksOnLogin
{
    public function handle(Login $event): void
    {
        $user = $event->user;
        $today = Carbon::today();

        $everydayTasks = EverydayTask::where('user_id', $user->id)->get();

        foreach ($everydayTasks as $task) {
            $exists = Task::where('user_id', $user->id)
                ->whereDate('due_date', $today)
                ->where('task_name', $task->task_name)
                ->exists();

            if (!$exists) {
                Task::create([
    'user_id' => $user->id,
    'name' => $task->name,
    'task_name' => $task->task_name,
    'description' => $task->description,
    'type' => $task->type,
    'priority_score' => $task->priority_score,
    'due_date' => $today,
    'due_time' => $task->due_time,
    'status' => 'pending',
    'created_by' => $task->created_by ?? $user->id,
    'is_repeating' => $task->is_repeating,
    'role_target' => $task->role_target ?? 'default_value',  // <-- dagdag dito, palitan ng tamang value o default
]);


            }
        }
    }
}
