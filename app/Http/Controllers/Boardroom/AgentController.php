<?php

namespace App\Http\Controllers\Boardroom;

use App\Boardroom\Capabilities\CapabilityRegistry;
use App\Boardroom\Providers\AdapterFactory;
use App\Boardroom\Providers\ProviderRequest;
use App\Boardroom\Support\Presenter;
use App\Boardroom\Support\Secrets;
use App\Http\Controllers\Controller;
use App\Models\Boardroom\Agent;
use App\Models\Boardroom\Credential;
use App\Models\Boardroom\Group;
use App\Models\Boardroom\ModelCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * /boardroom/agents — settings ng bawat role (CEO lang; naka-gate sa route).
 *
 * Seguridad: ang API key ay tinatanggap lang papasok (write-only). Walang endpoint dito na
 * nagbabalik ng naka-save na key — naka-mask na anyo lang (huling 4 na character).
 */
class AgentController extends Controller
{
    public function __construct(private CapabilityRegistry $registry, private AdapterFactory $adapters)
    {
    }

    public function page()
    {
        return view('boardroom.agents');
    }

    public function index(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, null);

        $agent = Agent::create([
            'handle'       => $data['handle'],
            'display_name' => $data['display_name'],
            'role_type'    => $data['role_type'],
            'description'  => $data['description'] ?? null,
            'instructions' => $data['instructions'] ?? null,
            'provider'     => $data['provider'],
            'model'        => $data['model'] ?? '',
            'settings'     => $this->settings($data),
            'enabled'      => (bool) ($data['enabled'] ?? true),
            'sort_order'   => (int) Agent::max('sort_order') + 1,
        ]);

        $this->saveKey($agent, $request);

        return response()->json(['ok' => true, 'agent' => Presenter::agent($agent->fresh(), $this->registry)]);
    }

    /** Ine-edit LANG ang role na ito — walang ibang role o credential na nagagalaw. */
    public function update(Request $request, int $id): JsonResponse
    {
        $agent = Agent::findOrFail($id);
        $data  = $this->validated($request, $agent);

        $agent->fill([
            'handle'       => $data['handle'],
            'display_name' => $data['display_name'],
            'role_type'    => $data['role_type'],
            'description'  => $data['description'] ?? null,
            'instructions' => $data['instructions'] ?? null,
            'provider'     => $data['provider'],
            'model'        => $data['model'] ?? '',
            'settings'     => $this->settings($data),
            'enabled'      => (bool) ($data['enabled'] ?? false),
        ])->save();

        $this->saveKey($agent, $request);

        return response()->json(['ok' => true, 'agent' => Presenter::agent($agent->fresh(), $this->registry)]);
    }

    public function destroyCredential(int $id): JsonResponse
    {
        $agent = Agent::findOrFail($id);
        Credential::where('agent_id', $agent->id)->where('provider', $agent->provider)->delete();

        return response()->json(['ok' => true, 'agent' => Presenter::agent($agent->fresh(), $this->registry)]);
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        $agent = Agent::findOrFail($id);
        $agent->forceFill(['archived_at' => $request->boolean('restore') ? null : now()])->save();
        if ($agent->archived_at) {
            DB::table('br_group_agents')->where('agent_id', $agent->id)->delete();
        }

        return response()->json(['ok' => true] + $this->payload());
    }

    /** Test Connection: isang MALIIT pero TOTOONG request gamit ang credential at settings ng role na ito. */
    public function test(int $id): JsonResponse
    {
        $agent = Agent::findOrFail($id);
        [$secret, $credential, $error] = $this->secretFor($agent);
        if ($error) {
            return response()->json(['ok' => false, 'error_code' => 'no_credential', 'message' => $error], 422);
        }
        if (trim((string) $agent->model) === '') {
            return response()->json(['ok' => false, 'error_code' => 'no_model', 'message' => 'Walang napiling model.'], 422);
        }

        $resolved = $this->registry->resolve($agent->provider, $agent->model, (array) ($agent->settings ?: []));
        $params   = $resolved['applied'];
        $params['max_output_tokens'] = (int) config('boardroom.limits.test_output_tokens', 64) + (int) ($params['thinking_budget_tokens'] ?? 0);
        $params['timeout_s']         = min(60, (int) $params['timeout_s']);

        $adapter = $this->adapters->make($agent->provider);
        $result  = $adapter->send(
            new ProviderRequest($agent->provider, $agent->model, 'This is a connection test. Reply briefly.', 'Reply with the single word: OK', $params),
            $secret
        );

        $message = $result->ok
            ? 'Nakakonekta. ' . ($result->finish === 'truncated' ? 'Naputol ang sagot sa maliit na token limit ng test — normal ito sa reasoning model.' : 'Sumagot ang model.')
            : Secrets::safeMessage($result->errorMessage, [$secret]);

        $credential->forceFill([
            'last_tested_at' => now(), 'last_test_ok' => $result->ok, 'last_test_message' => mb_substr($message, 0, 500),
        ])->save();
        if ($result->ok) {
            $this->registry->markLiveVerified($agent->provider, $agent->model);
        }

        return response()->json([
            'ok'             => $result->ok,
            'message'        => $message,
            'error_code'     => $result->errorCode,
            'http_status'    => $result->httpStatus,
            'finish'         => $result->ok ? $result->finish : null,
            'model_reported' => $result->modelReported,
            'usage'          => ['tokens_in' => $result->tokensIn, 'tokens_out' => $result->tokensOut],
            'sent'           => $result->sent,
            'not_sent'       => array_map(fn ($d) => Presenter::droppedText($d), $resolved['dropped']),
            'agent'          => Presenter::agent($agent->fresh(), $this->registry),
        ]);
    }

    /** Mga model ID na naa-access ng credential ng role na ito. */
    public function models(int $id): JsonResponse
    {
        $agent = Agent::findOrFail($id);
        [$secret, , $error] = $this->secretFor($agent);
        if ($error) {
            return response()->json(['ok' => false, 'models' => [], 'message' => $error], 422);
        }

        $list  = $this->adapters->make($agent->provider)->listModels($secret);
        $known = ModelCapability::where('provider', $agent->provider)->get()->keyBy('model');

        return response()->json([
            'ok'      => $list['ok'],
            'message' => $list['ok'] ? null : Secrets::safeMessage($list['error'], [$secret]),
            'models'  => array_map(fn ($m) => [
                'id'       => $m,
                'known'    => $known->has($m),
                'verified' => (bool) ($known[$m]->verified ?? false),
            ], $list['models']),
        ]);
    }

    public function updateGroup(Request $request, int $id): JsonResponse
    {
        $group = Group::findOrFail($id);
        $data  = $request->validate([
            'name'        => ['required', 'string', 'max:120'],
            'agent_ids'   => ['array'],
            'agent_ids.*' => ['integer'],
        ]);

        $ids = Agent::active()->whereIn('id', $data['agent_ids'] ?? [])->pluck('id')->all();
        DB::transaction(function () use ($group, $data, $ids) {
            $group->forceFill(['name' => $data['name']])->save();
            DB::table('br_group_agents')->where('group_id', $group->id)->delete();
            foreach (array_values($ids) as $i => $agentId) {
                DB::table('br_group_agents')->insert(['group_id' => $group->id, 'agent_id' => $agentId, 'sort_order' => $i + 1]);
            }
        });

        return response()->json(['ok' => true] + $this->payload());
    }

    // ── Model-capability registry (editable) ──────────────────────────────

    public function saveCapability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id'                => ['nullable', 'integer'],
            'provider'          => ['required', Rule::in(AdapterFactory::providers())],
            'model'             => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:\/\-]+$/'],
            'label'             => ['nullable', 'string', 'max:160'],
            'efforts'           => ['array'],
            'efforts.*'         => ['string', 'max:20', 'regex:/^[a-z_]+$/'],
            'default_effort'    => ['nullable', 'string', 'max:20'],
            'thinking_modes'    => ['array'],
            'thinking_modes.*'  => ['string', 'max:20', 'regex:/^[a-z_]+$/'],
            'default_thinking'  => ['nullable', 'string', 'max:20'],
            'structured_output' => ['required', Rule::in(['json_schema', 'json_object', 'none'])],
            'max_output_tokens' => ['nullable', 'integer', 'min:16', 'max:2000000'],
            'context_window'    => ['nullable', 'integer', 'min:1000', 'max:20000000'],
            'price_in'          => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'price_out'         => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'doc_url'           => ['nullable', 'url', 'max:500'],
            'verified'          => ['boolean'],
            'verified_at'       => ['nullable', 'date'],
            'notes'             => ['nullable', 'string', 'max:2000'],
        ]);

        if (! empty($data['verified']) && (empty($data['doc_url']) || empty($data['verified_at']))) {
            return response()->json(['ok' => false, 'message' => 'Para markahang verified, kailangan ang doc source (URL) at petsa ng verification.'], 422);
        }

        $cap = ! empty($data['id'])
            ? ModelCapability::findOrFail($data['id'])
            : ModelCapability::firstOrNew(['provider' => $data['provider'], 'model' => $data['model']]);

        $duplicate = ModelCapability::where('provider', $data['provider'])->where('model', $data['model'])
            ->when($cap->exists, fn ($q) => $q->where('id', '!=', $cap->id))->exists();
        if ($duplicate) {
            return response()->json(['ok' => false, 'message' => 'May ganito nang model sa registry para sa provider na ito.'], 422);
        }

        $cap->fill([
            'provider'          => $data['provider'],
            'model'             => $data['model'],
            'label'             => $data['label'] ?? null,
            'endpoint'          => $this->adapters->make($data['provider'])->endpointName(),
            'efforts'           => array_values(array_unique($data['efforts'] ?? [])),
            'default_effort'    => $data['default_effort'] ?? null,
            'thinking_modes'    => array_values(array_unique($data['thinking_modes'] ?? [])),
            'default_thinking'  => $data['default_thinking'] ?? null,
            'structured_output' => $data['structured_output'],
            'max_output_tokens' => $data['max_output_tokens'] ?? null,
            'context_window'    => $data['context_window'] ?? null,
            'price_in'          => $data['price_in'] ?? null,
            'price_out'         => $data['price_out'] ?? null,
            'doc_url'           => $data['doc_url'] ?? null,
            'verified'          => (bool) ($data['verified'] ?? false),
            'verified_at'       => $data['verified_at'] ?? null,
            'notes'             => $data['notes'] ?? null,
        ]);
        if (! $cap->exists) {
            $cap->optional_params = [];
        }
        $cap->save();
        $this->registry->forget();

        return response()->json(['ok' => true] + $this->payload());
    }

    public function destroyCapability(int $id): JsonResponse
    {
        ModelCapability::whereKey($id)->delete();
        $this->registry->forget();

        return response()->json(['ok' => true] + $this->payload());
    }

    // ── Mga katulong ──────────────────────────────────────────────────────

    private function payload(): array
    {
        $this->registry->forget();

        return [
            'agents'       => Agent::orderBy('sort_order')->orderBy('id')->get()->map(fn (Agent $a) => Presenter::agent($a, $this->registry))->values(),
            'providers'    => config('boardroom.providers'),
            'role_types'   => Agent::ROLE_TYPES,
            'capabilities' => ModelCapability::orderBy('provider')->orderBy('model')->get()
                ->map(fn (ModelCapability $c) => $this->registry->describe($c->provider, $c->model))->values(),
            'groups'       => Group::with('agents:id')->orderBy('id')->get()->map(fn (Group $g) => [
                'id' => $g->id, 'name' => $g->name, 'is_default' => $g->is_default, 'agent_ids' => $g->agents->pluck('id')->values(),
            ])->values(),
            'defaults'     => [
                'max_output_tokens' => (int) config('boardroom.limits.max_output_tokens'),
                'timeout_s'         => (int) config('boardroom.limits.timeout_s'),
                'timeout_max'       => CapabilityRegistry::TIMEOUT_MAX,
            ],
            'encryption'   => trim((string) config('boardroom.encryption_key')) !== '' ? 'BOARDROOM_ENCRYPTION_KEY' : 'APP_KEY',
        ];
    }

    private function validated(Request $request, ?Agent $agent): array
    {
        $request->merge(['handle' => strtoupper(trim((string) $request->input('handle')))]);

        return $request->validate([
            'handle'       => ['required', 'string', 'max:40', 'regex:/^[A-Z][A-Z0-9_\-]*$/', Rule::unique('br_agents', 'handle')->ignore($agent?->id)],
            'display_name' => ['required', 'string', 'max:120'],
            'role_type'    => ['required', Rule::in(Agent::ROLE_TYPES)],
            'description'  => ['nullable', 'string', 'max:2000'],
            'instructions' => ['nullable', 'string', 'max:20000'],
            'provider'     => ['required', Rule::in(AdapterFactory::providers())],   // walang naka-hardcode na provider kada role
            'model'        => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:\/\-]+$/'],
            'enabled'      => ['boolean'],
            'settings'                        => ['array'],
            'settings.effort'                 => ['nullable', 'string', 'max:20', 'regex:/^[a-z_]+$/'],
            'settings.thinking'               => ['nullable', 'string', 'max:20', 'regex:/^[a-z_]+$/'],
            'settings.thinking_budget_tokens' => ['nullable', 'integer', 'min:1024', 'max:200000'],
            'settings.max_output_tokens'      => ['nullable', 'integer', 'min:' . CapabilityRegistry::OUTPUT_MIN, 'max:400000'],
            'settings.timeout_s'              => ['nullable', 'integer', 'min:' . CapabilityRegistry::TIMEOUT_MIN, 'max:' . CapabilityRegistry::TIMEOUT_MAX],
            'api_key'      => ['nullable', 'string', 'min:8', 'max:500'],
            'key_label'    => ['nullable', 'string', 'max:120'],
        ]);
    }

    private function settings(array $data): array
    {
        $in  = (array) ($data['settings'] ?? []);
        $out = [];
        foreach (['effort', 'thinking'] as $k) {
            if (isset($in[$k]) && $in[$k] !== '') {
                $out[$k] = (string) $in[$k];
            }
        }
        foreach (['thinking_budget_tokens', 'max_output_tokens', 'timeout_s'] as $k) {
            if (isset($in[$k]) && $in[$k] !== '') {
                $out[$k] = (int) $in[$k];
            }
        }

        return $out;
    }

    /** Write-only: i-encrypt at i-save ang bagong key para sa (role, kasalukuyang provider). */
    private function saveKey(Agent $agent, Request $request): void
    {
        $key = trim((string) $request->input('api_key'));
        if ($key === '') {
            return;
        }

        $credential = Credential::firstOrNew(['agent_id' => $agent->id, 'provider' => $agent->provider]);
        $credential->fill([
            'label'             => $request->input('key_label') ?: "{$agent->handle} · {$agent->provider}",
            'secret_encrypted'  => Secrets::encrypt($key),
            'last4'             => Secrets::last4($key),
            'updated_by'        => $request->user()->id,
            'last_tested_at'    => null,
            'last_test_ok'      => null,
            'last_test_message' => null,
        ])->save();
    }

    /** @return array{0: ?string, 1: ?Credential, 2: ?string} [secret, credential, error] */
    private function secretFor(Agent $agent): array
    {
        $credential = $agent->credential();
        if (! $credential) {
            return [null, null, "Walang API key si {$agent->handle} para sa {$agent->provider}. I-save muna ang key."];
        }
        try {
            return [Secrets::decrypt($credential->secret_encrypted), $credential, null];
        } catch (\Throwable $e) {
            return [null, $credential, 'Hindi mabasa ang naka-save na key (nagbago ang encryption key?). I-enter ulit ang API key.'];
        }
    }
}
