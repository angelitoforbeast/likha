<?php

namespace App\Boardroom\Providers;

/**
 * Anthropic — native Messages API (POST /v1/messages), hindi OpenAI-compatible shim.
 * Docs na pinagbatayan (2026-09-28): platform.claude.com/docs/en/api/messages
 *
 * Sadyang WALANG `fallbacks` parameter: bawal ang tahimik na paglipat sa ibang model.
 * Ang refusal ay ibinabalik bilang finish status na "refused".
 */
class AnthropicAdapter extends HttpAdapter
{
    public function provider(): string
    {
        return 'anthropic';
    }

    public function endpointName(): string
    {
        return 'messages';
    }

    protected function baseUrl(): string
    {
        return rtrim((string) (config('boardroom.endpoints.anthropic') ?: 'https://api.anthropic.com/v1'), '/');
    }

    protected function chatPath(): string
    {
        return '/messages';
    }

    protected function modelsQuery(): array
    {
        return ['limit' => 1000];
    }

    protected function authHeaders(string $apiKey): array
    {
        return [
            'x-api-key'         => $apiKey,
            'anthropic-version' => (string) config('boardroom.anthropic_version', '2023-06-01'),
        ];
    }

    public function buildPayload(ProviderRequest $request): array
    {
        $p = $request->params;

        $payload = [
            'model'      => $request->model,
            'max_tokens' => (int) $p['max_output_tokens'],
            'system'     => $request->system,
            'messages'   => [['role' => 'user', 'content' => $request->input]],
        ];

        $thinking = $p['thinking'] ?? null;
        if ($thinking === 'adaptive') {
            $payload['thinking'] = ['type' => 'adaptive'];
        } elseif ($thinking === 'disabled') {
            $payload['thinking'] = ['type' => 'disabled'];
        } elseif ($thinking === 'enabled' && ! empty($p['thinking_budget_tokens'])) {
            $payload['thinking'] = ['type' => 'enabled', 'budget_tokens' => (int) $p['thinking_budget_tokens']];
        }

        $output = [];
        if (! empty($p['effort'])) {
            $output['effort'] = $p['effort'];
        }
        if ($request->schema && $request->structured === 'json_schema') {
            $output['format'] = ['type' => 'json_schema', 'schema' => $request->schema['schema']];
        }
        if ($output) {
            $payload['output_config'] = $output;
        }

        return $payload;
    }

    protected function parse(array $body, array $sent): ProviderResult
    {
        $text = '';
        foreach ((array) ($body['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');   // thinking blocks ay sadyang hindi kinukuha
            }
        }

        $stop   = (string) ($body['stop_reason'] ?? '');
        $finish = match ($stop) {
            'max_tokens', 'model_context_window_exceeded' => ProviderResult::TRUNCATED,
            'refusal'                                     => ProviderResult::REFUSED,
            default                                       => ProviderResult::COMPLETED,
        };

        $usage = (array) ($body['usage'] ?? []);
        $in    = (int) ($usage['input_tokens'] ?? 0)
               + (int) ($usage['cache_creation_input_tokens'] ?? 0)
               + (int) ($usage['cache_read_input_tokens'] ?? 0);

        return ProviderResult::success($text, $finish, [
            'in'  => $in,
            'out' => $usage['output_tokens'] ?? 0,
        ], [
            'raw_finish'  => $stop,
            'response_id' => $body['id'] ?? null,
            'model'       => $body['model'] ?? null,
            'sent'        => $sent,
        ]);
    }
}
