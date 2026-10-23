<?php

namespace App\Services\OrderEncode;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Ang mga tawag sa OpenAI ng dalawang script. extract(): chat completions (auto-encode.gs, temperature 0.2). strictJson():
 * Responses API muna, saka chat completions kapag hindi 2xx o may exception (OPENAI_callStrictJSON_ ng 1.0 OUTPUT).
 * Ang key ay galing sa config lang at hindi lumalabas sa anumang itinatala.
 */
class Gpt
{
    public const URL_CHAT = 'https://api.openai.com/v1/chat/completions';
    public const URL_RESPONSES = 'https://api.openai.com/v1/responses';

    /** Ang huling tawag: model, prompt_hash, requests, tokens_in, tokens_out, ms (para sa Steps). @var array<string, mixed> */
    public array $last = [];

    public function __construct(private string $key, private string $extractModel, private string $checkerModel, private Steps $steps, private int $timeout = 60)
    {
    }

    public function hasKey(): bool
    {
        return $this->key !== '';
    }

    /** Ang script ay nagtatapon kapag walang key (sa labas ng try ng bawat fixer). */
    public function requireKey(): void
    {
        if ($this->key === '') throw new RuntimeException('API Key not found');
    }

    public function checkerModel(): string
    {
        return $this->checkerModel;
    }

    public function extractModel(): string
    {
        return $this->extractModel;
    }

    private function begin(string $model, string $system, string $user): float
    {
        $this->last = ['model' => $model, 'prompt_hash' => sha1($system . "\n" . $user), 'requests' => 0, 'tokens_in' => null, 'tokens_out' => null];

        return microtime(true);
    }

    private function end(float $t0): void
    {
        $this->last['ms'] = (int) round((microtime(true) - $t0) * 1000);
    }

    private function post(string $url, array $payload)
    {
        $this->last['requests']++;

        return Http::withToken($this->key)->acceptJson()->asJson()->timeout($this->timeout)->post($url, $payload);
    }

    /**
     * Ang chat completion ng auto-encode.gs: ang sagot ay `choices[0].message.content` na trimmed, o "" kapag wala (ang HTTP error
     * ay "" din, gaya ng script). Nagtatapon lang kapag hindi makonekta.
     */
    public function extract(string $system, string $user): string
    {
        $t0 = $this->begin($this->extractModel, $system, $user);
        try {
            $res = $this->post(self::URL_CHAT, [
                'model' => $this->extractModel,
                'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
                'temperature' => 0.2,
            ]);
            $json = $res->json();
            $this->usage($json, 'prompt_tokens', 'completion_tokens');
            $this->last['http'] = $res->status();
            $text = Text::jsTrim((string) ($json['choices'][0]['message']['content'] ?? ''));
            if (!$res->successful()) $this->last['raw'] = (string) $res->body();

            return $text;
        } finally {
            $this->end($t0);
        }
    }

    /** @throws RuntimeException "OpenAI API error: <code> <body>" kapag pareho ay pumalya, gaya ng script */
    public function strictJson(string $system, string $user): string
    {
        $t0 = $this->begin($this->checkerModel, $system, $user);
        try {
            try {
                $res = $this->post(self::URL_RESPONSES, [
                    'model' => $this->checkerModel,
                    'temperature' => 0,
                    'input' => [
                        ['role' => 'system', 'content' => [['type' => 'input_text', 'text' => $system]]],
                        ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $user]]],
                    ],
                ]);
                if ($res->successful()) {
                    $data = $res->json();
                    $this->usage($data, 'input_tokens', 'output_tokens');

                    return self::outputText(is_array($data) ? $data : []) ?: (string) $res->body();
                }
            } catch (\Throwable $e) {
                // ang script ay lumilipat sa chat completions sa anumang pagkabigo
            }

            $res2 = $this->post(self::URL_CHAT, [
                'model' => $this->checkerModel,
                'temperature' => 0,
                'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user . "\nReturn JSON only."]],
            ]);
            if (!$res2->successful()) throw new RuntimeException('OpenAI API error: ' . $res2->status() . ' ' . $res2->body());
            $data2 = $res2->json();
            $this->usage($data2, 'prompt_tokens', 'completion_tokens');

            return Text::jsTrim((string) ($data2['choices'][0]['message']['content'] ?? ''));
        } finally {
            $this->end($t0);
        }
    }

    /** BRGYFIX_extractOutputText_ */
    public static function outputText(array $data): string
    {
        if (is_string($data['output_text'] ?? null) && Text::jsTrim($data['output_text']) !== '') return Text::jsTrim($data['output_text']);
        foreach ((array) ($data['output'] ?? []) as $item) {
            foreach ((array) ($item['content'] ?? []) as $c) {
                if (in_array($c['type'] ?? '', ['output_text', 'text'], true) && ($c['text'] ?? '') !== '') return Text::jsTrim((string) $c['text']);
            }
        }

        return '';
    }

    private function usage($json, string $in, string $out): void
    {
        if (!is_array($json)) return;
        $this->last['tokens_in'] = isset($json['usage'][$in]) ? (int) $json['usage'][$in] : null;
        $this->last['tokens_out'] = isset($json['usage'][$out]) ? (int) $json['usage'][$out] : null;
    }

    /** Ang mga field ng huling tawag para sa Steps::add(). @return array<string, mixed> */
    public function meta(): array
    {
        return array_intersect_key($this->last, array_flip(['model', 'prompt_hash', 'requests', 'tokens_in', 'tokens_out', 'ms']));
    }
}
