<?php

namespace App\Boardroom\Providers;

/**
 * Provider-neutral na request. Ang $params ay galing sa CapabilityRegistry::resolve() —
 * ibig sabihin, NASALA NA: ang wala rito ay hindi ipapadala sa provider.
 */
final class ProviderRequest
{
    /**
     * @param  array  $params      applied settings: max_output_tokens, timeout_s, effort?, thinking?, thinking_budget_tokens?
     * @param  array|null  $schema ['name' => string, 'schema' => array] kapag JSON ang kailangang sagot
     * @param  string  $structured json_schema | json_object | none (kakayahan ng model)
     */
    public function __construct(
        public string $provider,
        public string $model,
        public string $system,
        public string $input,
        public array $params = [],
        public ?array $schema = null,
        public string $structured = 'none',
    ) {
    }
}
