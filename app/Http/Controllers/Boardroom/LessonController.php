<?php

namespace App\Http\Controllers\Boardroom;

use App\Boardroom\Orchestrator\Playbook;
use App\Boardroom\Support\Secrets;
use App\Http\Controllers\Controller;
use App\Models\Boardroom\Agent;
use App\Models\Boardroom\Lesson;
use App\Models\Boardroom\Meeting;
use App\Models\Boardroom\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Playbook kada role (CEO lang; naka-gate sa route). Ang mga aral ay sa user na nagturo lang:
 * hindi nakikita o nagagamit ng ibang user.
 */
class LessonController extends Controller
{
    public function index(Request $request, int $agentId): JsonResponse
    {
        Agent::findOrFail($agentId);

        return response()->json($this->payload($request, $agentId));
    }

    /** Mano-manong pagdagdag ng aral (bukod sa kusang pagkatuto sa chat). */
    public function store(Request $request, int $agentId): JsonResponse
    {
        Agent::findOrFail($agentId);
        $data = $this->validated($request);

        Lesson::create([
            'agent_id'     => $agentId,
            'user_id'      => $request->user()->id,
            'project_id'   => $this->project($request, $data['project_id'] ?? null),
            'applies_when' => $this->clean($data['applies_when'] ?? null),
            'rule'         => $this->clean($data['rule']),
            'status'       => 'active',
            'source'       => 'manual',
        ]);

        return response()->json(['ok' => true] + $this->payload($request, $agentId));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $lesson = $this->mine($request, $id);
        $data   = $this->validated($request, true);

        if (array_key_exists('rule', $data)) {
            $lesson->rule = $this->clean($data['rule']);
        }
        if (array_key_exists('applies_when', $data)) {
            $lesson->applies_when = $this->clean($data['applies_when']);
        }
        if (array_key_exists('project_id', $data)) {
            $lesson->project_id = $this->project($request, $data['project_id']);
        }
        if (! empty($data['status'])) {
            $lesson->status = $data['status'];
            if ($data['status'] === 'active') {
                $lesson->replaced_by_id = null;
            }
        }
        $lesson->save();

        $response = ['ok' => true, 'lesson' => ['id' => $lesson->id, 'status' => $lesson->status]] + $this->payload($request, (int) $lesson->agent_id);

        // Galing sa chat (I-undo / Ibalik): ibalik din ang bagong estado ng meeting.
        if ($request->filled('meeting_id')) {
            $meeting = Meeting::where('user_id', $request->user()->id)->find((int) $request->input('meeting_id'));
            if ($meeting) {
                $response += ['state' => \App\Boardroom\Support\Presenter::meeting($meeting)];
            }
        }

        return response()->json($response);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $lesson  = $this->mine($request, $id);
        $agentId = (int) $lesson->agent_id;
        $lesson->delete();

        return response()->json(['ok' => true] + $this->payload($request, $agentId));
    }

    private function payload(Request $request, int $agentId): array
    {
        $uid      = $request->user()->id;
        $projects = Project::where('user_id', $uid)->pluck('name', 'id');
        $meetings = Meeting::where('user_id', $uid)->pluck('title', 'id');
        $rows     = Lesson::where('agent_id', $agentId)->where('user_id', $uid)->orderByDesc('id')->get();

        // Alin ang aktwal na isinasama sa prompt (pinakabagong MAX_IN_CONTEXT na aktibo)
        $inContext = $rows->where('status', 'active')->take(Playbook::MAX_IN_CONTEXT)->pluck('id')->all();

        return [
            'agent_id'       => $agentId,
            'max_in_context' => Playbook::MAX_IN_CONTEXT,
            'active'         => $rows->where('status', 'active')->count(),
            'projects'       => $projects->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->values(),
            'lessons'        => $rows->map(fn (Lesson $l) => [
                'id'           => $l->id,
                'applies_when' => (string) $l->applies_when,
                'rule'         => (string) $l->rule,
                'status'       => $l->status,
                'source'       => $l->source,
                'project_id'   => $l->project_id,
                'project'      => $l->project_id ? ($projects[$l->project_id] ?? 'Project') : null,
                'meeting'      => $l->meeting_id ? ($meetings[$l->meeting_id] ?? null) : null,
                'in_context'   => in_array($l->id, $inContext, true),
                'created_at'   => optional($l->created_at)->toDateTimeString(),
            ])->values(),
        ];
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $need = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'rule'         => [$need, 'string', 'min:3', 'max:1000'],
            'applies_when' => ['sometimes', 'nullable', 'string', 'max:300'],
            'project_id'   => ['sometimes', 'nullable', 'integer'],
            'status'       => ['sometimes', Rule::in(['active', 'disabled'])],
            'meeting_id'   => ['sometimes', 'nullable', 'integer'],
        ]);
    }

    private function project(Request $request, mixed $id): ?int
    {
        if (empty($id)) {
            return null;
        }

        return (int) Project::where('user_id', $request->user()->id)->findOrFail((int) $id)->id;
    }

    private function clean(?string $text): ?string
    {
        $text = trim(Secrets::redact((string) $text));

        return $text === '' ? null : $text;
    }

    private function mine(Request $request, int $id): Lesson
    {
        return Lesson::where('user_id', $request->user()->id)->findOrFail($id);
    }
}
