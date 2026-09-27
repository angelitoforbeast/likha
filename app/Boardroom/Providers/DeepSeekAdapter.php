<?php

namespace App\Boardroom\Providers;

/**
 * DeepSeek — documented endpoint: POST https://api.deepseek.com/chat/completions
 * Docs na pinagbatayan (2026-09-28): api-docs.deepseek.com
 *
 * Tala: magkaiba ang dalawang doc page sa pwesto ng reasoning_effort. Top-level ang ipinapadala rito
 * (ayon sa API reference). HINDI pa ito napapatunayan sa totoong request.
 */
class DeepSeekAdapter extends HttpAdapter
{
    public function provider(): string
    {
        return 'deepseek';
    }

    public function endpointName(): string
    {
        return 'chat.completions';
    }

    protected function baseUrl(): string
    {
        return rtrim((string) config('boardroom.endpoints.deepseek'), '/');
    }

    protected function chatPath(): string
    {
        return '/chat/completions';
    }

    protected function authHeaders(string $apiKey): array
    {
        return ['Authorization' => 'Bearer ' . $apiKey];
    }

    public function buildPayload(ProviderRequest $request): array
    {
        $p = $request->params;

        $payload = [
            'model'      => $request->model,
            'messages'   => [
                ['role' => 'system', 'content' => $request->system],
                ['role' => 'user', 'content' => $request->input],
            ],
            'max_tokens' => (int) $p['max_output_tokens'],
            'stream'     => false,
        ];

        if (in_array($p['thinking'] ?? null, ['enabled', 'disabled'], true)) {
            $payload['thinking'] = ['type' => $p['thinking']];
        }
        if (! empty($p['effort'])) {
            $payload['reasoning_effort'] = $p['effort'];
        }
        if ($request->schema && in_array($request->structured, ['json_object', 'json_schema'], true)) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        return $payload;
    }

    protected function parse(array $body, array $sent): ProviderResult
    {
        $choice = (array) ($body['choices'][0] ?? []);
        $text   = (string) ($choice['message']['content'] ?? '');   // reasoning_content ay sadyang hindi kinukuha
        $stop   = (string) ($choice['finish_reason'] ?? '');

        if (in_array($stop, ['insufficient_system_resource', 'aborted'], true)) {
            return ProviderResult::failure('provider_error', "Hindi natapos ng provider ang sagot ({$stop}).", true, 200, $sent);
        }

        $finish = match ($stop) {
            'length'         => ProviderResult::TRUNCATED,
            'content_filter' => ProviderResult::FILTERED,
            default          => ProviderResult::COMPLETED,
        };

        $usage = (array) ($body['usage'] ?? []);

        return ProviderResult::success($text, $finish, [
            'in'        => $usage['prompt_tokens'] ?? 0,
            'out'       => $usage['completion_tokens'] ?? 0,
            'reasoning' => $usage['completion_tokens_details']['reasoning_tokens'] ?? 0,
        ], [
            'raw_finish'  => $stop,
            'response_id' => $body['id'] ?? null,
            'model'       => $body['model'] ?? null,
            'sent'        => $sent,
        ]);
    }
}
