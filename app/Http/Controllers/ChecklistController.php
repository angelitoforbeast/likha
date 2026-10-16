<?php

namespace App\Http\Controllers;

use App\Models\ChecklistTask;
use App\Models\ChecklistSubmission;
use App\Models\ChecklistSubmissionFile;
use App\Models\ChecklistSubmissionLog;
use App\Models\ChecklistAnalysisLog;
use App\Models\ChecklistTaskChange;
use App\Models\ChecklistSubmissionValue;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        $todaySubmissions = ChecklistSubmission::with(['user', 'files', 'logs.user', 'latestApproval'])
            ->where('date', $today)->get();

        // Group tasks: any user's submission per task
        $submissionsByTask   = $todaySubmissions->keyBy('checklist_task_id');
        // Individual tasks: only the current user's submission per task
        $mySubmissionsByTask = $todaySubmissions->where('user_id', Auth::id())->keyBy('checklist_task_id');

        // ── Time gate: hide tasks whose scheduled_time hasn't arrived yet ──────────
        $tasks = $tasks->filter(function ($t) use ($nowManila, $submissionsByTask, $mySubmissionsByTask) {
            if (!$t->scheduled_time) return true;   // no time restriction → always show

            // Already submitted today → keep visible regardless of time
            $sub = $t->submission_type === 'individual'
                ? $mySubmissionsByTask->get($t->id)
                : $submissionsByTask->get($t->id);
            if ($sub) return true;

            // Parse scheduled_time ("HH:MM:SS") as today in Manila time
            [$h, $m, $s] = array_pad(explode(':', $t->scheduled_time), 3, '00');
            $due = $nowManila->copy()->setTime((int)$h, (int)$m, (int)$s);

            return $nowManila->gte($due);   // visible only if now >= scheduled time
        });
        // ──────────────────────────────────────────────────────────────────────────

        // Precompute true "done" per task: submission exists AND photo requirement fully met
        $doneByTask = [];
        foreach ($tasks as $t) {
            $sub = $t->submission_type === 'individual'
                ? $mySubmissionsByTask->get($t->id)
                : $submissionsByTask->get($t->id);
            if (!$sub) { $doneByTask[$t->id] = false; continue; }
            $reqPhotos  = (int)($t->required_photos ?? 0);
            $photoCount = $sub->files->filter(fn($f) => $f->isImage())->count();
            $doneByTask[$t->id] = $reqPhotos === 0 || $photoCount >= $reqPhotos;
        }

        // Status kada task, galing sa pinakahuling approval verdict ng submission ngayong araw:
        // approved / needs_fix (NOT APPROVED, o kulang pa ang photos) / checking (wala pang
        // verdict o hindi malinaw) / pending (wala pang submission).
        $statusByTask = [];
        foreach ($tasks as $t) {
            $sub = $t->submission_type === 'individual'
                ? $mySubmissionsByTask->get($t->id)
                : $submissionsByTask->get($t->id);
            $verdict = $sub?->latestApproval?->verdict;
            $state = match (true) {
                !$sub                       => 'pending',
                $verdict === 'not_approved' => 'needs_fix',
                !$doneByTask[$t->id]        => 'needs_fix',
                $verdict === 'approved'     => 'approved',
                default                     => 'checking',
            };
            $statusByTask[$t->id] = [
                'state'  => $state,
                'reason' => $verdict === 'not_approved' ? mb_substr((string) $sub->latestApproval->analysis_result, 0, 300) : null,
            ];
        }

        // Smart sort: kailangang ayusin at wala pang submission muna, sunod ang sinusuri pa,
        // approved sa dulo; timed tasks before untimed within each group
        $rank  = ['needs_fix' => 0, 'pending' => 0, 'checking' => 1, 'approved' => 2];
        $tasks = $tasks->sort(function ($a, $b) use ($statusByTask, $rank) {
            $aDone = $rank[$statusByTask[$a->id]['state']];
            $bDone = $rank[$statusByTask[$b->id]['state']];

            if ($aDone !== $bDone) return $aDone - $bDone;

            $aHasTime = $a->scheduled_time ? 0 : 1;
            $bHasTime = $b->scheduled_time ? 0 : 1;

            if ($aHasTime !== $bHasTime) return $aHasTime - $bHasTime;

            if ($a->scheduled_time && $b->scheduled_time) {
                return strcmp($a->scheduled_time, $b->scheduled_time);
            }

            return $a->sort_order - $b->sort_order;
        })->values();

        $states = collect($statusByTask)->pluck('state')->countBy();
        $counts = [
            'approved'     => $states->get('approved', 0),
            'needsFix'     => $states->get('needs_fix', 0),
            'checking'     => $states->get('checking', 0),
            'notSubmitted' => $states->get('pending', 0),
        ];
        // "Tapos" = approved lang; ang progress bar at "All tasks completed" ay dito nakabase
        $doneCount  = $counts['approved'];
        $totalTasks = $tasks->count();

        return view('checklist.index', compact(
            'tasks', 'submissionsByTask', 'mySubmissionsByTask',
            'today', 'doneCount', 'totalTasks', 'statusByTask', 'counts'
        ));
    }

    public function report(Request $request)
    {
        if ($request->query('view') === 'range') {
            return $this->rangeReport($request);
        }

        $date = $request->query('date', now()->toDateString());

        try {
            $dateObj = \Carbon\Carbon::parse($date);
        } catch (\Exception $e) {
            $dateObj = now();
        }

        $isToday = $dateObj->isToday();

        // Access policy: CEO sees ALL tasks + ALL submissions across users.
        // OIC sees their department's tasks plus tasks they created or are assigned to;
        // other users only see tasks where they're explicitly assigned
        // (via the assignedUsers pivot). Submissions are correspondingly
        // filtered to those tasks the user can see.
        $userId   = Auth::id();
        $isCEO    = $this->isCeo();

        if ($isToday) {
            // Today: same as index — only currently active tasks
            $taskQuery = ChecklistTask::with('assignedUsers')
                ->where('is_active', true);
        } else {
            // Past date: show tasks that actually existed on that day
            //   created_at <= end of report date  (task existed by then)
            //   AND (deleted_at IS NULL OR deleted_at > end of report date)  (not yet deleted)
            $endOfDay = $dateObj->copy()->endOfDay();

            $taskQuery = ChecklistTask::withTrashed()
                ->with('assignedUsers')
                ->whereDate('created_at', '<=', $dateObj->toDateString())
                ->where(function ($q) use ($endOfDay) {
                    $q->whereNull('deleted_at')
                      ->orWhere('deleted_at', '>', $endOfDay);
                });
        }

        // Non-CEO: OIC = department niya + ginawa o naka-assign; ang iba = naka-assign lang.
        $this->scopeReportTasks($taskQuery);

        $tasks = $taskQuery->orderBy('sort_order')->orderBy('id')->get();

        // ── Frequency filter — mirror ng index() para aligned ang views ──
        // Kung index() hides a task on this day (e.g., weekly on a wrong day, or
        // a once-ever task that's already been submitted), the report should
        // also hide it. Otherwise the report falsely shows "pending" tasks na
        // hindi naman lalabas sa user's actual checklist.
        //
        // For 'once' frequency, treat as DONE when any submission exists with
        // `date <= reportDate`. This lets past-date reports correctly show
        // the task as still-pending FOR THAT DAY (kung wala pa submission noon),
        // and hide it sa days on/after the actual completion.
        $reportDateStr = $dateObj->toDateString();
        $tasks = $tasks->filter(function ($t) use ($dateObj, $reportDateStr) {
            return match($t->frequency ?? 'daily') {
                'weekly'  => (int) $dateObj->dayOfWeek === (int) $t->frequency_day,
                'monthly' => (int) $dateObj->day       === (int) $t->frequency_day,
                'once'    => !ChecklistSubmission::where('checklist_task_id', $t->id)
                                ->where('date', '<=', $reportDateStr)
                                ->exists(),
                default   => true, // daily — show always (per-date submission check happens elsewhere)
            };
        })->values();

        // Submissions for this date — load all that the user is allowed to see.
        $subQuery = ChecklistSubmission::with(['user', 'files', 'logs.user', 'latestAnalysis.user', 'latestApproval.user', 'values'])
            ->withCount(['analysisLogs', 'approvalLogs'])
            ->where('date', $dateObj->toDateString());

        if (!$isCEO && $userId) {
            // Non-CEO: only submissions for tasks they're assigned to.
            $allowedTaskIds = $tasks->pluck('id')->all();
            if (empty($allowedTaskIds)) {
                $subQuery->whereRaw('1=0'); // no allowed tasks → empty result
            } else {
                $subQuery->whereIn('checklist_task_id', $allowedTaskIds);
            }
        }

        $allSubmissions = $subQuery->get();

        $submissionsByTask        = $allSubmissions->keyBy('checklist_task_id');     // group: one per task
        $submissionsGroupedByTask = $allSubmissions->groupBy('checklist_task_id');   // individual: all per task

        // Safety net: if a submission exists for a task not in our list, pull it in.
        // For non-CEO viewers, only pull tasks the user is actually assigned to —
        // we don't want this fallback to leak unrelated tasks.
        if (!$isToday) {
            $missingIds = $submissionsByTask->keys()->diff($tasks->pluck('id'));
            if ($missingIds->isNotEmpty()) {
                $extraQuery = ChecklistTask::withTrashed()
                    ->with('assignedUsers')
                    ->whereIn('id', $missingIds);
                $this->scopeReportTasks($extraQuery);
                $extra = $extraQuery->get();
                if ($extra->isNotEmpty()) {
                    $tasks = $tasks->merge($extra)->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values();
                }
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

    /**
     * "Over several days" ng /checklist/report (?view=range): buod kada araw, kada task at
     * kada tao sa isang date range (default huling 14 araw, hanggang 62).
     *
     * Visibility = day view (scopeReportTasks): CEO lahat; OIC ang department niya at ang ginawa o
     * naka-assign sa kanya; ang iba, ang mga task lang na naka-assign sa kanila.
     * Due rules = day view (frequency, frequency_day, once, created on/before ang araw, hindi
     * pa deleted noon), dagdag ang is_active. Lahat ng bilang ay galing sa iisang GROUP BY
     * (date, task, user) — walang query kada cell.
     */
    private function rangeReport(Request $request)
    {
        $parseDay = function ($value, Carbon $fallback): Carbon {
            try {
                return is_string($value) && $value !== '' ? Carbon::parse($value)->startOfDay() : $fallback;
            } catch (\Exception $e) {
                return $fallback;
            }
        };
        $today = now()->startOfDay();
        $to    = $parseDay($request->query('to'), $today->copy())->min($today);
        $from  = $parseDay($request->query('from'), $to->copy()->subDays(13))->min($to);
        if ($from->diffInDays($to) > 61) {
            $from = $to->copy()->subDays(61);
        }

        $dayObjs = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $dayObjs[$d->toDateString()] = $d->copy();
        }
        $days = array_keys($dayObjs);

        // Tasks na buhay kahit isang araw sa range
        $taskQuery = ChecklistTask::withTrashed()
            ->with('assignedUsers')
            ->where('is_active', true)
            ->whereDate('created_at', '<=', $to->toDateString())
            ->where(function ($q) use ($from) {
                $q->whereNull('deleted_at')
                  ->orWhere('deleted_at', '>', $from->copy()->endOfDay());
            });
        $this->scopeReportTasks($taskQuery);
        $tasks    = $taskQuery->orderBy('sort_order')->orderBy('id')->get();
        $allDepts = $tasks->pluck('department')->filter()->unique()->sort()->values()->all();
        $dept     = is_string($request->query('dept')) ? trim($request->query('dept')) : '';
        if ($dept !== '') {
            $tasks = $tasks->filter(fn ($t) => mb_strtolower((string) $t->department) === mb_strtolower($dept))->values();
        }
        $taskIds = $tasks->pluck('id')->all();

        if ($request->query('csv') === 'values') {
            return $this->valuesCsv($tasks, $from->toDateString(), $to->toDateString());
        }

        // 'once': due hanggang sa araw ng unang submission (kasama), hindi na pagkatapos
        $onceIds   = $tasks->where('frequency', 'once')->pluck('id')->all();
        $firstDone = empty($onceIds) ? collect() : DB::table('checklist_submissions')
            ->whereIn('checklist_task_id', $onceIds)
            ->groupBy('checklist_task_id')
            ->selectRaw('checklist_task_id, MIN(checklist_submissions.date) AS first_date')
            ->pluck('first_date', 'checklist_task_id')
            ->map(fn ($d) => substr((string) $d, 0, 10));

        // Submissions kada (araw, task, user), may bilang ng pinakahuling approval verdict
        // ng bawat submission (hindi bawat re-run).
        $rows = collect();
        if (!empty($taskIds)) {
            $latestVerdict = DB::table('checklist_analysis_logs as l')
                ->selectRaw("COALESCE(l.verdict, 'unknown')")
                ->whereColumn('l.submission_id', 's.id')
                ->where('l.log_type', 'approval')
                ->orderByDesc('l.created_at')
                ->orderByDesc('l.id')
                ->limit(1);
            $perSubmission = DB::table('checklist_submissions as s')
                ->select('s.date as sub_day', 's.checklist_task_id as task_id', 's.user_id as user_id')
                ->selectSub($latestVerdict, 'verdict')
                ->whereIn('s.checklist_task_id', $taskIds)
                // < kinabukasan: tama rin kahit may oras ang naka-store na date
                ->where('s.date', '>=', $from->toDateString())
                ->where('s.date', '<', $to->copy()->addDay()->toDateString());
            $rows = DB::query()->fromSub($perSubmission, 'x')
                ->selectRaw("x.sub_day, x.task_id, x.user_id, COUNT(*) AS subs,
                    SUM(CASE WHEN x.verdict = 'approved' THEN 1 ELSE 0 END) AS approved,
                    SUM(CASE WHEN x.verdict = 'not_approved' THEN 1 ELSE 0 END) AS not_approved,
                    SUM(CASE WHEN x.verdict IS NOT NULL AND x.verdict NOT IN ('approved', 'not_approved') THEN 1 ELSE 0 END) AS unknown")
                ->groupBy('x.sub_day', 'x.task_id', 'x.user_id')
                ->get();
        }

        $summary = array_fill_keys($days, ['due' => 0, 'done' => 0, 'pct' => null, 'approved' => 0, 'notApproved' => 0, 'unknown' => 0]);
        $submittedOn = [];   // [task_id][day] = true
        $submittedBy = [];   // [task_id][day][user_id] = true
        $taskSubs    = [];   // task_id => [subs, not_approved]
        $userStats   = [];   // user_id => [subs, approved, notApproved, days[day] = true]
        foreach ($rows as $r) {
            $day = substr((string) $r->sub_day, 0, 10);
            $tid = (int) $r->task_id;
            $uid = (int) $r->user_id;
            $submittedOn[$tid][$day] = true;
            $submittedBy[$tid][$day][$uid] = true;
            $taskSubs[$tid]['subs']        = ($taskSubs[$tid]['subs'] ?? 0) + (int) $r->subs;
            $taskSubs[$tid]['notApproved'] = ($taskSubs[$tid]['notApproved'] ?? 0) + (int) $r->not_approved;
            if (isset($summary[$day])) {
                $summary[$day]['approved']    += (int) $r->approved;
                $summary[$day]['notApproved'] += (int) $r->not_approved;
                $summary[$day]['unknown']     += (int) $r->unknown;
            }
            $userStats[$uid]['subs']        = ($userStats[$uid]['subs'] ?? 0) + (int) $r->subs;
            $userStats[$uid]['approved']    = ($userStats[$uid]['approved'] ?? 0) + (int) $r->approved;
            $userStats[$uid]['notApproved'] = ($userStats[$uid]['notApproved'] ?? 0) + (int) $r->not_approved;
            $userStats[$uid]['days'][$day]  = true;
        }

        $isDue = function (ChecklistTask $t, Carbon $day) use ($firstDone): bool {
            $dayStr = $day->toDateString();
            if ($t->created_at && $t->created_at->toDateString() > $dayStr) return false;
            if ($t->deleted_at && $t->deleted_at->lte($day->copy()->endOfDay())) return false;

            return match ($t->frequency ?? 'daily') {
                'weekly'  => (int) $day->dayOfWeek === (int) $t->frequency_day,
                'monthly' => (int) $day->day       === (int) $t->frequency_day,
                'once'    => !isset($firstDone[$t->id]) || $firstDone[$t->id] >= $dayStr,
                default   => true,
            };
        };

        // Cell: done = may submission noong araw na iyon; missing = due pero wala; none = hindi due.
        // Ang submission sa araw na hindi due ay "done" sa grid pero hindi bilang sa mga %.
        $taskRows    = [];
        $userDueDays = [];   // user_id => [day] = true
        $userOpenDays = [];  // user_id => [day] = true: may group task na hindi pa tapos ng team
        $userMissedDays = []; // user_id => [day] = true: may individual task na hindi niya naisumite
        foreach ($tasks as $t) {
            $cells = [];
            $due   = 0;
            $done  = 0;
            $notSubmitted = [];   // individual: [day] = mga pangalang hindi nagsumite
            $individual   = ($t->submission_type ?? 'group') === 'individual';
            foreach ($dayObjs as $dayStr => $dayObj) {
                $submitted = isset($submittedOn[$t->id][$dayStr]);
                $taskDue   = $isDue($t, $dayObj);
                $cells[$dayStr] = $submitted ? 'done' : ($taskDue ? 'missing' : 'none');
                if (!$taskDue) continue;
                $due++;
                $summary[$dayStr]['due']++;
                if ($submitted) {
                    $done++;
                    $summary[$dayStr]['done']++;
                }
                // Group task na may submission na noong araw: tapos na para sa buong team, kaya hindi
                // "missed" ng kahit sinong naka-assign. Individual: missed ng bawat taong hindi
                // nagsumite ng task na iyon, kahit may iba siyang naisumite noong araw.
                foreach ($t->assignedUsers as $u) {
                    $userDueDays[$u->id][$dayStr] = true;
                    if ($individual) {
                        if (!isset($submittedBy[$t->id][$dayStr][$u->id])) {
                            $userMissedDays[$u->id][$dayStr] = true;
                            $notSubmitted[$dayStr][] = $u->name;
                        }
                    } elseif (!$submitted) {
                        $userOpenDays[$u->id][$dayStr] = true;
                    }
                }
            }
            $subs = $taskSubs[$t->id]['subs'] ?? 0;
            if ($due === 0 && $subs === 0) continue;   // hal. 'once' na tapos na bago ang range
            $notApproved = $taskSubs[$t->id]['notApproved'] ?? 0;
            $taskRows[] = [
                'task'           => $t,
                'cells'          => $cells,
                'due'            => $due,
                'done'           => $done,
                'pct'            => $due > 0 ? (int) round($done / $due * 100) : null,
                'subs'           => $subs,
                'notApproved'    => $notApproved,
                'notApprovedPct' => $subs > 0 ? (int) round($notApproved / $subs * 100) : null,
                'notSubmitted'   => $notSubmitted,
            ];
        }
        // Pinakamahina sa itaas; ang walang due day ay sa dulo
        usort($taskRows, fn ($a, $b) => ($a['pct'] ?? 101) <=> ($b['pct'] ?? 101));

        foreach ($summary as $day => $s) {
            $summary[$day]['pct'] = $s['due'] > 0 ? (int) round($s['done'] / $s['due'] * 100) : null;
        }

        // Kada tao: lahat ng naka-assign sa kahit isang nakikitang task
        $people = [];
        foreach ($tasks->flatMap(fn ($t) => $t->assignedUsers)->unique('id') as $u) {
            $stats   = $userStats[$u->id] ?? [];
            $dueDays = array_keys($userDueDays[$u->id] ?? []);
            $people[] = [
                'user'        => $u,
                'subs'        => $stats['subs'] ?? 0,
                'approved'    => $stats['approved'] ?? 0,
                'notApproved' => $stats['notApproved'] ?? 0,
                'dueDays'     => count($dueDays),
                'missed'      => count(array_unique(array_merge(
                    array_keys($userMissedDays[$u->id] ?? []),
                    array_filter(array_keys($userOpenDays[$u->id] ?? []), fn ($d) => !isset($stats['days'][$d]))
                ))),
            ];
        }
        usort($people, fn ($a, $b) => [$b['missed'], $a['user']->name] <=> [$a['missed'], $b['user']->name]);

        $from = $from->toDateString();
        $to   = $to->toDateString();

        $csv = $request->query('csv');
        if ($csv === 'tasks' || $csv === 'people') {
            return $this->rangeCsv($csv, $days, $taskRows, $people, $from, $to);
        }

        return view('checklist.report-range', compact(
            'days', 'summary', 'taskRows', 'people', 'from', 'to', 'dept', 'allDepts'
        ));
    }

    /** CSV ng per-task o per-person table ng range view. */
    private function rangeCsv(string $part, array $days, array $taskRows, array $people, string $from, string $to)
    {
        // Ang text na galing sa user (title, department, pangalan) ay hindi dapat basahin ng Excel bilang formula
        $text = fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;

        if ($part === 'tasks') {
            $header = array_merge(['Task', 'Department'], $days,
                ['Due days', 'Done', 'Completion %', 'Submissions', 'Not approved', 'Not approved %']);
            $lines = array_map(fn ($r) => array_merge(
                [$text($r['task']->title), $text($r['task']->department ?? '')],
                array_map(fn ($c) => $c === 'none' ? '' : $c, array_values($r['cells'])),
                [$r['due'], $r['done'], $r['pct'] ?? '', $r['subs'], $r['notApproved'], $r['notApprovedPct'] ?? '']
            ), $taskRows);
        } else {
            $header = ['Person', 'Submissions', 'Approved', 'Not approved', 'Days with a due task', 'Missed days'];
            $lines  = array_map(fn ($p) => [
                $text($p['user']->name), $p['subs'], $p['approved'], $p['notApproved'], $p['dueDays'], $p['missed'],
            ], $people);
        }

        return response()->streamDownload(function () use ($header, $lines) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $header, ',', '"', '');
            foreach ($lines as $line) {
                fputcsv($out, $line, ',', '"', '');
            }
            fclose($out);
        }, "checklist-{$part}-{$from}-to-{$to}.csv", ['Content-Type' => 'text/csv']);
    }

    /**
     * "Values" CSV ng range view: isang row kada value na nakuha ng AI (date, task, field, value,
     * submitter), para sa mga task na nakikita sa view (parehong visibility at department filter).
     */
    private function valuesCsv($tasks, string $from, string $to)
    {
        // Ang text na galing sa user o sa AI ay hindi dapat basahin ng Excel bilang formula
        $text   = fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;
        $byId   = $tasks->keyBy('id');
        $values = $tasks->isEmpty() ? collect() : ChecklistSubmissionValue::query()
            ->join('checklist_submissions as s', 's.id', '=', 'checklist_submission_values.submission_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->whereIn('checklist_submission_values.checklist_task_id', $byId->keys()->all())
            ->where('checklist_submission_values.date', '>=', $from)
            ->where('checklist_submission_values.date', '<', Carbon::parse($to)->addDay()->toDateString())
            ->orderBy('checklist_submission_values.date')
            ->orderBy('checklist_submission_values.checklist_task_id')
            ->orderBy('checklist_submission_values.id')
            ->get(['checklist_submission_values.*', 'u.name as submitter']);

        $lines = $values->map(function ($v) use ($byId, $text) {
            $task  = $byId->get($v->checklist_task_id);
            $label = collect($task?->extract_fields ?? [])->firstWhere('key', $v->field_key)['label'] ?? $v->field_key;

            return [
                substr((string) $v->getRawOriginal('date'), 0, 10),
                $text($task?->title ?? ''),
                $text($label),
                $v->value_text !== null ? $text($v->plain()) : $v->plain(),
                $text($v->submitter ?? ''),
            ];
        });

        return response()->streamDownload(function () use ($lines) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Task', 'Field', 'Value', 'Submitter'], ',', '"', '');
            foreach ($lines as $line) {
                fputcsv($out, $line, ',', '"', '');
            }
            fclose($out);
        }, "checklist-values-{$from}-to-{$to}.csv", ['Content-Type' => 'text/csv']);
    }

    public function manage()
    {
        $user   = Auth::user();
        $dept   = $this->oicDepartment();
        $userId = $user?->id;

        $query = ChecklistTask::with(['assignedUsers', 'creator'])
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($this->isCeo()) {
            // sees all — no filter
        } elseif ($this->isOic() && $dept !== '') {
            // OIC sees tasks tagged with their department (trimmed, case-insensitive, gaya ng isOicOf),
            // pati ang mga task na siya ang gumawa kahit ibang department (hal. para sa Encoders)
            // Ang Marketing - OIC ay nakakakita rin ng naka-assign sa kanya, dahil puwede niyang i-edit ang mga iyon.
            $query->where(function ($q) use ($dept, $userId) {
                $q->whereRaw('LOWER(TRIM(department)) = ?', [$dept])
                  ->orWhere('created_by', $userId);
                if ($this->isMarketing()) {
                    $q->orWhereHas('assignedUsers', fn ($r) => $r->where('users.id', $userId));
                }
            });
        } else {
            // others see tasks they created OR are assigned to
            $query->where(function ($q) use ($userId) {
                $q->where('created_by', $userId)
                  ->orWhereHas('assignedUsers', fn ($r) => $r->where('users.id', $userId));
            });
        }

        $allTasks = $query->get();
        $allUsers = User::with('employeeProfile')->orderBy('name')->get();
        // "History" link: sa mga task lang na puwede mong i-edit
        $editableIds = $allTasks->filter(fn ($t) => $this->canEditTaskContent($t))->pluck('id')->all();

        return view('checklist.manage', compact('allTasks', 'allUsers', 'editableIds'));
    }

    /**
     * /checklist/manage/history: sino ang nagbago ng aling task, kailan, at ano ang dati → bago.
     * Pinakabago muna. Nakikita lang ang mga task na puwede mong i-edit (CEO lahat; OIC ang
     * department niya; ang gumawa ang sarili niyang task). ?task= = iisang task (403 kung hindi sa iyo).
     */
    public function history(Request $request)
    {
        $visible = ChecklistTask::withTrashed()->get(['id', 'title', 'department', 'created_by'])
            ->filter(fn ($t) => $this->canEditTaskContent($t))
            ->keyBy('id');

        $task = null;
        if ($request->filled('task')) {
            $task = ChecklistTask::withTrashed()->findOrFail((int) $request->query('task'));
            if (!$visible->has($task->id)) {
                $this->refuse('Hindi mo puwedeng tingnan ang kasaysayan ng task na ito.');
            }
        }

        $parseDay = function ($value, ?Carbon $fallback): ?Carbon {
            try {
                return is_string($value) && $value !== '' ? Carbon::parse($value)->startOfDay() : $fallback;
            } catch (\Exception $e) {
                return $fallback;
            }
        };
        $to     = $parseDay($request->query('to'), now()->startOfDay());
        // Iisang task: buong kasaysayan; ang buong listahan: huling 30 araw
        $from   = $parseDay($request->query('from'), $task ? null : now()->startOfDay()->subDays(29));
        $userId = (int) $request->query('user');

        $taskIds = $task ? [$task->id] : $visible->keys()->all();
        $users   = User::whereIn('id', ChecklistTaskChange::whereIn('checklist_task_id', $taskIds)->select('user_id'))
            ->orderBy('name')->get(['id', 'name']);

        $changes = ChecklistTaskChange::with('user')
            ->whereIn('checklist_task_id', $taskIds)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->where('created_at', '<', $to->copy()->addDay())
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(100)->withQueryString();

        // Mga pangalan para sa "Assigned to" (id ang naka-store)
        $names = User::whereIn('id', $changes->getCollection()
                ->flatMap(fn ($c) => collect($c->changes['assigned_users'] ?? [])->flatten())->unique()->all())
            ->pluck('name', 'id');
        $show = function (string $field, $v) use ($names): string {
            if ($field === 'assigned_users') {
                return $v ? collect($v)->map(fn ($id) => $names[$id] ?? "#$id")->implode(', ') : 'Anyone';
            }
            if ($field === 'extract_fields') {
                return $v ? collect($v)->map(fn ($f) => ($f['label'] ?? '?') . ' (' . ($f['type'] ?? '?') . ')')->implode(', ') : '—';
            }
            if ($v === null || $v === '') return '—';
            if (is_bool($v)) return $v ? 'Yes' : 'No';
            if (is_array($v)) return json_encode($v, JSON_UNESCAPED_UNICODE);

            return \Illuminate\Support\Str::limit((string) $v, 300);
        };
        $rows = $changes->getCollection()->map(fn ($c) => [
            'when'   => $c->created_at,
            'who'    => $c->user?->name ?? '—',
            'action' => ChecklistTaskChange::ACTION_LABELS[$c->action] ?? $c->action,
            'task'   => $visible->get($c->checklist_task_id)?->title ?? '#' . $c->checklist_task_id,
            'lines'  => collect($c->changes ?? [])->map(fn ($pair, $field) => [
                'label'  => ChecklistTaskChange::FIELD_LABELS[$field] ?? $field,
                'before' => $show($field, $pair[0] ?? null),
                'after'  => $show($field, $pair[1] ?? null),
            ])->values()->all(),
        ]);

        $from = $from?->toDateString();
        $to   = $to->toDateString();

        return view('checklist.history', compact('changes', 'rows', 'task', 'users', 'from', 'to', 'userId'));
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
        if ($submission->user_id !== Auth::id() && !$this->isCeo()) {
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

        if ($submission->user_id !== Auth::id() && !$this->isCeo()) {
            abort(403);
        }

        Storage::disk('public')->delete($file->file_path);
        $file->delete();

        return back()->with('success', 'File removed.');
    }

    public function storeTask(Request $request)
    {
        if (!$this->isCeo() && !$this->isOic()) {
            $this->refuse('CEO o OIC lang ang puwedeng gumawa ng task.');
        }

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
            ...$this->extractFieldRules(),
        ], self::EXTRACT_FIELD_MESSAGES);
        $validated = $this->cleanExtractFields($validated);

        $userIds = array_filter((array) $request->input('assigned_users', []));

        DB::transaction(function () use ($validated, $userIds) {
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
            $task->assignedUsers()->sync($userIds);

            $changes = collect($task->only(self::LOGGED_TASK_FIELDS))
                ->reject(fn ($v) => $v === null || $v === '' || $v === [])
                ->map(fn ($v) => [null, $v])
                ->all();
            if ($userIds) {
                $changes['assigned_users'] = [[], $this->idList($userIds)];
            }
            $this->logTaskChange($task->id, 'created', $changes);
        });

        return back()->with('success', 'Task added!');
    }

    public function updateTask(Request $request, ChecklistTask $task)
    {
        if (!$this->canEditTaskContent($task)) {
            $this->refuse('Hindi mo puwedeng baguhin ang task na ito.');
        }

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
            ...$this->extractFieldRules(),
        ], self::EXTRACT_FIELD_MESSAGES);
        // Ang form na walang extract_fields (hal. ang Disable button) ay hindi nagagalaw ang fields.
        $validated = $this->cleanExtractFields($validated);

        // Sync assigned users (empty array = all users can submit)
        $userIds = array_filter((array) $request->input('assigned_users', []));

        DB::transaction(function () use ($task, $validated, $userIds) {
            $beforeIds = $this->idList($task->assignedUsers()->pluck('users.id')->all());

            $task->fill($validated);
            $changes = $this->dirtyChanges($task);
            $task->save();
            $task->assignedUsers()->sync($userIds);

            if ($beforeIds !== $this->idList($userIds)) {
                $changes['assigned_users'] = [$beforeIds, $this->idList($userIds)];
            }
            if (array_key_exists('extract_fields', $changes)) {
                $this->logTaskChange($task->id, 'fields_changed', ['extract_fields' => $changes['extract_fields']]);
                unset($changes['extract_fields']);
            }
            // Ang pag-on/off ang hinahanap kapag may nawawalang task, kaya iyon ang action kapag kasama.
            $action = isset($changes['is_active']) ? ($task->is_active ? 'activated' : 'deactivated') : 'updated';
            $this->logTaskChange($task->id, $action, $changes);
        });

        return back()->with('success', 'Task updated!');
    }

    public function destroyTask(ChecklistTask $task)
    {
        $this->authorizeEditTask($task);

        // Hard delete — cascades to submissions and assignments via FK
        DB::transaction(function () use ($task) {
            $task->delete();
            $this->logTaskChange($task->id, 'deleted', ['deleted' => [false, true]]);
        });
        return back()->with('success', 'Task deleted.');
    }

    public function duplicateTask(ChecklistTask $task)
    {
        $this->authorizeEditTask($task);

        DB::transaction(function () use ($task) {
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
                'extract_fields'  => $task->extract_fields,
                'created_by'      => Auth::id(),
            ]);

            $new->assignedUsers()->sync($task->assignedUsers->pluck('id'));

            $this->logTaskChange($new->id, 'duplicated', [
                'copy_of' => [null, "#{$task->id} {$task->title}"],
                'title'   => [null, $new->title],
            ]);
        });

        return back()->with('success', 'Task duplicated! Edit the copy to rename it.');
    }

    public function bulkAssign(Request $request)
    {
        $this->authorizeManageTasks([]);
        $request->validate([
            'task_ids'   => 'required|array',
            'task_ids.*' => 'integer|exists:checklist_tasks,id',
            'user_ids'   => 'nullable|array',
            'user_ids.*' => 'nullable|integer|exists:users,id',
        ]);
        $this->authorizeManageTasks($request->input('task_ids'));

        // If "clear_assign" is checked, sync to empty (anyone can submit)
        $userIds = $request->boolean('clear_assign')
            ? []
            : array_filter((array) $request->input('user_ids', []));
        $count   = 0;
        DB::transaction(function () use ($request, $userIds, &$count) {
            foreach ($request->input('task_ids') as $taskId) {
                $task = ChecklistTask::find($taskId);
                if ($task) {
                    $beforeIds = $this->idList($task->assignedUsers()->pluck('users.id')->all());
                    $task->assignedUsers()->sync($userIds);
                    $count++;
                    if ($beforeIds !== $this->idList($userIds)) {
                        $this->logTaskChange($task->id, 'bulk_assigned', ['assigned_users' => [$beforeIds, $this->idList($userIds)]]);
                    }
                }
            }
        });

        return back()->with('success', $count . ' task(s) updated.');
    }

    public function bulkType(Request $request)
    {
        $this->authorizeManageTasks([]);
        $request->validate([
            'task_ids'   => 'required|array',
            'task_ids.*' => 'integer|exists:checklist_tasks,id',
            'type'       => 'required|in:any,photo,note,both',
        ]);
        $this->authorizeManageTasks($request->input('task_ids'));

        $count = $this->bulkUpdateLogged($request->input('task_ids'), 'type', $request->input('type'), 'bulk_type');

        return back()->with('success', $count . ' task(s) updated.');
    }

    public function bulkSubmissionType(Request $request)
    {
        $this->authorizeManageTasks([]);
        $request->validate([
            'task_ids'        => 'required|array',
            'task_ids.*'      => 'integer|exists:checklist_tasks,id',
            'submission_type' => 'required|in:group,individual',
        ]);
        $this->authorizeManageTasks($request->input('task_ids'));

        $count = $this->bulkUpdateLogged($request->input('task_ids'), 'submission_type', $request->input('submission_type'), 'bulk_submission_type');

        return back()->with('success', $count . ' task(s) updated.');
    }

    public function reorderTasks(Request $request)
    {
        $this->authorizeManageTasks((array) $request->input('order', []), true);

        $order = (array) $request->input('order', []);
        DB::transaction(function () use ($order) {
            $before = ChecklistTask::whereIn('id', $order)->pluck('sort_order', 'id')->all();
            foreach ($order as $index => $id) {
                ChecklistTask::where('id', $id)->update(['sort_order' => $index]);
                if (isset($before[$id]) && (int) $before[$id] !== (int) $index) {
                    $this->logTaskChange((int) $id, 'reordered', ['sort_order' => [(int) $before[$id], (int) $index]]);
                    $before[$id] = $index;
                }
            }
        });
        return response()->json(['ok' => true]);
    }

    public function analyzeSubmission(Request $request, ChecklistSubmission $submission)
    {
        $this->authorizeReviewSubmission($submission);

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
        $this->authorizeReviewSubmission($submission);

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
        $this->authorizeReviewSubmission($submission);

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
        $prompt .= $this->extractionPrompt($task);

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
            $text      = $this->takeExtractedValues($submission, $task, (string) $text);

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
        $this->authorizeReviewSubmission($submission);

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

    // ── Access rules ──────────────────────────────────────────────────────────
    // Iisang lugar ng lahat ng role check ng /checklist. Ang role ay galing sa
    // employeeProfile->role, trimmed at case-insensitive.

    private function checklistRole(): string
    {
        return strtoupper(trim((string) (Auth::user()?->employeeProfile?->role ?? '')));
    }

    private function isCeo(): bool
    {
        return $this->checklistRole() === 'CEO';
    }

    private function isOic(): bool
    {
        return str_contains($this->checklistRole(), 'OIC');
    }

    /**
     * Department ng OIC, lowercase at trimmed: ang nasa profile; kapag blangko, ang bahagi ng role
     * bago ang "- OIC" (hal. "Marketing - OIC" → "marketing"), dahil blangko ang department ng mga
     * OIC sa profile. Blangko kapag wala sa dalawa.
     */
    private function oicDepartment(): string
    {
        $profile = Auth::user()?->employeeProfile;
        $dept    = trim((string) ($profile?->department ?? ''));
        if ($dept === '' && preg_match('/^(.+?)\s*-\s*OIC\b/i', trim((string) ($profile?->role ?? '')), $m)) {
            $dept = trim($m[1]);
        }

        return mb_strtolower($dept);
    }

    /** OIC ng $department. Ang task na walang department ay hindi sakop ng kahit sinong OIC. */
    private function isOicOf(?string $department): bool
    {
        $mine = $this->oicDepartment();

        return $this->isOic() && $mine !== '' && $mine === mb_strtolower(trim((string) $department));
    }

    /** Reorder at bulk changes: CEO, o OIC ng department ng task. */
    private function canManageTask(ChecklistTask $task): bool
    {
        return $this->isCeo() || $this->isOicOf($task->department);
    }

    /** Edit, delete, duplicate (at ang kasaysayan ng task): pati ang gumawa ng task. */
    private function canEditTask(ChecklistTask $task): bool
    {
        $isCreator = $task->created_by !== null && (int) $task->created_by === (int) Auth::id();

        return $this->canManageTask($task) || $isCreator;
    }

    /** "Marketing" o "Marketing - OIC" (kahit anong espasyo sa paligid ng dash). */
    private function isMarketing(): bool
    {
        return (bool) preg_match('/^MARKETING(\s*-\s*OIC)?$/', $this->checklistRole());
    }

    /**
     * Edit form, updateTask at kasaysayan: ang canEditTask, pati ang Marketing / Marketing - OIC na
     * naka-assign sa task, dahil karamihan ng task nila ay walang department o gumawa. Ang delete,
     * duplicate at bulk ay hindi kasama.
     */
    private function canEditTaskContent(ChecklistTask $task): bool
    {
        return $this->canEditTask($task)
            || ($this->isMarketing() && $task->assignedUsers()->where('users.id', Auth::id())->exists());
    }

    private function authorizeEditTask(ChecklistTask $task): void
    {
        if (!$this->canEditTask($task)) {
            $this->refuse('Hindi mo puwedeng baguhin ang task na ito.');
        }
    }

    /** CEO o OIC lang; at bawat task sa $ids ay dapat sakop mo. Kapag may isang hindi, walang mababago. */
    private function authorizeManageTasks(array $ids, bool $json = false): void
    {
        $message = 'CEO o OIC ng department lang ang puwedeng mag-ayos ng mga task na ito.';
        if (!$this->isCeo() && !$this->isOic()) {
            $this->refuse($message, $json);
        }
        if ($this->isCeo() || empty($ids)) {
            return;
        }
        foreach (ChecklistTask::whereIn('id', $ids)->get() as $task) {
            if (!$this->canManageTask($task)) {
                $this->refuse($message, $json);
            }
        }
    }

    /**
     * Saklaw ng mga report (isang araw, ilang araw, CSV): CEO lahat; OIC ang mga task ng department
     * niya, ang ginawa niya at ang naka-assign sa kanya; ang iba, ang naka-assign lang sa kanila.
     */
    private function scopeReportTasks($query): void
    {
        if ($this->isCeo()) {
            return;
        }
        $userId = Auth::id();
        $isOic  = $this->isOic();
        $dept   = $this->oicDepartment();
        $query->where(function ($q) use ($userId, $isOic, $dept) {
            $q->whereHas('assignedUsers', fn ($r) => $r->where('users.id', $userId));
            if ($isOic) {
                $q->orWhere('created_by', $userId);
                if ($dept !== '') {
                    $q->orWhereRaw('LOWER(TRIM(department)) = ?', [$dept]);
                }
            }
        });
    }

    /** OIC na nakakakita ng task sa report: department niya, o siya ang gumawa o naka-assign. */
    private function oicSeesTask(ChecklistTask $task): bool
    {
        if (!$this->isOic()) {
            return false;
        }

        return $this->isOicOf($task->department)
            || (int) $task->created_by === (int) Auth::id()
            || $task->assignedUsers()->where('users.id', Auth::id())->exists();
    }

    /** AI analysis, approval check at ang logs nila: CEO, may-ari ng submission, o OIC na nakakakita ng task sa report. */
    private function authorizeReviewSubmission(ChecklistSubmission $submission): void
    {
        if ($this->isCeo() || (int) $submission->user_id === (int) Auth::id()) {
            return;
        }
        $task = ChecklistTask::withTrashed()->find($submission->checklist_task_id);
        if (!$task || !$this->oicSeesTask($task)) {
            $this->refuse('Sa sarili mong submission lang ito puwede (o sa department mo kung OIC ka).', true);
        }
    }

    /** 403: JSON {"ok":false,...} para sa fetch() ng page, HTML 403 para sa mga form. */
    private function refuse(string $message, bool $json = false): never
    {
        if ($json || request()->expectsJson()) {
            throw new HttpResponseException(response()->json(['ok' => false, 'message' => $message], 403));
        }
        abort(403, $message);
    }

    // ── Change log ng mga task ────────────────────────────────────────────────

    /** Ang mga field ng task na sinusulat sa checklist_task_changes kapag nagbago. */
    private const LOGGED_TASK_FIELDS = [
        'title', 'description', 'type', 'is_active', 'scheduled_time', 'submission_type', 'ai_prompt',
        'approval_prompt', 'required_photos', 'frequency', 'frequency_day', 'department', 'extract_fields',
    ];

    /** Isang row ng kasaysayan, ng naka-login na user. Kapag walang nagbago, walang row. */
    private function logTaskChange(int $taskId, string $action, array $changes): void
    {
        if (empty($changes)) {
            return;
        }
        ChecklistTaskChange::create([
            'checklist_task_id' => $taskId,
            'user_id'           => Auth::id(),
            'action'            => $action,
            'changes'           => $changes,
        ]);
    }

    /** {field: [before, after]} ng binago ng fill(), bago i-save. */
    private function dirtyChanges(ChecklistTask $task): array
    {
        $changes = [];
        foreach (array_keys($task->getDirty()) as $field) {
            $before = $task->getOriginal($field);
            $after  = $task->getAttribute($field);
            // "08:00" at "08:00:00" ay iisang oras (time input vs TIME column)
            if ($field === 'scheduled_time' && substr((string) $before, 0, 5) === substr((string) $after, 0, 5)) {
                continue;
            }
            // Iba ang ayos ng keys na ibinabalik ng JSON column ng MySQL; ang laman ang ikinukumpara
            $sorted = fn ($fields) => array_map(function ($f) { ksort($f); return $f; }, (array) $fields);
            if ($field === 'extract_fields' && $sorted($before) === $sorted($after)) {
                continue;
            }
            $changes[$field] = [$before, $after];
        }

        return $changes;
    }

    /** Ayos na listahan ng user id, para maikumpara ang dati at bagong assignees. */
    private function idList(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids))));
        sort($ids);

        return $ids;
    }

    /** Bulk na pagpalit ng isang column; may row lang ang mga task na talagang nagbago. */
    private function bulkUpdateLogged(array $ids, string $field, string $value, string $action): int
    {
        return DB::transaction(function () use ($ids, $field, $value, $action) {
            $before = ChecklistTask::whereIn('id', $ids)->pluck($field, 'id');
            $count  = ChecklistTask::whereIn('id', $ids)->update([$field => $value]);
            foreach ($before as $id => $old) {
                if ((string) $old !== $value) {
                    $this->logTaskChange((int) $id, $action, [$field => [$old, $value]]);
                }
            }

            return $count;
        });
    }

    // ── Fields to extract ─────────────────────────────────────────────────────

    /** Simula ng huling linya ng approval answer na may mga value (JSON). */
    private const VALUES_MARKER = 'EXTRACTED_VALUES:';

    private const EXTRACT_FIELD_MESSAGES = [
        'extract_fields.*.key.regex'    => 'Ang key ng field ay snake_case lang (a-z, 0-9, _), hal. total_sticker.',
        'extract_fields.*.key.distinct' => 'Dalawang field ang may parehong key; dapat iba-iba.',
        'extract_fields.*.type.in'      => 'Ang type ng field ay number, money, percent, text, date o yes_no lang.',
    ];

    private function extractFieldRules(): array
    {
        return [
            'extract_fields'         => 'nullable|array|max:20',
            'extract_fields.*.key'   => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct'],
            'extract_fields.*.label' => 'required|string|max:100',
            'extract_fields.*.type'  => ['required', \Illuminate\Validation\Rule::in(ChecklistSubmissionValue::TYPES)],
            'extract_fields.*.hint'  => 'nullable|string|max:300',
        ];
    }

    /** Pare-parehong hugis ng bawat field ({key, label, type, hint}); walang field = null. */
    private function cleanExtractFields(array $validated): array
    {
        if (array_key_exists('extract_fields', $validated)) {
            $fields = array_map(fn ($f) => [
                'key'   => $f['key'],
                'label' => $f['label'],
                'type'  => $f['type'],
                'hint'  => ($f['hint'] ?? null) ?: null,
            ], array_values($validated['extract_fields'] ?? []));
            $validated['extract_fields'] = $fields ?: null;
        }

        return $validated;
    }

    /** Dagdag sa approval prompt kapag may fields ang task; blangko kapag wala. */
    private function extractionPrompt(ChecklistTask $task): string
    {
        if (empty($task->extract_fields)) {
            return '';
        }
        $kinds = [
            'number' => 'a number', 'money' => 'an amount in PHP', 'percent' => 'a percentage',
            'text' => 'short text', 'date' => 'a date', 'yes_no' => 'true or false',
        ];
        $prompt  = "\n\nAfter your explanation, add ONE last line that starts with \"" . self::VALUES_MARKER . "\" followed by a JSON object on the same line, with exactly these keys. Read the values from the staff notes AND the images:\n";
        foreach ($task->extract_fields as $f) {
            $prompt .= "- {$f['key']}: {$f['label']} (" . ($kinds[$f['type']] ?? 'text') . ')' . (!empty($f['hint']) ? " — {$f['hint']}" : '') . "\n";
        }
        $prompt .= "Copy each value as written (e.g. \"1,612\", \"₱2,295.67\", \"2,01%\"). Use null when a value is not in the notes or images; never guess. The verdict stays on the first line.";

        return $prompt;
    }

    /**
     * Kinukuha ang values line sa approval answer at sine-save ang mga value (pinapalitan ang dati
     * ng submission). Ibinabalik ang answer na wala na ang values line. Kapag sira o wala ang
     * line: walang sine-save (nananatili ang dating value), warning lang; hindi naaapektuhan ang verdict.
     */
    private function takeExtractedValues(ChecklistSubmission $submission, ChecklistTask $task, string $text): string
    {
        if (empty($task->extract_fields)) {
            return $text;
        }
        $pos   = strrpos($text, self::VALUES_MARKER);
        $clean = $pos === false ? $text : rtrim(substr($text, 0, $pos));
        $rest  = $pos === false ? '' : substr($text, $pos + strlen(self::VALUES_MARKER));
        $start = strpos($rest, '{');
        $end   = strrpos($rest, '}');
        $data  = $start !== false && $end > $start ? json_decode(substr($rest, $start, $end - $start + 1)) : null;

        if (!$data instanceof \stdClass) {
            Log::warning('Checklist values: malformed AI answer, nothing stored', ['submission_id' => $submission->id]);
            return $clean;
        }

        try {
            $data = (array) $data;
            DB::transaction(function () use ($submission, $task, $data) {
                ChecklistSubmissionValue::where('submission_id', $submission->id)->delete();
                foreach ($task->extract_fields as $f) {
                    ChecklistSubmissionValue::create([
                        ...ChecklistSubmissionValue::fromAi($f['type'], $data[$f['key']] ?? null),
                        'submission_id'     => $submission->id,
                        'checklist_task_id' => $task->id,
                        'date'              => substr((string) $submission->getRawOriginal('date'), 0, 10),
                        'field_key'         => $f['key'],
                        'source'            => 'ai',
                    ]);
                }
            });
        } catch (\Throwable $e) {
            Log::warning('Checklist values: could not store values', ['submission_id' => $submission->id, 'error' => $e->getMessage()]);
        }

        return $clean;
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
            $aPrompt .= $this->extractionPrompt($task);

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
                $text      = $this->takeExtractedValues($submission, $task, (string) $text);

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
