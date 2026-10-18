<?php

namespace App\Services\Chatbot;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Ang AI step: isang chat completion sa OpenAI gamit ang umiiral na config (services.openai.key). Walang key na
 * nakatala o ibinabalik sa error.
 */
class ChatbotAi
{
    public const URL = 'https://api.openai.com/v1/chat/completions';
    public const TIMEOUT = 30;

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return array{ok: bool, text: string, error: ?string}
     */
    public function complete(string $model, array $messages, int $maxTokens): array
    {
        $key = (string) config('services.openai.key');
        if ($key === '') return ['ok' => false, 'text' => '', 'error' => 'No OpenAI key is set on the server.'];

        try {
            $res = Http::withToken($key)->acceptJson()->timeout(self::TIMEOUT)->post(self::URL, [
                'model' => $model, 'messages' => $messages, 'max_tokens' => $maxTokens,
            ]);
        } catch (ConnectionException $e) {
            return ['ok' => false, 'text' => '', 'error' => 'OpenAI did not answer.'];
        }

        $text = trim((string) $res->json('choices.0.message.content', ''));
        if (!$res->successful()) {
            return ['ok' => false, 'text' => '', 'error' => 'OpenAI refused (HTTP ' . $res->status() . ').']; // walang mensahe ng OpenAI: maaaring may bahagi ng key
        }
        if ($text === '') return ['ok' => false, 'text' => '', 'error' => 'OpenAI gave an empty answer.'];

        return ['ok' => true, 'text' => $text, 'error' => null];
    }
}
