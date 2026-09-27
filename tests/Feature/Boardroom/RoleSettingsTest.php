<?php

namespace Tests\Feature\Boardroom;

use App\Boardroom\Capabilities\CapabilityRegistry;
use App\Boardroom\Providers\AnthropicAdapter;
use App\Boardroom\Providers\DeepSeekAdapter;
use App\Boardroom\Providers\OpenAIAdapter;
use App\Boardroom\Providers\ProviderRequest;
use App\Models\Boardroom\Agent;
use App\Models\Boardroom\Credential;
use App\Models\Boardroom\ModelCapability;

/**
 * MOCKED — per-role na credential, model, at settings; at ang pagsala ng mga parameter.
 * Walang totoong provider call.
 */
class RoleSettingsTest extends BoardroomTestCase
{
    /** Test 1: bawat role ay gumagamit ng SARILI nitong credential, model, at settings. */
    public function test_each_role_uses_its_own_credential_model_and_settings(): void
    {
        $user = $this->user();
        ModelCapability::create([
            'provider' => 'openai', 'model' => 'test-model-b', 'efforts' => ['low', 'medium'], 'thinking_modes' => [],
            'structured_output' => 'json_schema', 'max_output_tokens' => 9000, 'verified' => true, 'verified_at' => now(),
            'doc_url' => 'https://example.test/docs', 'price_in' => 1, 'price_out' => 2,
        ]);

        $this->agent('CEO')->update(['model' => 'gpt-6-astra', 'settings' => ['effort' => 'xhigh', 'max_output_tokens' => 20000]]);
        $this->agent('CTO')->update(['model' => 'test-model-b', 'settings' => ['effort' => 'low', 'max_output_tokens' => 50000]]);
        $this->agent('COO')->update(['model' => 'gpt-5.2', 'settings' => ['effort' => 'low', 'max_output_tokens' => 3000]]);   // unverified sa registry
        $this->agent('REVIEWER')->update(['model' => 'gpt-6-astra', 'settings' => ['effort' => 'medium', 'max_output_tokens' => 7000]]);
        foreach (['CEO', 'CTO', 'COO', 'REVIEWER'] as $h) {
            $this->agent($h)->update(['instructions' => "ROLE-INSTRUCTIONS-OF-{$h}"]);
        }

        $this->giveKeys();
        $this->fakeProvider();
        $m = $this->start($this->meeting($user));
        $this->assertSame('completed', $m->status, (string) $m->last_error);

        $expected = [
            'CEO'      => ['model' => 'gpt-6-astra',  'effort' => 'xhigh',  'max' => 20000],
            'CTO'      => ['model' => 'test-model-b', 'effort' => 'low',    'max' => 9000],    // naka-clamp sa limit ng model
            'COO'      => ['model' => 'gpt-5.2',      'effort' => null,     'max' => 3000],    // unverified → walang effort na ipinadala
            'REVIEWER' => ['model' => 'gpt-6-astra',  'effort' => 'medium', 'max' => 7000],
        ];

        $seen = [];
        foreach ($this->calls as $call) {
            $handle = $call['handle'];
            $this->assertNotNull($handle, 'Bawat request ay dapat gumamit ng key ng isang kilalang role.');
            $seen[$handle] = true;
            $want = $expected[$handle];

            $this->assertSame($want['model'], $call['body']['model'], "model ni {$handle}");
            $this->assertSame($want['max'], $call['body']['max_output_tokens'], "max_output_tokens ni {$handle}");
            $this->assertSame($want['effort'], $call['body']['reasoning']['effort'] ?? null, "effort ni {$handle}");
            $this->assertStringContainsString("ROLE-INSTRUCTIONS-OF-{$handle}", $call['body']['instructions']);
            foreach (array_diff(array_keys($expected), [$handle]) as $other) {
                $this->assertStringNotContainsString("ROLE-INSTRUCTIONS-OF-{$other}", $call['body']['instructions']);
            }
        }
        $this->assertSame(['CEO', 'COO', 'CTO', 'REVIEWER'], collect(array_keys($seen))->sort()->values()->all());

        // Snapshot ng non-secret config kada meeting run
        $snapshot = collect($m->agents_snapshot)->keyBy('handle');
        $this->assertSame('test-model-b', $snapshot['CTO']['model']);
        $this->assertStringNotContainsString('sk-test', json_encode($m->agents_snapshot));

        // Walang alam na presyo ang gpt-5.2? May presyo ito sa seed; ang test-model-b ay may presyo rin.
        $this->assertSame(0, (int) $m->unpriced_calls);
    }

    /** Test 2: ang pag-edit kay CTO ay hindi gumagalaw kay CEO (o sa kahit sinong iba). */
    public function test_editing_cto_does_not_change_ceo(): void
    {
        $user = $this->user();
        $this->giveKeys();

        $snapshot = fn () => [
            'agents' => Agent::where('handle', '!=', 'CTO')->orderBy('id')->get()->map->getAttributes()->all(),
            'creds'  => Credential::whereIn('agent_id', Agent::where('handle', '!=', 'CTO')->pluck('id'))->orderBy('id')->get()->map->getAttributes()->all(),
        ];
        $before = $snapshot();
        $cto    = $this->agent('CTO');
        $oldCto = Credential::where('agent_id', $cto->id)->value('secret_encrypted');

        $response = $this->actingAs($user)->putJson("/boardroom/api/agents/{$cto->id}", [
            'handle' => 'CTO', 'display_name' => 'Chief Technology Officer', 'role_type' => 'contributor',
            'description' => 'bago', 'instructions' => 'Bagong instructions ni CTO', 'provider' => 'openai', 'model' => 'gpt-5.2',
            'enabled' => true, 'settings' => ['effort' => 'low', 'max_output_tokens' => 5000, 'timeout_s' => 120],
            'api_key' => 'sk-test-brand-new-cto-key-0000000000009999',
        ]);

        $response->assertOk()->assertJsonPath('agent.model', 'gpt-5.2')->assertJsonPath('agent.credential.masked', '••••••••9999');
        $this->assertSame($before, $snapshot(), 'Walang ibang role o credential ang dapat nagbago.');

        $cto->refresh();
        $this->assertSame('Chief Technology Officer', $cto->display_name);
        $this->assertSame(5000, $cto->settings['max_output_tokens']);
        $this->assertNotSame($oldCto, Credential::where('agent_id', $cto->id)->value('secret_encrypted'));
        $this->assertSame(4, Credential::count(), 'Isang credential kada role — walang global key.');
    }

    /** Ang paglipat ng provider ay HINDI nagdadala ng key ng lumang provider. */
    public function test_switching_provider_never_reuses_another_providers_key(): void
    {
        $user = $this->user();
        $this->giveKeys(['CTO']);
        $cto = $this->agent('CTO');

        $this->actingAs($user)->putJson("/boardroom/api/agents/{$cto->id}", [
            'handle' => 'CTO', 'display_name' => 'CTO', 'role_type' => 'contributor', 'provider' => 'anthropic',
            'model' => 'claude-opus-5', 'enabled' => true, 'settings' => ['effort' => 'high', 'thinking' => 'adaptive'],
        ])->assertOk()->assertJsonPath('agent.credential', null);

        $this->fakeProvider();
        $this->actingAs($user)->postJson("/boardroom/api/agents/{$cto->id}/test")->assertStatus(422)->assertJsonPath('error_code', 'no_credential');
        $this->assertCount(0, $this->calls, 'Walang request na lumabas: walang key para sa bagong provider.');
    }

    /** Test 3: ang hindi supported na parameter ay HINDI nakakarating sa provider. */
    public function test_unsupported_parameters_never_reach_the_provider(): void
    {
        $registry = app(CapabilityRegistry::class);
        $payload  = function (string $adapter, string $provider, string $model, array $settings, bool $schema = false) use ($registry) {
            $r = $registry->resolve($provider, $model, $settings);

            return [app($adapter)->buildPayload(new ProviderRequest(
                $provider, $model, 'sys', 'in', $r['applied'],
                $schema ? ['name' => 'x', 'schema' => ['type' => 'object']] : null, $r['structured']
            )), $r['dropped']];
        };

        // OpenAI gpt-6-astra: effort=none ay hindi tinatanggap; walang thinking parameter ang OpenAI
        [$p, $dropped] = $payload(OpenAIAdapter::class, 'openai', 'gpt-6-astra', ['effort' => 'none', 'thinking' => 'adaptive']);
        $this->assertArrayNotHasKey('reasoning', $p);
        $this->assertArrayNotHasKey('thinking', $p);
        $this->assertSame(['unsupported_thinking_mode', 'unsupported_effort'], array_column($dropped, 'reason'));

        // Hindi kilalang model (manual ID): walang advanced parameter at walang schema enforcement
        [$p, $dropped] = $payload(OpenAIAdapter::class, 'openai', 'my-manual-model', ['effort' => 'high'], true);
        $this->assertArrayNotHasKey('reasoning', $p);
        $this->assertArrayNotHasKey('text', $p);
        $this->assertSame('model_unknown', $dropped[0]['reason']);

        // Nasa registry pero hindi verified: ganoon din
        [$p] = $payload(OpenAIAdapter::class, 'openai', 'gpt-5.2', ['effort' => 'low']);
        $this->assertArrayNotHasKey('reasoning', $p);

        // Anthropic Haiku 4.5: walang effort
        [$p] = $payload(AnthropicAdapter::class, 'anthropic', 'claude-haiku-4-5', ['effort' => 'high', 'thinking' => 'adaptive']);
        $this->assertArrayNotHasKey('output_config', $p);
        $this->assertArrayNotHasKey('thinking', $p);

        // Anthropic Haiku 4.5: thinking=enabled → may budget_tokens na mas mababa sa max_tokens
        [$p] = $payload(AnthropicAdapter::class, 'anthropic', 'claude-haiku-4-5', ['thinking' => 'enabled', 'max_output_tokens' => 8000]);
        $this->assertSame('enabled', $p['thinking']['type']);
        $this->assertGreaterThanOrEqual(1024, $p['thinking']['budget_tokens']);
        $this->assertLessThan($p['max_tokens'], $p['thinking']['budget_tokens']);

        // Anthropic Fable 5.1: hindi tinatanggap ang thinking=disabled; walang budget_tokens kahit kailan
        [$p] = $payload(AnthropicAdapter::class, 'anthropic', 'claude-fable-5-1', ['thinking' => 'disabled', 'effort' => 'max', 'thinking_budget_tokens' => 5000]);
        $this->assertArrayNotHasKey('thinking', $p);
        $this->assertSame('max', $p['output_config']['effort']);
        $this->assertStringNotContainsString('budget_tokens', json_encode($p));
        $this->assertArrayNotHasKey('fallbacks', $p, 'Walang awtomatikong fallback sa ibang model.');

        // Anthropic Opus 5: thinking=disabled ay hanggang effort=high lang
        [$p] = $payload(AnthropicAdapter::class, 'anthropic', 'claude-opus-5', ['thinking' => 'disabled', 'effort' => 'xhigh']);
        $this->assertArrayNotHasKey('thinking', $p);
        [$p] = $payload(AnthropicAdapter::class, 'anthropic', 'claude-opus-5', ['thinking' => 'disabled', 'effort' => 'high']);
        $this->assertSame(['type' => 'disabled'], $p['thinking']);
        foreach (['temperature', 'top_p', 'top_k'] as $sampling) {
            $this->assertArrayNotHasKey($sampling, $p);
        }

        // DeepSeek: effort ay may bisa lang kapag naka-enable ang thinking; "medium" ay hindi supported
        [$p] = $payload(DeepSeekAdapter::class, 'deepseek', 'deepseek-v4-pro', ['thinking' => 'disabled', 'effort' => 'high']);
        $this->assertArrayNotHasKey('reasoning_effort', $p);
        $this->assertSame(['type' => 'disabled'], $p['thinking']);
        [$p] = $payload(DeepSeekAdapter::class, 'deepseek', 'deepseek-v4-pro', ['thinking' => 'enabled', 'effort' => 'medium']);
        $this->assertArrayNotHasKey('reasoning_effort', $p);
        foreach (['temperature', 'presence_penalty', 'frequency_penalty'] as $unsupported) {
            $this->assertArrayNotHasKey($unsupported, $p);
        }
    }

    /** Test 3b: end-to-end — ang aktwal na HTTP request ay walang hindi supported na parameter. */
    public function test_unsupported_parameters_are_absent_from_the_real_request(): void
    {
        $user = $this->user();
        $this->giveKeys(['CTO']);
        $cto = $this->agent('CTO');
        $cto->update(['model' => 'gpt-6-astra', 'settings' => ['effort' => 'none', 'thinking' => 'adaptive', 'max_output_tokens' => 999999]]);

        $this->fakeProvider();
        $response = $this->actingAs($user)->postJson("/boardroom/api/agents/{$cto->id}/test")->assertOk()->assertJsonPath('ok', true);

        $this->assertCount(1, $this->calls);
        $body = $this->calls[0]['body'];
        $this->assertSame($this->keys['CTO'], $this->calls[0]['key']);
        $this->assertArrayNotHasKey('reasoning', $body);
        $this->assertArrayNotHasKey('thinking', $body);
        $this->assertLessThanOrEqual(128, $body['max_output_tokens'], 'Maliit lang dapat ang Test Connection.');
        $this->assertCount(3, $response->json('not_sent'));
        $this->assertNotEmpty($response->json('agent.flags'));
    }

    /** Hindi kilalang model: pwede sa manual ID pero may markang unverified. */
    public function test_unknown_model_is_allowed_but_marked_unverified(): void
    {
        $user = $this->user();
        $ceo  = $this->agent('CEO');

        $response = $this->actingAs($user)->putJson("/boardroom/api/agents/{$ceo->id}", [
            'handle' => 'CEO', 'display_name' => 'CEO', 'role_type' => 'moderator', 'provider' => 'openai',
            'model' => 'gpt-future-x', 'enabled' => true, 'settings' => ['effort' => 'high'],
        ])->assertOk();

        $this->assertFalse($response->json('agent.capability.known'));
        $this->assertFalse($response->json('agent.capability.verified'));
        $this->assertArrayNotHasKey('effort', $response->json('agent.will_send'));
        $this->assertStringContainsString('UNVERIFIED', json_encode($response->json('agent.flags')));
    }

    /** Ang registry ay editable; kailangan ng doc source at petsa para ma-verify. */
    public function test_capability_registry_is_editable_and_requires_a_source_to_verify(): void
    {
        $user = $this->user();

        $this->actingAs($user)->postJson('/boardroom/api/capabilities', [
            'provider' => 'openai', 'model' => 'gpt-future-x', 'efforts' => ['low', 'high'], 'thinking_modes' => [],
            'structured_output' => 'json_schema', 'verified' => true,
        ])->assertStatus(422);

        $this->actingAs($user)->postJson('/boardroom/api/capabilities', [
            'provider' => 'openai', 'model' => 'gpt-future-x', 'efforts' => ['low', 'high'], 'thinking_modes' => [],
            'structured_output' => 'json_schema', 'verified' => true, 'doc_url' => 'https://platform.openai.com/docs/models',
            'verified_at' => '2026-09-28', 'max_output_tokens' => 32000,
        ])->assertOk();

        $resolved = app(CapabilityRegistry::class)->resolve('openai', 'gpt-future-x', ['effort' => 'high']);
        $this->assertSame('high', $resolved['applied']['effort']);
        $this->assertNull(app(CapabilityRegistry::class)->estimateCost('openai', 'gpt-future-x', 1000, 1000), 'Walang presyo = walang hinuhulaang gastos.');
    }

    /** Fetch ng mga model ID gamit ang credential ng role. */
    public function test_model_ids_are_fetched_with_the_roles_own_credential(): void
    {
        $user = $this->user();
        $this->giveKeys(['COO']);
        $coo = $this->agent('COO');

        \Illuminate\Support\Facades\Http::fake(function ($request) {
            $this->calls[] = ['url' => $request->url(), 'key' => preg_replace('/^Bearer\s+/i', '', $request->header('Authorization')[0] ?? '')];

            return \Illuminate\Support\Facades\Http::response(['data' => [['id' => 'gpt-6-astra'], ['id' => 'gpt-zzz-new']]], 200);
        });

        $response = $this->actingAs($user)->postJson("/boardroom/api/agents/{$coo->id}/models")->assertOk();

        $this->assertSame($this->keys['COO'], $this->calls[0]['key']);
        $this->assertStringEndsWith('/models', $this->calls[0]['url']);
        $models = collect($response->json('models'))->keyBy('id');
        $this->assertTrue($models['gpt-6-astra']['verified']);
        $this->assertFalse($models['gpt-zzz-new']['known']);
    }
}
