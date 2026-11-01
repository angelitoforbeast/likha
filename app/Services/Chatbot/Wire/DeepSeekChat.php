<?php

namespace App\Services\Chatbot\Wire;

use App\Boardroom\Providers\DeepSeekAdapter;
use App\Boardroom\Providers\ProviderRequest;

/** DeepSeek (OpenAI-compatible na chat completions) para sa chatbot: ang chat messages ay ipinapadala gaya ng dati. */
class DeepSeekChat extends DeepSeekAdapter
{
    use ChatWire;

    protected function chatBody(string $model, array $messages, int $maxTokens): array
    {
        $body = $this->buildPayload(new ProviderRequest('deepseek', $model, '', '', ['max_output_tokens' => $maxTokens]));
        $body['messages'] = array_values(array_map(fn ($m) => ['role' => (string) ($m['role'] ?? 'user'), 'content' => (string) ($m['content'] ?? '')], $messages));

        return $body;
    }
}
