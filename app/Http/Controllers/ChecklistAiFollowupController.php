<?php

namespace App\Http\Controllers;

use App\Models\ChecklistAiFollowup;
use App\Services\AiFollowups;
use App\Services\ChecklistDueToday;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * /checklist/ai-followups (spec 293): ang inbox ng CEO para sa mga draft ng Marketing head. Approve, edit and approve,
 * o reject na may dahilan; ang Sent ay may Waiting / Done / Expired; ang switch, budget at mga presyo ay dito rin.
 * CEO lang (parehong check ng ChecklistController::isCeo()); 403 sa lahat ng iba, pati OIC.
 */
class ChecklistAiFollowupController extends Controller
{
    private const TABS = ['drafts', 'sent', 'rejected'];

    private const LIST_LIMIT = 100;

    public function index(Request $request)
    {
        $this->authorizeCeo();
        $now = Carbon::now(AiFollowups::TZ);
        AiFollowups::expireStale($now);
        AiFollowups::syncDone($now);

        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'drafts';
        $with = ['assignedUsers.employeeProfile', 'followsTask', 'reviewer.employeeProfile'];
        $drafts = ChecklistAiFollowup::with($with)->where('review_status', 'draft')
            ->orderByDesc('created_at')->orderByDesc('id')->get();
        $sent = ChecklistAiFollowup::with($with)->where('review_status', 'approved')
            ->orderByDesc('reviewed_at')->orderByDesc('id')->limit(self::LIST_LIMIT)->get();
        $rejected = ChecklistAiFollowup::with($with)->whereIn('review_status', ['rejected', 'expired'])
            ->orderByDesc('updated_at')->orderByDesc('id')->limit(self::LIST_LIMIT)->get();
        $doneIds = AiFollowups::doneIds($sent);
        $states = $sent->mapWithKeys(fn ($f) => [$f->id => AiFollowups::state($f, $doneIds, $now)]);

        $settings = AiFollowups::settings();
        $budget = AiFollowups::budget($now, $settings);
        $window = AiFollowups::window($now);
        $card = fn (ChecklistAiFollowup $f) => $this->card($f, $settings);

        return view('checklist.ai-followups', compact(
            'tab', 'drafts', 'sent', 'rejected', 'states', 'settings', 'budget', 'window', 'card'
        ));
    }

    public function approve(Request $request, int $followup)
    {
        $this->authorizeCeo();
        $f = $this->draft($followup);
        $deadline = AiFollowups::clampDeadline($f->deadline_time, Carbon::now(AiFollowups::TZ));
        if ($deadline === null) {
            return $this->fail('Too late to send today: the last deadline is 9:00 PM and it must be at least 30 minutes from now.', 422);
        }
        AiFollowups::approve($f, Auth::user(), (string) $f->description, $deadline);

        return $this->ok($request, $f, 'Approved. It now shows on their checklist.');
    }

    public function edit(Request $request, int $followup)
    {
        $this->authorizeCeo();
        $f = $this->draft($followup);
        $validated = $request->validate([
            'message'  => 'required|string|max:' . AiFollowups::MAX_CHARS,
            'deadline' => 'required|date_format:H:i',
        ]);
        $window = AiFollowups::window(Carbon::now(AiFollowups::TZ));
        $minutes = ChecklistDueToday::minutes($validated['deadline']);
        if ($window === null || $minutes < $window[0] || $minutes > $window[1]) {
            throw ValidationException::withMessages(['deadline' => $window === null
                ? 'Too late to send today: the last deadline is 9:00 PM.'
                : 'Pick a time today between ' . AiFollowups::clock(ChecklistDueToday::hhmm($window[0])) . ' and 9:00 PM.']);
        }
        AiFollowups::approve($f, Auth::user(), trim($validated['message']), ChecklistDueToday::hhmm($minutes));

        return $this->ok($request, $f, 'Edited and approved. It now shows on their checklist.');
    }

    public function reject(Request $request, int $followup)
    {
        $this->authorizeCeo();
        $f = $this->draft($followup);
        $validated = $request->validate(['note' => 'required|string|max:' . AiFollowups::MAX_NOTE]);
        AiFollowups::reject($f, Auth::user(), trim($validated['note']));

        return $this->ok($request, $f, 'Rejected. Your reason is kept for the next drafts.');
    }

    public function settings(Request $request)
    {
        $this->authorizeCeo();
        $validated = $request->validate([
            'enabled'           => 'sometimes|boolean',
            'monthly_cap_php'   => 'sometimes|numeric|min:0|max:100000',
            'php_per_usd'       => 'sometimes|numeric|gt:0|max:1000',
            'input_usd_per_1m'  => 'sometimes|numeric|min:0|max:1000',
            'output_usd_per_1m' => 'sometimes|numeric|min:0|max:1000',
        ]);
        if (array_key_exists('enabled', $validated)) {
            $validated['enabled'] = filter_var($validated['enabled'], FILTER_VALIDATE_BOOLEAN);
        }
        AiFollowups::saveSettings($validated);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'settings' => AiFollowups::settings()]);
        }

        return back()->with('success', 'Settings saved.');
    }

    /** Ang mga value na kailangan ng isang card (draft, sent o rejected), handa na para sa Blade. */
    private function card(ChecklistAiFollowup $f, array $settings): array
    {
        $person = $f->person();
        $name = AiFollowups::displayName($person) ?: 'Unknown';
        $profile = $person?->employeeProfile;
        $task = $f->followsTask;
        $taskDeadline = $f->ai_draft['task_deadline'] ?? ($task
            ? (($m = ChecklistDueToday::deadlineMinutes($task->scheduled_time, $task->deadline_time)) === null ? null : ChecklistDueToday::hhmm($m))
            : null);
        $pesos = (float) $f->ai_cost_usd * $settings['php_per_usd'];

        return [
            'id'         => $f->id,
            'name'       => $name,
            'initials'   => collect(preg_split('/\s+/', $name))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode(''),
            'department' => $this->department($profile?->department, $profile?->role),
            'task'       => $task?->title ?? $f->title,
            'task_id'    => $f->follows_task_id,
            'reason'     => $f->follows_reason,
            'late_since' => AiFollowups::clock($taskDeadline),
            'verdict'    => $f->ai_draft['verdict_reason'] ?? null,
            'message'    => (string) $f->description,
            'draft'      => (string) ($f->ai_draft['message'] ?? ''),
            'edited'     => $f->wasEdited(),
            'deadline'   => (string) $f->deadline_time,
            'clock'      => AiFollowups::clock($f->deadline_time),
            'cost'       => $pesos > 0 && $pesos < 0.005 ? '< ₱0.01' : '₱' . number_format($pesos, 2),
            'model'      => $f->ai_draft['model'] ?? null,
            'date'       => Carbon::parse((string) $f->follows_date)->format('M j'),
            'reviewer'   => AiFollowups::firstName($f->reviewer),
            'reviewed'   => $f->reviewed_at?->timezone(AiFollowups::TZ)->format('M j, g:i A'),
            'note'       => $f->review_note,
            'status'     => $f->review_status,
        ];
    }

    /** Department na ipinapakita: ang nasa profile; kapag blangko, ang role na walang "- OIC" (gaya ng ChecklistBadge::departmentKey). */
    private function department(?string $department, ?string $role): string
    {
        $dept = trim((string) $department);

        return $dept !== '' ? $dept : trim((string) preg_replace('/\s*-\s*OIC\b.*$/i', '', trim((string) $role)));
    }

    /** Ang draft na ire-review: 404 kapag hindi follow-up, 409 kapag na-review na o expired na. */
    private function draft(int $id): ChecklistAiFollowup
    {
        $f = ChecklistAiFollowup::findOrFail($id);
        AiFollowups::expireStale(Carbon::now(AiFollowups::TZ));
        $f->refresh();
        if ($f->review_status !== 'draft') {
            throw new HttpResponseException($this->fail($f->review_status === 'expired'
                ? 'This draft expired: its day ended before it was reviewed.'
                : 'This draft was already reviewed.', 409));
        }

        return $f;
    }

    private function ok(Request $request, ChecklistAiFollowup $f, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'id' => $f->id, 'status' => $f->fresh()->review_status, 'message' => $message]);
        }

        return back()->with('success', $message);
    }

    private function fail(string $message, int $status)
    {
        if (request()->expectsJson()) {
            return response()->json(['ok' => false, 'message' => $message], $status);
        }

        return back()->with('error', $message);
    }

    /** Parehong rule ng ChecklistController::isCeo(): ang role sa employee profile, trimmed, case-insensitive. */
    private function authorizeCeo(): void
    {
        $role = strtoupper(trim((string) (Auth::user()?->employeeProfile?->role ?? '')));
        if ($role !== 'CEO') {
            if (request()->expectsJson()) {
                throw new HttpResponseException(response()->json(['ok' => false, 'message' => 'Only the CEO can review AI follow-ups.'], 403));
            }
            abort(403, 'Only the CEO can review AI follow-ups.');
        }
    }
}
