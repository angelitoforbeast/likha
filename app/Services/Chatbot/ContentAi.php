<?php

namespace App\Services\Chatbot;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Isang tanong sa OpenAI para sa content check (services.openai.content_check_model, mura): JSON lang ang sagot.
 * Ang gastos ay tokens × services.openai.ai_checker_prices (USD kada 1M), gaya ng AI Checker.
 * null kapag walang key, pumalya ang tawag o hindi JSON ang sagot: ang tanong ay "not checked yet" pa rin.
 */
class ContentAi
{
    private const CONNECT_TIMEOUT = 5;
    private const TIMEOUT = 40;

    public function model(): string
    {
        return (string) config('services.openai.content_check_model', 'gpt-4o-mini');
    }

    /**
     * @param array<int, array> $content ang mga bahagi ng tanong ng user (text at image_url)
     * @return array{answer: array, model: string, tokens_in: int, tokens_out: int, cost_usd: float}|null
     */
    public function ask(string $system, array $content): ?array
    {
        $key = (string) config('services.openai.key');
        if ($key === '') return null;
        $model = $this->model();
        try {
            $res = Http::withToken($key)->connectTimeout(self::CONNECT_TIMEOUT)->timeout(self::TIMEOUT)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => 0,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $content]],
                ]);
        } catch (Throwable $e) {
            Log::warning('chatbot content check: OpenAI call failed', ['error' => $e->getMessage()]);
            return null;
        }
        if (!$res->successful()) {
            Log::warning('chatbot content check: OpenAI HTTP ' . $res->status());
            return null;
        }
        $answer = json_decode((string) $res->json('choices.0.message.content'), true);
        if (!is_array($answer)) return null;

        $in = (int) $res->json('usage.prompt_tokens', 0);
        $out = (int) $res->json('usage.completion_tokens', 0);
        [$pi, $po] = (array) (config('services.openai.ai_checker_prices')[$model] ?? [0.0, 0.0]) + [0.0, 0.0];

        return ['answer' => $answer, 'model' => $model, 'tokens_in' => $in, 'tokens_out' => $out,
            'cost_usd' => round($in / 1e6 * (float) $pi + $out / 1e6 * (float) $po, 6)];
    }
}
