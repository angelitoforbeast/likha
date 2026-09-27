<?php

namespace App\Boardroom\Providers;

/**
 * OpenAI — Responses API (POST /v1/responses).
 * Docs na pinagbatayan (2026-09-28): platform.openai.com/docs/api-reference/responses
 */
class OpenAIAdapter extends HttpAdapter
{
    public function provider(): string
    {
        return 'openai';
    }

    public function endpointName(): string
    {
        return 'responses';
    }

    protected function baseUrl(): string
    {
        return rtrim((string) config('boardroom.endpoints.openai'), '/');
    }

    protected function chatPath(): string
    {
        return '/responses';
    }

    protected function authHeaders(string $apiKey): array
    {
        return ['Authorization' => 'Bearer ' . $apiKey];
    }

    public function buildPayload(ProviderRequest $request): array
    {
        $p = $request->params;

        $payload = [
            'model'             => $request->model,
            'instructions'      => $request->system,
            'input'             => $request->input,
            'max_output_tokens' => (int) $p['max_output_tokens'],
            'store'             => false,   // walang previous_response_id na ginagamit; hindi kailangang i-store sa provider
        ];

        if (! empty($p['effort'])) {
            $payload['reasoning'] = ['effort' => $p['effort']];
        }

        if ($request->schema) {
            if ($request->structured === 'json_schema') {
                $payload['text'] = ['format' => [
                    'type'   => 'json_schema',
                    'name'   => $request->schema['name'],
                    'schema' => $request->schema['schema'],
                    'strict' => true,
                ]];
            } elseif ($request->structured === 'json_object') {
                $payload['text'] = ['format' => ['type' => 'json_object']];
            }
        }

        return $payload;
    }

    protected function parse(array $body, array $sent): ProviderResult
    {
        $text    = '';
        $refusal = '';
        foreach ((array) ($body['output'] ?? []) as $item) {
            if (($item['type'] ?? '') !== 'message') {
                continue;   // reasoning items atbp. ay sadyang hindi kinukuha
            }
            foreach ((array) ($item['content'] ?? []) as $part) {
                $type = $part['type'] ?? '';
                if ($type === 'output_text') {
                    $text .= (string) ($part['text'] ?? '');
                } elseif ($type === 'refusal') {
                    $refusal .= (string) ($part['refusal'] ?? '');
                }
            }
        }

        $status = (string) ($body['status'] ?? '');
        $reason = (string) ($body['incomplete_details']['reason'] ?? '');

        if ($status === 'failed') {
            $err = (array) ($body['error'] ?? []);

            return ProviderResult::failure(
                'provider_error',
                \App\Boardroom\Support\Secrets::safeMessage((string) ($err['message'] ?? 'Nabigo ang response.')),
                false,
                200,
                $sent
            );
        }

        $finish = ProviderResult::COMPLETED;
        if ($refusal !== '' && $text === '') {
            $finish = ProviderResult::REFUSED;
            $text   = $refusal;
        } elseif ($status === 'incomplete') {
            $finish = $reason === 'content_filter' ? ProviderResult::FILTERED : ProviderResult::TRUNCATED;
        }

        $usage = (array) ($body['usage'] ?? []);

        return ProviderResult::success($text, $finish, [
            'in'        => $usage['input_tokens'] ?? 0,
            'out'       => $usage['output_tokens'] ?? 0,
            'reasoning' => $usage['output_tokens_details']['reasoning_tokens'] ?? 0,
        ], [
            'raw_finish'  => $status . ($reason !== '' ? ":{$reason}" : ''),
            'response_id' => $body['id'] ?? null,
            'model'       => $body['model'] ?? null,
            'sent'        => $sent,
        ]);
    }
}
