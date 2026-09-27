<?php

namespace App\Boardroom\Capabilities;

use App\Models\Boardroom\ModelCapability;

/**
 * Model-capability registry: iisang pinagmumulan ng katotohanan kung anong settings ang pwedeng
 * ipadala sa bawat (provider, model). Ang HINDI nakalista ay HINDI ipinapadala.
 *
 * - Kilala + verified na model → ipinapadala lang ang mga value na nasa listahan.
 * - Hindi kilala / hindi verified → walang advanced parameter (effort, thinking, options) na ipinapadala.
 */
class CapabilityRegistry
{
    public const TIMEOUT_MIN = 10;
    public const TIMEOUT_MAX = 540;   // (540 + allowance) x 3 na subok ay kasya pa sa timeout ng RunTurnJob
    public const OUTPUT_MIN  = 16;

    /** @var array<string, ModelCapability|null> */
    private array $cache = [];

    public function find(string $provider, string $model): ?ModelCapability
    {
        $key = $provider . '|' . $model;
        if (! array_key_exists($key, $this->cache)) {
            $this->cache[$key] = ModelCapability::where('provider', $provider)->where('model', $model)->first();
        }

        return $this->cache[$key];
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    /** Normalized na paglalarawan ng kakayahan ng model (ligtas ipadala sa browser). */
    public function describe(string $provider, string $model): array
    {
        $cap = $this->find($provider, $model);
        if (! $cap) {
            return [
                'provider' => $provider, 'model' => $model, 'label' => $model, 'known' => false, 'verified' => false,
                'endpoint' => null, 'efforts' => [], 'default_effort' => null, 'thinking_modes' => [],
                'default_thinking' => null, 'optional_params' => [], 'structured_output' => 'none',
                'max_output_tokens' => null, 'context_window' => null, 'price_in' => null, 'price_out' => null,
                'doc_url' => null, 'verified_at' => null, 'live_verified_at' => null, 'notes' => null,
            ];
        }

        return [
            'id'                => $cap->id,
            'provider'          => $cap->provider,
            'model'             => $cap->model,
            'label'             => $cap->label ?: $cap->model,
            'known'             => true,
            'verified'          => (bool) $cap->verified,
            'endpoint'          => $cap->endpoint,
            'efforts'           => array_values($cap->efforts ?: []),
            'default_effort'    => $cap->default_effort,
            'thinking_modes'    => array_values($cap->thinking_modes ?: []),
            'default_thinking'  => $cap->default_thinking,
            'optional_params'   => $cap->optional_params ?: [],
            'structured_output' => $cap->structured_output ?: 'none',
            'max_output_tokens' => $cap->max_output_tokens,
            'context_window'    => $cap->context_window,
            'price_in'          => $cap->price_in,
            'price_out'         => $cap->price_out,
            'doc_url'           => $cap->doc_url,
            'verified_at'       => optional($cap->verified_at)->toDateString(),
            'live_verified_at'  => optional($cap->live_verified_at)->toDateTimeString(),
            'notes'             => $cap->notes,
        ];
    }

    /**
     * Salain ang settings ng isang role laban sa kakayahan ng model.
     *
     * @return array{applied: array, dropped: array<int, array{key:string,value:mixed,reason:string}>, structured: string, capability: array}
     */
    public function resolve(string $provider, string $model, array $settings): array
    {
        $cap      = $this->describe($provider, $model);
        $advanced = $cap['known'] && $cap['verified'];
        $applied  = [];
        $dropped  = [];

        $effort   = $this->clean($settings['effort'] ?? null);
        $thinking = $this->clean($settings['thinking'] ?? null);
        $options  = $cap['optional_params'];

        // ── Max output tokens + timeout: laging ipinapadala, naka-clamp sa alam na limit ──
        $maxOut = (int) ($settings['max_output_tokens'] ?? 0);
        if ($maxOut <= 0) {
            $maxOut = (int) config('boardroom.limits.max_output_tokens', 16000);
        }
        $maxOut = max(self::OUTPUT_MIN, $maxOut);
        if ($cap['max_output_tokens'] && $maxOut > (int) $cap['max_output_tokens']) {
            $dropped[] = ['key' => 'max_output_tokens', 'value' => $maxOut, 'reason' => 'clamped_to_model_limit'];
            $maxOut = (int) $cap['max_output_tokens'];
        }
        $applied['max_output_tokens'] = $maxOut;

        $timeout = (int) ($settings['timeout_s'] ?? 0);
        if ($timeout <= 0) {
            $timeout = (int) config('boardroom.limits.timeout_s', 240);
        }
        $applied['timeout_s'] = max(self::TIMEOUT_MIN, min(self::TIMEOUT_MAX, $timeout));

        // ── Advanced parameters: para LANG sa kilala at verified na model ──
        if (! $advanced) {
            foreach (['effort' => $effort, 'thinking' => $thinking] as $k => $v) {
                if ($v !== null) {
                    $dropped[] = ['key' => $k, 'value' => $v, 'reason' => $cap['known'] ? 'model_unverified' : 'model_unknown'];
                }
            }

            return ['applied' => $applied, 'dropped' => $dropped, 'structured' => 'none', 'capability' => $cap];
        }

        if ($thinking !== null) {
            if (in_array($thinking, $cap['thinking_modes'], true)) {
                $applied['thinking'] = $thinking;
            } else {
                $dropped[] = ['key' => 'thinking', 'value' => $thinking, 'reason' => 'unsupported_thinking_mode'];
            }
        }

        if ($effort !== null) {
            if (in_array($effort, $cap['efforts'], true)) {
                $applied['effort'] = $effort;
            } else {
                $dropped[] = ['key' => 'effort', 'value' => $effort, 'reason' => 'unsupported_effort'];
            }
        }

        // Rule: thinking=disabled ay tinatanggap lang hanggang sa isang effort level (hal. Claude Opus 5 → high).
        $cap_at = $options['thinking_disabled_max_effort'] ?? null;
        if ($cap_at && ($applied['thinking'] ?? null) === 'disabled' && isset($applied['effort'])) {
            $order = ['low', 'medium', 'high', 'xhigh', 'max'];
            if (array_search($applied['effort'], $order, true) > array_search($cap_at, $order, true)) {
                $dropped[] = ['key' => 'thinking', 'value' => 'disabled', 'reason' => 'thinking_disabled_not_allowed_at_this_effort'];
                unset($applied['thinking']);
            }
        }

        // Rule: ang effort ay may bisa lang kapag naka-enable ang thinking (hal. DeepSeek).
        if (! empty($options['effort_requires_thinking']) && isset($applied['effort']) && ($applied['thinking'] ?? null) !== 'enabled') {
            $dropped[] = ['key' => 'effort', 'value' => $applied['effort'], 'reason' => 'effort_requires_thinking_enabled'];
            unset($applied['effort']);
        }

        // Rule: thinking=enabled na nangangailangan ng budget_tokens (hal. Claude Haiku 4.5).
        if (isset($options['thinking_budget_tokens']) && ($applied['thinking'] ?? null) === 'enabled') {
            $rule   = (array) $options['thinking_budget_tokens'];
            $min    = (int) ($rule['min'] ?? 1024);
            $budget = (int) ($settings['thinking_budget_tokens'] ?? ($rule['default'] ?? $min));
            $budget = max($min, $budget);
            if ($budget >= $applied['max_output_tokens']) {
                $budget = max($min, (int) floor($applied['max_output_tokens'] / 2));
            }
            if ($budget >= $applied['max_output_tokens']) {
                $dropped[] = ['key' => 'thinking', 'value' => 'enabled', 'reason' => 'max_output_tokens_too_small_for_thinking_budget'];
                unset($applied['thinking']);
            } else {
                $applied['thinking_budget_tokens'] = $budget;
            }
        }

        return ['applied' => $applied, 'dropped' => $dropped, 'structured' => $cap['structured_output'], 'capability' => $cap];
    }

    /** Tantiyang gastos sa USD; null kapag HINDI alam ang presyo (walang hinuhulaang halaga). */
    public function estimateCost(string $provider, string $model, int $tokensIn, int $tokensOut): ?float
    {
        $cap = $this->find($provider, $model);
        if (! $cap || $cap->price_in === null || $cap->price_out === null) {
            return null;
        }

        return round(($tokensIn * (float) $cap->price_in + $tokensOut * (float) $cap->price_out) / 1_000_000, 6);
    }

    public function markLiveVerified(string $provider, string $model): void
    {
        $cap = $this->find($provider, $model);
        if ($cap) {
            $cap->forceFill(['live_verified_at' => now()])->save();
        }
    }

    private function clean(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return ($value === null || $value === '') ? null : $value;
    }
}
