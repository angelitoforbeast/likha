<?php

namespace App\Http\Controllers;

use App\Models\ChecklistTask;
use App\Models\ChecklistSubmission;
use App\Models\ChecklistSubmissionFile;
use App\Models\ChecklistSubmissionLog;
use App\Models\ChecklistAnalysisLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class ChecklistController extends Controller
{
    public function index()
    {
        $today = now()->toDateString();

        $tasks = ChecklistTask::with('assignedUsers')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        // Visibility: only show tasks the current user is assigned to (or open tasks with no assignments)
        $tasks = $tasks->filter(function ($t) {
            $assignedIds = $t->assignedUsers->pluck('id');
            return $assignedIds->isEmpty() || $assignedIds->contains(Auth::id());
        });

        // Frequency filter: only show tasks that are due today
        $nowManila = Carbon::now('Asia/Manila');
        $tasks = $tasks->filter(function ($t) use ($nowManila) {
            return match($t->frequency ?? 'daily') {
                'weekly'  => (int) $nowManila->dayOfWeek === (int) $t->frequency_day,
                'monthly' => (int) $nowManila->day       === (int) $t->frequency_day,
                'once'    => !ChecklistSubmission::where('checklist_task_id', $t->id)->exists(),
                default   => true,
            };
        });

        // Load all today's submissions once
        $todaySubmissions = ChecklistSubmission::with(['user', 'files', 'logs.user'])
            ->where('date', $today)->get();

        // Group tasks: any user's submission per task
        $submissionsByTask   = $todaySubmissions->keyBy('checklist_task_id');
        // Individual tasks: only the current user's submission per task
        $mySubmissionsByTask = $todaySubmissions->where('user_id', Auth::id())->keyBy('checklist_task_id');

        // Smart sort: pending first, done last; timed tasks before untimed within each group
        $tasks = $tasks->sort(function ($a, $b) use ($submissionsByTask, $mySubmissionsByTask) {
            $aSub  = $a->submission_type === 'individual' ? $mySubmissionsByTask->get($a->id) : $submissionsByTask->get($a->id);
            $bSub  = $b->submission_type === 'individual' ? $mySubmissionsByTask->get($b->id) : $submissionsByTask->get($b->id);
            $aDone = $aSub ? 1 : 0;
            $bDone = $bSub ? 1 : 0;

            if ($aDone !== $bDone) return $aDone - $bDone;

            $aHasTime = $a->scheduled_time ? 0 : 1;
            $bHasTime = $b->scheduled_time ? 0 : 1;

            if ($aHasTime !== $bHasTime) return $aHasTime - $bHasTime;

            if ($a->scheduled_time && $b->scheduled_time) {
                return strcmp($a->scheduled_time, $b->scheduled_time);
            }

            return $a->sort_order - $b->sort_order;
        })->values();

        $doneCount  = $tasks->filter(function ($t) use ($submissionsByTask, $mySubmissionsByTask) {
            return $t->submission_type === 'individual'
                ? $mySubmissionsByTask->has($t->id)
                : $submissionsByTask->has($t->id);
        })->count();
        $totalTasks = $tasks->count();

        return view('checklist.index', compact(
            'tasks', 'submissionsByTask', 'mySubmissionsByTask',
            'today', 'doneCount', 'totalTasks'
        ));
    }

    public function report(Request $request)
    {
        $date = $request->query('date', now()->toDateString());

        try {
            $dateObj = \Carbon\Carbon::parse($date);
        } catch (\Exception $e) {
            $dateObj = now();
        }

        $isToday = $dateObj->isToday();

        if ($isToday) {
            // Today: same as index — only currently active tasks
            $tasks = ChecklistTask::with('assignedUsers')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
        } else {
            // Past date: show tasks that actually existed on that day
            //   created_at <= end of report date  (task existed by then)
            //   AND (deleted_at IS NULL OR deleted_at > end of report date)  (not yet deleted)
            $endOfDay = $dateObj->copy()->endOfDay();

            $tasks = ChecklistTask::withTrashed()
                ->with('assignedUsers')
                ->whereDate('created_at', '<=', $dateObj->toDateString())
                ->where(function ($q) use ($endOfDay) {
                    $q->whereNull('deleted_at')
                      ->orWhere('deleted_at', '>', $endOfDay);
                })
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
        }

        // Submissions for this date — load all (supports both group and individual tasks)
        $allSubmissions = ChecklistSubmission::with(['user', 'files', 'logs.user', 'latestAnalysis.user', 'latestApproval.user'])
            ->withCount(['analysisLogs', 'approvalLogs'])
            ->where('date', $dateObj->toDateString())
            ->get();

        $submissionsByTask        = $allSubmissions->keyBy('checklist_task_id');     // group: one per task
        $submissionsGroupedByTask = $allSubmissions->groupBy('checklist_task_id');   // individual: all per task

        // Safety net: if a submission exists for a task not in our list, pull it in
        if (!$isToday) {
            $missingIds = $submissionsByTask->keys()->diff($tasks->pluck('id'));
            if ($missingIds->isNotEmpty()) {
                $extra = ChecklistTask::withTrashed()
                    ->with('assignedUsers')
                    ->whereIn('id', $missingIds)
                    ->get();
                $tasks = $tasks->merge($extra)->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values();
            }
        }

        $doneCount  = $allSubmissions->pluck('checklist_task_id')->unique()->count();
        $totalTasks = $tasks->count();

        $prevDate = $dateObj->copy()->subDay()->toDateString();
        $nextDate = $dateObj->copy()->addDay()->toDateString();

        return view('checklist.report', compact(
            'tasks', 'submissionsByTask', 'submissionsGroupedByTask',
            'doneCount', 'totalTasks',
            'dateObj', 'prevDate', 'nextDate', 'isToday'
        ));
    }

    public function manage()
    {
        $user   = Auth::user();
        $role   = $user?->employeeProfile?->role ?? '';
        $dept   = $user?->employeeProfile?->department ?? '';
        $userId = $user?->id;

        $query = ChecklistTask::with('assignedUsers')->orderBy('sort_order')->orderBy('id');

        if ($role === 'CEO') {
            // sees all — no filter
        } elseif (str_contains($role, 'OIC') && $dept !== '') {
            // OIC sees tasks tagged with their department
            $query->where('department', $dept);
        } else {
            // others see tasks they created OR are assigned to
            $query->where(function ($q) use ($userId) {
                $q->where('created_by', $userId)
                  ->orWhereHas('assignedUsers', fn ($r) => $r->where('users.id', $userId));
            });
        }

        $allTasks = $query->get();
        $allUsers = User::with('employeeProfile')->orderBy('name')->get();

        return view('checklist.manage', compact('allTasks', 'allUsers'));
    }

    public function submit(Request $request, ChecklistTask $task)
    {
        $today = now()->toDateString();

        // Check assignment — if task has assigned users, only they can submit
        $assignedIds = $task->assignedUsers()->pluck('users.id')->toArray();
        if (!empty($assignedIds) && !in_array(Auth::id(), $assignedIds)) {
            return back()->with('error', 'You are not assigned to this task.');
        }

        $imageMimes = 'jpg,jpeg,png,gif,webp';
        $anyMimes   = 'jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,csv';

        // Load existing submission — for individual tasks, scope to current user
        $existingQuery = ChecklistSubmission::with('files')
            ->where('checklist_task_id', $task->id)
            ->where('date', $today);
        if ($task->submission_type === 'individual') {
            $existingQuery->where('user_id', Auth::id());
        }
        $existing = $existingQuery->first();

        $isNew            = $existing === null;
        $hasExistingFiles = $existing && ($existing->files->count() > 0 || $existing->file_path);

        $rules = ['notes' => 'nullable|string|max:2000'];

        if ($task->type === 'photo') {
            $rules['files']   = $hasExistingFiles ? 'nullable|array|max:10' : 'required|array|min:1|max:10';
            $rules['files.*'] = "file|max:10240|mimes:{$imageMimes}";
        } elseif ($task->type === 'both') {
            $rules['notes']   = 'required|string|max:2000';
            $rules['files']   = $hasExistingFiles ? 'nullable|array|max:10' : 'required|array|min:1|max:10';
            $rules['files.*'] = "file|max:10240|mimes:{$imageMimes}";
        } elseif ($task->type === 'any') {
            $rules['files']   = 'nullable|array|max:10';
            $rules['files.*'] = "file|max:10240|mimes:{$anyMimes}";
        }

        $request->validate($rules);

        if ($task->submission_type === 'individual') {
            // Individual: one submission per user per day
            $submission = ChecklistSubmission::updateOrCreate(
                ['checklist_task_id' => $task->id, 'user_id' => Auth::id(), 'date' => $today],
                ['notes' => $request->notes]
            );
        } else {
            // Group: one submission per task per day (any user)
            $submission = ChecklistSubmission::updateOrCreate(
                ['checklist_task_id' => $task->id, 'date' => $today],
                ['notes' => $request->notes, 'user_id' => $isNew ? Auth::id() : $existing->user_id]
            );
        }

        // Log this action
        $fileCount = $request->hasFile('files') ? count($request->file('files')) : ($submission->files()->count());
        ChecklistSubmissionLog::create([
            'checklist_submission_id' => $submission->id,
            'user_id'                 => Auth::id(),
            'action'                  => $isNew ? 'submitted' : 'updated',
            'notes_snapshot'          => $request->notes ? \Str::limit($request->notes, 200) : null,
            'file_count'              => $fileCount,
        ]);

        // If new files uploaded, ADD to existing (not replace)
        if ($request->hasFile('files')) {
            $nextOrder = $submission->files()->max('sort_order') + 1;
            foreach ($request->file('files') as $i => $file) {
                $submission->files()->create([
                    'file_path'          => $file->store("checklist/{$today}", 'public'),
                    'file_original_name' => $file->getClientOriginalName(),
                    'file_mime'          => $file->getMimeType(),
                    'sort_order'         => $nextOrder + $i,
                ]);
            }
        }

        // Auto-analyze: runs AI analysis + approval check silently after saving
        $this->runAutoAnalysis($submission);

        return back()->with('success', "'{$task->title}' submitted!");
    }

    public function deleteSubmission(ChecklistSubmission $submission)
    {
        if ($submission->user_id !== Auth::id() &&
            Auth::user()?->employeeProfile?->role !== 'CEO') {
            abort(403);
        }

        // Delete all associated files from storage
        foreach ($submission->files as $file) {
            Storage::disk('public')->delete($file->file_path);
        }
        // Also delete legacy single file if present
        if ($submission->file_path) {
            Storage::disk('public')->delete($submission->file_path);
        }

        $submission->delete();
        return back()->with('success', 'Submission removed.');
    }

    public function deleteFile(ChecklistSubmissionFile $file)
    {
        $submission = $file->submission;

        if ($submission->user_id !== Auth::id() &&
            Auth::user()?->employeeProfile?->role !== 'CEO') {
            abort(403);
        }

        Storage::disk('public')->delete($file->file_path);
        $file->delete();

        return back()->with('success', 'File removed.');
    }

    public function storeTask(Request $request)
    {
        $validated = $request->validate([
            'title'           => 'required|string|max:255',
            'description'     => 'nullable|string|max:1000',
            'type'            => 'required|in:photo,note,any,both',
            'scheduled_time'  => 'nullable|string|max:20',
            'submission_type' => 'nullable|in:group,individual',
            'ai_prompt'       => 'nullable|string|max:2000',
            'approval_prompt' => 'nullable|string|max:2000',
            'required_photos' => 'nullable|integer|min:0|max:20',
            'frequency'       => 'nullable|in:daily,weekly,monthly,once',
            'frequency_day'   => 'nullable|integer|min:0|max:31',
            'department'      => 'nullable|string|max:100',
        ]);

        $task = ChecklistTask::create([
            ...$validated,
            'sort_order'      => (ChecklistTask::max('sort_order') ?? 0) + 1,
            'submission_type' => $validated['submission_type'] ?? 'group',
            'frequency'       => $validated['frequency'] ?? 'daily',
            'required_photos' => $validated['required_photos'] ?? 0,
            'is_active'       => true,
            'created_by'      => Auth::id(),
        ]);

        // Sync assigned users
        $userIds = array_filter((array) $request->input('assigned_users', []));
        $task->assignedUsers()->sync($userIds);

        return back()->with('success', 'Task added!');
    }

    public function updateTask(Request $request, ChecklistTask $task)
    {
        $validated = $request->validate([
            'title'           => 'required|string|max:255',
            'description'     => 'nullable|string|max:1000',
            'type'            => 'required|in:photo,note,any,both',
            'is_active'       => 'boolean',
            'scheduled_time'  => 'nullable|string|max:20',
            'submission_type' => 'nullable|in:group,individual',
            'ai_prompt'       => 'nullable|string|max:2000',
            'approval_prompt' => 'nullable|string|max:2000',
            'required_photos' => 'nullable|integer|min:0|max:20',
            'frequency'       => 'nullable|in:daily,weekly,monthly,once',
            'frequency_day'   => 'nullable|integer|min:0|max:31',
            'department'      => 'nullable|string|max:100',
        ]);

        $task->update($validated);

        // Sync assigned users (empty array = all users can submit)
        $userIds = array_filter((array) $request->input('assigned_users', []));
        $task->assignedUsers()->sync($userIds);

        return back()->with('success', 'Task updated!');
    }

    public function destroyTask(ChecklistTask $task)
    {
        // Hard delete — cascades to submissions and assignments via FK
        $task->delete();
        return back()->with('success', 'Task deleted.');
    }

    public function duplicateTask(ChecklistTask $task)
    {
        $new = ChecklistTask::create([
            'title'           => $task->title . ' (Copy)',
            'description'     => $task->description,
            'type'            => $task->type,
            'scheduled_time'  => $task->scheduled_time,
            'submission_type' => $task->submission_type,
            'ai_prompt'       => $task->ai_prompt,
            'approval_prompt' => $task->approval_prompt,
            'is_active'       => $task->is_active,
            'sort_order'      => (ChecklistTask::max('sort_order') ?? 0) + 1,
            'required_photos' => $task->required_photos,
            'frequency'       => $task->frequency,
            'frequency_day'   => $task->frequency_day,
            'department'      => $task->department,
            'created_by'      => Auth::id(),
        ]);

        $new->assignedUsers()->sync($task->assignedUsers->pluck('id'));

        return back()->with('success', 'Task duplicated! Edit the copy to rename it.');
    }

    public function bulkAssign(Request $request)
    {
        $request->validate([
            'task_ids'   => 'required|array',
            'task_ids.*' => 'integer|exists:checklist_tasks,id',
            'user_ids'   => 'nullable|array',
            'user_ids.*' => 'nullable|integer|exists:users,id',
        ]);

        // If "clear_assign" is checked, sync to empty (anyone can submit)
        $userIds = $request->boolean('clear_assign')
            ? []
            : array_filter((array) $request->input('user_ids', []));
        $count   = 0;
        foreach ($request->input('task_ids') as $taskId) {
            $task = ChecklistTask::find($taskId);
            if ($task) {
                $task->assignedUsers()->sync($userIds);
                $count++;
            }
        }

        return back()->with('success', $count . ' task(s) updated.');
    }

    public function bulkType(Request $request)
    {
        $request->validate([
            'task_ids'   => 'required|array',
            'task_ids.*' => 'integer|exists:checklist_tasks,id',
            'type'       => 'required|in:any,photo,note,both',
        ]);

        $count = ChecklistTask::whereIn('id', $request->input('task_ids'))
            ->update(['type' => $request->input('type')]);

        return back()->with('success', $count . ' task(s) updated.');
    }

    public function bulkSubmissionType(Request $request)
    {
        $request->validate([
            'task_ids'        => 'required|array',
            'task_ids.*'      => 'integer|exists:checklist_tasks,id',
            'submission_type' => 'required|in:group,individual',
        ]);

        $count = ChecklistTask::whereIn('id', $request->input('task_ids'))
            ->update(['submission_type' => $request->input('submission_type')]);

        return back()->with('success', $count . ' task(s) updated.');
    }

    public function reorderTasks(Request $request)
    {
        foreach ($request->input('order', []) as $index => $id) {
            ChecklistTask::where('id', $id)->update(['sort_order' => $index]);
        }
        return response()->json(['ok' => true]);
    }

    public function analyzeSubmission(Request $request, ChecklistSubmission $submission)
    {
        $submission->load(['task', 'files']);
        $task     = $submission->task;
        $imgFiles = $submission->files->filter(fn($f) => $f->isImage());

        if (!$task) {
            return response()->json(['error' => 'Task not found for this submission.'], 404);
        }

        // Build prompt
        $prompt  = "You are reviewing a daily operational task submission for a business.\n\n";
        $prompt .= "Task: {$task->title}\n";
        if ($task->description) {
            $prompt .= "Task Description: {$task->description}\n";
        }
        if ($submission->notes) {
            $prompt .= "Staff Notes: {$submission->notes}\n";
        }
        if ($imgFiles->isEmpty()) {
            $prompt .= "\n(No images were submitted for this task.)\n";
        }

        // Use task-specific AI focus prompt if set, otherwise use default
        if ($task->ai_prompt) {
            $prompt .= "\nAnalysis Focus: {$task->ai_prompt}";
            $prompt .= "\n\nUsing the above focus, provide a concise analysis in 2-4 sentences based on the submission.";
        } else {
            $prompt .= "\nBased on the above, provide a concise analysis in 2-4 sentences: Was the task completed properly? What do the images show (if any)? Any observations, concerns, or recommendations?";
        }

        $content = [['type' => 'text', 'text' => $prompt]];

        // Attach images (up to 5) as URLs — storage is public
        foreach ($imgFiles->take(5) as $f) {
            $content[] = [
                'type'      => 'image_url',
                'image_url' => [
                    'url'    => url(Storage::url($f->file_path)),
                    'detail' => 'auto',
                ],
            ];
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.openai.key'),
            'Content-Type'  => 'application/json',
        ])->timeout(30)->post('https://api.openai.com/v1/chat/completions', [
            'model'      => 'gpt-4o',
            'max_tokens' => 512,
            'messages'   => [
                ['role' => 'user', 'content' => $content],
            ],
        ]);

        if ($response->successful()) {
            $analysisText = $response->json('choices.0.message.content');

            ChecklistAnalysisLog::create([
                'submission_id'   => $submission->id,
                'user_id'         => Auth::id(),
                'log_type'        => 'analysis',
                'prompt_used'     => $prompt,
                'analysis_result' => $analysisText,
            ]);

            return response()->json([
                'analysis'    => $analysisText,
                'prompt_used' => $prompt,
                'analyzed_by' => Auth::user()?->name,
                'analyzed_at' => now()->format('M j, h:i A'),
            ]);
        }

        return response()->json([
            'error' => 'AI analysis failed (' . $response->status() . '). Check your API key.',
        ], 500);
    }

    public function getAnalysisLogs(ChecklistSubmission $submission)
    {
        $logs = $submission->analysisLogs()->with('user')->get()->map(fn($log) => [
            'id'          => $log->id,
            'analysis'    => $log->analysis_result,
            'prompt_used' => $log->prompt_used,
            'user'        => $log->user?->name ?? 'Unknown',
            'created_at'  => $log->created_at->format('M j, Y g:i A'),
        ]);

        return response()->json(['logs' => $logs]);
    }

    public function approvalCheck(Request $request, ChecklistSubmission $submission)
    {
        $submission->load(['task', 'files']);
        $task     = $submission->task;
        $imgFiles = $submission->files->filter(fn($f) => $f->isImage());

        if (!$task) {
            return response()->json(['error' => 'Task not found.'], 404);
        }
        $todayManila = Carbon::now('Asia/Manila')->format('l, F j, Y \a\t g:i A T');
        $prompt  = "You are a quality control reviewer for a business daily checklist submission.\n\n";
        $prompt .= "Today's date and time (Manila, Philippines): {$todayManila}\n\n";
        $prompt .= "Task: {$task->title}\n";
        if ($task->description) $prompt .= "Description: {$task->description}\n";
        if ($submission->notes) $prompt .= "Staff Notes: {$submission->notes}\n";
        if ($imgFiles->isEmpty()) $prompt .= "\n(No images were submitted.)\n";
        $criteria = $task->approval_prompt
            ?: 'Evaluate whether the submission properly completes the task based on the title, description, and submitted content (notes and/or images). Assess overall quality and completeness.';
        $prompt .= "\nApproval Criteria: {$criteria}\n";
        $prompt .= "\nIMPORTANT: Your response MUST start with exactly \"APPROVED\" or \"NOT APPROVED\" on the first line, followed by a blank line, then your explanation in 2-3 sentences.";

        $content = [['type' => 'text', 'text' => $prompt]];
        foreach ($imgFiles->take(5) as $f) {
            $content[] = [
                'type'      => 'image_url',
                'image_url' => ['url' => url(Storage::url($f->file_path)), 'detail' => 'auto'],
            ];
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.openai.key'),
            'Content-Type'  => 'application/json',
        ])->timeout(30)->post('https://api.openai.com/v1/chat/completions', [
            'model'      => 'gpt-4o',
            'max_tokens' => 512,
            'messages'   => [['role' => 'user', 'content' => $content]],
        ]);

        if ($response->successful()) {
            $text      = $response->json('choices.0.message.content');
            $firstLine = strtoupper(trim(explode("\n", trim($text))[0]));
            $verdict   = str_starts_with($firstLine, 'NOT APPROVED') ? 'not_approved'
                       : (str_starts_with($firstLine, 'APPROVED')    ? 'approved' : 'unknown');

            ChecklistAnalysisLog::create([
                'submission_id'   => $submission->id,
                'user_id'         => Auth::id(),
                'log_type'        => 'approval',
                'prompt_used'     => $prompt,
                'analysis_result' => $text,
                'verdict'         => $verdict,
            ]);

            return response()->json([
                'verdict'     => $verdict,
                'analysis'    => $text,
                'prompt_used' => $prompt,
                'checked_by'  => Auth::user()?->name,
                'checked_at'  => now()->format('M j, g:i A'),
            ]);
        }

        return response()->json([
            'error' => 'Approval check failed (' . $response->status() . '). Check your API key.',
        ], 500);
    }

    public function getApprovalLogs(ChecklistSubmission $submission)
    {
        $logs = $submission->approvalLogs()->with('user')->get()->map(fn($log) => [
            'id'          => $log->id,
            'verdict'     => $log->verdict,
            'analysis'    => $log->analysis_result,
            'prompt_used' => $log->prompt_used,
            'user'        => $log->user?->name ?? 'Unknown',
            'created_at'  => $log->created_at->format('M j, Y g:i A'),
        ]);

        return response()->json(['logs' => $logs]);
    }

    private function runAutoAnalysis(ChecklistSubmission $submission): void
    {
        try {
            $submission->load(['task', 'files']);
            $task     = $submission->task;
            $imgFiles = $submission->files->filter(fn($f) => $f->isImage());

            if (!$task) return;

            // --- AI Analysis ---
            $prompt  = "You are reviewing a daily operational task submission for a business.\n\n";
            $prompt .= "Task: {$task->title}\n";
            if ($task->description) $prompt .= "Task Description: {$task->description}\n";
            if ($submission->notes) $prompt .= "Staff Notes: {$submission->notes}\n";
            if ($imgFiles->isEmpty()) $prompt .= "\n(No images were submitted for this task.)\n";

            if ($task->ai_prompt) {
                $prompt .= "\nAnalysis Focus: {$task->ai_prompt}";
                $prompt .= "\n\nUsing the above focus, provide a concise analysis in 2-4 sentences based on the submission.";
            } else {
                $prompt .= "\nBased on the above, provide a concise analysis in 2-4 sentences: Was the task completed properly? What do the images show (if any)? Any observations, concerns, or recommendations?";
            }

            $content = [['type' => 'text', 'text' => $prompt]];
            foreach ($imgFiles->take(5) as $f) {
                $content[] = [
                    'type'      => 'image_url',
                    'image_url' => ['url' => url(Storage::url($f->file_path)), 'detail' => 'auto'],
                ];
            }

            $response = Http::withHeaders(['Authorization' => 'Bearer ' . config('services.openai.key')])
                ->timeout(30)->post('https://api.openai.com/v1/chat/completions', [
                    'model'      => 'gpt-4o',
                    'max_tokens' => 512,
                    'messages'   => [['role' => 'user', 'content' => $content]],
                ]);

            if ($response->successful()) {
                ChecklistAnalysisLog::create([
                    'submission_id'   => $submission->id,
                    'user_id'         => Auth::id(),
                    'log_type'        => 'analysis',
                    'prompt_used'     => $prompt,
                    'analysis_result' => $response->json('choices.0.message.content'),
                ]);
            }

            // --- Approval Check ---
            $criteria    = $task->approval_prompt
                ?: 'Evaluate whether the submission properly completes the task based on the title, description, and submitted content (notes and/or images). Assess overall quality and completeness.';
            $todayManila = Carbon::now('Asia/Manila')->format('l, F j, Y \a\t g:i A T');
            $aPrompt     = "You are a quality control reviewer for a business daily checklist submission.\n\n";
            $aPrompt    .= "Today's date and time (Manila, Philippines): {$todayManila}\n\n";
            $aPrompt    .= "Task: {$task->title}\n";
            if ($task->description) $aPrompt .= "Description: {$task->description}\n";
            if ($submission->notes) $aPrompt .= "Staff Notes: {$submission->notes}\n";
            if ($imgFiles->isEmpty()) $aPrompt .= "\n(No images were submitted.)\n";
            $aPrompt .= "\nApproval Criteria: {$criteria}\n";
            $aPrompt .= "\nIMPORTANT: Your response MUST start with exactly \"APPROVED\" or \"NOT APPROVED\" on the first line, followed by a blank line, then your explanation in 2-3 sentences.";

            $aContent = [['type' => 'text', 'text' => $aPrompt]];
            foreach ($imgFiles->take(5) as $f) {
                $aContent[] = [
                    'type'      => 'image_url',
                    'image_url' => ['url' => url(Storage::url($f->file_path)), 'detail' => 'auto'],
                ];
            }

            $aResponse = Http::withHeaders(['Authorization' => 'Bearer ' . config('services.openai.key')])
                ->timeout(30)->post('https://api.openai.com/v1/chat/completions', [
                    'model'      => 'gpt-4o',
                    'max_tokens' => 512,
                    'messages'   => [['role' => 'user', 'content' => $aContent]],
                ]);

            if ($aResponse->successful()) {
                $text      = $aResponse->json('choices.0.message.content');
                $firstLine = strtoupper(trim(explode("\n", trim($text))[0]));
                $verdict   = str_starts_with($firstLine, 'NOT APPROVED') ? 'not_approved'
                           : (str_starts_with($firstLine, 'APPROVED')    ? 'approved' : 'unknown');

                ChecklistAnalysisLog::create([
                    'submission_id'   => $submission->id,
                    'user_id'         => Auth::id(),
                    'log_type'        => 'approval',
                    'prompt_used'     => $aPrompt,
                    'analysis_result' => $text,
                    'verdict'         => $verdict,
                ]);
            }
        } catch (\Exception $e) {
            // Silent fail — submission always succeeds even if AI errors
        }
    }
}
