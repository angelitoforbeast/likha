<?php

namespace App\Services;

use App\Models\PageSetting;
use App\Services\Chatbot\ChatbotMockAi;
use App\Support\BotcakeClock;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Ang pekeng Botcake ng mga test page (stress test): sinasagot ng BotcakeClient ang tawag dito mismo, walang HTTP at walang
 * web route. Para lang sa page na may botcake.mock = 1 AT botcake.page_id na nagsisimula sa 99 (ang test range); ang page
 * na may totoong ID ay hindi kailanman pinepeke. Ang bawat tawag ay naghihintay nang botcake.mock_latency_ms (400, hanggang
 * 5000) at sumasagot sa hugis ng Botcake; ang padala (POST) ay 500 sa botcake.mock_error_rate (0–0.5). Ang mga field na
 * isinulat, ang statistics ng flow at ang bilang ng bawat uri ng tawag ay nasa cache kada page. Ang bawat send_content ay
 * itinatala (PSID na pinadalhan at text) para mabilang ng --stats ang naligaw na sagot ng pekeng AI (ChatbotMockAi:
 * chatbot.mock_ai = 1 sa parehong mock page).
 */
class BotcakeMock
{
    public const PREFIX = '99';
    public const PAGES_CACHE = 'botcake-mock:pages';
    public const KEYS = ['page_id', 'mock', 'mock_latency_ms', 'mock_error_rate'];
    public const AI_KEYS = ['mock_ai', 'mock_ai_latency_ms']; // provider chatbot (ChatbotMockAi)
    public const KINDS = ['send_content', 'customer_fields', 'send_flow', 'customer_info', 'flows', 'custom_fields', 'message_templates', 'statistics', 'customers', 'other', 'error'];
    public const DEFAULT_LATENCY_MS = 400;
    public const MAX_LATENCY_MS = 5000;
    public const MAX_ERROR_RATE = 0.5;
    public const FLOW_ID = '990001';
    public const FIELD_ID = '990002';
    private const TTL = 86400;

    /** Para sa test: ang "roll" ng error (0–1); null = mt_rand. */
    public static ?Closure $roll = null;

    private static ?array $memo = null;
    private static float $memoAt = 0;

    public static function isTestId(string $pageId): bool
    {
        return str_starts_with(trim($pageId), self::PREFIX) && ctype_digit(trim($pageId));
    }

    /**
     * Ang mga mock page: page_id => [latency_ms, error_rate, ai: bagal ng pekeng AI o null], naka-cache nang 60 s
     * (binubura ng PageSettings kapag nagbago ang alinman sa KEYS o AI_KEYS). Isang query.
     * @return array<string, array{latency: int, error: float, ai: ?int}>
     */
    public static function pages(): array
    {
        // Sa bawat tawag sa Botcake (pati totoong page): nasa memory ng worker nang 10 s, para hindi isang cache read kada tawag.
        if (self::$memo !== null && microtime(true) - self::$memoAt < 10) return self::$memo;
        self::$memoAt = microtime(true);

        return self::$memo = Cache::remember(self::PAGES_CACHE, 60, function () {
            $by = [];
            $rows = PageSetting::where('active', true)->where(fn ($q) => $q->where(fn ($b) => $b->where('provider', 'botcake')->whereIn('key', self::KEYS))
                ->orWhere(fn ($c) => $c->where('provider', 'chatbot')->whereIn('key', self::AI_KEYS)))->orderBy('id')->get(['page_name', 'provider', 'key', 'value', 'is_secret']);
            foreach ($rows as $s) {
                $by[mb_strtolower(trim((string) $s->page_name))][$s->provider === 'chatbot' ? 'chatbot.' . $s->key : $s->key] = trim((string) $s->plainValue());
            }
            $out = [];
            foreach ($by as $v) {
                if (($v['mock'] ?? '') !== '1' || !self::isTestId($v['page_id'] ?? '')) continue;
                $out[$v['page_id']] = [
                    'latency' => max(0, min(self::MAX_LATENCY_MS, is_numeric($v['mock_latency_ms'] ?? null) ? (int) $v['mock_latency_ms'] : self::DEFAULT_LATENCY_MS)),
                    'error' => max(0.0, min(self::MAX_ERROR_RATE, is_numeric($v['mock_error_rate'] ?? null) ? (float) $v['mock_error_rate'] : 0.0)),
                    'ai' => ($v['chatbot.mock_ai'] ?? '') !== '1' ? null : max(0, min(ChatbotMockAi::MAX_LATENCY_MS,
                        is_numeric($v['chatbot.mock_ai_latency_ms'] ?? null) ? (int) $v['chatbot.mock_ai_latency_ms'] : ChatbotMockAi::DEFAULT_LATENCY_MS)),
                ];
            }

            return $out;
        });
    }

    public static function forget(): void
    {
        self::$memo = null;
        Cache::forget(self::PAGES_CACHE);
    }

    /**
     * Ang sagot sa tawag kapag mock page ang nasa path (/pages/{id}/…), o null (totoong Botcake). Naghihintay muna nang
     * latency ng page (sa BotcakeClock, gaya ng totoong tawag).
     * @return array{0: int, 1: mixed}|null [HTTP status, body]
     */
    public static function answer(string $method, string $path, array $data, BotcakeClock $clock): ?array
    {
        if (!preg_match('#^/pages/(\d+)(/[^?]*)?$#', $path, $m) || !self::isTestId($m[1])) return null;
        $page = self::pages()[$m[1]] ?? null;
        if ($page === null) return null;
        $pageId = $m[1];
        $rest = $m[2] ?? '';
        self::perSecond($pageId);
        if ($page['latency'] > 0) $clock->sleep($page['latency'] / 1000);

        // Ang padala (POST) ay pumapalya sa error rate: 500, para tumakbo ang retry at ang failed na daan.
        if ($method !== 'GET' && $page['error'] > 0 && (self::$roll ? (self::$roll)() : mt_rand() / mt_getrandmax()) < $page['error']) {
            self::count($pageId, 'error');
            return [500, ['success' => false, 'message' => 'Mock Botcake error']];
        }

        $ok = ['success' => true];
        if ($method !== 'GET' && $rest === '/flows/send_content') {
            self::record($pageId, (string) ($data['psid'] ?? ''), (array) ($data['data']['content']['messages'] ?? []));
            return self::hit($pageId, 'send_content', [200, $ok]);
        }
        if ($method !== 'GET' && $rest === '/flows/send_flow') {
            Cache::add(self::key($pageId, 'stats'), 0, self::TTL);
            Cache::increment(self::key($pageId, 'stats'));
            return self::hit($pageId, 'send_flow', [200, $ok]);
        }
        if (preg_match('#^/customer/([^/]+)/customer_fields$#', $rest, $c)) {
            $key = self::key($pageId, 'fields:' . $c[1]);
            if ($method !== 'GET') {
                $fields = (array) Cache::get($key, []);
                foreach ((array) ($data['data'] ?? []) as $f) {
                    if (is_array($f) && isset($f['id'])) $fields[(string) $f['id']] = (string) ($f['value'] ?? '');
                }
                Cache::put($key, $fields, self::TTL);
                return self::hit($pageId, 'customer_fields', [200, $ok]);
            }
            $rows = array_map(fn ($id, $v) => ['id' => (string) $id, 'value' => $v], array_keys((array) Cache::get($key, [])), (array) Cache::get($key, []));
            return self::hit($pageId, 'customer_fields', [200, ['success' => true, 'data' => $rows]]);
        }
        if ($method === 'GET' && preg_match('#^/customer/([^/]+)/info$#', $rest, $c)) {
            return self::hit($pageId, 'customer_info', [200, ['success' => true, 'data' => ['psid' => $c[1], 'name' => 'Stress customer']]]);
        }
        if ($method === 'GET' && $rest === '/flows') {
            $name = app(ChatblastSetupCheck::class)->names()['flow'];
            return self::hit($pageId, 'flows', [200, ['success' => true, 'data' => ['flows' => [['id' => (int) self::FLOW_ID, 'name' => $name, 'is_removed' => false, 'parent_id' => null]], 'folders' => []]]]);
        }
        if ($method === 'GET' && $rest === '/custom_fields') {
            return self::hit($pageId, 'custom_fields', [200, ['success' => true, 'data' => [['id' => (int) self::FIELD_ID, 'name' => 'message']]]]);
        }
        if ($method === 'GET' && $rest === '/message_templates') {
            return self::hit($pageId, 'message_templates', [200, ['success' => true, 'data' => [['name' => 'post_order', 'status' => 'APPROVED', 'category' => 'UTILITY',
                'components' => [['type' => 'BODY', 'text' => "May update po sa order nyo:\n{{1}}"]]]]]]);
        }
        if ($method === 'GET' && preg_match('#^/flows/\d+/statistics$#', $rest)) {
            $n = (int) Cache::get(self::key($pageId, 'stats'), 0);
            return self::hit($pageId, 'statistics', [200, ['success' => true, 'data' => ['seen' => $n, 'sent' => $n, 'delivery' => $n, 'total_phone_number' => 0, 'is_clicked' => 0]]]);
        }
        if ($method === 'GET' && $rest === '/customer') return self::hit($pageId, 'customers', [200, []]);

        // Hindi pa ginagaya: 404 na hugis-Botcake, isang log lang kada uri ng path.
        $pattern = preg_replace('#/\d+#', '/{id}', $method . ' ' . $rest);
        if (Cache::add('botcake-mock:logged:' . sha1($pattern), true, self::TTL)) Log::info('Botcake mock: no fake answer for this call', ['call' => $pattern]);

        return self::hit($pageId, 'other', [404, ['success' => false, 'message' => 'Not found']]);
    }

    private static function hit(string $pageId, string $kind, array $answer): array
    {
        self::count($pageId, $kind);

        return $answer;
    }

    private static function count(string $pageId, string $kind): void
    {
        $key = self::key($pageId, 'count:' . $kind);
        Cache::add($key, 0, self::TTL);
        Cache::increment($key);
    }

    /** Ang cache key ng page, sa "generation" nito (ang cleanup ay nagsisimula ng bago, kaya nawawala ang luma). */
    private static function key(string $pageId, string $what): string
    {
        return 'botcake-mock:' . (int) Cache::get('botcake-mock:gen:' . $pageId, 0) . ':' . $pageId . ':' . $what;
    }

    /** Ang natanggap ng "customer": ang PSID na pinadalhan at ang text (hanggang 2000 letra), isa kada send_content. */
    private static function record(string $pageId, string $psid, array $messages): void
    {
        $text = implode("\n", array_filter(array_map(fn ($m) => is_array($m) ? (string) ($m['text'] ?? '') : '', $messages)));
        $key = self::key($pageId, 'sent');
        Cache::add($key, 0, self::TTL);
        $n = Cache::increment($key);
        Cache::put(self::key($pageId, 'sent:' . $n), [$psid, mb_substr($text, 0, 2000)], self::TTL);
    }

    /** @return array<int, array{0: string, 1: string}> ang naitalang send_content ng page: [PSID na pinadalhan, text] */
    public static function sent(string $pageId): array
    {
        $out = [];
        for ($i = 1, $n = (int) Cache::get(self::key($pageId, 'sent'), 0); $i <= $n; $i++) {
            if (is_array($r = Cache::get(self::key($pageId, 'sent:' . $i)))) $out[] = $r;
        }

        return $out;
    }

    /** Ilang tawag sa page sa segundong ito; ang pinakamarami ay itinatala (ang rate kada page na nakita, para sa --stats). */
    private static function perSecond(string $pageId): void
    {
        $key = self::key($pageId, 'sec:' . time());
        Cache::add($key, 0, 120);
        $n = (int) Cache::increment($key);
        if ($n > (int) Cache::get(self::key($pageId, 'max_per_second'), 0)) Cache::put(self::key($pageId, 'max_per_second'), $n, self::TTL);
    }

    /** Ang pinakamaraming tawag sa isang segundo sa page (lahat ng uri). */
    public static function maxPerSecond(string $pageId): int
    {
        return (int) Cache::get(self::key($pageId, 'max_per_second'), 0);
    }

    /** @return array<string, int> uri ng tawag => bilang (ang may bilang lang) */
    public static function counts(string $pageId): array
    {
        $out = [];
        foreach (self::KINDS as $kind) {
            $n = (int) Cache::get(self::key($pageId, 'count:' . $kind), 0);
            if ($n > 0) $out[$kind] = $n;
        }

        return $out;
    }

    /** Burahin ang mga field, statistics at bilang ng page (bagong generation), at ang listahan ng mock page. */
    public static function reset(string $pageId): void
    {
        Cache::forever('botcake-mock:gen:' . $pageId, (int) Cache::get('botcake-mock:gen:' . $pageId, 0) + 1);
        self::forget();
    }
}
