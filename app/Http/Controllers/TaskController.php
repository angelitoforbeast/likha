<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    public function index()
    {
        $tasks = Task::latest()->get();
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
            'priority_level' => 'required|in:P1,P2,P3,P4,P5',
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
                'description' => $request->description,
                'type' => $request->type,
                'is_repeating' => $request->has('is_repeating'),
                'due_date' => $request->due_date,
                'status' => $request->status ?? 'pending',
                'created_by' => auth()->id(),
                'remarks' => $request->remarks,
                'priority_level' => $request->priority_level,
            ]);
        }

        return redirect()->route('task.index')->with('success', 'Tasks created for selected roles.');
    }

    public function myTasks()
{
    $statusOrder = ['pending', 'in_progress', 'completed'];
    $priorityOrder = ['P1', 'P2', 'P3', 'P4', 'P5'];

    $tasks = Task::where('user_id', auth()->id())
        ->get()
        ->sortBy([
            fn($a, $b) => array_search($a->status, $statusOrder) <=> array_search($b->status, $statusOrder),
            fn($a, $b) => array_search($a->priority_level, $priorityOrder) <=> array_search($b->priority_level, $priorityOrder),
            fn($a, $b) => strtotime($a->due_date) <=> strtotime($b->due_date),
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
        $task->completed_at = now('Asia/Manila'); // ✅ Set PH timezone
    }

    $task->save();

    return back()->with('success', 'Task updated successfully.');
}

}
