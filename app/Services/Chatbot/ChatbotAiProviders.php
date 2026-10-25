<?php

namespace App\Services\Chatbot;

/**
 * Ang mga AI na maaaring sumagot sa AI step (services.chatbot_ai.providers): OpenAI at Local, parehong OpenAI-compatible.
 * Dito ang URL ng request, ang key (wala = walang Authorization header), kung naka-set up, at ang pagbasa ng sagot. Ang mga
 * error ay nakapirming text na may pangalan ng provider: walang body, key o text ng customer.
 */
class ChatbotAiProviders
{
    public const OPENAI = 'openai';
    public const LOCAL = 'local';
    public const MAX_TOKENS_SEEN = 10000000; // ang usage na lampas dito (o hindi numero) ay hindi tinatanggap

    /** @return array<int, string> */
    public static function names(): array
    {
        return array_keys((array) config('services.chatbot_ai.providers', []));
    }

    public static function exists(string $name): bool
    {
        return in_array($name, self::names(), true);
    }

    public static function label(string $name): string
    {
        return (string) (config("services.chatbot_ai.providers.$name.label") ?: ucfirst($name));
    }

    /** Ang default ng provider mula sa config (model, timeout, concurrency, breaker_*), o $default. */
    public static function option(string $name, string $key, mixed $default = null): mixed
    {
        return config("services.chatbot_ai.providers.$name.$key", $default);
    }

    public static function key(string $name): string
    {
        $from = (string) self::option($name, 'key_config', '');

        return trim((string) ($from !== '' ? config($from) : self::option($name, 'key', '')));
    }

    /**
     * Ang URL ng request (<base>/v1/chat/completions, isang slash lang), o null kapag walang base o bawal. Ang localhost_only
     * ay http sa 127.0.0.1 o localhost lang (kahit anong port, walang user:pass): ang ibang host ay hindi kailanman tinatawagan.
     */
    public static function url(string $name): ?string
    {
        $base = trim((string) self::option($name, 'url', ''));
        if ($base === '' || !self::exists($name)) return null;
        $u = parse_url($base);
        if (!is_array($u) || !isset($u['scheme'], $u['host']) || isset($u['user']) || isset($u['pass']) || isset($u['query']) || isset($u['fragment'])) return null;
        $scheme = strtolower($u['scheme']);
        if (self::option($name, 'localhost_only', false)) {
            if ($scheme !== 'http' || !in_array(strtolower($u['host']), ['127.0.0.1', 'localhost'], true)) return null;
        } elseif (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $path = rtrim((string) ($u['path'] ?? ''), '/');
        if (!str_ends_with($path, '/v1')) $path .= '/v1';
        if (str_contains($path, '..')) return null;

        return $scheme . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '') . $path . '/chat/completions';
    }

    /** Bakit hindi magagamit ang provider ("<Label> is not set up."), o null kapag handa. */
    public static function notReady(string $name): ?string
    {
        if (!self::exists($name) || self::url($name) === null) return self::label($name) . ' is not set up.';
        if (self::option($name, 'needs_key', false) && self::key($name) === '') return self::label($name) . ' is not set up.';

        return null;
    }

    /** Ang error ng step kapag hindi naka-set up: ang OpenAI na walang key ay ang dating text. */
    public static function notReadyError(string $name): string
    {
        return $name === self::OPENAI && self::url($name) !== null ? ChatbotAi::NO_KEY : (string) self::notReady($name);
    }

    public static function noAnswer(string $name, bool $timedOut): string
    {
        // Ang OpenAI ay may iisang text gaya ng dati; ang iba ay sinasabi kung naubos ang oras.
        return self::label($name) . ($timedOut && $name !== self::OPENAI ? ' did not answer in time.' : ' did not answer.');
    }

    /**
     * Ang sagot (HTTP status at JSON) bilang resulta ng step, kasama ang usage. Ang OpenAI ay binabasa gaya ng dati
     * (ChatbotAi::parse); ang iba ay inaalisan din ng <think>…</think> sa unahan (hindi magagamit ang puro pag-iisip).
     * @return array{ok: bool, text: string, error: ?string, prompt_tokens: ?int, completion_tokens: ?int}
     */
    public static function parse(string $name, int $status, mixed $json): array
    {
        $json = is_array($json) ? $json : [];
        $usage = ['prompt_tokens' => self::tokens($json, 'prompt_tokens'), 'completion_tokens' => self::tokens($json, 'completion_tokens')];
        if ($name === self::OPENAI) return ChatbotAi::parse($status, $json) + $usage;

        $label = self::label($name);
        if ($status < 200 || $status >= 300) return ['ok' => false, 'text' => '', 'error' => $label . ' refused (HTTP ' . $status . ').'] + $usage;
        $raw = data_get($json, 'choices.0.message.content');
        $text = trim(self::withoutThink(is_string($raw) ? $raw : ''));
        if ($text === '') return ['ok' => false, 'text' => '', 'error' => $label . ' gave an empty answer.'] + $usage;

        return ['ok' => true, 'text' => $text, 'error' => null] + $usage;
    }

    /** Ang text na wala ang <think>…</think> sa unahan; ang hindi naisarang <think> (naputol) ay walang natitira. */
    public static function withoutThink(string $text): string
    {
        $text = (string) preg_replace('/^\s*(<think>.*?<\/think>\s*)+/is', '', $text);

        return preg_match('/^\s*<think>/i', $text) ? '' : $text;
    }

    private static function tokens(array $json, string $key): ?int
    {
        $v = data_get($json, 'usage.' . $key);
        if (is_int($v) || (is_string($v) && ctype_digit($v))) {
            $v = (int) $v;
            return $v >= 0 && $v <= self::MAX_TOKENS_SEEN ? $v : null;
        }

        return null;
    }
}
