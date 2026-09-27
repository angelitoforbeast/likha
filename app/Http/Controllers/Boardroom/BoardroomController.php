<?php

namespace App\Http\Controllers\Boardroom;

use App\Boardroom\Orchestrator\MeetingOrchestrator;
use App\Boardroom\Support\Presenter;
use App\Http\Controllers\Controller;
use App\Models\Boardroom\Agent;
use App\Models\Boardroom\Credential;
use App\Models\Boardroom\Decision;
use App\Models\Boardroom\Group;
use App\Models\Boardroom\Knowledge;
use App\Models\Boardroom\Meeting;
use App\Models\Boardroom\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /boardroom — projects, meetings, at ang usapan (CEO lang; naka-gate sa route).
 * Lahat ng project at meeting ay naka-scope sa user na gumawa nito.
 */
class BoardroomController extends Controller
{
    public function __construct(private MeetingOrchestrator $orchestrator)
    {
    }

    public function page()
    {
        return view('boardroom.index');
    }

    public function bootstrap(Request $request): JsonResponse
    {
        $uid = $request->user()->id;

        $projects = Project::where('user_id', $uid)->whereNull('archived_at')->orderByDesc('id')->get();
        $meetings = Meeting::where('user_id', $uid)->whereIn('project_id', $projects->pluck('id'))->orderByDesc('id')->get()->groupBy('project_id');
        $withKey  = Credential::query()->get(['agent_id', 'provider'])->groupBy('agent_id');

        return response()->json([
            'projects' => $projects->map(fn (Project $p) => [
                'id' => $p->id, 'name' => $p->name, 'description' => (string) $p->description,
                'meetings' => ($meetings[$p->id] ?? collect())->map(fn (Meeting $m) => Presenter::meetingRow($m))->values(),
            ])->values(),
            'agents' => Agent::active()->orderBy('sort_order')->orderBy('id')->get()->map(fn (Agent $a) => [
                'id' => $a->id, 'handle' => $a->handle, 'display_name' => $a->display_name, 'role_type' => $a->role_type,
                'provider' => $a->provider, 'model' => $a->model, 'enabled' => (bool) $a->enabled,
                'has_key' => ($withKey[$a->id] ?? collect())->contains('provider', $a->provider),
            ])->values(),
            'groups' => Group::with('agents:id')->orderByDesc('is_default')->orderBy('id')->get()->map(fn (Group $g) => [
                'id' => $g->id, 'name' => $g->name, 'is_default' => $g->is_default, 'agent_ids' => $g->agents->pluck('id')->values(),
            ])->values(),
            'limits' => [
                'max_calls'  => (int) config('boardroom.limits.max_calls'),
                'max_cycles' => (int) config('boardroom.limits.max_cycles'),
            ],
        ]);
    }

    public function storeProject(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $project = Project::create($data + ['user_id' => $request->user()->id]);

        return response()->json(['ok' => true, 'project' => ['id' => $project->id, 'name' => $project->name, 'description' => (string) $project->description, 'meetings' => []]]);
    }

    public function archiveProject(Request $request, int $id): JsonResponse
    {
        $project = Project::where('user_id', $request->user()->id)->findOrFail($id);
        $running = Meeting::where('project_id', $project->id)->whereIn('status', ['running', 'paused', 'needs_input'])->exists();
        if ($running) {
            return response()->json(['ok' => false, 'message' => 'May tumatakbo pang meeting sa project na ito. I-stop muna.'], 422);
        }
        $project->forceFill(['archived_at' => now()])->save();

        return response()->json(['ok' => true]);
    }

    public function storeMeeting(Request $request): JsonResponse
    {
        $data = $request->validate([
            'project_id'              => ['required', 'integer'],
            'group_id'                => ['nullable', 'integer'],
            'title'                   => ['required', 'string', 'max:200'],
            'objective'               => ['required', 'string', 'max:8000'],
            'constraints'             => ['nullable', 'string', 'max:8000'],
            'agent_ids'               => ['required', 'array', 'min:2', 'max:6'],
            'agent_ids.*'             => ['integer'],
            'max_calls'               => ['nullable', 'integer', 'min:4', 'max:' . (int) config('boardroom.limits.max_calls', 16)],
            'max_cycles'              => ['nullable', 'integer', 'min:1', 'max:' . (int) config('boardroom.limits.max_cycles', 3)],
            'max_total_output_tokens' => ['nullable', 'integer', 'min:1000', 'max:5000000'],
            'spend_limit_usd'         => ['nullable', 'numeric', 'min:0.01', 'max:10000'],
        ]);

        $meeting = $this->orchestrator->create($request->user()->id, $data);

        return response()->json(['ok' => true, 'meeting' => Presenter::meetingRow($meeting)]);
    }

    public function showMeeting(Request $request, int $id): JsonResponse
    {
        $meeting = $this->meeting($request, $id);
        $this->orchestrator->recoverStale($meeting);

        return response()->json(Presenter::meeting($meeting->fresh(), max(0, (int) $request->query('after', 0))));
    }

    /** start | pause | resume | stop | retry */
    public function action(Request $request, int $id, string $action): JsonResponse
    {
        $meeting = $this->meeting($request, $id);
        $errors  = [];

        switch ($action) {
            case 'start':
                $result = $this->orchestrator->start($meeting);
                $errors = $result['errors'];
                break;
            case 'pause':
                $this->orchestrator->pause($meeting);
                break;
            case 'resume':
                $this->orchestrator->resume($meeting);
                break;
            case 'stop':
                $this->orchestrator->stop($meeting);
                break;
            case 'retry':
                $this->orchestrator->retry($meeting);
                break;
            default:
                abort(404);
        }

        return response()->json(
            ['ok' => ! $errors, 'errors' => $errors] + Presenter::meeting($meeting->fresh()),
            $errors ? 422 : 200
        );
    }

    public function postMessage(Request $request, int $id): JsonResponse
    {
        $meeting = $this->meeting($request, $id);
        $data    = $request->validate(['body' => ['required', 'string', 'max:8000']]);

        $result = $this->orchestrator->postUserMessage($meeting, $request->user()->id, trim($data['body']));

        return response()->json(['ok' => true, 'notes' => $result['notes']] + Presenter::meeting($meeting->fresh()));
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $data     = $request->validate(['status' => ['required', Rule::in(['approved', 'rejected', 'proposed'])]]);
        $decision = Decision::findOrFail($id);
        $meeting  = $this->meeting($request, (int) $decision->meeting_id);

        $decision->forceFill([
            'status'     => $data['status'],
            'decided_by' => $data['status'] === 'proposed' ? null : $request->user()->id,
            'decided_at' => $data['status'] === 'proposed' ? null : now(),
        ])->save();

        return response()->json(['ok' => true] + Presenter::meeting($meeting->fresh()));
    }

    // ── Company knowledge (ginagamit lang ng mga role kapag APPROVED) ──────

    public function knowledge(Request $request): JsonResponse
    {
        return response()->json(['knowledge' => $this->knowledgeRows($request)]);
    }

    public function storeKnowledge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id'         => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'title'      => ['required', 'string', 'max:200'],
            'body'       => ['required', 'string', 'max:12000'],
            'approved'   => ['boolean'],
        ]);
        $uid = $request->user()->id;

        if (! empty($data['project_id'])) {
            Project::where('user_id', $uid)->findOrFail($data['project_id']);
        }

        $row = ! empty($data['id']) ? Knowledge::where('user_id', $uid)->findOrFail($data['id']) : new Knowledge(['user_id' => $uid]);
        $approved = (bool) ($data['approved'] ?? false);
        $row->fill([
            'project_id'  => $data['project_id'] ?? null,
            'title'       => $data['title'],
            'body'        => $data['body'],
            'approved'    => $approved,
            'approved_at' => $approved ? ($row->approved_at ?: now()) : null,
        ])->save();

        return response()->json(['ok' => true, 'knowledge' => $this->knowledgeRows($request)]);
    }

    public function destroyKnowledge(Request $request, int $id): JsonResponse
    {
        Knowledge::where('user_id', $request->user()->id)->whereKey($id)->delete();

        return response()->json(['ok' => true, 'knowledge' => $this->knowledgeRows($request)]);
    }

    private function knowledgeRows(Request $request): array
    {
        return Knowledge::where('user_id', $request->user()->id)->orderByDesc('id')->get()->map(fn (Knowledge $k) => [
            'id' => $k->id, 'project_id' => $k->project_id, 'title' => $k->title, 'body' => $k->body,
            'approved' => (bool) $k->approved, 'approved_at' => optional($k->approved_at)->toDateTimeString(),
        ])->values()->all();
    }

    /** Ang meeting ay makikita lang ng user na may-ari nito. */
    private function meeting(Request $request, int $id): Meeting
    {
        return Meeting::where('user_id', $request->user()->id)->findOrFail($id);
    }
}
