<?php

namespace App\Services\Chatbot;

use App\Services\PageSettings;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Ang pagkakasunod ng mga AI na sumasagot sa AI step ng isang page (ang chain) at ang naghihintay na takbo nito.
 * - Ang chain: ang provider ng page (chatbot.ai_provider) o ang site default (app_settings chatbot.ai.*) o OpenAI, saka ang
 *   fallback (chatbot.ai_fallback; default: Local → OpenAI, OpenAI → wala). Ang OpenAI ay ang model ng node; ang iba ay ang
 *   sariling model at timeout (page, site, config).
 * - Isang sagot lang: sunod-sunod ang subok sa loob ng oras ng step; ang unang magagamit na sagot ang ginagamit.
 * - Ang may cap (Local) ay may mga slot na cache lock (pinagsasaluhan ng mga worker at ng pool, kusang nawawala), at ang may
 *   breaker ay nilalaktawan nang ilang segundo pagkatapos ng sunod-sunod na palya. Ang OpenAI ay wala nito: gaya ng dati.
 */
class ChatbotAiChain
{
    public const PROVIDER = 'chatbot'; // page_settings provider ng mga key ng page
    public const SITE_PREFIX = 'chatbot.ai.'; // app_settings
    public const SITE_CACHE = 'chatbot-ai-site';
    public const NONE = 'none';
    public const EFFORTS = ['none', 'low', 'medium', 'high'];
    public const OMIT = 'omit'; // ang naka-save na "huwag ipadala ang reasoning_effort" (ang blangko ay hindi maitatala sa history)

    /** Ang mga setting ng site (app_settings chatbot.ai.<key>), naka-cache nang sandali. @return array<string, string> */
    public static function site(): array
    {
        return Cache::remember(self::SITE_CACHE, 60, fn () => Schema::hasTable('app_settings')
            ? DB::table('app_settings')->where('key', 'like', self::SITE_PREFIX . '%')->pluck('value', 'key')
                ->mapWithKeys(fn ($v, $k) => [substr($k, strlen(self::SITE_PREFIX)) => (string) $v])->all()
            : []);
    }

    public static function forget(): void
    {
        Cache::forget(self::SITE_CACHE);
    }

    /** Ang setting ng page, o ng site, o null. */
    private static function pick(array $page, array $site, string $key): ?string
    {
        foreach ([$page['ai_' . $key] ?? null, $site[$key] ?? null] as $v) {
            if ($v !== null && trim((string) $v) !== '') return trim((string) $v);
        }

        return null;
    }

    /** Ang provider na pinili para sa page (o ang site default, o OpenAI). */
    public static function providerOf(array $page, array $site): string
    {
        $p = self::pick($page, $site, 'provider');

        return $p !== null && ChatbotAiProviders::exists($p) ? $p : ChatbotAiProviders::OPENAI;
    }

    /**
     * Ang chain ng page: [{provider, model (null = ang model ng node), timeout (null = ang natitirang oras)}].
     * @return array<int, array{provider: string, model: ?string, timeout: ?int}>
     */
    public static function forPage(string $pageName, ?int $group = null, ?array $site = null): array
    {
        $page = app(PageSettings::class)->all($pageName, self::PROVIDER);
        // Ang ad group ng customer (AdGroups): ang AI na itinakda nito ang pumapalit sa AI ng page; ang hindi itinakda = ang sa page.
        if ($group !== null) {
            $values = app(AdGroups::class)->groups($pageName)[$group]['values'] ?? [];
            foreach (AdGroups::AI_KEYS as $key) if (isset($values[$key])) $page[$key] = $values[$key];
        }
        $site ??= self::site(); // ang listahan ng /chatbot/pages ay nagbibigay ng iisang basa para sa lahat ng page
        $first = self::providerOf($page, $site);
        $fallback = self::pick($page, $site, 'fallback') ?? ($first === ChatbotAiProviders::OPENAI ? self::NONE : ChatbotAiProviders::OPENAI);
        $names = [$first];
        foreach (explode(',', $fallback) as $n) {
            $n = trim($n);
            if ($n !== self::NONE && ChatbotAiProviders::exists($n) && !in_array($n, $names, true)) $names[] = $n;
        }

        return array_map(fn ($p) => $p === ChatbotAiProviders::OPENAI ? ['provider' => $p, 'model' => null, 'timeout' => null] : [
            'provider' => $p,
            'model' => self::pick($page, $site, $p . '_model') ?? (string) ChatbotAiProviders::option($p, 'model', ''),
            'timeout' => max(1, (int) (self::pick($page, $site, $p . '_timeout') ?? ChatbotAiProviders::option($p, 'timeout', 8))),
        ], $names);
    }

    /**
     * Ang chain ng isang ticket ng pool (chatbot_ai_requests): ang page nito at ang ad group ng takbo na naitala sa context
     * (ChatbotAiQueue::submit); ang walang group (o ang lumang ticket) = ang AI ng page.
     */
    public static function forRow(object $row): array
    {
        $group = json_decode((string) ($row->context ?? ''), true)['ad_group'] ?? null;

        return self::forPage((string) $row->page_name, is_int($group) ? $group : null);
    }

    /** Isang numero ng site (hal. local_concurrency), o ang default ng provider sa config. */
    private static function siteInt(string $provider, string $key, int $default): int
    {
        $site = self::site();
        $v = $site[$provider . '_' . $key] ?? $site[$key] ?? null;

        return max(1, (int) ($v ?? ChatbotAiProviders::option($provider, $key, $default)));
    }

    /** May cap ang provider (Local)? Ang OpenAI ay wala: ang pool lang ang naglilimita sa kanya, gaya ng dati. */
    public static function capped(string $provider): bool
    {
        return ChatbotAiProviders::option($provider, 'concurrency') !== null;
    }

    /** Isang bakanteng slot ng provider na may cap (hanggang $ttl segundo), o null kapag puno. */
    public static function slot(string $provider, int $ttl): ?Lock
    {
        $cap = self::siteInt($provider, 'concurrency', 4);
        for ($i = 0; $i < $cap; $i++) {
            $lock = Cache::lock('chatbot-ai:' . $provider . ':slot:' . $i, max(1, $ttl));
            if ($lock->get()) return $lock;
        }

        return null;
    }

    private static function hasBreaker(string $provider): bool
    {
        return ChatbotAiProviders::option($provider, 'breaker_failures') !== null;
    }

    /** Nakabukas ang breaker (nilalaktawan ang provider)? */
    public static function open(string $provider): bool
    {
        return self::hasBreaker($provider) && (int) Cache::get('chatbot-ai:' . $provider . ':open_until', 0) > now()->getTimestamp();
    }

    /** Isang palya: sa ika-N na sunod-sunod, nilalaktawan ang provider nang ilang segundo (saka susubukan ulit). */
    public static function failed(string $provider): void
    {
        if (!self::hasBreaker($provider)) return;
        $key = 'chatbot-ai:' . $provider . ':fails';
        Cache::add($key, 0, 3600);
        if (Cache::increment($key) >= self::siteInt($provider, 'breaker_failures', 3)) {
            Cache::put('chatbot-ai:' . $provider . ':open_until', now()->getTimestamp() + self::siteInt($provider, 'breaker_seconds', 30), 3600);
            Cache::forget($key);
        }
    }

    public static function succeeded(string $provider): void
    {
        if (self::hasBreaker($provider)) Cache::forget('chatbot-ai:' . $provider . ':fails');
    }

    /** Bakit nilalaktawan ang hakbang bago pa tumawag (hindi naka-set up, walang model, nakabukas ang breaker), o null. */
    public static function skip(array $step): ?array
    {
        $p = $step['provider'];
        if (($why = ChatbotAiProviders::notReady($p)) !== null) return ['why' => $why, 'error' => ChatbotAiProviders::notReadyError($p)];
        if ($step['model'] !== null && $step['model'] === '') return ['why' => ChatbotAiProviders::label($p) . ' is not set up.', 'error' => ChatbotAiProviders::label($p) . ' is not set up.'];
        if (self::open($p)) return ['why' => ChatbotAiProviders::label($p) . ' is paused after failures.', 'error' => ChatbotAiProviders::label($p) . ' is paused after failures.'];

        return null;
    }

    /** Ang subok na hindi tumawag. */
    public static function skipped(array $step, string $model, array $skip): array
    {
        return ['provider' => $step['provider'], 'model' => $model, 'ms' => 0, 'ok' => false, 'why' => $skip['why'], 'error' => $skip['error'], 'text' => '',
            'prompt_tokens' => null, 'completion_tokens' => null, 'called' => false];
    }

    /** Ang HTTP request ng provider (pareho sa naghihintay at sa pool). @return array{url: string, headers: array, body: array} */
    public static function request(string $provider, string $model, array $messages, int $maxTokens): array
    {
        $key = ChatbotAiProviders::key($provider);

        $body = ChatbotAi::payload($model, $messages, $maxTokens);
        // Ang hindi OpenAI (Local): ang reasoning_effort (site, o config) ang pumapatay sa pag-iisip ng model; ang OpenAI ay ang dating body.
        if ($provider !== ChatbotAiProviders::OPENAI && ($effort = self::reasoning($provider)) !== '') $body['reasoning_effort'] = $effort;

        return ['url' => (string) ChatbotAiProviders::url($provider), 'headers' => ($key !== '' ? ['Authorization' => 'Bearer ' . $key] : []) + ['Accept' => 'application/json'],
            'body' => $body];
    }

    /** Ang reasoning_effort ng provider (none | low | medium | high), o '' = hindi ipinapadala. */
    public static function reasoning(string $provider): string
    {
        $v = self::site()[$provider . '_reasoning_effort'] ?? ChatbotAiProviders::option($provider, 'reasoning_effort', '');
        $v = (string) $v === self::OMIT ? '' : (string) $v;

        return in_array($v, self::EFFORTS, true) ? $v : '';
    }

    /**
     * Ang naghihintay na takbo ng chain (Test, sequence, walang pool, CHATBOT_AI_ASYNC=false), sa loob ng $budget segundo.
     * @return array ang resulta ng step at ang record (outcome())
     */
    public function complete(string $pageName, string $nodeModel, array $messages, int $maxTokens, int $budget = ChatbotAi::TIMEOUT, ?int $group = null): array
    {
        $start = microtime(true);
        $attempts = [];
        foreach (self::forPage($pageName, $group) as $step) {
            $p = $step['provider'];
            $model = $step['model'] ?? $nodeModel;
            if ($skip = self::skip($step)) {
                $attempts[] = self::skipped($step, $model, $skip);
                continue;
            }
            $left = $attempts ? (int) floor($budget - (microtime(true) - $start)) : $budget;
            if ($left < 1) {
                $attempts[] = self::skipped($step, $model, ['why' => ChatbotAiProviders::late($p), 'error' => ChatbotAiProviders::late($p)]);
                break;
            }
            $timeout = $step['timeout'] !== null ? min($step['timeout'], $left) : $left;
            $slot = self::capped($p) ? self::slot($p, $timeout + 5) : false;
            if ($slot === null) {
                $busy = ChatbotAiProviders::label($p) . ' is busy.';
                $attempts[] = self::skipped($step, $model, ['why' => $busy, 'error' => $busy]);
                continue;
            }
            try {
                $attempt = $this->call($p, $model, $messages, $maxTokens, $timeout);
            } finally {
                if ($slot) $slot->release();
            }
            $attempt['ok'] ? self::succeeded($p) : self::failed($p);
            $attempts[] = $attempt;
            if ($attempt['ok']) break;
        }

        return self::outcome($attempts);
    }

    private function call(string $p, string $model, array $messages, int $maxTokens, int $timeout): array
    {
        $r = self::request($p, $model, $messages, $maxTokens);
        $t = microtime(true);
        try {
            $req = Http::withHeaders(array_diff_key($r['headers'], ['Accept' => 1]))->acceptJson()->timeout($timeout);
            // Ang hindi OpenAI (Local): walang sinusundang redirect, at ang patay na koneksyon ay agad na palya.
            if ($p !== ChatbotAiProviders::OPENAI) $req = $req->withoutRedirecting()->connectTimeout(min(5, $timeout));
            $res = $req->post($r['url'], $r['body']);
            $out = ChatbotAiProviders::parse($p, $res->status(), $res->json());
        } catch (ConnectionException $e) {
            $out = ['ok' => false, 'text' => '', 'error' => ChatbotAiProviders::noAnswer($p, self::timedOut($e->getMessage())), 'prompt_tokens' => null, 'completion_tokens' => null];
        }

        return ['provider' => $p, 'model' => $model, 'ms' => (int) round((microtime(true) - $t) * 1000), 'ok' => $out['ok'], 'why' => $out['error'],
            'error' => $out['error'], 'text' => $out['text'], 'prompt_tokens' => $out['prompt_tokens'], 'completion_tokens' => $out['completion_tokens'], 'called' => true];
    }

    public static function timedOut(string $message): bool
    {
        return str_contains($message, 'cURL error 28') || stripos($message, 'timed out') !== false;
    }

    /**
     * Ang resulta ng step mula sa mga subok, at ang record (walang text): sino ang sumagot, model, ilang ms ang hinintay ng
     * customer, tokens, kung lumipat, at bawat subok na may dahilan. Lahat pumalya = ang error ng huli (gaya ng dati).
     */
    public static function outcome(array $attempts): array
    {
        $last = $attempts ? $attempts[array_key_last($attempts)] : null;
        $ok = (bool) ($last['ok'] ?? false);
        $before = $ok ? array_slice($attempts, 0, -1) : array_slice($attempts, 0, max(0, count($attempts) - 1));
        $reasons = array_map(fn ($a) => 'fell back from ' . ChatbotAiProviders::label($a['provider']) . ': ' . $a['why'], $before);

        return [
            'ok' => $ok, 'text' => $ok ? (string) $last['text'] : '',
            'error' => $ok ? null : (string) ($last['error'] ?? ChatbotAiProviders::noAnswer(ChatbotAiProviders::OPENAI, false)),
            'provider' => $ok ? $last['provider'] : null, 'model_used' => $ok ? $last['model'] : null,
            'fell_back' => $ok && count($attempts) > 1,
            'fallback_reason' => $reasons ? mb_substr(implode('; ', $reasons), 0, 255) : null,
            'latency_ms' => $attempts ? array_sum(array_column($attempts, 'ms')) : null,
            'prompt_tokens' => $ok ? $last['prompt_tokens'] : null, 'completion_tokens' => $ok ? $last['completion_tokens'] : null,
            'attempts' => array_map(fn ($a) => ['provider' => $a['provider'], 'model' => mb_substr((string) $a['model'], 0, 100), 'ms' => (int) $a['ms'], 'ok' => (bool) $a['ok'],
                'why' => $a['ok'] ? null : (string) $a['why']] + (($a['called'] ?? true) ? [] : ['skipped' => true]), $attempts), // skipped: hindi tinawag (hindi sa bilis)
        ];
    }
}
