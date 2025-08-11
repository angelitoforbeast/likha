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

    return redirect()->route('task.team-tasks')->with('success', 'Tasks created for selected users.');
}


    public function myTasks(Request $request)
{
    $start = $request->input('start_date');
    $end   = $request->input('end_date');

    $today = now('Asia/Manila')->toDateString();
    $statusOrder = ['pending', 'in_progress', 'completed'];

    // Build a reusable date condition closure
    $dateFilter = function ($q) use ($start, $end, $today) {
        if ($start && $end) {
            $q->whereBetween('due_date', [$start, $end]);
        } elseif ($start) {
            $q->whereDate('due_date', '>=', $start);
        } elseif ($end) {
            $q->whereDate('due_date', '<=', $end);
        } else {
            // default: today
            $q->whereDate('due_date', $today);
        }
    };

    $query = Task::with(['creator.employeeProfile'])
        ->where('user_id', auth()->id())
        ->where(function ($q) use ($dateFilter) {
            // (in date range/today)
            $q->where($dateFilter)
              // OR any non-completed regardless of date
              ->orWhereIn('status', ['pending', 'in_progress']);
        });

    $tasks = $query->get()
        ->sortBy(function ($task) use ($statusOrder) {
            return [
                array_search($task->status, $statusOrder),
                $task->status === 'completed'
                    ? -strtotime($task->completed_at ?? '1970-01-01')
                    : 0,
                -((int)($task->priority_score ?? 0)),
                strtotime(trim(($task->due_date ?? '') . ' ' . ($task->due_time ?? '00:00'))),
            ];
        })
        ->values();

    // Manual pagination
    $perPage = 10;
    $page = \Illuminate\Pagination\LengthAwarePaginator::resolveCurrentPage();
    $paginated = new \Illuminate\Pagination\LengthAwarePaginator(
        $tasks->forPage($page, $perPage),
        $tasks->count(),
        $perPage,
        $page,
        ['path' => $request->url(), 'query' => $request->query()]
    );

    // Pass start/end so the inputs can show the current filter (or today's defaults)
    if (!$start && !$end) {
        $start = $today;
        $end   = $today;
    }

    return view('tasks.my_tasks', [
        'tasks' => $paginated,
        'start' => $start,
        'end'   => $end,
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

    $query = Task::with(['creator.employeeProfile', 'creator']);

    if ($start && $end) {
        $query->whereBetween('due_date', [$start, $end]);
    } elseif ($start) {
        $query->whereDate('due_date', '>=', $start);
    } elseif ($end) {
        $query->whereDate('due_date', '<=', $end);
    }

    $tasks = $query->get();

    // Group tasks by task identity
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

    // Internal sorting per group (status order inside the group)
    $statusOrder = ['pending', 'in_progress', 'completed'];
    $grouped = $grouped->map(function ($group) use ($statusOrder) {
        return $group->sortBy(function ($task) use ($statusOrder) {
            return array_search($task->status, $statusOrder);
        })->values();
    });

    // Rank groups based on presence of status
    $grouped = $grouped->sortBy(function ($group) {
        $statuses = $group->pluck('status')->unique();

        if ($statuses->contains('pending')) {
            return 1;
        } elseif ($statuses->contains('in_progress')) {
            return 2;
        } elseif ($statuses->count() === 1 && $statuses->contains('completed')) {
            // For completed-only groups, use negative max(completed_at) timestamp (newest first)
            $latestCompletedAt = $group->max(function ($task) {
                return strtotime($task->completed_at ?? '1970-01-01');
            });
            return 3 - ($latestCompletedAt / 100000000000); // subtract small fraction to sort newest first
        } else {
            return 4; // fallback for unexpected combinations
        }
    });

    return view('tasks.team_tasks', [
        'groupedTasks' => $grouped,
        'start' => $start,
        'end' => $end,
    ]);
}


    public function updateTeamTask(Request $request)
{
    $taskId = $request->input('task_id');

    $statuses = $request->input('statuses', []);
    $remarks = $request->input('creator_remarks', []);


    foreach ($statuses as $id => $status) {
        $task = Task::find($id);

        if ($task) {
            $task->status = $status;

            // Save remarks if available for this specific assignee
            if (isset($remarks[$id])) {
                $task->creator_remarks = $remarks[$id];
            }

            $task->save();
        }
    }

    return redirect()->back()->with('success', 'Task updated successfully!');
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
