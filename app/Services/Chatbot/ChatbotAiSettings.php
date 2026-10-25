<?php

namespace App\Services\Chatbot;

use App\Models\AppSetting;
use App\Services\ChatblastSetupCheck;
use App\Services\PageSettings;
use Illuminate\Support\Facades\DB;

/**
 * Ang pagpili ng AI sa /chatbot/pages: ang override kada page (page_settings chatbot.ai_*, may history) at ang site default
 * (app_settings chatbot.ai.*, at ang history nito bilang row ng nakalaang page na "_all", kita sa Changes). Lahat o wala:
 * walang naisusulat kapag may isang maling value. Walang key o URL dito: nasa config lang ang mga iyon.
 */
class ChatbotAiSettings
{
    public const SITE_PAGE = ChatblastSetupCheck::ALL_PAGE;
    public const DEFAULT = 'default'; // ang value na nagbabalik sa page sa site default
    public const MODEL_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,99}$/'; // mga pangalan ng Ollama: qwen3:14b, hf.co/org/model
    public const NOT_SET_UP = 'Local AI is not set up yet.';
    public const NO_MODEL = 'Set the Local model name first.';

    /** Ang mga key ng page at ng site (walang "ai_" sa app_settings), at ang pangalan nila sa Changes. */
    public const PAGE_FIELDS = ['provider', 'fallback', 'local_model', 'local_timeout'];
    public const SITE_FIELDS = ['provider', 'fallback', 'local_model', 'local_timeout', 'local_concurrency', 'breaker_failures', 'breaker_seconds', 'local_reasoning_effort'];
    public const LABELS = ['ai_provider' => 'AI provider', 'ai_fallback' => 'AI fallback', 'ai_local_model' => 'Local model', 'ai_local_timeout' => 'Local timeout',
        'ai_local_concurrency' => 'Local concurrency', 'ai_breaker_failures' => 'Breaker failures', 'ai_breaker_seconds' => 'Breaker seconds',
        'ai_local_reasoning_effort' => 'Local reasoning'];
    private const RANGES = ['local_timeout' => [1, 60], 'local_concurrency' => [1, 50], 'breaker_failures' => [1, 20], 'breaker_seconds' => [5, 600]];

    public function __construct(private PageSettings $settings)
    {
    }

    /** @return array<int, string> ang mga key sa page_settings (chatbot.ai_*) */
    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }

    /** Ang value sa Changes: OpenAI / Local / None, at may "s" ang segundo. */
    public static function shown(string $key, string $value): string
    {
        return match ($key) {
            'ai_provider' => ChatbotAiProviders::label($value),
            'ai_fallback' => implode(' → ', array_map(fn ($p) => $p === ChatbotAiChain::NONE ? 'None' : ChatbotAiProviders::label($p), explode(',', $value))),
            'ai_local_timeout', 'ai_breaker_seconds' => $value . ' s',
            'ai_local_reasoning_effort' => $value === ChatbotAiChain::OMIT ? 'Not sent' : $value,
            default => $value,
        };
    }

    /**
     * Ang mga binago lang sa $input (ang wala o null ay hindi ginagalaw), sinuri. @return array{0: ?string, 1: array<string, string>}
     * [error, field => value]; ang "default" (o blangko sa model at timeout ng page) ay pagbalik sa site default.
     */
    public static function clean(array $input, bool $site): array
    {
        $out = [];
        foreach ($site ? self::SITE_FIELDS : self::PAGE_FIELDS as $f) {
            if (!array_key_exists($f, $input)) continue;
            // Ang blangko ay nagiging null sa request; sa reasoning ng Local, ang blangko ay may kahulugan (hindi ipinapadala).
            if ($input[$f] === null) {
                if ($f !== 'local_reasoning_effort') continue;
                $input[$f] = '';
            }
            if (!is_scalar($input[$f])) return ['Pick a valid ' . strtolower(self::LABELS['ai_' . $f]) . '.', []];
            $v = trim((string) $input[$f]);
            $clear = !$site && ($v === self::DEFAULT || ($v === '' && in_array($f, ['local_model', 'local_timeout'], true)));
            // Ang reasoning ng Local: blangko = hindi ipinapadala (naitatala bilang "omit").
            if ($f === 'local_reasoning_effort') {
                if ($v !== '' && !in_array($v, ChatbotAiChain::EFFORTS, true)) return ['Local reasoning: none, low, medium, high, or empty (not sent).', []];
                $out[$f] = $v === '' ? ChatbotAiChain::OMIT : $v;
                continue;
            }
            if ($clear) {
                $out[$f] = self::DEFAULT;
                continue;
            }
            $ok = match ($f) {
                'provider' => ChatbotAiProviders::exists($v),
                'fallback' => $v === ChatbotAiChain::NONE || ChatbotAiProviders::exists($v),
                'local_model' => (bool) preg_match(self::MODEL_PATTERN, $v),
                default => ctype_digit($v) && (int) $v >= self::RANGES[$f][0] && (int) $v <= self::RANGES[$f][1],
            };
            if (!$ok) {
                $range = self::RANGES[$f] ?? null;
                return [match ($f) {
                    'provider', 'fallback' => 'Pick OpenAI or Local' . ($f === 'fallback' ? ' (or None)' : '') . '.',
                    'local_model' => 'Model name: letters, digits and . : - _ / only, up to 100.',
                    default => self::LABELS['ai_' . $f] . ': a whole number from ' . $range[0] . ' to ' . $range[1] . '.',
                }, []];
            }
            $out[$f] = $f === 'local_timeout' || isset(self::RANGES[$f]) ? (string) (int) $v : $v;
        }

        return $out ? [null, $out] : ['Nothing to change.', []];
    }

    /** Ang Local ay pinili (bilang provider o fallback): kailangang naka-set up at may model. */
    private function localError(array $page, array $site, array $changes, bool $forSite): ?string
    {
        $merged = $forSite ? array_merge($site, $changes) : array_merge($page, array_map(fn ($v) => $v === self::DEFAULT ? null : $v, self::prefixed($changes)));
        $get = fn (string $f) => $forSite ? ($merged[$f] ?? null) : ($merged['ai_' . $f] ?? $site[$f] ?? null);
        $usesLocal = ($get('provider') ?? ChatbotAiProviders::OPENAI) === ChatbotAiProviders::LOCAL || in_array(ChatbotAiProviders::LOCAL, explode(',', (string) ($get('fallback') ?? '')), true);
        if (!$usesLocal) return null;
        if (ChatbotAiProviders::notReady(ChatbotAiProviders::LOCAL) !== null) return self::NOT_SET_UP;
        $model = $get('local_model') ?? $site['local_model'] ?? (string) ChatbotAiProviders::option(ChatbotAiProviders::LOCAL, 'model', '');

        return trim((string) $model) === '' ? self::NO_MODEL : null;
    }

    private static function prefixed(array $changes): array
    {
        return array_combine(array_map(fn ($f) => 'ai_' . $f, array_keys($changes)), array_values($changes));
    }

    /**
     * Isulat sa mga page (lahat o wala). @param array<int, string> $pages ang mga nakalistang pangalan
     * @return array{status: string, error: ?string}
     */
    public function savePages(array $pages, array $changes, ?int $userId): array
    {
        $site = ChatbotAiChain::site();
        foreach ($pages as $page) {
            if ($e = $this->localError($this->settings->all($page, ChatbotAiChain::PROVIDER), $site, $changes, false)) return ['status' => 'refused', 'error' => $e];
        }
        $statuses = DB::transaction(function () use ($pages, $changes, $userId) {
            $out = [];
            foreach ($pages as $page) {
                foreach (self::prefixed($changes) as $key => $value) {
                    if ($value !== self::DEFAULT) {
                        $out[] = $this->settings->set($page, ChatbotAiChain::PROVIDER, $key, $value, false, $userId, true)['status'];
                        continue;
                    }
                    $rows = $this->settings->pageQuery($page)->where('provider', ChatbotAiChain::PROVIDER)->where('key', $key)->where('active', true)->get();
                    foreach ($rows as $row) $this->settings->deactivate($row, $userId);
                    $out[] = $rows->isEmpty() ? 'unchanged' : 'cleared';
                }
            }

            return $out;
        });

        return ['status' => array_unique($statuses) === ['unchanged'] ? 'unchanged' : 'saved', 'error' => null];
    }

    /** Isulat ang site default: ang value sa app_settings at ang history line (page "_all"), sabay. @return array{status: string, error: ?string} */
    public function saveSite(array $changes, ?int $userId): array
    {
        $site = ChatbotAiChain::site();
        if ($e = $this->localError([], $site, $changes, true)) return ['status' => 'refused', 'error' => $e];
        $statuses = DB::transaction(function () use ($changes, $userId) {
            $out = [];
            foreach ($changes as $f => $value) {
                $out[] = $this->settings->set(self::SITE_PAGE, ChatbotAiChain::PROVIDER, 'ai_' . $f, $value, false, $userId, true)['status'];
                AppSetting::set(ChatbotAiChain::SITE_PREFIX . $f, $value);
            }

            return $out;
        });
        ChatbotAiChain::forget();
        DB::afterCommit(fn () => ChatbotAiChain::forget());

        return ['status' => array_unique($statuses) === ['unchanged'] ? 'unchanged' : 'saved', 'error' => null];
    }

    /** Ang AI ng isang page para sa row ng /chatbot/pages. */
    public function row(string $page): array
    {
        $own = $this->settings->all($page, ChatbotAiChain::PROVIDER);
        $site = ChatbotAiChain::site();
        $provider = ChatbotAiChain::providerOf($own, $site);
        $override = isset($own['ai_provider']) && ChatbotAiProviders::exists((string) $own['ai_provider']);
        $chain = ChatbotAiChain::forPage($page);

        return [
            'provider' => $provider, 'override' => $override, 'label' => ChatbotAiProviders::label($provider) . ($override ? '' : ' (default)'),
            'fallback' => $own['ai_fallback'] ?? null, 'local_model' => $own['ai_local_model'] ?? null, 'local_timeout' => isset($own['ai_local_timeout']) ? (int) $own['ai_local_timeout'] : null,
            'chain' => implode(' → ', array_map(fn ($s) => ChatbotAiProviders::label($s['provider']), $chain)),
        ];
    }

    /** Ang site default at ang mga mapipili (walang URL o key). */
    public function site(): array
    {
        $site = ChatbotAiChain::site();
        $local = ChatbotAiProviders::LOCAL;

        return [
            'provider' => ChatbotAiChain::providerOf([], $site), 'fallback' => $site['fallback'] ?? null,
            'local_model' => $site['local_model'] ?? ((string) ChatbotAiProviders::option($local, 'model', '') ?: null),
            'local_timeout' => (int) ($site['local_timeout'] ?? ChatbotAiProviders::option($local, 'timeout', 8)),
            'local_concurrency' => (int) ($site['local_concurrency'] ?? ChatbotAiProviders::option($local, 'concurrency', 4)),
            'breaker_failures' => (int) ($site['breaker_failures'] ?? ChatbotAiProviders::option($local, 'breaker_failures', 3)),
            'breaker_seconds' => (int) ($site['breaker_seconds'] ?? ChatbotAiProviders::option($local, 'breaker_seconds', 30)),
            'local_reasoning_effort' => ChatbotAiChain::reasoning($local),
            'local_ready' => ChatbotAiProviders::notReady($local) === null,
            'providers' => array_map(fn ($p) => ['name' => $p, 'label' => ChatbotAiProviders::label($p)], ChatbotAiProviders::names()),
        ];
    }
}
