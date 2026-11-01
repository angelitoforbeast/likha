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

    /**
     * Ang chain bilang mga subok (spec 210): ang provider na may pool ay isang hakbang kada magagamit na key (iniikot ng
     * ChatbotAiKeys::usable, kaya nahahati ang mga tawag); walang magagamit = isang hakbang na walang key ("<Label> has no
     * usable key."). Ang OpenAI na wala kahit isang key (pool o server) ay ang dating "not set up". Ang Local ay walang key.
     */
    public static function steps(array $chain): array
    {
        $keys = app(ChatbotAiKeys::class);
        $out = [];
        foreach ($chain as $step) {
            $p = $step['provider'];
            if (!ChatbotAiKeys::pooled($p)) {
                $out[] = $step + ['key' => null];
                continue;
            }
            $usable = $keys->usable($p);
            if (!$usable) {
                $out[] = $step + ['key' => null, 'nokey' => $p !== ChatbotAiProviders::OPENAI || $keys->inPool($p)];
                continue;
            }
            foreach ($usable as $k) $out[] = $step + ['key' => $k];
        }

        return $out;
    }

    /** Bakit nilalaktawan ang hakbang bago pa tumawag (hindi naka-set up, walang key, walang model, nakabukas ang breaker), o null. */
    public static function skip(array $step): ?array
    {
        $p = $step['provider'];
        if (!empty($step['nokey'])) {
            $why = ChatbotAiProviders::label($p) . ' has no usable key.';
            return ['why' => $why, 'error' => $why, 'kind' => 'no_key'];
        }
        if (($step['key'] ?? null) === null && ($why = ChatbotAiProviders::notReady($p)) !== null) return ['why' => $why, 'error' => ChatbotAiProviders::notReadyError($p)];
        if ($step['model'] !== null && $step['model'] === '') return ['why' => ChatbotAiProviders::label($p) . ' is not set up.', 'error' => ChatbotAiProviders::label($p) . ' is not set up.'];
        if (self::open($p)) return ['why' => ChatbotAiProviders::label($p) . ' is paused after failures.', 'error' => ChatbotAiProviders::label($p) . ' is paused after failures.'];

        return null;
    }

    /** Ang subok na hindi tumawag. */
    public static function skipped(array $step, string $model, array $skip): array
    {
        return ['provider' => $step['provider'], 'model' => $model, 'ms' => 0, 'ok' => false, 'why' => $skip['why'], 'error' => $skip['error'], 'text' => '',
            'prompt_tokens' => null, 'completion_tokens' => null, 'called' => false, 'key' => $step['key'] ?? null, 'kind' => $skip['kind'] ?? 'skipped'];
    }

    /**
     * Ang HTTP request ng provider (pareho sa naghihintay at sa pool). Ang may key (pool, o ang server key ng OpenAI) ay ang
     * hugis ng provider (ChatbotAiWire); ang Local ay ang dati. @return array{url: string, headers: array, body: array}
     */
    public static function request(string $provider, string $model, array $messages, int $maxTokens, ?array $key = null): array
    {
        if ($key !== null) return ChatbotAiWire::request($provider, (string) $key['secret'], $model, $messages, $maxTokens);
        $key = ChatbotAiProviders::key($provider);

        $body = ChatbotAi::payload($model, $messages, $maxTokens);
        // Ang hindi OpenAI (Local): ang reasoning_effort (site, o config) ang pumapatay sa pag-iisip ng model; ang OpenAI ay ang dating body.
        if ($provider !== ChatbotAiProviders::OPENAI && ($effort = self::reasoning($provider)) !== '') $body['reasoning_effort'] = $effort;

        return ['url' => (string) ChatbotAiProviders::url($provider), 'headers' => ($key !== '' ? ['Authorization' => 'Bearer ' . $key] : []) + ['Accept' => 'application/json'],
            'body' => $body];
    }

    /** Ang sagot ng provider (HTTP status at body) bilang subok: ang may key ay ChatbotAiWire (may kind); ang Local ay ang dati. */
    public static function read(array $step, int $status, string $body): array
    {
        if (($step['key'] ?? null) !== null) return ChatbotAiWire::read($step['provider'], $status, $body, (string) $step['key']['secret']);

        return ChatbotAiProviders::parse($step['provider'], $status, json_decode($body, true)) + ['kind' => null];
    }

    /** Ang reasoning_effort ng provider (none | low | medium | high), o '' = hindi ipinapadala. */
    public static function reasoning(string $provider): string
    {
        $v = self::site()[$provider . '_reasoning_effort'] ?? ChatbotAiProviders::option($provider, 'reasoning_effort', '');
        $v = (string) $v === self::OMIT ? '' : (string) $v;

        return in_array($v, self::EFFORTS, true) ? $v : '';
    }

    /**
     * Pagkatapos ng isang tinawag na subok: ang breaker (Local), at ang key (spec 210): ang sagot ay naglilinis ng marka; ang
     * palyang kasalanan ng key ay marka (isang beses lang kahit maraming sabay); ang model o request na palya ay hindi ang key.
     * @return array ang subok, may "marked" kapag ang tawag na ito ang nagmarka
     */
    public static function settle(array $attempt): array
    {
        $p = $attempt['provider'];
        $attempt['ok'] ? self::succeeded($p) : self::failed($p);
        $key = $attempt['key'] ?? null;
        if ($key === null || ($key['id'] ?? null) === null) return $attempt;
        $keys = app(ChatbotAiKeys::class);
        if ($attempt['ok']) $keys->succeeded($key);
        elseif ($keys->failed($key, (string) ($attempt['kind'] ?? ''))) $attempt['marked'] = $attempt['kind'];

        return $attempt;
    }

    /** Ang palya na hindi kasalanan ng key (model, request): ang ibang key ng parehong provider ay hindi na sinusubukan. */
    public static function notTheKey(array $attempt): bool
    {
        return !$attempt['ok'] && ($attempt['key'] ?? null) !== null && in_array($attempt['kind'] ?? null, ['model', 'request'], true);
    }

    /**
     * Ang naghihintay na takbo ng chain (Test, sequence, walang pool, CHATBOT_AI_ASYNC=false), sa loob ng $budget segundo.
     * @return array ang resulta ng step at ang record (outcome())
     */
    public function complete(string $pageName, string $nodeModel, array $messages, int $maxTokens, int $budget = ChatbotAi::TIMEOUT, ?int $group = null): array
    {
        $start = microtime(true);
        $attempts = [];
        $skipProvider = null;
        foreach (self::steps(self::forPage($pageName, $group)) as $step) {
            $p = $step['provider'];
            if ($p === $skipProvider) continue;
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
            $keySlot = $slot !== null && $step['key'] !== null ? ChatbotAiKeys::slot($step['key'], $timeout + 5) : false;
            if ($slot === null || $keySlot === null) {
                if ($slot) $slot->release();
                $busy = ($step['key'] !== null && $keySlot === null ? 'The ' . ChatbotAiProviders::label($p) . ' key ' . $step['key']['label'] : ChatbotAiProviders::label($p)) . ' is busy.';
                $attempts[] = self::skipped($step, $model, ['why' => $busy, 'error' => $busy]);
                continue;
            }
            try {
                $attempt = $this->call($step, $model, $messages, $maxTokens, $timeout);
            } finally {
                if ($slot) $slot->release();
                if ($keySlot) $keySlot->release();
            }
            $attempts[] = $attempt = self::settle($attempt);
            if ($attempt['ok']) break;
            if (self::notTheKey($attempt)) $skipProvider = $p;
        }
        self::afterChain($pageName, $attempts);

        return self::outcome($attempts);
    }

    private function call(array $step, string $model, array $messages, int $maxTokens, int $timeout): array
    {
        $p = $step['provider'];
        $r = self::request($p, $model, $messages, $maxTokens, $step['key']);
        $t = microtime(true);
        try {
            $req = Http::withHeaders(array_diff_key($r['headers'], ['Accept' => 1]))->acceptJson()->timeout($timeout);
            // Ang hindi OpenAI (Local, Claude, DeepSeek): walang sinusundang redirect, at ang patay na koneksyon ay agad na palya.
            if ($p !== ChatbotAiProviders::OPENAI) $req = $req->withoutRedirecting()->connectTimeout(min(5, $timeout));
            $res = $req->post($r['url'], $r['body']);
            $out = self::read($step, $res->status(), (string) $res->body());
        } catch (ConnectionException $e) {
            $out = ChatbotAiWire::noAnswer($p, self::timedOut($e->getMessage()));
        }

        return ['provider' => $p, 'model' => $model, 'ms' => (int) round((microtime(true) - $t) * 1000), 'ok' => $out['ok'], 'why' => $out['error'],
            'error' => $out['error'], 'text' => $out['text'], 'prompt_tokens' => $out['prompt_tokens'], 'completion_tokens' => $out['completion_tokens'], 'called' => true,
            'key' => $step['key'], 'kind' => $out['kind'] ?? null];
    }

    public static function timedOut(string $message): bool
    {
        return str_contains($message, 'cURL error 28') || stripos($message, 'timed out') !== false;
    }

    /** Ang pangalan ng key sa record at sa event (walang key): ang label, o ang provider kapag walang key. */
    private static function keyName(array $a): string
    {
        return ($a['key'] ?? null) !== null ? (string) $a['key']['label'] : ChatbotAiProviders::label($a['provider']);
    }

    /** Lahat ng subok ay pumalya, may key ng pool, at walang palya na hindi kasalanan ng key: "All AI keys failed." */
    public static function allKeysFailed(array $attempts): bool
    {
        if (!$attempts || array_filter($attempts, fn ($a) => $a['ok'])) return false;
        // Ang "busy" o "late" na laktaw ay hindi palya ng key: ang tinawag na key na pumalya (o walang magagamit na key) lang.
        $pool = array_filter($attempts, fn ($a) => (($a['key'] ?? null) !== null && !($a['key']['legacy'] ?? false) && in_array($a['kind'] ?? null, ChatbotAiWire::KEY_KINDS, true))
            || ($a['kind'] ?? null) === 'no_key');
        $notKey = array_filter($attempts, fn ($a) => in_array($a['kind'] ?? null, ['model', 'request'], true));

        return $pool && !$notKey;
    }

    /**
     * Pagkatapos ng chain (naghihintay o pool): isang event kada key na minarkahan ng tawag na ito (mula → ang sumunod na
     * tinawag), at ang alert kapag lahat ng key ay pumalya. Hindi dapat makapigil sa sagot.
     */
    public static function afterChain(string $pageName, array $attempts): void
    {
        try {
            $keys = app(ChatbotAiKeys::class);
            foreach ($attempts as $i => $a) {
                if (!isset($a['marked'])) continue;
                $next = null;
                foreach (array_slice($attempts, $i + 1) as $b) if ($b['called'] ?? true) { $next = self::keyName($b); break; }
                $keys->event($pageName, $a['provider'], self::keyName($a), $next, ChatbotAiKeys::REASONS[$a['marked']] ?? $a['marked']);
            }
            if (self::allKeysFailed($attempts)) $keys->alert($pageName, self::ALL_KEYS_FAILED);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public const ALL_KEYS_FAILED = 'All AI keys failed.';

    /**
     * Ang resulta ng step mula sa mga subok, at ang record (walang text): sino ang sumagot, model, key (label at huling 4),
     * ilang ms ang hinintay ng customer, tokens, kung lumipat, at bawat subok na may dahilan. Lahat pumalya = ang error ng huli
     * (gaya ng dati), o "All AI keys failed." kapag key ng pool ang lahat ng pumalya.
     */
    public static function outcome(array $attempts): array
    {
        $last = $attempts ? $attempts[array_key_last($attempts)] : null;
        $ok = (bool) ($last['ok'] ?? false);
        $before = $ok ? array_slice($attempts, 0, -1) : array_slice($attempts, 0, max(0, count($attempts) - 1));
        $reasons = array_map(fn ($a) => 'fell back from ' . ChatbotAiProviders::label($a['provider']) . (($a['key'] ?? null) !== null && !($a['key']['legacy'] ?? false) ? ' (' . $a['key']['label'] . ')' : '') . ': ' . $a['why'], $before);
        $key = $ok ? ($last['key'] ?? null) : null;
        $error = $ok ? null : (string) ($last['error'] ?? ChatbotAiProviders::noAnswer(ChatbotAiProviders::OPENAI, false));
        if (!$ok && self::allKeysFailed($attempts)) $error = self::ALL_KEYS_FAILED;

        return [
            'ok' => $ok, 'text' => $ok ? (string) $last['text'] : '',
            'error' => $error,
            'provider' => $ok ? $last['provider'] : null, 'model_used' => $ok ? $last['model'] : null,
            'fell_back' => $ok && count($attempts) > 1,
            'fallback_reason' => $reasons ? mb_substr(implode('; ', $reasons), 0, 255) : null,
            'latency_ms' => $attempts ? array_sum(array_column($attempts, 'ms')) : null,
            'prompt_tokens' => $ok ? $last['prompt_tokens'] : null, 'completion_tokens' => $ok ? $last['completion_tokens'] : null,
            'key_id' => $key['id'] ?? null, 'key_label' => $key !== null ? mb_substr((string) $key['label'], 0, 100) : null, 'key_last4' => $key !== null ? substr((string) $key['last4'], -4) : null,
            'attempts' => array_map(fn ($a) => ['provider' => $a['provider'], 'model' => mb_substr((string) $a['model'], 0, 100), 'ms' => (int) $a['ms'], 'ok' => (bool) $a['ok'],
                'why' => $a['ok'] ? null : (string) $a['why']] + (($a['called'] ?? true) ? [] : ['skipped' => true]) // skipped: hindi tinawag (hindi sa bilis)
                + (($a['key'] ?? null) !== null ? ['key' => mb_substr((string) $a['key']['label'], 0, 100), 'last4' => substr((string) $a['key']['last4'], -4)] : []), $attempts),
        ];
    }
}
