<?php

namespace App\Services\Chatbot;

use App\Services\Chatbot\Wire\ClaudeChat;
use App\Services\Chatbot\Wire\DeepSeekChat;
use App\Services\Chatbot\Wire\OpenAiChat;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Ang tawag sa isang key ng pool (spec 210): ang request ng provider (OpenAI, Claude, DeepSeek) at ang pagbasa ng sagot sa
 * iisang hugis {ok, text, error, prompt_tokens, completion_tokens, kind}. Ang kind ang nagsasabi kung kasalanan ng key:
 * no_credit, invalid, rate_limited, failing (5xx, 529, timeout) = ang key; model, request = ang tawag (walang markang key).
 * Ang error ay nakapirming text na may pangalan ng provider at HTTP status: hindi kailanman ang text ng provider (maaaring
 * may bahagi ng key o ng header).
 */
class ChatbotAiWire
{
    /** Ang mga kind na kasalanan ng key (minamarkahan); ang iba ay hindi. */
    public const KEY_KINDS = ['no_credit', 'invalid', 'rate_limited', 'failing'];
    public const TEST_TOKENS = 1;

    private static function adapter(string $provider): ClaudeChat|DeepSeekChat|OpenAiChat
    {
        return match ($provider) {
            'anthropic' => new ClaudeChat(),
            'deepseek' => new DeepSeekChat(),
            default => new OpenAiChat(),
        };
    }

    /** @return array{url: string, headers: array, body: array} */
    public static function request(string $provider, string $key, string $model, array $messages, int $maxTokens): array
    {
        return self::adapter($provider)->chatRequest($key, $model, $messages, $maxTokens);
    }

    /** Ang normalized na code ng Boardroom (classify) bilang kind ng chatbot. */
    public static function kindOf(?string $code): string
    {
        return match ($code) {
            'billing' => 'no_credit',
            'invalid_credentials', 'permission_denied' => 'invalid',
            'rate_limited' => 'rate_limited',
            'model_not_found' => 'model',
            'overloaded', 'provider_error', 'timeout', 'network', 'invalid_response' => 'failing',
            default => 'request',
        };
    }

    /**
     * Ang sagot (HTTP status at body) bilang resulta ng subok.
     * @return array{ok: bool, text: string, error: ?string, prompt_tokens: ?int, completion_tokens: ?int, kind: string}
     */
    public static function read(string $provider, int $status, string $body, string $key): array
    {
        $label = ChatbotAiProviders::label($provider);
        $json = json_decode($body, true);
        $json = is_array($json) ? $json : [];
        if ($status < 200 || $status >= 300) {
            $kind = self::kindOf(self::adapter($provider)->readFailure($status, $body, $key)->errorCode);
            // Ang OpenAI ay ang dating text (ChatbotAi::parse); ang iba ay "<Label> refused (HTTP n)."
            $out = $provider === ChatbotAiProviders::OPENAI ? ChatbotAiProviders::parse($provider, $status, $json)
                : ['ok' => false, 'text' => '', 'error' => $label . ' refused (HTTP ' . $status . ').', 'prompt_tokens' => null, 'completion_tokens' => null];

            return $out + ['kind' => $kind];
        }
        if ($provider === 'deepseek') return self::deepseek($json, $label);
        if ($provider === ChatbotAiProviders::OPENAI) {
            $out = ChatbotAiProviders::parse($provider, $status, $json);

            return $out + ['kind' => $out['ok'] ? 'ok' : 'request'];
        }
        $r = (new ClaudeChat())->readOk($json);
        [$in, $outTokens] = ClaudeChat::usage($json);
        $text = trim($r->text);
        if (!$r->ok || $text === '') return ['ok' => false, 'text' => '', 'error' => $label . ' gave an empty answer.', 'prompt_tokens' => $in, 'completion_tokens' => $outTokens, 'kind' => 'request'];

        return ['ok' => true, 'text' => $text, 'error' => null, 'prompt_tokens' => $in, 'completion_tokens' => $outTokens, 'kind' => 'ok'];
    }

    private static function deepseek(array $json, string $label): array
    {
        $usage = ['prompt_tokens' => self::int(data_get($json, 'usage.prompt_tokens')), 'completion_tokens' => self::int(data_get($json, 'usage.completion_tokens'))];
        $r = (new DeepSeekChat())->readOk($json);
        // Ang "insufficient_system_resource" o "aborted" ng DeepSeek ay palya ng provider (failing), hindi ng tawag.
        if (!$r->ok) return ['ok' => false, 'text' => '', 'error' => $label . ' did not finish the answer.'] + $usage + ['kind' => self::kindOf($r->errorCode)];
        $text = trim(ChatbotAiProviders::withoutThink($r->text));
        if ($text === '') return ['ok' => false, 'text' => '', 'error' => $label . ' gave an empty answer.'] + $usage + ['kind' => 'request'];

        return ['ok' => true, 'text' => $text, 'error' => null] + $usage + ['kind' => 'ok'];
    }

    private static function int(mixed $v): ?int
    {
        return is_int($v) && $v >= 0 && $v <= ChatbotAiProviders::MAX_TOKENS_SEEN ? $v : null;
    }

    /** Ang palya ng koneksyon (walang sagot): laging failing. */
    public static function noAnswer(string $provider, bool $timedOut): array
    {
        return ['ok' => false, 'text' => '', 'error' => ChatbotAiProviders::noAnswer($provider, $timedOut), 'prompt_tokens' => null, 'completion_tokens' => null, 'kind' => 'failing'];
    }

    /** Ang model ng Test ng key: ang test_model, o ang default ng provider. */
    public static function testModel(string $provider): string
    {
        return (string) (ChatbotAiProviders::option($provider, 'test_model') ?: ChatbotAiProviders::option($provider, 'model', 'gpt-4o-mini'));
    }

    /**
     * Ang Test ng key: isang tawag na isang token lang ang sagot. @return string ok | invalid | no_credit | rate_limited |
     * failing | model | request
     */
    public static function test(string $provider, string $key): string
    {
        $r = self::request($provider, $key, self::testModel($provider), [['role' => 'user', 'content' => 'Reply with OK.']], self::TEST_TOKENS);
        try {
            $res = Http::withHeaders(array_diff_key($r['headers'], ['Accept' => 1]))->acceptJson()->connectTimeout(5)->timeout(20)->withoutRedirecting()->post($r['url'], $r['body']);
        } catch (ConnectionException) {
            return 'failing';
        } catch (Throwable $e) {
            report(new \RuntimeException('chatbot AI key test failed: ' . \App\Boardroom\Support\Secrets::safeMessage($e->getMessage(), [$key])));
            return 'failing';
        }
        $out = self::read($provider, $res->status(), (string) $res->body(), $key);
        // Ang isang token ay maaaring putol o walang text: pumasa pa rin (2xx = tama ang key at may credit).
        if ($res->successful()) return 'ok';

        return $out['kind'];
    }
}
