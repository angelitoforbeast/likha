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
    public const NO_KEY = 'No OpenAI key is set on the server.';

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return array{ok: bool, text: string, error: ?string}
     */
    public function complete(string $model, array $messages, int $maxTokens): array
    {
        $key = (string) config('services.openai.key');
        if ($key === '') return ['ok' => false, 'text' => '', 'error' => self::NO_KEY];

        try {
            $res = Http::withToken($key)->acceptJson()->timeout(self::TIMEOUT)->post(self::URL, self::payload($model, $messages, $maxTokens));
        } catch (ConnectionException $e) {
            return ['ok' => false, 'text' => '', 'error' => 'OpenAI did not answer.'];
        }

        return self::parse($res->status(), $res->json());
    }

    /** Ang body ng request; pareho para sa naghihintay at sa chatbot:ai-pool. */
    public static function payload(string $model, array $messages, int $maxTokens): array
    {
        return ['model' => $model, 'messages' => $messages, 'max_tokens' => $maxTokens];
    }

    /**
     * Ang sagot ng OpenAI (HTTP status at JSON) bilang resulta ng step; pareho para sa naghihintay at sa chatbot:ai-pool.
     * @return array{ok: bool, text: string, error: ?string}
     */
    public static function parse(int $status, mixed $json): array
    {
        $text = trim((string) data_get(is_array($json) ? $json : [], 'choices.0.message.content', ''));
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'text' => '', 'error' => 'OpenAI refused (HTTP ' . $status . ').']; // walang mensahe ng OpenAI: maaaring may bahagi ng key
        }
        if ($text === '') return ['ok' => false, 'text' => '', 'error' => 'OpenAI gave an empty answer.'];

        return ['ok' => true, 'text' => $text, 'error' => null];
    }
}
