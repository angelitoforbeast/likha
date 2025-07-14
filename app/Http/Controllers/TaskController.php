<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Carbon\Carbon;

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

    $usersByRole = DB::table('employee_profiles')
        ->whereNotNull('role')
        ->join('users', 'employee_profiles.user_id', '=', 'users.id')
        ->select('employee_profiles.role', 'employee_profiles.name', 'users.id')
        ->get()
        ->groupBy('role');

    return view('tasks.create', compact('roles', 'usersByRole'));
}


    public function create(Request $request)
{
    $request->validate([
        'name' => 'required|string|max:255',
        'type' => 'required|string',
        'priority_score' => 'required|integer|min:1|max:100',
        'due_date' => 'required|date',
        'due_time' => 'nullable|date_format:H:i',
        'target_users' => 'required|array|min:1',
    ]);

    foreach ($request->target_users as $userId) {
        $employee = DB::table('employee_profiles')->where('user_id', $userId)->first();
        if (!$employee) continue;

        Task::create([
            'user_id' => $userId,
            'name' => $employee->name,
            'task_name' => $request->name,
            'role_target' => $employee->role,
            'collaborators' => $request->collaborators ?? null,
            'description' => $request->description,
            'type' => $request->type,
            'is_repeating' => $request->has('is_repeating'),
            'due_date' => $request->due_date,
            'due_time' => $request->due_time,
            'reminder_at' => $request->reminder_at ?? null,
            'status' => 'pending',
            'created_by' => auth()->id(),
            'remarks' => $request->remarks,
            'priority_score' => $request->priority_score,
            'parent_task_id' => $request->parent_task_id ?? null,
        ]);
    }

    return redirect()->route('task.index')->with('success', 'Tasks created for selected users.');
}


    public function myTasks(Request $request)
{
    $start = $request->input('start_date');
    $end = $request->input('end_date');

    $statusOrder = ['pending', 'in_progress', 'completed'];

    $query = Task::with(['creator.employeeProfile'])
        ->where('user_id', auth()->id());

    if ($start && $end) {
        $query->whereBetween('due_date', [$start, $end]);
    } elseif ($start) {
        $query->whereDate('due_date', '>=', $start);
    } elseif ($end) {
        $query->whereDate('due_date', '<=', $end);
    } else {
        $today = now()->format('Y-m-d');
        $query->whereDate('due_date', $today); // Default to today
    }

    $tasks = $query->get()
        ->sortBy([
            fn($a, $b) => array_search($a->status, $statusOrder) <=> array_search($b->status, $statusOrder),
            fn($a, $b) => $a->priority_score <=> $b->priority_score,
            fn($a, $b) => strtotime($a->due_date . ' ' . $a->due_time) <=> strtotime($b->due_date . ' ' . $b->due_time),
        ])
        ->values();

    // Paginate manually
    $perPage = 10;
    $page = LengthAwarePaginator::resolveCurrentPage();
    $paginated = new LengthAwarePaginator(
        $tasks->forPage($page, $perPage),
        $tasks->count(),
        $perPage,
        $page,
        ['path' => $request->url(), 'query' => $request->query()]
    );

    return view('tasks.my_tasks', [
        'tasks' => $paginated,
    ]);
}
    public function teamTasks(Request $request)
{
    $start = $request->input('start_date');
    $end = $request->input('end_date');

    if (!$start && !$end) {
        $start = now()->subDays(6)->format('Y-m-d');
        $end = now()->format('Y-m-d');
    }

    $statusOrder = ['pending', 'in_progress', 'completed'];

    $query = Task::with(['creator.employeeProfile', 'creator']);

    if ($start && $end) {
        $query->whereBetween('due_date', [$start, $end]);
    } elseif ($start) {
        $query->whereDate('due_date', '>=', $start);
    } elseif ($end) {
        $query->whereDate('due_date', '<=', $end);
    }

    $tasks = $query->get()
        ->sortBy([
            fn($a, $b) => array_search($a->status, $statusOrder) <=> array_search($b->status, $statusOrder),
            fn($a, $b) => $a->priority_score <=> $b->priority_score,
            fn($a, $b) => strtotime($a->due_date . ' ' . $a->due_time) <=> strtotime($b->due_date . ' ' . $b->due_time),
        ])
        ->values();

    // Group by combined task fields
    $grouped = $tasks->groupBy(function ($task) {
        return implode('|', [
            $task->created_at,
            $task->task_name,
            $task->description,
            $task->priority_score,
            $task->due_date,
            $task->due_time,
            
            $task->creator_id,
        ]);
    });

    return view('tasks.team_tasks', [
        'groupedTasks' => $grouped,
        'start' => $start,
        'end' => $end,
    ]);
}

    public function updateTeamTask(Request $request)
{
    $request->validate([
        'task_id' => 'required|exists:tasks,id',
        'task_name' => 'required|string|max:255',
        'description' => 'nullable|string',
        'creator_remarks' => 'nullable|string',
        'statuses' => 'required|array',
    ]);

    $mainTask = Task::findOrFail($request->task_id);

    // Get all tasks in the group (same created_at, task name, description)
    $group = Task::where('task_name', $mainTask->task_name)
        ->where('description', $mainTask->description)
        ->whereDate('created_at', $mainTask->created_at->format('Y-m-d'))
        ->get();

    foreach ($group as $task) {
        $task->task_name = $request->task_name;
        $task->description = $request->description;
        $task->creator_remarks = $request->creator_remarks;
        $task->status = $request->statuses[$task->id] ?? $task->status;

        if ($task->status === 'completed' && !$task->completed_at) {
            $task->completed_at = now('Asia/Manila');
        }

        $task->save();
    }

    return back()->with('success', 'Task group updated successfully.');
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
