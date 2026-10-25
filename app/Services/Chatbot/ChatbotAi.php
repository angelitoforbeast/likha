<?php

namespace App\Services\Chatbot;

/**
 * Ang AI step: ang chat completion ng page (OpenAI gaya ng dati, o ang chain ng ChatbotAiChain). Walang key na
 * nakatala o ibinabalik sa error.
 */
class ChatbotAi
{
    public const URL = 'https://api.openai.com/v1/chat/completions';
    public const TIMEOUT = 30;
    public const NO_KEY = 'No OpenAI key is set on the server.';

    /**
     * Ang naghihintay na AI step: ang chain ng page (ChatbotAiChain; walang setting = isang tawag sa OpenAI gaya ng dati).
     * @return array{ok: bool, text: string, error: ?string}
     */
    public function answer(FlowRun $run, string $nodeId, string $model, array $messages, int $maxTokens): array
    {
        $out = app(ChatbotAiChain::class)->complete($run->pageName, $model, $messages, $maxTokens);
        try {
            ChatbotAiQueue::record($run, $nodeId, $model, $maxTokens, $out);
        } catch (\Throwable $e) {
            report($e); // ang record ay hindi dapat makapigil sa sagot sa customer
        }

        return ['ok' => $out['ok'], 'text' => $out['text'], 'error' => $out['error']];
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
