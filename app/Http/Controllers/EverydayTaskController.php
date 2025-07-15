<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\EverydayTask;
use Carbon\Carbon;

class EverydayTaskController extends Controller
{
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

        return view('tasks.create_everyday_task', compact('roles', 'usersByRole'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string',
            'priority_score' => 'required|integer|min:1|max:100',
            'due_time' => 'nullable|date_format:H:i',
            'target_users' => 'required|array|min:1',
        ]);

        foreach ($request->target_users as $userId) {
            $employee = DB::table('employee_profiles')->where('user_id', $userId)->first();
            if (!$employee) continue;

            EverydayTask::create([
                'user_id' => $userId,
                'name' => $employee->name,
                'task_name' => $request->name,
                'collaborators' => $request->collaborators ?? null,
                'description' => $request->description,
                'type' => $request->type,
                'due_time' => $request->due_time,
                'reminder_at' => $request->reminder_at ?? null,
                'status' => 'pending',
                'created_by' => auth()->id(),
                'remarks' => $request->remarks,
                'priority_score' => $request->priority_score,
                'parent_task_id' => $request->parent_task_id ?? null,
            ]);
        }

        return redirect()->route('everyday-tasks.index')->with('success', 'Everyday tasks created successfully.');
    }

    public function index()
    {
        $tasks = EverydayTask::where('user_id', auth()->id())
            ->orderBy('priority_score')
            ->orderBy('due_time')
            ->get();

        return view('tasks.my_everyday_task', compact('tasks'));
    }

    public function update(Request $request, $id)
    {
        $task = EverydayTask::where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $request->validate([
            'task_name' => 'required|string|max:255',
            'priority_score' => 'required|integer|min:1|max:100',
            'due_time' => 'nullable|date_format:H:i',
            'description' => 'nullable|string',
        ]);

        $task->update([
            'task_name' => $request->task_name,
            'priority_score' => $request->priority_score,
            'due_time' => $request->due_time,
            'description' => $request->description,
        ]);

        return back()->with('success', 'Task updated successfully.');
    }

    public function destroy($id)
    {
        $task = EverydayTask::where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $task->delete();

        return back()->with('success', 'Task deleted.');
    }
}
