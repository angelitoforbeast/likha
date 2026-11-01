<?php

namespace App\Services\Chatbot\Wire;

use App\Boardroom\Providers\OpenAIAdapter;
use App\Services\Chatbot\ChatbotAi;
use App\Services\Chatbot\ChatbotAiProviders;

/**
 * OpenAI para sa chatbot: ang chat completions na gamit ngayon (ChatbotAi::payload, ang URL ng config), hindi ang Responses
 * API ng Boardroom; ang classify lang ng error ang galing sa adapter.
 */
class OpenAiChat extends OpenAIAdapter
{
    use ChatWire;

    public function chatRequest(#[\SensitiveParameter] string $key, string $model, array $messages, int $maxTokens): array
    {
        return ['url' => (string) ChatbotAiProviders::url(ChatbotAiProviders::OPENAI), 'headers' => ['Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json'],
            'body' => $this->chatBody($model, $messages, $maxTokens)];
    }

    protected function chatBody(string $model, array $messages, int $maxTokens): array
    {
        return ChatbotAi::payload($model, $messages, $maxTokens);
    }
}
