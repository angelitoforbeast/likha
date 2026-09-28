<?php

namespace App\Http\Controllers\Boardroom;

use App\Boardroom\Orchestrator\Registry;
use App\Boardroom\Support\Presenter;
use App\Boardroom\Support\Secrets;
use App\Http\Controllers\Controller;
use App\Models\Boardroom\Agent;
use App\Models\Boardroom\Change;
use App\Models\Boardroom\Meeting;
use App\Models\Boardroom\PaymentAccount;
use App\Models\Boardroom\Project;
use App\Models\Boardroom\ResourceEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /boardroom/resources — ang resources registry (CEO lang; naka-gate sa route).
 *
 * Dito nakikita at naitatama ng user ang itinala ng AI. Ang TULUYANG pagbura ay dito lang pwede —
 * archive lang ang kaya ng AI. Ang account number ay naka-mask sa listahan; ang buong numero ay
 * ibinibigay lang kapag tahasang hiniling (Ipakita), sa may-ari lang.
 */
class ResourceController extends Controller
{
    public function __construct(private Registry $registry)
    {
    }

    public function page()
    {
        return view('boardroom.resources');
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedResource($request);

        $r = ResourceEntry::create($this->fields($request, $data) + [
            'user_id' => $request->user()->id, 'status' => 'active', 'source' => 'manual',
        ]);
        $this->registry->log($r->user_id, 'resource', $r->id, 'add', "Idinagdag: [{$r->type}] {$r->name}", null, $r->snapshot(), ['actor' => 'user']);

        return response()->json(['ok' => true] + $this->payload($request));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $r      = $this->resource($request, $id);
        $data   = $this->validatedResource($request);
        $before = $r->snapshot();

        $r->fill($this->fields($request, $data, $r->id));
        if ($r->isDirty()) {
            $r->save();
            $this->registry->log($r->user_id, 'resource', $r->id, 'edit', "Binago: [{$r->type}] {$r->name}", $before, $r->snapshot(), ['actor' => 'user']);
        }

        return response()->json(['ok' => true] + $this->payload($request));
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        $r       = $this->resource($request, $id);
        $restore = $request->boolean('restore');
        $before  = $r->snapshot();

        $r->forceFill(['status' => $restore ? 'active' : 'archived'])->save();
        $this->registry->log($r->user_id, 'resource', $r->id, $restore ? 'restore' : 'archive',
            ($restore ? 'Ibinalik' : 'In-archive') . ": [{$r->type}] {$r->name}", $before, $r->snapshot(), ['actor' => 'user']);

        return response()->json(['ok' => true] + $this->payload($request));
    }

    /** Tuluyang pagbura — user lang ang makakagawa nito, hindi ang AI. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $r = $this->resource($request, $id);

        $this->registry->log($r->user_id, 'resource', $r->id, 'delete', "Binura nang tuluyan: [{$r->type}] {$r->name}", $r->snapshot(), null, ['actor' => 'user']);
        PaymentAccount::where('resource_id', $r->id)->delete();
        ResourceEntry::where('user_id', $r->user_id)->where('parent_id', $r->id)->update(['parent_id' => null]);
        $r->delete();

        return response()->json(['ok' => true] + $this->payload($request));
    }

    // ── Payment accounts ──────────────────────────────────────────────────

    public function storeAccount(Request $request, int $id): JsonResponse
    {
        $owner = $this->resource($request, $id);
        $data  = $this->validatedAccount($request, true);

        $a = new PaymentAccount([
            'user_id' => $owner->user_id, 'resource_id' => $owner->id, 'method' => $data['method'],
            'account_name' => $data['account_name'] ?? null, 'notes' => $data['notes'] ?? null, 'status' => 'active', 'source' => 'manual',
        ]);
        $a->setNumber($data['account_number']);
        $a->save();
        $this->registry->log($a->user_id, 'account', $a->id, 'add', "Idinagdag: {$a->method} {$a->masked()} ni {$owner->name}", null, $a->snapshot(), ['actor' => 'user']);

        return response()->json(['ok' => true] + $this->payload($request));
    }

    public function updateAccount(Request $request, int $id): JsonResponse
    {
        $a      = $this->account($request, $id);
        $data   = $this->validatedAccount($request, false);
        $before = $a->snapshot();

        $a->fill(['method' => $data['method'], 'account_name' => $data['account_name'] ?? null, 'notes' => $data['notes'] ?? null]);
        if (! empty($data['account_number'])) {
            $a->setNumber($data['account_number']);   // blangko = hindi binabago ang numero
        }
        if ($request->has('status')) {
            $a->status = $request->input('status') === 'archived' ? 'archived' : 'active';
        }
        if ($a->isDirty()) {
            $a->save();
            $owner = ResourceEntry::find($a->resource_id);
            $this->registry->log($a->user_id, 'account', $a->id, 'edit', "Binago: {$a->method} {$a->masked()} ni " . ($owner?->name ?? 'contact'), $before, $a->snapshot(), ['actor' => 'user']);
        }

        return response()->json(['ok' => true] + $this->payload($request));
    }

    public function destroyAccount(Request $request, int $id): JsonResponse
    {
        $a     = $this->account($request, $id);
        $owner = ResourceEntry::find($a->resource_id);
        $this->registry->log($a->user_id, 'account', $a->id, 'delete', "Binura nang tuluyan: {$a->method} {$a->masked()} ni " . ($owner?->name ?? 'contact'), $a->snapshot(), null, ['actor' => 'user']);
        $a->delete();

        return response()->json(['ok' => true] + $this->payload($request));
    }

    /** Ang buong account number — sa may-ari lang, at kapag tahasang hiniling lang. */
    public function reveal(Request $request, int $id): JsonResponse
    {
        $a      = $this->account($request, $id);
        $number = $a->number();
        if ($number === null) {
            return response()->json(['ok' => false, 'message' => 'Hindi mabasa ang numero (nagbago ang encryption key?). I-enter ulit.'], 422);
        }

        return response()->json(['ok' => true, 'id' => $a->id, 'account_number' => $number])
            ->header('Cache-Control', 'no-store');
    }

    // ── Kasaysayan at undo ────────────────────────────────────────────────

    public function undo(Request $request, int $id): JsonResponse
    {
        $change = Change::where('user_id', $request->user()->id)->findOrFail($id);
        $result = $this->registry->undo($change);

        $response = ['ok' => $result['ok'], 'message' => $result['message']] + $this->payload($request);
        if ($request->filled('meeting_id')) {
            $meeting = Meeting::where('user_id', $request->user()->id)->find((int) $request->input('meeting_id'));
            if ($meeting) {
                $response['state'] = Presenter::meeting($meeting);
            }
        }

        return response()->json($response, $result['ok'] ? 200 : 422);
    }

    // ── Mga katulong ──────────────────────────────────────────────────────

    private function payload(Request $request): array
    {
        $uid      = $request->user()->id;
        $projects = Project::where('user_id', $uid)->pluck('name', 'id');
        $agents   = Agent::pluck('handle', 'id');
        $rows     = ResourceEntry::with(['accounts' => fn ($q) => $q->orderBy('id')])->where('user_id', $uid)->orderBy('type')->orderBy('name')->get();
        $names    = $rows->pluck('name', 'id');
        $sent     = $this->registry->forContext($uid, null)->pluck('id')->all();

        return [
            'types'          => ResourceEntry::TYPES,
            'max_in_context' => Registry::MAX_IN_CONTEXT,
            'active'         => $rows->where('status', 'active')->count(),
            'projects'       => $projects->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->values(),
            'resources'      => $rows->map(fn (ResourceEntry $r) => [
                'id'         => $r->id,
                'type'       => $r->type,
                'name'       => $r->name,
                'purpose'    => (string) $r->purpose,
                'tags'       => array_values((array) $r->tags),
                'location'   => (string) $r->location,
                'parent_id'  => $r->parent_id,
                'parent'     => $r->parent_id ? ($names[$r->parent_id] ?? null) : null,
                'details'    => (string) $r->details,
                'holder'     => (string) $r->holder,
                'project_id' => $r->project_id,
                'project'    => $r->project_id ? ($projects[$r->project_id] ?? 'Project') : null,
                'status'     => $r->status,
                'source'     => $r->source,
                'recorded_by' => $r->agent_id ? ($agents[$r->agent_id] ?? null) : null,
                'in_context' => $r->status !== 'active' || $r->project_id || in_array($r->id, $sent, true),
                'updated_at' => optional($r->updated_at)->toDateTimeString(),
                'accounts'   => $r->accounts->map(fn (PaymentAccount $a) => [
                    'id' => $a->id, 'method' => $a->method, 'account_name' => (string) $a->account_name,
                    'masked' => $a->masked(), 'notes' => (string) $a->notes, 'status' => $a->status, 'source' => $a->source,
                ])->values(),
            ])->values(),
            'history'        => Change::where('user_id', $uid)->orderByDesc('id')->limit(60)->get()->map(fn (Change $c) => [
                'id'     => $c->id,
                'label'  => $c->label,
                'action' => $c->action,
                'actor'  => $c->actor === 'ai' ? ('AI · ' . ($agents[$c->agent_id] ?? 'role')) : 'Ikaw',
                'undone' => $c->undone_at !== null,
                'can_undo' => $c->undone_at === null && $c->action !== 'delete',
                'at'     => optional($c->created_at)->toDateTimeString(),
            ])->values(),
        ];
    }

    private function validatedResource(Request $request): array
    {
        return $request->validate([
            'type'       => ['required', Rule::in(ResourceEntry::TYPES)],
            'name'       => ['required', 'string', 'max:200'],
            'purpose'    => ['nullable', 'string', 'max:500'],
            'tags'       => ['nullable', 'array', 'max:12'],
            'tags.*'     => ['string', 'max:40'],
            'location'   => ['nullable', 'string', 'max:1000'],
            'parent_id'  => ['nullable', 'integer'],
            'details'    => ['nullable', 'string', 'max:4000'],
            'holder'     => ['nullable', 'string', 'max:200'],
            'project_id' => ['nullable', 'integer'],
        ]);
    }

    private function fields(Request $request, array $data, ?int $selfId = null): array
    {
        $uid    = $request->user()->id;
        $parent = ! empty($data['parent_id']) ? ResourceEntry::where('user_id', $uid)->findOrFail((int) $data['parent_id']) : null;
        if ($parent && $parent->id === $selfId) {
            $parent = null;
        }
        $project = ! empty($data['project_id']) ? Project::where('user_id', $uid)->findOrFail((int) $data['project_id']) : null;
        $clean   = fn (?string $v) => ($v = trim(Secrets::redact((string) $v))) === '' ? null : $v;

        return [
            'type'       => $data['type'],
            'name'       => trim($data['name']),
            'purpose'    => $clean($data['purpose'] ?? null),
            'tags'       => array_values(array_filter(array_map(fn ($t) => mb_strtolower(trim((string) $t)), (array) ($data['tags'] ?? [])))) ?: null,
            'location'   => $clean($data['location'] ?? null),
            'parent_id'  => $parent?->id,
            'details'    => $clean($data['details'] ?? null),
            'holder'     => $clean($data['holder'] ?? null),
            'project_id' => $project?->id,
        ];
    }

    private function validatedAccount(Request $request, bool $numberRequired): array
    {
        return $request->validate([
            'method'         => ['required', 'string', 'max:60'],
            'account_name'   => ['nullable', 'string', 'max:200'],
            'account_number' => [$numberRequired ? 'required' : 'nullable', 'string', 'min:6', 'max:60', 'regex:/^[A-Za-z0-9\s\-]+$/'],
            'notes'          => ['nullable', 'string', 'max:500'],
            'status'         => ['sometimes', Rule::in(['active', 'archived'])],
        ]);
    }

    private function resource(Request $request, int $id): ResourceEntry
    {
        return ResourceEntry::where('user_id', $request->user()->id)->findOrFail($id);
    }

    private function account(Request $request, int $id): PaymentAccount
    {
        return PaymentAccount::where('user_id', $request->user()->id)->findOrFail($id);
    }
}
