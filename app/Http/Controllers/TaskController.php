<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    public function index()
{
    $tasks = Task::with(['creator.employeeProfile'])->latest()->get();
    return view('tasks.index', compact('tasks'));
}


    public function updateCreatorRemarks(Request $request)
    {
        $request->validate([
            'task_id' => 'required|exists:tasks,id',
            'creator_remarks' => 'nullable|string',
        ]);

        $task = Task::findOrFail($request->task_id);
        $task->creator_remarks = $request->creator_remarks;
        $task->save();

        return back()->with('success', 'Creator remarks updated.');
    }

    public function showCreateForm()
    {
        $roles = DB::table('employee_profiles')
            ->whereNotNull('role')
            ->distinct()
            ->pluck('role');

        return view('tasks.create', compact('roles'));
    }

    public function create(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string',
            'role_target' => 'required|array',
            'priority_score' => 'required|integer|min:1|max:100',
            'due_date' => 'required|date',
            'due_time' => 'nullable|date_format:H:i', // expecting string like "13:00"
        ]);

        $roles = $request->role_target;

        $employees = DB::table('employee_profiles')
            ->whereIn('role', $roles)
            ->get();

        foreach ($employees as $employee) {
            Task::create([
                'user_id' => $employee->user_id,
                'name' => $employee->name,
                'task_name' => $request->name,
                'role_target' => implode(', ', $roles),
                'collaborators' => $request->collaborators ?? null,
                'description' => $request->description,
                'type' => $request->type,
                'is_repeating' => $request->has('is_repeating'),
                'due_date' => $request->due_date,
                'due_time' => $request->due_time,
                'reminder_at' => $request->reminder_at ?? null,
                'status' => $request->status ?? 'pending',
                'created_by' => auth()->id(),
                'remarks' => $request->remarks,
                'priority_score' => $request->priority_score,
                'parent_task_id' => $request->parent_task_id ?? null,
            ]);
        }

        return redirect()->route('task.index')->with('success', 'Tasks created for selected roles.');
    }

    public function myTasks()
{
    $statusOrder = ['pending', 'in_progress', 'completed'];

    $tasks = Task::with(['creator.employeeProfile']) // ← add this
        ->where('user_id', auth()->id())
        ->get()
        ->sortBy([
            fn($a, $b) => array_search($a->status, $statusOrder) <=> array_search($b->status, $statusOrder),
            fn($a, $b) => $a->priority_score <=> $b->priority_score,
            fn($a, $b) => strtotime($a->due_date . ' ' . $a->due_time) <=> strtotime($b->due_date . ' ' . $b->due_time),
        ]);

    return view('tasks.my_tasks', compact('tasks'));
}


    public function updateStatus(Request $request)
    {
        $request->validate([
            'task_id' => 'required|exists:tasks,id',
            'status' => 'required|string',
            'assignee_remarks' => 'nullable|string',
        ]);

        $task = Task::findOrFail($request->task_id);

        if ($task->user_id !== auth()->id()) {
            abort(403);
        }

        $task->status = $request->status;
        $task->assignee_remarks = $request->assignee_remarks;

        if ($request->status === 'completed' && !$task->completed_at) {
            $task->completed_at = now('Asia/Manila');
        }

        $task->save();

        return back()->with('success', 'Task updated successfully.');
    }
}
