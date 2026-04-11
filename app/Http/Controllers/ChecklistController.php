<?php

namespace App\Http\Controllers;

use App\Models\ChecklistTask;
use App\Models\ChecklistSubmission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ChecklistController extends Controller
{
    public function index()
    {
        $today = now()->toDateString();

        $tasks = ChecklistTask::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        // All today's submissions grouped by task_id
        $submissionsByTask = ChecklistSubmission::with('user')
            ->where('date', $today)
            ->get()
            ->groupBy('checklist_task_id');

        // My submissions keyed by task_id
        $mySubmissions = ChecklistSubmission::where('user_id', Auth::id())
            ->where('date', $today)
            ->get()
            ->keyBy('checklist_task_id');

        // All users for team summary
        $users = User::with('employeeProfile')
            ->orderBy('name')
            ->get();

        $myDoneCount  = $mySubmissions->count();
        $totalTasks   = $tasks->count();

        // All tasks (including inactive) for manage panel
        $allTasks = ChecklistTask::orderBy('sort_order')->orderBy('id')->get();

        return view('checklist.index', compact(
            'tasks', 'submissionsByTask', 'mySubmissions',
            'users', 'today', 'myDoneCount', 'totalTasks', 'allTasks'
        ));
    }

    public function submit(Request $request, ChecklistTask $task)
    {
        $today = now()->toDateString();

        $rules = ['notes' => 'nullable|string|max:2000'];

        if ($task->type === 'photo') {
            $rules['file'] = 'required|file|max:10240|mimes:jpg,jpeg,png,gif,webp';
        } elseif ($task->type === 'any') {
            $rules['file'] = 'nullable|file|max:10240|mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,csv';
        }

        $request->validate($rules);

        $data = ['notes' => $request->notes];

        if ($request->hasFile('file')) {
            // Delete old file if re-submitting
            $existing = ChecklistSubmission::where([
                'checklist_task_id' => $task->id,
                'user_id'           => Auth::id(),
                'date'              => $today,
            ])->first();

            if ($existing?->file_path) {
                Storage::disk('public')->delete($existing->file_path);
            }

            $file = $request->file('file');
            $data['file_path']          = $file->store("checklist/{$today}", 'public');
            $data['file_original_name'] = $file->getClientOriginalName();
            $data['file_mime']          = $file->getMimeType();
        }

        ChecklistSubmission::updateOrCreate(
            [
                'checklist_task_id' => $task->id,
                'user_id'           => Auth::id(),
                'date'              => $today,
            ],
            $data
        );

        return back()->with('success', "'{$task->title}' submitted!");
    }

    public function deleteSubmission(ChecklistSubmission $submission)
    {
        if ($submission->user_id !== Auth::id()) {
            abort(403);
        }

        if ($submission->file_path) {
            Storage::disk('public')->delete($submission->file_path);
        }

        $submission->delete();

        return back()->with('success', 'Submission removed.');
    }

    public function storeTask(Request $request)
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'type'        => 'required|in:photo,note,any',
        ]);

        ChecklistTask::create([
            ...$validated,
            'sort_order' => (ChecklistTask::max('sort_order') ?? 0) + 1,
            'is_active'  => true,
        ]);

        return back()->with('success', 'Task added!');
    }

    public function updateTask(Request $request, ChecklistTask $task)
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'type'        => 'required|in:photo,note,any',
            'is_active'   => 'boolean',
        ]);

        $task->update($validated);

        return back()->with('success', 'Task updated!');
    }

    public function destroyTask(ChecklistTask $task)
    {
        $task->update(['is_active' => false]);
        return back()->with('success', 'Task deactivated.');
    }

    public function reorderTasks(Request $request)
    {
        foreach ($request->input('order', []) as $index => $id) {
            ChecklistTask::where('id', $id)->update(['sort_order' => $index]);
        }
        return response()->json(['ok' => true]);
    }
}
